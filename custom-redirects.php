<?php
/**
 * Plugin Name:       Redirecionador Customizado com Rastreamento
 * Description:       Cria URLs personalizadas no formato /go/[slug] que redirecionam para um destino e rastreiam os cliques diários, com detecção de bots.
 * Version:           1.10 (Melhorias de Slug e Relatórios)
 * Author:            Seu Nome (Revisado por Especialista)
 *
 * Changelog:
 * 1.9 - NOVO: Geração automática de slug aleatório de 5 caracteres para novos links.
 *     - NOVO: Validação de unicidade para slugs para evitar conflitos.
 *     - MELHORADO: A coluna de acessos na listagem agora exibe "Hoje / Ontem / Total".
 *     - MELHORADO: O título do post não influencia mais na geração do slug.
 * 1.8 - CORRIGIDO: Lógica de redirecionamento que enviava para a página inicial incorretamente.
 *     - MELHORADO: Relatório de acessos agora exibe os últimos 30 dias, incluindo dias com 0 acessos.
 *     - MELHORADO: Coluna de acessos na listagem agora exibe "Acessos de Hoje / Total de Acessos".
 *     - OTIMIZADO: Pequenas melhorias de performance e legibilidade do código.
 */

if (!defined('ABSPATH')) exit; // Previne acesso direto

// =========================================================================
// 1. SETUP INICIAL, ATIVAÇÃO E ESTILOS
// =========================================================================

register_activation_hook(__FILE__, 'cr_activate_plugin');
function cr_activate_plugin() {
    cr_create_tracking_table();
    flush_rewrite_rules();
}

function cr_create_tracking_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'redirect_clicks';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        link_id bigint(20) NOT NULL,
        click_date date NOT NULL,
        click_count int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY link_day (link_id, click_date)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

add_action('admin_head', 'cr_admin_custom_styles');
function cr_admin_custom_styles() {
    echo '<style>.cr-copy-button { cursor: pointer; color: #0073aa; vertical-align: middle; margin-left: 5px; }.cr-copy-button:hover { color: #00a0d2; }.cr-copy-button.copied { color: #46b450; cursor: default; } .cr-suggested-slug { background: #f0f0f1; padding: 5px 10px; border-radius: 3px; font-family: monospace; display: inline-block; border: 1px solid #ddd; }</style>';
}

// =========================================================================
// 2. REGISTRO DO CUSTOM POST TYPE 'redirect_link'
// =========================================================================

add_action('init', 'cr_register_redirect_link_cpt');
function cr_register_redirect_link_cpt() {
    $labels = [
        'name'          => 'Links de Redirecionamento',
        'singular_name' => 'Link',
        'menu_name'     => 'Redirecionamentos',
        'add_new_item'  => 'Adicionar Novo Link',
        'add_new'       => 'Adicionar Novo',
        'edit_item'     => 'Editar Link',
        'view_item'     => 'Ver Link',
        'all_items'     => 'Todos os Links'
    ];
    $args = [
        'labels'          => $labels,
        'public'          => false,
        'show_ui'         => true,
        'show_in_menu'    => true,
        'menu_icon'       => 'dashicons-admin-links',
        'capability_type' => 'post',
        'hierarchical'    => false,
        'supports'        => ['title'],
        'rewrite'         => false
    ];
    register_post_type('redirect_link', $args);
}



// =========================================================================
// 3. META BOX, CAMPOS PERSONALIZADOS E LÓGICA DE SLUG
// =========================================================================

add_action('add_meta_boxes', 'cr_add_meta_boxes');
function cr_add_meta_boxes() {
    add_meta_box('cr_link_details_meta_box', 'Detalhes do Link de Redirecionamento', 'cr_render_meta_box_content', 'redirect_link', 'normal', 'high');
}

// NOVO: Função auxiliar para verificar se um slug já existe
function cr_is_slug_unique($slug, $post_id) {
    global $wpdb;
    $check_slug = $wpdb->get_var($wpdb->prepare(
        "SELECT post_name FROM $wpdb->posts WHERE post_type = 'redirect_link' AND post_name = %s AND ID != %d LIMIT 1",
        $slug, $post_id
    ));
    return $check_slug === null;
}

// NOVO: Função para gerar um slug aleatório e único
function cr_generate_unique_slug($length = 5) {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    do {
        $slug = '';
        for ($i = 0; $i < $length; $i++) {
            $slug .= $chars[rand(0, strlen($chars) - 1)];
        }
    } while (!cr_is_slug_unique($slug, 0)); // Verifica se o slug gerado já existe
    return $slug;
}
function cr_render_meta_box_content($post) {
    wp_nonce_field('cr_save_meta_box_data', 'cr_meta_box_nonce');
    
    $destination_url = get_post_meta($post->ID, '_destination_url', true);
    $link_notes      = get_post_meta($post->ID, '_link_notes', true);
    $is_inactive     = get_post_meta($post->ID, '_is_inactive', true);
    $slug            = $post->post_name;
    
    // Lógica para exibir slug sugerido ou URL gerada
    if ($post->post_status === 'auto-draft') {
        // É um novo post, então sugerimos um slug
        $suggested_slug = cr_generate_unique_slug();
        echo '<input type="hidden" name="cr_suggested_slug" value="' . esc_attr($suggested_slug) . '" />';
        
        // --- ALTERAÇÃO PRINCIPAL AQUI ---
        // Adicionamos um botão ao lado do slug sugerido.
        echo '<p><strong>Slug Sugerido:</strong> ' .
             '<span class="cr-suggested-slug">' . esc_html($suggested_slug) . '</span> ' .
             '<button type="button" id="cr_use_suggested_slug_btn" class="button button-small" style="vertical-align: middle;">Usar este</button>' .
             '</p>';
        // --- FIM DA ALTERAÇÃO ---

        echo '<p><small>Este slug será usado se o campo "Slug Personalizado" for deixado em branco.</small></p>';

    } elseif ($slug) {
        // É um post existente, mostramos a URL final
        echo '<p><strong>URL Gerada:</strong> <code>' . esc_url(home_url('/go/' . $slug . '/')) . '</code><span class="dashicons dashicons-admin-page cr-copy-button" title="Copiar URL Gerada" data-copy-text="' . esc_attr(home_url('/go/' . $slug . '/')) . '"></span></p>';
    }

    echo '<hr>';
    
    echo '<p style="background: #f0f0f1; padding: 10px; border-left: 4px solid #7e8993;"><label for="cr_is_inactive"><input type="checkbox" id="cr_is_inactive" name="cr_is_inactive" value="1" ' . checked($is_inactive, '1', false) . ' /> <strong>Inativo</strong></label><br><small>Se marcado, este link redirecionará para a página inicial do site em vez do destino.</small></p>';
    
    echo '<p><label for="cr_destination_url"><strong>URL de Destino:</strong></label><br><input type="url" id="cr_destination_url" name="cr_destination_url" value="' . esc_attr($destination_url) . '" style="width: 100%;" placeholder="https://exemplo.com/pagina-de-destino" required /></p>';

    echo '<p><label for="cr_custom_slug"><strong>Slug Personalizado (Opcional):</strong></label><br><input type="text" id="cr_custom_slug" name="cr_custom_slug" value="' . esc_attr($slug) . '" style="width: 100%;" placeholder="promocao-de-natal" /><small>Use apenas letras minúsculas, números e hífens. Se deixado em branco em um novo link, o slug sugerido acima será usado.</small></p>';
    
    echo '<p><label for="cr_link_notes"><strong>Notas (Uso interno):</strong></label><br><textarea id="cr_link_notes" name="cr_link_notes" rows="4" style="width: 100%;">' . esc_textarea($link_notes) . '</textarea></p>';
}

