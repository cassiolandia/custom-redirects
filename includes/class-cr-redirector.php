<?php

class CR_Redirector {

    public static function init() {
        add_action('init', [self::class, 'add_rewrite_rules']);
        add_filter('query_vars', [self::class, 'add_query_vars']);
        add_action('parse_request', [self::class, 'process_redirect_performant'], 10, 1);
    }

    public static function add_rewrite_rules() {
        add_rewrite_rule('^go/([^/]+)/?$', 'index.php?redirect_slug=$matches[1]', 'top');
    }

    public static function add_query_vars($vars) {
        $vars[] = 'redirect_slug';
        return $vars;
    }

    public static function process_redirect_performant($wp) {
        if (!isset($wp->query_vars['redirect_slug'])) {
            return;
        }

        $slug = $wp->query_vars['redirect_slug'];

        if (empty($slug) || strlen($slug) > 255) {
            return;
        }

        $cache_group = 'cr_redirects';
        $cache_key = 'slug_' . $slug;
        $link_data = wp_cache_get($cache_key, $cache_group);

        if (false === $link_data) {
            global $wpdb;

            $sql = $wpdb->prepare(
                "SELECT
                    p.ID,
                    mt1.meta_value AS destination_url,
                    mt2.meta_value AS is_inactive
                FROM
                    {$wpdb->posts} AS p
                LEFT JOIN
                    {$wpdb->postmeta} AS mt1 ON (p.ID = mt1.post_id AND mt1.meta_key = '_destination_url')
                LEFT JOIN
                    {$wpdb->postmeta} AS mt2 ON (p.ID = mt2.post_id AND mt2.meta_key = '_is_inactive')
                WHERE
                    p.post_name = %s
                    AND p.post_type = 'redirect_link'
                    AND p.post_status = 'publish'
                LIMIT 1",
                $slug
            );
            $link_data = $wpdb->get_row($sql);

            wp_cache_set($cache_key, $link_data, $cache_group, 12 * HOUR_IN_SECONDS);
        }
        
        if (!$link_data) {
            return;
        }

        $destination_url = $link_data->destination_url ?? '';
        $is_inactive = ($link_data->is_inactive ?? '') === '1';
        
        $is_valid_url = !empty($destination_url) && 
                       (strpos($destination_url, 'http://') === 0 || strpos($destination_url, 'https://') === 0) &&
                       strlen($destination_url) < 2048;

        if ($is_inactive || !$is_valid_url) {
            $final_redirect_url = home_url('/');
            $track_this_click = false;
        } else {
            $final_redirect_url = $destination_url;
            $track_this_click = true;
        }

        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
            header('Location: ' . $final_redirect_url, true, 307);
        }

        if ($track_this_click && !(defined('DOING_CRON') && DOING_CRON) && !self::is_bot()) {
            self::track_click_async($link_data->ID);
        }

