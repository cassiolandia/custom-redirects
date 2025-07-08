<?php

class CR_Redirector {

    public static function init() {
        if (is_admin()) {
            return;
        }
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
        // Initial, non-UA checks that are fast and definitive.
        if (self::is_system_request() || self::is_automation_request()) {
            return true;
        }

        // Basic request validation. This also handles some suspicious headers.
        if (self::is_invalid_basic_request()) {
            return true;
        }

        // Pre-emptive checks based on User Agent patterns.
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $lowerUA = strtolower($userAgent);
        static $patterns_cache = null;
        self::initialize_patterns($patterns_cache);

        // Allow WordPress and Social bots for previews, etc. They are not human, but we want to allow them.
        if (preg_match($patterns_cache['wordpress'], $userAgent) || preg_match($patterns_cache['social'], $lowerUA)) {
            return false;
        }

        // Block known malicious/automation bots immediately based on UA.
        if (preg_match($patterns_cache['bot_mega'], $lowerUA) || preg_match($patterns_cache['suspicious'], $userAgent)) {
            return true;
        }

        // For more ambiguous cases, we use a scoring system.
        $human_score = 0;
        $penalty_score = 0;

        self::score_headers($human_score, $penalty_score, $lowerUA);
        self::score_user_agent($human_score, $penalty_score, $userAgent, $lowerUA, $patterns_cache);

        // Final decision based on the calculated score.
        $final_score = $human_score - $penalty_score;
        $has_browser_signature = (bool)preg_match($patterns_cache['browser'], $userAgent);
        $threshold = $has_browser_signature ? 50 : 85;

        if ($final_score < $threshold) {
            return true;
        }
        
        // A request with a browser signature should have a reasonably high score.
        if ($has_browser_signature && $final_score < 70) {
            return true;
        }

        return false;
    }

    private static function initialize_patterns(&$patterns_cache) {
        if ($patterns_cache !== null) {
            return;
        }
        $patterns_cache = [
            'wordpress' => '/^(wordpress|jetpack|wp-rocket|wp-optimize|wp-cron)\/i',
            'social' => '/\b(whatsapp|facebookexternalhit|facebookcatalog|facebot|instagram|discordbot|linkedinbot|twitterbot|pinterest|skypeuripreview|slackbot-linkexpanding|telegrambot|redditbot|vkshare|line|kakaotalk|wechat)\b/i',
            'browser' => '/\b(mozilla.*webkit|chrome\/[\d.]+|firefox\/[\d.]+|safari\/[\d.]+|edge\/[\d.]+|opera\/[\d.]+)\b/i',
            'suspicious' => '/\b(headless|automation|webdriver|test|check|scan|monitor)\b|^[a-z]+\/[\d.]+$|\b(api|sdk|client|lib)\s*[\d.]*$/i',
            'chrome_version' => '/chrome\/(\d+)/i',
            'parentheses' => '/\([^)]+\)/',
            'bot_mega' => '/\b(googlebot|bingbot|yandexbot|baiduspider|slurp|duckduckbot|applebot|seznam|nutch|petalbot|sogou|exabot|gptbot|claude-web|openai|anthropic-ai|perplexity|ccbot|bytespider|chatgpt-user|bard|gemini-pro|python-requests|python-urllib|go-http-client|node-fetch|axios|guzzle|httpclient|postman|insomnia|httpie|selenium|puppeteer|playwright|phantomjs|headlesschrome|webdriver|chromedriver|cypress|scrapy|ahrefs|semrush|mj12bot|screaming frog|moz\.com|pingdom|uptimerobot|statuscake)\b/i'
        ];
    }

    private static function is_system_request(): bool {
        return (defined('DOING_CRON') && DOING_CRON) ||
            php_sapi_name() === 'cli' ||
            (defined('WP_CLI') && WP_CLI);
    }

    private static function is_automation_request(): bool {
        static $automation_headers = [
            'HTTP_X_AUTOMATION' => 1, 'HTTP_X_SELENIUM' => 1, 'HTTP_X_WEBDRIVER' => 1,
            'HTTP_WEBDRIVER' => 1, 'HTTP_X_PUPPETEER' => 1, 'HTTP_X_PLAYWRIGHT' => 1
        ];
        return (bool)array_intersect_key($_SERVER, $automation_headers);
    }

    private static function is_invalid_basic_request(): bool {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            $referer = $_SERVER['HTTP_REFERER'] ?? '';
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if (!$referer || !str_contains($referer, $host)) {
                return true;
            }
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return true;
        }