// ALTERADO: Lógica de salvamento para usar o novo sistema de slugs
add_action('save_post_redirect_link', 'cr_save_meta_box_data', 10, 2);
function cr_save_meta_box_data($post_id, $post) {
    if (!isset($_POST['cr_meta_box_nonce']) || !wp_verify_nonce($_POST['cr_meta_box_nonce'], 'cr_save_meta_box_data')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    if (wp_is_post_revision($post_id)) return;

    $final_slug = $post->post_name; // Começa com o slug atual

    // 1. Determina qual slug usar
    $custom_slug = isset($_POST['cr_custom_slug']) ? sanitize_title(trim($_POST['cr_custom_slug'])) : '';
    
    if (!empty($custom_slug)) {
        // O usuário digitou um slug personalizado
        $final_slug = $custom_slug;
    } elseif (empty($post->post_name) || $post->post_status === 'auto-draft') {
        // É um post novo e o slug personalizado está vazio, usa o sugerido
        $final_slug = isset($_POST['cr_suggested_slug']) ? sanitize_title($_POST['cr_suggested_slug']) : cr_generate_unique_slug();
    }

    // 2. Valida a unicidade do slug e atualiza o post
    if ($final_slug !== $post->post_name) {
        if (!cr_is_slug_unique($final_slug, $post_id)) {
            // Conflito de slug! Adiciona um aviso e reverte para o slug antigo ou um novo aleatório
            add_action('admin_notices', function() use ($final_slug) {
                echo '<div class="notice notice-error is-dismissible"><p>O slug "<strong>' . esc_html($final_slug) . '</strong>" já está em uso. Um slug aleatório foi gerado para evitar conflitos.</p></div>';
            });
            $final_slug = cr_generate_unique_slug();
        }
        
        // Remove esta ação para evitar loop infinito e atualiza o post com o slug final
        remove_action('save_post_redirect_link', 'cr_save_meta_box_data', 10);
        wp_update_post(['ID' => $post_id, 'post_name' => $final_slug]);
        add_action('save_post_redirect_link', 'cr_save_meta_box_data', 10, 2);
    }

    // 3. Salva os outros metadados
    if (isset($_POST['cr_destination_url'])) {
        update_post_meta($post_id, '_destination_url', esc_url_raw(trim($_POST['cr_destination_url'])));
    }

    if (isset($_POST['cr_link_notes'])) {
        update_post_meta($post_id, '_link_notes', sanitize_textarea_field($_POST['cr_link_notes']));
    }

    update_post_meta($post_id, '_is_inactive', isset($_POST['cr_is_inactive']) ? '1' : '0');

    flush_rewrite_rules();
}

// =========================================================================
// 4. LÓGICA DE DETECÇÃO DE BOT, REDIRECIONAMENTO E VALIDAÇÃO
// =========================================================================
// 
function cr_is_bot(): bool
{
    // === CACHE ESTÁTICO PARA PADRÕES (COMPILADOS UMA VEZ) ===
    static $patterns_cache = null;
    static $log_file = null;
    
    if ($patterns_cache === null) {
        // Pré-compila todos os padrões regex de uma vez
        $patterns_cache = [
            'wordpress' => '/^(wordpress|jetpack|wp-rocket|wp-optimize|wp-cron)\//i',
            'social' => '/\b(whatsapp|facebookexternalhit|facebookcatalog|facebot|instagram|discordbot|linkedinbot|twitterbot|pinterest|skypeuripreview|slackbot-linkexpanding|telegrambot|redditbot|vkshare|line|kakaotalk|wechat)\b/i',
            'browser' => '/\b(mozilla.*webkit|chrome\/[\d.]+|firefox\/[\d.]+|safari\/[\d.]+|edge\/[\d.]+|opera\/[\d.]+)\b/i',
            'suspicious' => '/\b(headless|automation|webdriver|test|check|scan|monitor)\b|^[a-z]+\/[\d.]+$|\b(api|sdk|client|lib)\s*[\d.]*$/i',
            'chrome_version' => '/chrome\/(\d+)/i',
            'parentheses' => '/\([^)]+\)/',
            'bot_mega' => '/\b(googlebot|bingbot|yandexbot|baiduspider|slurp|duckduckbot|applebot|seznam|nutch|petalbot|sogou|exabot|gptbot|claude-web|openai|anthropic-ai|perplexity|ccbot|bytespider|chatgpt-user|bard|gemini-pro|python-requests|python-urllib|go-http-client|node-fetch|axios|guzzle|httpclient|postman|insomnia|httpie|selenium|puppeteer|playwright|phantomjs|headlesschrome|webdriver|chromedriver|cypress|scrapy|ahrefs|semrush|mj12bot|screaming frog|moz\.com|pingdom|uptimerobot|statuscake)\b/i'
        ];
        
        $log_file = WP_CONTENT_DIR . '/cr-bot-detection.log';
    }
    
    // === SISTEMA DE LOG CORRIGIDO E SEMPRE ATIVO ===
    
    // Dados base para log (sempre coletados)
    $log_data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'empty', 0, 120),
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'referer' => isset($_SERVER['HTTP_REFERER']) ? 'yes' : 'no'
    ];
    
    $write_log = function($result, $reason, $score = null) use ($log_file, $log_data) {
        // Garantir que o diretório existe e tem permissões
        $dir = dirname($log_file);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        
        // Verificar se consegue escrever
        if (!is_writable($dir)) {
            error_log("CR Bot Detection: Cannot write to {$dir}");
            return;
        }
        
        $log_entry = "{$log_data['timestamp']} | " . 
                    ($result ? 'BOT' : 'HUMAN') . " | {$reason}" .
                    ($score !== null ? " | Score: {$score}" : '') . 
                    " | IP: {$log_data['ip']} | Ref: {$log_data['referer']} | UA: {$log_data['ua']}\n";
        
        // Tentar escrever com fallback
        $written = @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        if ($written === false) {
            // Fallback para error_log se falhar
            error_log("CR Bot Detection: {$log_entry}");
        }
    };

    // === CAMADA 0: VERIFICAÇÕES MAIS RÁPIDAS (EARLY RETURNS) ===
    
    if ((defined('DOING_CRON') && DOING_CRON) || 
        php_sapi_name() === 'cli' || 
        (defined('WP_CLI') && WP_CLI)) {
        $write_log(true, 'System context');
        return true;
    }
    
    // AJAX verification (optimized)
    if (defined('DOING_AJAX') && DOING_AJAX) {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (!$referer || !str_contains($referer, $host)) {
            $write_log(true, 'AJAX no referer');
            return true;
        }
    }

    // === CAMADA 1: HEADERS DE AUTOMAÇÃO (HASH LOOKUP) ===
    
    // Usando array_intersect_key que é O(1) average case
    static $automation_headers = [
        'HTTP_X_AUTOMATION' => 1, 'HTTP_X_SELENIUM' => 1, 'HTTP_X_WEBDRIVER' => 1,
        'HTTP_WEBDRIVER' => 1, 'HTTP_X_PUPPETEER' => 1, 'HTTP_X_PLAYWRIGHT' => 1
    ];
    
    if (array_intersect_key($_SERVER, $automation_headers)) {
        $write_log(true, 'Automation header');
        return true;
    }

    // === CAMADA 2: USER-AGENT BÁSICO ===
    
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $userAgent_len = strlen($userAgent);
    
    if ($userAgent_len < 15 || $userAgent_len > 2000) {
        $write_log(true, "Invalid UA length: {$userAgent_len}");
        return true;
    }
    
    // Converte para lowercase uma vez só
    $lowerUA = strtolower($userAgent);

    // === CAMADA 3: WHITELIST (REGEX PRÉ-COMPILADAS) ===
    
    // WordPress tools (early whitelist)
    if (preg_match($patterns_cache['wordpress'], $userAgent)) {
        $write_log(false, 'WordPress tool');
        return false;
    }
    
    // Social media bots (uma regex para todos)
    if (preg_match($patterns_cache['social'], $lowerUA)) {
        $write_log(false, 'Social bot');
        return false;
    }

    // === CAMADA 4: BLACKLIST MEGA-PATTERN ===
    
    // Uma única regex para todos os bots conhecidos
    if (preg_match($patterns_cache['bot_mega'], $lowerUA)) {
        $write_log(true, 'Known bot');
        return true;
    }

    // === CAMADA 5: MÉTODO HTTP (QUICK CHECK) ===
    
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        $write_log(true, 'Non-GET method');
        return true;
    }

    // === CAMADA 6: VERIFICAÇÕES RIGOROSAS DE HEADERS ===
    
    // Headers OBRIGATÓRIOS para browsers modernos
    $required_headers = ['HTTP_ACCEPT', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_ACCEPT_ENCODING'];
    foreach ($required_headers as $header) {
        if (!isset($_SERVER[$header])) {
            $write_log(true, "Missing required header: {$header}");
            return true;
        }
    }
    
    // Headers suspeitos que indicam automação
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
    
    // Lookup table para headers essenciais (O(1))
    static $essential_headers = [
        'HTTP_ACCEPT' => 18,                    // Aumentado
        'HTTP_ACCEPT_LANGUAGE' => 15,           // Aumentado
        'HTTP_ACCEPT_ENCODING' => 12,           // Aumentado
        'HTTP_SEC_FETCH_SITE' => 20,            // Muito importante
        'HTTP_SEC_FETCH_MODE' => 15,            // Aumentado
        'HTTP_SEC_FETCH_DEST' => 12,            // Aumentado
        'HTTP_UPGRADE_INSECURE_REQUESTS' => 15, // Aumentado
        'HTTP_SEC_CH_UA' => 18,                 // Cliente moderno
        'HTTP_SEC_CH_UA_MOBILE' => 10,
        'HTTP_DNT' => 5,
        'HTTP_CACHE_CONTROL' => 8,              // Novo
        'HTTP_SEC_FETCH_USER' => 12             // Novo
    ];
    
    $human_score = 0;
    $penalty_score = 0;
    
    // Single pass através dos headers importantes
    foreach ($essential_headers as $header => $score) {
        if (isset($_SERVER[$header])) {
            $human_score += $score;
        } else {
            $penalty_score += $score; // Penalidade total agora
        }
    }

    // === CAMADA 7: ANÁLISE RIGOROSA DE ACCEPT ===
    
    if (isset($_SERVER['HTTP_ACCEPT'])) {
        $accept = $_SERVER['HTTP_ACCEPT'];
        
        // REJEITAR accepts muito suspeitos imediatamente
        $forbidden_accepts = ['*/*', 'application/json', 'text/plain', 'text/*', 'image/*'];
        if (in_array(trim($accept), $forbidden_accepts, true)) {
            $write_log(true, "Forbidden accept: {$accept}");
            return true;
        }
        
        // Accept deve ter complexidade mínima de browser real
        if (substr_count($accept, ',') < 2) {
            $write_log(true, "Too simple accept header");
            return true;
        }
        
        // Browsers reais sempre incluem text/html em primeiro
        if (!str_starts_with($accept, 'text/html')) {
            $penalty_score += 25;
        }
        
        // Pontuação para accepts complexos
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
        
        // Bonus para qualifiers (q=0.9, etc)
        if (str_contains($accept, 'q=')) {
            $human_score += 10;
        }
    }
    
    // Accept-Language mais rigoroso
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
        
        // Deve ter pelo menos uma vírgula ou hífen
        if (!str_contains($lang, ',') && !str_contains($lang, '-')) {
            $penalty_score += 20;
        }
        
        // Bonus para complexity
        if (str_contains($lang, ',')) $human_score += 12;
        if (str_contains($lang, 'q=')) $human_score += 8;
        
        // Penalidade severa para idiomas muito básicos
        if (preg_match('/^(en|pt|es|fr|de|ja|zh)$/i', trim($lang))) {
            $penalty_score += 25;
        }
    }
    
    // Accept-Encoding obrigatório para browsers modernos
    if (isset($_SERVER['HTTP_ACCEPT_ENCODING'])) {
        $encoding = $_SERVER['HTTP_ACCEPT_ENCODING'];
        
        // Browsers modernos sempre suportam gzip
        if (!str_contains($encoding, 'gzip')) {
            $penalty_score += 20;
        }
        
        // Bonus para encodings modernos
        if (str_contains($encoding, 'br')) $human_score += 8; // Brotli
        if (str_contains($encoding, 'deflate')) $human_score += 5;
    }

    // === CAMADA 8: ANÁLISE RIGOROSA DE USER-AGENT ===
    
    // Verificação de browser signature mais rigorosa
    $has_browser_signature = (bool)preg_match($patterns_cache['browser'], $userAgent);
    if ($has_browser_signature) {
        $human_score += 20; // Aumentado
        
        // Verificações adicionais para browsers legítimos
        if (str_contains($lowerUA, 'mozilla') && str_contains($lowerUA, 'webkit')) {
            $human_score += 10;
        }
    } else {
        $penalty_score += 40; // Aumentado significativamente
    }
    
    // Estrutura obrigatória de User-Agent
    if (!preg_match($patterns_cache['parentheses'], $userAgent)) {
        $write_log(true, 'No parentheses in UA');
        return true; // Mais rigoroso - rejeita imediatamente
    }
    
    // Verificações de comprimento mais rigorosas
    if ($userAgent_len < 60) { // Aumentado de 50
        $penalty_score += 25; // Aumentado
    }
    
    if ($userAgent_len > 1000) { // User-Agents muito longos são suspeitos
        $penalty_score += 15;
    }
    
    // Verificação de tokens obrigatórios para browsers modernos
    $required_tokens = 0;
    $browser_tokens = ['mozilla', 'webkit', 'chrome', 'safari', 'firefox', 'edge'];
    
    foreach ($browser_tokens as $token) {
        if (str_contains($lowerUA, $token)) {
            $required_tokens++;
        }
    }
    
    if ($required_tokens < 2) { // Deve ter pelo menos 2 tokens de browser
        $penalty_score += 30;
    }
    
    // Chrome version check mais rigoroso
    if (str_contains($lowerUA, 'chrome') && preg_match($patterns_cache['chrome_version'], $userAgent, $matches)) {
        $version = (int)$matches[1];
        if ($version < 100 || $version > 130) { // Range mais restrito
            $penalty_score += 25; // Aumentado
        }
        
        // Bonus para versões recentes
        if ($version >= 120) {
            $human_score += 8;
        }
    }
    
    // Verificação de sistema operacional
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

    // === CAMADA 9: PADRÕES SUSPEITOS (SINGLE REGEX) ===
    
    if (preg_match($patterns_cache['suspicious'], $userAgent)) {
        $write_log(true, 'Suspicious pattern');
        return true;
    }

    // === DECISÃO FINAL MAIS RIGOROSA ===
    
    $final_score = $human_score - $penalty_score;
    
    // Thresholds mais altos
    $threshold = $has_browser_signature ? 60 : 90; // Aumentado significativamente
    
    // Log detalhado da pontuação
    $write_log(null, "Score calculation: Human={$human_score}, Penalty={$penalty_score}, Final={$final_score}, Threshold={$threshold}", $final_score);
    
    if ($final_score < $threshold) {
        $write_log(true, 'Failed score threshold', $final_score);
        return true;
    }
    
    // Verificação adicional: Se tem assinatura de browser mas score ainda baixo
    if ($has_browser_signature && $final_score < 80) {
        $write_log(true, 'Low score despite browser signature', $final_score);
        return true;
    }
    
    $write_log(false, 'Verified human', $final_score);
    return false;
}
// ... (O restante do código de redirecionamento e detecção de bot permanece o mesmo) ...