        exit;
    }

    private static function track_click_async($post_id) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        
        self::track_click($post_id);
    }

    private static function track_click($link_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'redirect_clicks';
        $today      = current_time('Y-m-d');
        
        $sql = $wpdb->prepare(
            "INSERT INTO {$table_name} (link_id, click_date, click_count) VALUES (%d, %s, 1) 
             ON DUPLICATE KEY UPDATE click_count = click_count + 1",
            $link_id, $today
        );
        $wpdb->query($sql);
    }

    public static function is_bot(): bool {
        static $patterns_cache = null;
        static $log_file = null;
        
        if ($patterns_cache === null) {
            $patterns_cache = [
                'wordpress' => '/^(wordpress|jetpack|wp-rocket|wp-optimize|wp-cron)\/i',
                'social' => '/\b(whatsapp|facebookexternalhit|facebookcatalog|facebot|instagram|discordbot|linkedinbot|twitterbot|pinterest|skypeuripreview|slackbot-linkexpanding|telegrambot|redditbot|vkshare|line|kakaotalk|wechat)\b/i',
                'browser' => '/\b(mozilla.*webkit|chrome\/[\d.]+|firefox\/[\d.]+|safari\/[\d.]+|edge\/[\d.]+|opera\/[\d.]+)\b/i',
                'suspicious' => '/\b(headless|automation|webdriver|test|check|scan|monitor)\b|^[a-z]+\/[\d.]+$|\b(api|sdk|client|lib)\s*[\d.]*$/i',
                'chrome_version' => '/chrome\/(\d+)/i',
                'parentheses' => '/\([^)]+\)/',
                'bot_mega' => '/\b(googlebot|bingbot|yandexbot|baiduspider|slurp|duckduckbot|applebot|seznam|nutch|petalbot|sogou|exabot|gptbot|claude-web|openai|anthropic-ai|perplexity|ccbot|bytespider|chatgpt-user|bard|gemini-pro|python-requests|python-urllib|go-http-client|node-fetch|axios|guzzle|httpclient|postman|insomnia|httpie|selenium|puppeteer|playwright|phantomjs|headlesschrome|webdriver|chromedriver|cypress|scrapy|ahrefs|semrush|mj12bot|screaming frog|moz\.com|pingdom|uptimerobot|statuscake)\b/i'
            ];
            
            $log_file = WP_CONTENT_DIR . '/cr-bot-detection.log';
        }
        
        $log_data = [
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'empty', 0, 120),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'referer' => isset($_SERVER['HTTP_REFERER']) ? 'yes' : 'no'
        ];
        
        $write_log = function($result, $reason, $score = null) use ($log_file, $log_data) {
            $dir = dirname($log_file);
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            
            if (!is_writable($dir)) {
                error_log("CR Bot Detection: Cannot write to {$dir}");
                return;
            }
            
            $log_entry = "{$log_data['timestamp']} | " .
                        ($result ? 'BOT' : 'HUMAN') . " | {$reason}" .
                        ($score !== null ? " | Score: {$score}" : '') .
                        " | IP: {$log_data['ip']} | Ref: {$log_data['referer']} | UA: {$log_data['ua']}\n";
            
            $written = @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
            
            if ($written === false) {
                error_log("CR Bot Detection: {$log_entry}");
            }
        };

        if ((defined('DOING_CRON') && DOING_CRON) || 
            php_sapi_name() === 'cli' || 
            (defined('WP_CLI') && WP_CLI)) {
            $write_log(true, 'System context');
            return true;
        }
        
        if (defined('DOING_AJAX') && DOING_AJAX) {
            $referer = $_SERVER['HTTP_REFERER'] ?? '';
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if (!$referer || !str_contains($referer, $host)) {
                $write_log(true, 'AJAX no referer');
                return true;
            }
        }

        static $automation_headers = [
            'HTTP_X_AUTOMATION' => 1, 'HTTP_X_SELENIUM' => 1, 'HTTP_X_WEBDRIVER' => 1,
            'HTTP_WEBDRIVER' => 1, 'HTTP_X_PUPPETEER' => 1, 'HTTP_X_PLAYWRIGHT' => 1
        ];
        
        if (array_intersect_key($_SERVER, $automation_headers)) {
            $write_log(true, 'Automation header');
            return true;
        }

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $userAgent_len = strlen($userAgent);
        
        if ($userAgent_len < 15 || $userAgent_len > 2000) {
            $write_log(true, "Invalid UA length: {$userAgent_len}");
            return true;
        }
        
        $lowerUA = strtolower($userAgent);

        if (preg_match($patterns_cache['wordpress'], $userAgent)) {
            $write_log(false, 'WordPress tool');
            return false;
        }
        
        if (preg_match($patterns_cache['social'], $lowerUA)) {
            $write_log(false, 'Social bot');
            return false;
        }

        if (preg_match($patterns_cache['bot_mega'], $lowerUA)) {
            $write_log(true, 'Known bot');
            return true;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            $write_log(true, 'Non-GET method');
            return true;
        }

        $required_headers = ['HTTP_ACCEPT', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_ACCEPT_ENCODING'];
        foreach ($required_headers as $header) {
            if (!isset($_SERVER[$header])) {
                $write_log(true, "Missing required header: {$header}");
                return true;
            }
        }
        
        $suspicious_headers = [
            'HTTP_CONNECTION' => 'close',
            'HTTP_ACCEPT' => '*/*',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ACCEPT' => 'text/plain'
        ];
        
        foreach ($suspicious_headers as $header => $suspicious_value) {
            if (isset($_SERVER[$header]) && $_SERVER[$header] === $suspicious_value) {
                $write_log(true, "Suspicious header value: {$header}={$suspicious_value}");
                return true;
            }
        }
        
        static $essential_headers = [
            'HTTP_ACCEPT' => 18,
            'HTTP_ACCEPT_LANGUAGE' => 15,
            'HTTP_ACCEPT_ENCODING' => 12,
            'HTTP_SEC_FETCH_SITE' => 20,
            'HTTP_SEC_FETCH_MODE' => 15,
            'HTTP_SEC_FETCH_DEST' => 12,
            'HTTP_UPGRADE_INSECURE_REQUESTS' => 15,
            'HTTP_SEC_CH_UA' => 18,
            'HTTP_SEC_CH_UA_MOBILE' => 10,
            'HTTP_DNT' => 5,
            'HTTP_CACHE_CONTROL' => 8,
            'HTTP_SEC_FETCH_USER' => 12
        ];
        
        $human_score = 0;
        $penalty_score = 0;
        
        foreach ($essential_headers as $header => $score) {
            if (isset($_SERVER[$header])) {
                $human_score += $score;
            } else {
                $penalty_score += $score;
            }
        }

        if (isset($_SERVER['HTTP_ACCEPT'])) {
            $accept = $_SERVER['HTTP_ACCEPT'];
            
            $forbidden_accepts = ['*/*', 'application/json', 'text/plain', 'text/*', 'image/*'];
            if (in_array(trim($accept), $forbidden_accepts, true)) {
                $write_log(true, "Forbidden accept: {$accept}");
                return true;
            }
            
            if (substr_count($accept, ',') < 2) {
                $write_log(true, "Too simple accept header");
                return true;
            }
            
            if (!str_starts_with($accept, 'text/html')) {
                $penalty_score += 25;
            }
            
            $score_map = [
                'text/html' => 15,
                'application/xhtml+xml' => 12,
                'image/webp' => 10,
                'image/avif' => 8,
                'image/apng' => 6,
                'application/signed-exchange' => 5
            ];
            
            foreach ($score_map as $type => $score) {
                if (str_contains($accept, $type)) {
                    $human_score += $score;
                }
            }
            
            if (str_contains($accept, 'q=')) {
                $human_score += 10;
            }
        }
        
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
            
            if (!str_contains($lang, ',') && !str_contains($lang, '-')) {
                $penalty_score += 20;
            }
            
            if (str_contains($lang, ',')) $human_score += 12;
            if (str_contains($lang, 'q=')) $human_score += 8;
            
            if (preg_match('/^(en|pt|es|fr|de|ja|zh)$/i', trim($lang))) {
                $penalty_score += 25;
            }
        }
        
        if (isset($_SERVER['HTTP_ACCEPT_ENCODING'])) {
            $encoding = $_SERVER['HTTP_ACCEPT_ENCODING'];
            
            if (!str_contains($encoding, 'gzip')) {
                $penalty_score += 20;
            }
            
            if (str_contains($encoding, 'br')) $human_score += 8;
            if (str_contains($encoding, 'deflate')) $human_score += 5;
        }

        $has_browser_signature = (bool)preg_match($patterns_cache['browser'], $userAgent);
        if ($has_browser_signature) {
            $human_score += 20;
            
            if (str_contains($lowerUA, 'mozilla') && str_contains($lowerUA, 'webkit')) {
                $human_score += 10;
            }
        } else {
            $penalty_score += 40;
        }
        
        if (!preg_match($patterns_cache['parentheses'], $userAgent)) {
            $write_log(true, 'No parentheses in UA');
            return true;
        }
        
        if ($userAgent_len < 60) {
            $penalty_score += 25;
        }
        
        if ($userAgent_len > 1000) {
            $penalty_score += 15;
        }
        
        $required_tokens = 0;
        $browser_tokens = ['mozilla', 'webkit', 'chrome', 'safari', 'firefox', 'edge'];
        
        foreach ($browser_tokens as $token) {
            if (str_contains($lowerUA, $token)) {
                $required_tokens++;
            }
        }
        
        if ($required_tokens < 2) {
            $penalty_score += 30;
        }
        
        if (str_contains($lowerUA, 'chrome') && preg_match($patterns_cache['chrome_version'], $userAgent, $matches)) {
            $version = (int)$matches[1];
            if ($version < 100 || $version > 130) {
                $penalty_score += 25;
            }
            
            if ($version >= 120) {
                $human_score += 8;
            }
        }
        
        $os_patterns = ['windows', 'macintosh', 'linux', 'android', 'iphone', 'ipad'];
        $has_os = false;
        
        foreach ($os_patterns as $os) {
            if (str_contains($lowerUA, $os)) {
                $has_os = true;
                $human_score += 8;
                break;
            }
        }
        
        if (!$has_os) {
            $penalty_score += 20;
        }

        if (preg_match($patterns_cache['suspicious'], $userAgent)) {
            $write_log(true, 'Suspicious pattern');
            return true;
        }

        $final_score = $human_score - $penalty_score;
        $threshold = $has_browser_signature ? 60 : 90;
        
        $write_log(null, "Score calculation: Human={$human_score}, Penalty={$penalty_score}, Final={$final_score}, Threshold={$threshold}", $final_score);
        
        if ($final_score < $threshold) {
            $write_log(true, 'Failed score threshold', $final_score);
            return true;
        }
        
        if ($has_browser_signature && $final_score < 80) {
            $write_log(true, 'Low score despite browser signature', $final_score);
            return true;
        }
        
        $write_log(false, 'Verified human', $final_score);
        return false;
    }
}