        $userAgent_len = strlen($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($userAgent_len < 15 || $userAgent_len > 2000) {
            return true;
        }

        $required_headers = ['HTTP_ACCEPT', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_ACCEPT_ENCODING'];
        foreach ($required_headers as $header) {
            if (!isset($_SERVER[$header])) {
                return true;
            }
        }
        
        if (isset($_SERVER['HTTP_CONNECTION']) && $_SERVER['HTTP_CONNECTION'] === 'close') {
            return true;
        }

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $forbidden_accepts = ['*/*', 'application/json', 'text/plain', 'text/*', 'image/*'];
        if (in_array(trim($accept), $forbidden_accepts, true)) {
            return true;
        }
        
        if (substr_count($accept, ',') < 2) {
            return true;
        }

        return false;
    }

    private static function score_headers(&$human_score, &$penalty_score, $lowerUA) {
        static $essential_headers = [
            'HTTP_ACCEPT' => 18, 'HTTP_ACCEPT_LANGUAGE' => 15, 'HTTP_ACCEPT_ENCODING' => 12,
            'HTTP_SEC_FETCH_SITE' => 20, 'HTTP_SEC_FETCH_MODE' => 15, 'HTTP_SEC_FETCH_DEST' => 12,
            'HTTP_UPGRADE_INSECURE_REQUESTS' => 15, 'HTTP_SEC_CH_UA' => 18, 'HTTP_SEC_CH_UA_MOBILE' => 10,
            'HTTP_DNT' => 5, 'HTTP_CACHE_CONTROL' => 8, 'HTTP_SEC_FETCH_USER' => 12
        ];

        foreach ($essential_headers as $header => $score) {
            if (isset($_SERVER[$header])) {
                $human_score += $score;
            } else {
                $penalty_score += $score;
            }
        }

        if (isset($_SERVER['HTTP_ACCEPT'])) {
            $accept = $_SERVER['HTTP_ACCEPT'];
            if (!str_starts_with($accept, 'text/html')) $penalty_score += 25;
            
            $score_map = [
                'text/html' => 15, 'application/xhtml+xml' => 12, 'image/webp' => 10,
                'image/avif' => 8, 'image/apng' => 6, 'application/signed-exchange' => 5
            ];
            foreach ($score_map as $type => $score) {
                if (str_contains($accept, $type)) $human_score += $score;
            }
            if (str_contains($accept, 'q=')) $human_score += 10;
        }

        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
            if (!str_contains($lang, ',') && !str_contains($lang, '-')) $penalty_score += 20;
            if (preg_match('/^(en|pt|es|fr|de|ja|zh)$/i', trim($lang))) $penalty_score += 25;
            if (str_contains($lang, ',')) $human_score += 12;
            if (str_contains($lang, 'q=')) $human_score += 8;
        }

        if (isset($_SERVER['HTTP_ACCEPT_ENCODING'])) {
            $encoding = $_SERVER['HTTP_ACCEPT_ENCODING'];
            if (!str_contains($encoding, 'gzip')) $penalty_score += 20;
            if (str_contains($encoding, 'br')) $human_score += 8;
            if (str_contains($encoding, 'deflate')) $human_score += 5;
        }

        if (isset($_SERVER['HTTP_SEC_CH_UA'])) {
            $client_hints = strtolower($_SERVER['HTTP_SEC_CH_UA']);

            if (str_contains($client_hints, 'headless')) {
                $penalty_score += 100;
            }

            $ua_has_chrome = str_contains($lowerUA, 'chrome');
            $ch_has_chrome = str_contains($client_hints, 'chrome') || str_contains($client_hints, 'chromium');
            
            $ua_has_edge = str_contains($lowerUA, 'edge');
            $ch_has_edge = str_contains($client_hints, 'edge');

            if ($ua_has_chrome != $ch_has_chrome) {
                $penalty_score += 35;
            }
            
            if ($ua_has_edge != $ch_has_edge) {
                $penalty_score += 30;
            }

            if ($ua_has_chrome && $ch_has_chrome) {
                $human_score += 15;
            }
        }
    }

    private static function score_user_agent(&$human_score, &$penalty_score, $userAgent, $lowerUA, $patterns) {
        $userAgent_len = strlen($userAgent);

        if (!preg_match($patterns['browser'], $userAgent)) {
            $penalty_score += 40;
        } else {
            $human_score += 20;
            if (str_contains($lowerUA, 'mozilla') && str_contains($lowerUA, 'webkit')) {
                $human_score += 10;
            }
        }

        if (!preg_match($patterns['parentheses'], $userAgent)) {
            $penalty_score += 20;
        }

        if ($userAgent_len < 60) $penalty_score += 25;
        if ($userAgent_len > 1000) $penalty_score += 15;

        $browser_tokens = ['mozilla', 'webkit', 'chrome', 'safari', 'firefox', 'edge'];
        $required_tokens = 0;
        foreach ($browser_tokens as $token) {
            if (str_contains($lowerUA, $token)) $required_tokens++;
        }
        if ($required_tokens < 2) $penalty_score += 30;

        if (str_contains($lowerUA, 'chrome') && preg_match($patterns['chrome_version'], $userAgent, $matches)) {
            $version = (int)$matches[1];
            if ($version < 100 || $version > 130) $penalty_score += 25;
            if ($version >= 120) $human_score += 8;
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
        if (!$has_os) $penalty_score += 20;
    }
}