// =========================================================================
// 4. LÓGICA DE DETECÇÃO DE BOT, REDIRECIONAMENTO E VALIDAÇÃO
// =========================================================================
// 

/**
 * Verifica se a requisição atual é provavelmente de um bot, usando uma abordagem de múltiplas camadas
 * de verificação para alta performance e precisão aprimorada sem dependências externas.
 *
*/

/*
function cr_is_bot(): bool
{
    // --- CAMADA 0: WHITELIST (Regra absoluta e mais rápida) ---
    if (isset($_SERVER['HTTP_USER_AGENT']) && str_starts_with(strtolower($_SERVER['HTTP_USER_AGENT']), 'wordpress/')) {
        return false;
    }

    // --- CAMADA 1: VERIFICAÇÕES LEVES (HEADERS) ---
    // Calculamos uma pontuação inicial baseada apenas nos cabeçalhos, que são verificações muito rápidas.
    $score = 0;
    if (!isset($_SERVER['HTTP_USER_AGENT']) || $_SERVER['HTTP_USER_AGENT'] === '') { $score += 60; }
    if (!isset($_SERVER['HTTP_ACCEPT'])) { $score += 60; }
    if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) { $score += 25; }

    // --- OTIMIZAÇÃO DE PERFORMANCE: SAÍDA ANTECIPADA ---
    // Se a pontuação já é suficiente para classificar como bot, retornamos 'true' imediatamente.
    // Isso evita a execução da verificação de regex (preg_match), que é a operação mais lenta da função.
    if ($score >= 60) {
        return true;
    }

    // --- CAMADA 2: VERIFICAÇÃO PESADA (BLACKLIST DE BOTS) ---
    // Este código só será executado se o acesso passar pela primeira camada (score < 60).
    // Se o User-Agent corresponder a um bot conhecido, retornamos 'true'.
    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '';
    
    // Não precisamos nem verificar se o userAgent é vazio, pois se fosse, o score já seria 60.
    // Mas é uma boa prática para evitar erros caso a lógica mude.
    if ($userAgent === '') {
        return false; // Já teria sido pego acima, mas é uma segurança extra.
    }
    
 static $bot_signatures = [
        // === GENÉRICOS (JÁ PRESENTES NO SEU ORIGINAL) ===
        'bot', 'crawler', 'spider', 'robot', 'scraper',

        // === CRAWLERS DE BUSCA E INDEXAÇÃO ===
        'adsbot-google', 'applebot', 'baiduspider', 'bingbot', 'brave search', 'bytespider', 
        'ccbot', 'coccoc', 'dotbot', 'duckduckbot', 'google-extended', 'googlebot', 
        'ia_archiver', 'msnbot', 'petalbot', 'qwantify', 'slurp', 'sogou', 'yandexbot', 
        'yeti', 'seznam.cz', 'nutch', 'yahoo! slurp', 'archive.org_bot', 'heritrix', 'wayback',

        // === BOTS DE REDES SOCIAIS E MENSAGEIROS (PRÉ-VISUALIZAÇÃO) ===
        'discordbot', 'facebookexternalhit', 'linkedinbot', 'pinterestbot', 'redditbot', 
        'skypebot', 'slackbot', 'telegrambot', 'twitterbot', 'vkshare', 'whatsapp',

        // === FERRAMENTAS DE SEO E ANÁLISE ===
        'ahrefs', 'ahrefsbot', 'deepcrawl', 'linkdex', 'majestic-12', 'mj12bot', 'moz.com', 
        'rogerbot', 'semrush', 'semrushbot', 'serpstatbot', 'sitebulb', 'sistrix', 
        'screaming frog', 'seznambot', 'ubersuggest', 'linkassistant',

        // === BOTS DE IA / LLMS ===
        'anthropic-ai', 'bard', 'claude-web', 'copilot', 'gptbot', 'openai', 'perplexity', 
        'perplexitybot',

        // === FERRAMENTAS DE LINHA DE COMANDO E AUTOMAÇÃO ===
        'curl', 'go-http-client', 'headlesschrome', 'node-fetch', 'phantomjs', 'playwright', 
        'postman', 'puppeteer', 'python-requests', 'scrapy', 'selenium', 'webdriver', 'wget',

        // === SERVIÇOS DE MONITORAMENTO E OUTROS ===
        'bitlybot', 'embedly', 'exabot', 'feedly', 'pingdom', 'turnitinbot', 'uptimerobot', 
        'w3c_validator'
    ];

    static $pattern = null;
    if ($pattern === null) {
        $escaped_signatures = array_map(function($sig) { return preg_quote($sig, '/'); }, $bot_signatures);
        $pattern = '/' . implode('|', $escaped_signatures) . '/i';
    }

    if (preg_match($pattern, $userAgent)) {
        return true;
    }

    // --- DECISÃO FINAL ---
    // Se a requisição passou por todas as verificações, não é um bot.
    return false;
}

*/

function cr_track_click($link_id) {
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

add_action('init', 'cr_add_rewrite_rules');
function cr_add_rewrite_rules() {
    add_rewrite_rule('^go/([^/]+)/?$', 'index.php?redirect_slug=$matches[1]', 'top');
}

add_filter('query_vars', 'cr_add_query_vars');
function cr_add_query_vars($vars) {
    $vars[] = 'redirect_slug';
    return $vars;
}

add_action('parse_request', 'cr_process_redirect_performant', 999);


/**
 * Processa um redirecionamento de forma performática, sem usar cache de objetos.
 *
 * Otimizações aplicadas:
 * 1. Execução antecipada no hook 'init' para evitar a carga completa do WordPress.
 * 2. Análise direta da URL ($_SERVER['REQUEST_URI']) em vez de esperar pelos query_vars.
 * 3. Consulta ÚNICA ao banco de dados usando LEFT JOIN para buscar todos os dados necessários de uma vez.
 */

/*
function cr_process_redirect_performant() {
    // Defina o prefixo que identifica suas URLs de redirecionamento.
    // Exemplo: https://seusite.com.br/go/link-desejado
    // O prefixo é '/go/'. Certifique-se de que ele termina e começa com uma barra.
    $redirect_prefix = '/go/';

    // Verificação inicial ultrarrápida: se a URL não começa com o prefixo, saia imediatamente.
    if (strpos($_SERVER['REQUEST_URI'], $redirect_prefix) !== 0) {
        return;
    }

    // Extrai o slug da URL.
    // strtok remove qualquer query string (ex: ?utm_source=...).
    // basename pega a última parte do caminho.
    $request_path = strtok($_SERVER['REQUEST_URI'], '?');
    $slug = basename($request_path);

    // Se o slug estiver vazio por algum motivo (ex: URL era apenas '/go/'), não faz nada.
    if (empty($slug)) {
        return;
    }
    
    global $wpdb;

    // CONSULTA OTIMIZADA: Uma única chamada ao DB para buscar o post e seus metadados.
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

    // Se a consulta não retornou um link, o WordPress continuará e provavelmente mostrará uma página 404.
    if (!$link_data) {
        return;
    }

    // Lógica para determinar a URL final.
    $final_redirect_url = home_url('/'); // URL de fallback caso o link seja inválido.
    $track_this_click = false;

    if (
        $link_data->is_inactive !== '1' && 
        !empty($link_data->destination_url) && 
        filter_var($link_data->destination_url, FILTER_VALIDATE_URL)
    ) {
        $final_redirect_url = $link_data->destination_url;
        $track_this_click = true;
    }
    
    // Rastreia o clique se for um link válido e não for um bot.
    if ($track_this_click && !cr_is_bot()) { // Use sua função de detecção de bot otimizada.
        cr_track_click($link_data->ID);
    }
    
	   // =====================================================================
    // >> MELHORIA CRÍTICA AQUI <<
    // Envia cabeçalhos para instruir navegadores e proxies a não fazer cache desta resposta.
    // Isso força cada acesso a executar este script.
// =====================================================================
    // >> VERSÃO FINAL E REFORÇADA DOS HEADERS <<
    // O 'true' no final força a substituição de qualquer header Cache-Control anterior.
    // Adicionamos s-maxage=0 para anular explicitamente a instrução conflitante.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, s-maxage=0', true);
    header('Pragma: no-cache', true); // Também pode forçar a substituição por segurança
    header('Expires: 0', true);
    // =====================================================================

    // Executa o redirecionamento e interrompe o script.
    // Usar status 307 (Temporary Redirect) é uma boa prática para links de rastreamento.
    wp_redirect($final_redirect_url, 307);
    exit;
}
*/
/**
 * Processa um redirecionamento com máxima performance.
 *
 * Otimizações aplicadas:
 * 1. Execução antecipada no hook 'init' com saída imediata.
 * 2. Cache de Objetos Persistente: Evita a consulta ao banco de dados em acessos repetidos ao mesmo link.
 * 3. Cache Negativo: Armazena em cache slugs que não existem para evitar consultas inúteis.
 * 4. Validação de URL ultrarrápida com `strpos` em vez do lento `filter_var`.
 * 5. Redirecionamento direto com `header('Location: ...')` para bypassar o overhead do WordPress.
 * 6. Rastreamento de cliques assíncrono para não bloquear o redirecionamento do usuário.
 */
function cr_process_redirect_performant() {
    // 1. Verificação inicial ultrarrápida
    $redirect_prefix = '/go/';
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($request_uri, $redirect_prefix) !== 0) {
        return;
    }

    // 2. Extração e validação do slug
    $request_path = strtok($request_uri, '?');
    $slug = basename($request_path);

    if (empty($slug) || strlen($slug) > 255) { // Limite de post_name
        return;
    }

    // 3. OTIMIZAÇÃO PRINCIPAL: Cache de Objetos Persistente
    $cache_group = 'cr_redirects';
    $cache_key = 'slug_' . $slug;
    $link_data = wp_cache_get($cache_key, $cache_group);

    // Se não estiver no cache (`false`), busca no banco
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

        // Armazena no cache para a próxima vez.
        // Se $link_data for nulo (não encontrou), armazena `null` para fazer "cache negativo".
        // O cache expira em 12 horas, um bom equilíbrio.
        wp_cache_set($cache_key, $link_data, $cache_group, 12 * HOUR_IN_SECONDS);
    }
    
    // Se, após o cache e o DB, não houver dados, o link não existe.
    if (!$link_data) {
        // O WordPress continuará e mostrará uma página 404.
        return;
    }

    // 4. Lógica de redirecionamento
    $destination_url = $link_data->destination_url ?? '';
    $is_inactive = ($link_data->is_inactive ?? '') === '1';
    
    // Validação rápida de URL
    $is_valid_url = !empty($destination_url) && 
                   (strpos($destination_url, 'http://') === 0 || strpos($destination_url, 'https://') === 0) &&
                   strlen($destination_url) < 2048; // Limite padrão

    if ($is_inactive || !$is_valid_url) {
        $final_redirect_url = home_url('/');
        $track_this_click = false;
    } else {
        $final_redirect_url = $destination_url;
        $track_this_click = true;
    }

    // 5. Envia headers e redireciona IMEDIATAMENTE
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        header('Location: ' . $final_redirect_url, true, 307);
    }

    // 6. O Rastreamento Assíncrono acontece DEPOIS que o usuário já foi redirecionado
    if ($track_this_click && !(defined('DOING_CRON') && DOING_CRON)) {
        // Uma função de detecção de bot otimizada deve ser usada aqui.
        // Ex: if (!cr_is_bot()) { ... }
        cr_track_click_async($link_data->ID);
    }

    // Finaliza a execução para garantir que nada mais seja processado.
    exit;
}

/**
 * Exemplo de como a função de rastreamento assíncrono poderia ser implementada.
 * A melhor abordagem depende da arquitetura do seu servidor.
 */
function cr_track_click_async($post_id) {
    // Garante que o usuário não precise esperar por isso.
    // Ignora a conexão do usuário, fecha e continua o processamento no servidor.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
    
    // Agora, execute a lógica de rastreamento que escreve no banco de dados.
    // Esta parte roda "em segundo plano".
    cr_track_click($post_id); // Sua função original de escrita no DB
}

/**
 * Nota: Para que o cache de objetos (wp_cache_get/set) seja persistente, 
 * seu site precisa ter um sistema de cache de objetos configurado,
 * como Redis ou Memcached. Se não tiver, o WordPress usará um cache não persistente
 * que dura apenas para a requisição, tornando o benefício menor (mas ainda válido
 * para evitar múltiplas chamadas `get_row` na mesma requisição, se o código for refatorado).
 */

// =========================================================================
// 5. SCRIPTS DO ADMIN
// =========================================================================

add_action('admin_enqueue_scripts', 'cr_enqueue_admin_scripts');
function cr_enqueue_admin_scripts($hook_suffix) {
    // Pega a tela atual para verificar o tipo de post.
    $screen = get_current_screen();
	
	    $allowed_hooks = [
        'edit.php',     // A página de listagem de todos os posts.
        'post.php',     // A página de edição de um post.
        'post-new.php'  // A página de criação de um novo post.
    ];

    if (   in_array( $hook_suffix, $allowed_hooks)  && 'redirect_link' === $screen->post_type ) {
        
        wp_enqueue_script(
            'cr-admin-logic', // 1. Um nome único (handle) para o seu script.
            plugin_dir_url(__FILE__) . 'js/admin-scripts.js', // 2. O caminho completo para o arquivo JS.
            array('jquery'), // 3. Dependências (nosso script precisa do jQuery).
            '1.0.0', // 4. Versão do seu script (bom para controle de cache).
            true // 5. Carregar o script no rodapé da página (melhor para performance).
        );
    }
}

function cr_add_inline_copy_script() {
    ?>
    <script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function() {
        document.body.addEventListener('click', function(e) {
            if (e.target.classList.contains('cr-copy-button')) {
                const button = e.target;
                const textToCopy = button.getAttribute('data-copy-text');
                navigator.clipboard.writeText(textToCopy).then(function() {
                    const originalTitle = button.getAttribute('title');
                    button.setAttribute('title', 'Copiado!');
                    button.classList.add('copied');
                    setTimeout(function() {
                        button.setAttribute('title', originalTitle);
                        button.classList.remove('copied');
                    }, 2000);
                }).catch(function(err) {
                    console.error('Não foi possível copiar o texto: ', err);
                });
            }
        });
    });
    </script>
    <?php
}

// =========================================================================
// 6. TELA DE LISTAGEM DE LINKS (COLUNAS, FILTRO, ORDENAÇÃO)
// =========================================================================
/**
 * 1. Define os cabeçalhos das colunas na listagem de links.
 */
add_filter('manage_redirect_link_posts_columns', 'cr_set_custom_edit_redirect_link_columns');
function cr_set_custom_edit_redirect_link_columns($columns) {
    unset($columns['date']); 
    return [
        'cb'              => $columns['cb'],
        'title'           => 'Nome do Link',
        'redirect_url'    => 'URL Gerada',
        'destination_url' => 'URL de Destino',
        'link_notes'      => 'Notas Internas', // NOVO: Adiciona a coluna de notas.
        'clicks'          => 'Acessos (Hoje / Ontem / Total)',
        'status'          => 'Status',
        'publish_date'    => 'Data',
        'actions'         => 'Ações',
    ];
}

/**
 * 2. Preenche o conteúdo de cada coluna customizada.
 */
add_action('manage_redirect_link_posts_custom_column', 'cr_custom_redirect_link_column', 10, 2);
function cr_custom_redirect_link_column($column, $post_id) {
    switch ($column) {
        case 'redirect_url':
            $slug = get_post($post_id)->post_name;
            if ($slug && strpos($slug, 'auto-draft') === false) {
                $full_url = home_url('/go/' . $slug . '/');
                $relative_url = '/go/' . $slug . '/';
                echo '<a href="' . esc_url($full_url) . '" target="_blank" rel="noopener"><code>' . esc_html($relative_url) . '</code></a>
                      <span class="dashicons dashicons-admin-page cr-copy-button" title="Copiar URL Gerada" data-copy-text="' . esc_attr($full_url) . '"></span>';
            } else {
                echo '<em>Pendente...</em>';
            }
            break;

        case 'destination_url':
            // A lógica de cortar o texto foi passada para o CSS.
            $url = get_post_meta($post_id, '_destination_url', true);
            if ($url) {
                echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener" title="' . esc_attr($url) . '">' . esc_html($url) . '</a>
                      <span class="dashicons dashicons-admin-page cr-copy-button" title="Copiar URL de Destino" data-copy-text="' . esc_attr($url) . '"></span>';
            } else {
                echo 'N/A';
            }
            break;
            
        case 'link_notes':
            $notes = get_post_meta($post_id, '_link_notes', true);
            if ($notes) {
                echo esc_html($notes);
            } else {
                echo '<em>—</em>';
            }
            break;

        case 'clicks':
            global $wpdb;
            $table_name = $wpdb->prefix . 'redirect_clicks';
            $today = current_time('Y-m-d');
            $yesterday = date('Y-m-d', strtotime('-1 day', strtotime($today)));

            $counts = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    SUM(click_count) AS total_clicks,
                    SUM(CASE WHEN click_date = %s THEN click_count ELSE 0 END) AS today_clicks,
                    SUM(CASE WHEN click_date = %s THEN click_count ELSE 0 END) AS yesterday_clicks
                FROM {$table_name}
                WHERE link_id = %d",
                $today,
                $yesterday,
                $post_id
            ));

            $today_count = $counts && $counts->today_clicks ? $counts->today_clicks : 0;
            $yesterday_count = $counts && $counts->yesterday_clicks ? $counts->yesterday_clicks : 0;
            $total_count = $counts && $counts->total_clicks ? $counts->total_clicks : 0;
            
            echo '<strong>' . number_format_i18n($today_count) . '</strong> / ' . number_format_i18n($yesterday_count) . ' / ' . number_format_i18n($total_count);
            break;

        case 'status':
            $is_inactive = get_post_meta($post_id, '_is_inactive', true);
            echo ($is_inactive === '1') 
                ? '<span style="color: #d63638; font-weight: bold;">● Inativo</span>'
                : '<span style="color: #2271b1; font-weight: bold;">● Ativo</span>';
            break;

        case 'publish_date':
            echo get_the_date('d/m/Y', $post_id);
            break;

        case 'actions':
            $report_url = admin_url('edit.php?post_type=redirect_link&page=cr_click_reports&link_id=' . $post_id);
            echo '<a href="' . esc_url($report_url) . '" class="button button-secondary">Relatório</a>';
            break;
    }
}

/**
 * 3. Adiciona o CSS para a coluna 'URL de Destino' no cabeçalho do admin.
 */
add_action('admin_head', 'cr_add_admin_list_table_styles');
function cr_add_admin_list_table_styles() {
    $screen = get_current_screen();

    if ($screen && 'edit-redirect_link' == $screen->id) {
        echo '<style>
            /* Alvo específico para o link dentro da coluna "URL de Destino" */
            .column-destination_url a {
                display: -webkit-box;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 3; /* Define o número máximo de linhas */
                overflow: hidden;
                text-overflow: ellipsis;
                word-break: break-all; /* Garante que URLs longas sem espaços quebrem a linha */
            }
        </style>';
    }
}

// ... (O restante do código de filtros, ordenação e relatórios permanece o mesmo) ...

add_action('restrict_manage_posts', 'cr_add_status_filter');
function cr_add_status_filter($post_type) {
    if ('redirect_link' !== $post_type) return;
    $current_status = isset($_GET['redirect_status']) ? sanitize_text_field($_GET['redirect_status']) : '';
    ?>
    <select name="redirect_status">
        <option value="">Todos os status</option>
        <option value="active" <?php selected($current_status, 'active'); ?>>Ativos</option>
        <option value="inactive" <?php selected($current_status, 'inactive'); ?>>Inativos</option>
    </select>
    <?php
}

add_filter('manage_edit-redirect_link_sortable_columns', 'cr_make_columns_sortable');
function cr_make_columns_sortable($columns) {
    $columns['status'] = 'status';
    $columns['clicks'] = 'clicks';
    return $columns;
}

add_action('pre_get_posts', 'cr_modify_admin_query');
function cr_modify_admin_query($query) {
    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'redirect_link') {
        return;
    }

    if ($query->get('orderby') === 'status') {
        $query->set('meta_key', '_is_inactive');
        $query->set('orderby', 'meta_value');
    }

    if ($query->get('orderby') === 'clicks') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'redirect_clicks';

        $post_ids = $wpdb->get_col("
            SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN (
                SELECT link_id, SUM(click_count) as total_clicks
                FROM {$table_name}
                GROUP BY link_id
            ) c ON p.ID = c.link_id
            WHERE p.post_type = 'redirect_link' AND p.post_status = 'publish'
            ORDER BY c.total_clicks " . ($query->get('order') ?: 'DESC')
        );

        if (!empty($post_ids)) {
            $query->set('post__in', $post_ids);
            $query->set('orderby', 'post__in');
        } else {
             $query->set('post__in', [0]);
        }
    }

    if (isset($_GET['redirect_status']) && !empty($_GET['redirect_status'])) {
        $status = sanitize_text_field($_GET['redirect_status']);
        $meta_query = $query->get('meta_query') ?: [];
        if ($status === 'active') {
            $meta_query['relation'] = 'OR';
            $meta_query[] = ['key' => '_is_inactive', 'compare' => 'NOT EXISTS'];
            $meta_query[] = ['key' => '_is_inactive', 'value' => '0', 'compare' => '='];
        } elseif ($status === 'inactive') {
            $meta_query[] = ['key' => '_is_inactive', 'value' => '1', 'compare' => '='];
        }
        $query->set('meta_query', $meta_query);
    }
}

// =========================================================================
// 7. PÁGINA DE RELATÓRIOS INDIVIDUAIS
// =========================================================================

add_action('admin_menu', 'cr_add_reports_page');
function cr_add_reports_page() {
    add_submenu_page(
        null,
        'Relatórios de Cliques',
        'Relatórios de Cliques',
        'manage_options',
        'cr_click_reports',
        'cr_render_reports_page'
    );
}

function cr_render_reports_page() {
    if (!isset($_GET['link_id']) || !absint($_GET['link_id'])) {
        echo '<div class="wrap"><h1>Relatórios</h1><p>Para visualizar um relatório, vá para a <a href="' . admin_url('edit.php?post_type=redirect_link') . '">lista de links</a> e clique no botão "Relatório" do link desejado.</p></div>';
        return;
    }

    $link_id = absint($_GET['link_id']);
    $link = get_post($link_id);

    if (!$link || $link->post_type !== 'redirect_link') {
        wp_die('O link especificado não foi encontrado.');
    }

    global $wpdb;
    $clicks_table = $wpdb->prefix . 'redirect_clicks';

    $results = $wpdb->get_results(
        $wpdb->prepare("SELECT click_date, click_count FROM {$clicks_table} WHERE link_id = %d", $link_id),
        OBJECT_K
    );
    
    $total_clicks = 0;
    if ($results) {
        foreach ($results as $row) {
            $total_clicks += $row->click_count;
        }
    }
    
    $date_range = [];
    try {
        $start_date = new DateTime($link->post_date);
        $end_date = (new DateTime('now', new DateTimeZone('UTC')))->modify('+1 day');
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start_date, $interval, $end_date);
        $date_range = array_reverse(iterator_to_array($period));
    } catch (Exception $e) {
        // Silencioso
    }
    
    ?>
    <div class="wrap">
        <h1>Relatório de Acessos para: <?php echo esc_html($link->post_title); ?></h1>
        <p><a href="<?php echo admin_url('edit.php?post_type=redirect_link'); ?>">← Voltar para todos os links</a></p>
        <p><strong>Link gerado:</strong> <code><?php echo esc_url(home_url('/go/' . $link->post_name . '/')); ?></code></p>
        <p><strong>Total de acessos (humanos):</strong> <?php echo number_format_i18n($total_clicks); ?></p>
        
        <p>Exibindo relatório completo desde a criação do link (<?php echo esc_html(date_i18n(get_option('date_format'), strtotime($link->post_date))); ?>).</p>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" style="width: 200px;">Data</th>
                    <th scope="col">Acessos no Dia</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($date_range)) : ?>
                    <?php foreach ($date_range as $date) : 
                        $date_key = $date->format('Y-m-d');
                        $count = isset($results[$date_key]) ? $results[$date_key]->click_count : 0;
                    ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n(get_option('date_format'), $date->getTimestamp())); ?></td>
                            <td><?php echo number_format_i18n($count); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="2">Nenhum acesso registrado para este link ainda.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * PARTE 1: Adiciona o link "Zerar Acessos" às ações da linha na listagem de posts.
 * 
 * Usamos o filtro 'post_row_actions' que é a maneira correta do WordPress para isso.
 */
add_filter('post_row_actions', 'cr_add_reset_clicks_link', 10, 2);
function cr_add_reset_clicks_link($actions, $post) {
    // Garante que o link só apareça no nosso Custom Post Type 'redirect_link'.
    if ($post->post_type === 'redirect_link') {
        
        // Cria um nonce para segurança. Isso previne execuções acidentais ou maliciosas.
        // O nome do nonce é único para cada post.
        $nonce = wp_create_nonce('cr_reset_clicks_nonce_' . $post->ID);

        // Monta a URL para a ação. Passamos a ação, o ID do link e o nonce.
        $reset_link = admin_url('edit.php?post_type=redirect_link&action=cr_reset_clicks&link_id=' . $post->ID . '&_wpnonce=' . $nonce);

        // Adiciona um alerta de confirmação em JavaScript, já que esta é uma ação destrutiva.
        $confirm_message = 'Tem certeza que deseja zerar TODOS os acessos deste link? Esta ação não pode ser desfeita.';
        
        // Cria o HTML do link, com a cor vermelha para indicar uma ação de "perigo".
        $actions['reset_clicks'] = '<a style="color: #d63638;" href="' . esc_url($reset_link) . '" onclick="return confirm(\'' . esc_js($confirm_message) . '\');">Zerar Acessos</a>';
    }
    
    return $actions;
}

/**
 * PARTE 2: Processa a ação de zerar os cliques.
 * 
 * Usamos o hook 'admin_init' para verificar se a nossa ação foi chamada pela URL.
 */
add_action('admin_init', 'cr_handle_reset_clicks_action');
function cr_handle_reset_clicks_action() {
    // Verifica se a ação e o ID do link estão presentes na URL.
    if (isset($_GET['action']) && $_GET['action'] === 'cr_reset_clicks' && isset($_GET['link_id'])) {

        $post_id = intval($_GET['link_id']);

        // 1. VERIFICAÇÃO DE SEGURANÇA: Checa o nonce.
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'cr_reset_clicks_nonce_' . $post_id)) {
            wp_die('A verificação de segurança falhou. Por favor, tente novamente.');
        }

        // 2. VERIFICAÇÃO DE PERMISSÃO: Checa se o usuário atual pode editar este post.
        if (!current_user_can('edit_post', $post_id)) {
            wp_die('Você não tem permissão para realizar esta ação.');
        }
        
        // 3. AÇÃO NO BANCO DE DADOS: Zera os cliques.
        global $wpdb;
        $table_name = $wpdb->prefix . 'redirect_clicks';
        $wpdb->delete($table_name, ['link_id' => $post_id], ['%d']);

        // 4. AÇÃO NO POST: Atualiza a data de criação e modificação para agora.
        $current_time = current_time('mysql');
        $current_time_gmt = current_time('mysql', 1); // 1 = GMT

        wp_update_post([
            'ID'                => $post_id,
            'post_date'         => $current_time,
            'post_date_gmt'     => $current_time_gmt,
            'post_modified'     => $current_time,
            'post_modified_gmt' => $current_time_gmt,
        ]);
        
        // 5. REDIRECIONAMENTO: Envia o usuário de volta para a listagem com uma mensagem de sucesso.
        $redirect_url = admin_url('edit.php?post_type=redirect_link&cr_reset_success=1');
        wp_safe_redirect($redirect_url);
        exit;
    }
}

/**
 * PARTE 3: Exibe a notificação de sucesso.
 * 
 * Usa o hook 'admin_notices' para mostrar a mensagem após o redirecionamento.
 */
add_action('admin_notices', 'cr_show_reset_success_notice');
function cr_show_reset_success_notice() {
    if (isset($_GET['cr_reset_success']) && $_GET['cr_reset_success'] == '1') {
        echo '<div class="notice notice-success is-dismissible">
                <p><strong>A contagem de acessos foi zerada e a data do link foi atualizada com sucesso!</strong></p>
              </div>';
    }
}

// O JavaScript anterior para slug não é mais necessário, a lógica agora é feita no backend (PHP).
// Apenas o script de cópia foi mantido.