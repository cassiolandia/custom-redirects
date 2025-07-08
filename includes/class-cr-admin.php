<?php

class CR_Admin {

    public static function init() {
        add_action('admin_head', [self::class, 'admin_custom_styles']);
        add_action('add_meta_boxes', [self::class, 'add_meta_boxes']);
        add_action('save_post_redirect_link', [self::class, 'save_meta_box_data'], 10, 2);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_scripts']);
        add_action('admin_footer', [self::class, 'add_inline_copy_script']);
        add_filter('manage_redirect_link_posts_columns', [self::class, 'set_custom_edit_redirect_link_columns']);
        add_action('manage_redirect_link_posts_custom_column', [self::class, 'custom_redirect_link_column'], 10, 2);
        add_action('admin_head', [self::class, 'add_admin_list_table_styles']);
        add_action('restrict_manage_posts', [self::class, 'add_status_filter']);
        add_filter('manage_edit-redirect_link_sortable_columns', [self::class, 'make_columns_sortable']);
        add_action('pre_get_posts', [self::class, 'modify_admin_query']);
        add_action('admin_menu', [self::class, 'add_reports_page']);
        add_filter('post_row_actions', [self::class, 'add_reset_clicks_link'], 10, 2);
        add_action('admin_init', [self::class, 'handle_reset_clicks_action']);
        add_action('admin_notices', [self::class, 'show_reset_success_notice']);
    }

    public static function admin_custom_styles() {
        echo '<style>.cr-copy-button { cursor: pointer; color: #0073aa; vertical-align: middle; margin-left: 5px; }.cr-copy-button:hover { color: #00a0d2; }.cr-copy-button.copied { color: #46b450; cursor: default; } .cr-suggested-slug { background: #f0f0f1; padding: 5px 10px; border-radius: 3px; font-family: monospace; display: inline-block; border: 1px solid #ddd; }</style>';
    }

    public static function add_meta_boxes() {
        add_meta_box('cr_link_details_meta_box', 'Detalhes do Link de Redirecionamento', [self::class, 'render_meta_box_content'], 'redirect_link', 'normal', 'high');
    }

    public static function is_slug_unique($slug, $post_id) {
        global $wpdb;
        $check_slug = $wpdb->get_var($wpdb->prepare(
            "SELECT post_name FROM $wpdb->posts WHERE post_type = 'redirect_link' AND post_name = %s AND ID != %d LIMIT 1",
            $slug, $post_id
        ));
        return $check_slug === null;
    }

    public static function generate_unique_slug($length = 5) {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        do {
            $slug = '';
            for ($i = 0; $i < $length; $i++) {
                $slug .= $chars[rand(0, strlen($chars) - 1)];
            }
        } while (!self::is_slug_unique($slug, 0));
        return $slug;
    }

    public static function render_meta_box_content($post) {
        wp_nonce_field('cr_save_meta_box_data', 'cr_meta_box_nonce');
        
        $destination_url = get_post_meta($post->ID, '_destination_url', true);
        $link_notes      = get_post_meta($post->ID, '_link_notes', true);
        $is_inactive     = get_post_meta($post->ID, '_is_inactive', true);
        $slug            = $post->post_name;
        
        if ($post->post_status === 'auto-draft') {
            $suggested_slug = self::generate_unique_slug();
            echo '<input type="hidden" name="cr_suggested_slug" value="' . esc_attr($suggested_slug) . '" />';
            echo '<p><strong>Slug Sugerido:</strong> ' .
                 '<span class="cr-suggested-slug">' . esc_html($suggested_slug) . '</span> ' .
                 '<button type="button" id="cr_use_suggested_slug_btn" class="button button-small" style="vertical-align: middle;">Usar este</button>' .
                 '</p>';
            echo '<p><small>Este slug será usado se o campo "Slug Personalizado" for deixado em branco.</small></p>';
        } elseif ($slug) {
            echo '<p><strong>URL Gerada:</strong> <code>' . esc_url(home_url('/go/' . $slug . '/')) . '</code><span class="dashicons dashicons-admin-page cr-copy-button" title="Copiar URL Gerada" data-copy-text="' . esc_attr(home_url('/go/' . $slug . '/')) . '"></span></p>';
        }

        echo '<hr>';
        echo '<p style="background: #f0f0f1; padding: 10px; border-left: 4px solid #7e8993;"><label for="cr_is_inactive"><input type="checkbox" id="cr_is_inactive" name="cr_is_inactive" value="1" ' . checked($is_inactive, '1', false) . ' /> <strong>Inativo</strong></label><br><small>Se marcado, este link redirecionará para a página inicial do site em vez do destino.</small></p>';
        echo '<p><label for="cr_destination_url"><strong>URL de Destino:</strong></label><br><input type="url" id="cr_destination_url" name="cr_destination_url" value="' . esc_attr($destination_url) . '" style="width: 100%;" placeholder="https://exemplo.com/pagina-de-destino" required /></p>';
        echo '<p><label for="cr_custom_slug"><strong>Slug Personalizado (Opcional):</strong></label><br><input type="text" id="cr_custom_slug" name="cr_custom_slug" value="' . esc_attr($slug) . '" style="width: 100%;" placeholder="promocao-de-natal" /><small>Use apenas letras minúsculas, números e hífens. Se deixado em branco em um novo link, o slug sugerido acima será usado.</small></p>';
        echo '<p><label for="cr_link_notes"><strong>Notas (Uso interno):</strong></label><br><textarea id="cr_link_notes" name="cr_link_notes" rows="4" style="width: 100%;">' . esc_textarea($link_notes) . '</textarea></p>';
    }

    public static function save_meta_box_data($post_id, $post) {
        if (!isset($_POST['cr_meta_box_nonce']) || !wp_verify_nonce($_POST['cr_meta_box_nonce'], 'cr_save_meta_box_data')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (wp_is_post_revision($post_id)) return;

        $final_slug = $post->post_name;
        $custom_slug = isset($_POST['cr_custom_slug']) ? sanitize_title(trim($_POST['cr_custom_slug'])) : '';
        
        if (!empty($custom_slug)) {
            $final_slug = $custom_slug;
        } elseif (empty($post->post_name) || $post->post_status === 'auto-draft') {
            $final_slug = isset($_POST['cr_suggested_slug']) ? sanitize_title($_POST['cr_suggested_slug']) : self::generate_unique_slug();
        }

        if ($final_slug !== $post->post_name) {
            if (!self::is_slug_unique($final_slug, $post_id)) {
                add_action('admin_notices', function() use ($final_slug) {
                    echo '<div class="notice notice-error is-dismissible"><p>O slug "<strong>' . esc_html($final_slug) . '</strong>" já está em uso. Um slug aleatório foi gerado para evitar conflitos.</p></div>';
                });
                $final_slug = self::generate_unique_slug();
            }
            
            remove_action('save_post_redirect_link', [self::class, 'save_meta_box_data'], 10);
            wp_update_post(['ID' => $post_id, 'post_name' => $final_slug]);
            add_action('save_post_redirect_link', [self::class, 'save_meta_box_data'], 10, 2);
        }

        if (isset($_POST['cr_destination_url'])) {
            update_post_meta($post_id, '_destination_url', esc_url_raw(trim($_POST['cr_destination_url'])));
        }

        if (isset($_POST['cr_link_notes'])) {
            update_post_meta($post_id, '_link_notes', sanitize_textarea_field($_POST['cr_link_notes']));
        }

        update_post_meta($post_id, '_is_inactive', isset($_POST['cr_is_inactive']) ? '1' : '0');

        flush_rewrite_rules();
    }

    public static function enqueue_admin_scripts($hook_suffix) {
        $screen = get_current_screen();
        $allowed_hooks = ['edit.php', 'post.php', 'post-new.php'];

        if (in_array($hook_suffix, $allowed_hooks) && 'redirect_link' === $screen->post_type) {
            wp_enqueue_script('cr-admin-logic', plugin_dir_url(__FILE__) . '../js/admin-scripts.js', ['jquery', 'postbox'], '1.0.0', true);
            wp_enqueue_style('cr-admin-style', plugin_dir_url(__FILE__) . '../css/admin-style.css');
        }
    }

    public static function add_inline_copy_script() {
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

    public static function set_custom_edit_redirect_link_columns($columns) {
        unset($columns['date']); 
        return [
            'cb'              => $columns['cb'],
            'title'           => 'Nome do Link',
            'redirect_url'    => 'URL Gerada',
            'destination_url' => 'URL de Destino',
            'cr_origin'       => 'Origens',
            'cr_campaign'     => 'Campanhas',
            'link_notes'      => 'Notas Internas',
            'clicks'          => 'Acessos (Hoje / Ontem / Total)',
            'status'          => 'Status',
            'publish_date'    => 'Data',
            'actions'         => 'Ações',
        ];
    }

    public static function custom_redirect_link_column($column, $post_id) {
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

            case 'cr_campaign':
            $terms = get_the_terms($post_id, 'cr_campaign');
            if (!empty($terms)) {
                $campaign_links = [];
                foreach ($terms as $term) {
                    $campaign_links[] = sprintf('<a href="%s">%s</a>', 
                        esc_url(admin_url('edit.php?post_type=redirect_link&cr_campaign=' . $term->slug)),
                        esc_html($term->name)
                    );
                }
                echo implode(', ', $campaign_links);
            } else {
                echo '<em>—</em>';
            }
            break;

        case 'cr_origin':
            $terms = get_the_terms($post_id, 'cr_origin');
            if (!empty($terms)) {
                $origin_links = [];
                foreach ($terms as $term) {
                    $origin_links[] = sprintf('<a href="%s">%s</a>', 
                        esc_url(admin_url('edit.php?post_type=redirect_link&cr_origin=' . $term->slug)),
                        esc_html($term->name)
                    );
                }
                echo implode(', ', $origin_links);
            } else {
                echo '<em>—</em>';
            }
            break;

        case 'destination_url':
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

    public static function add_admin_list_table_styles() {
        $screen = get_current_screen();

        if ($screen && 'edit-redirect_link' == $screen->id) {
            echo '<style>
                .column-destination_url a {
                    display: -webkit-box;
                    -webkit-box-orient: vertical;
                    -webkit-line-clamp: 3;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    word-break: break-all;
                }
            </style>';
        }
    }

    public static function add_status_filter($post_type) {
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

    public static function make_columns_sortable($columns) {
        $columns['status'] = 'status';
        $columns['clicks'] = 'clicks';
        return $columns;
    }

    public static function modify_admin_query($query) {
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

    public static function add_reports_page() {
        add_submenu_page(
            null,
            'Relatórios de Cliques',
            'Relatórios de Cliques',
            'manage_options',
            'cr_click_reports',
            [self::class, 'render_reports_page']
        );
    }

    public static function render_reports_page() {
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

    public static function add_reset_clicks_link($actions, $post) {
        if ($post->post_type === 'redirect_link') {
            $nonce = wp_create_nonce('cr_reset_clicks_nonce_' . $post->ID);
            $reset_link = admin_url('edit.php?post_type=redirect_link&action=cr_reset_clicks&link_id=' . $post->ID . '&_wpnonce=' . $nonce);
            $confirm_message = 'Tem certeza que deseja zerar TODOS os acessos deste link? Esta ação não pode ser desfeita.';
            $actions['reset_clicks'] = '<a style="color: #d63638;" href="' . esc_url($reset_link) . '" onclick="return confirm(\"' . esc_js($confirm_message) . '\
");">Zerar Acessos</a>';
        }
        return $actions;
    }

    public static function handle_reset_clicks_action() {
        if (isset($_GET['action']) && $_GET['action'] === 'cr_reset_clicks' && isset($_GET['link_id'])) {
            $post_id = intval($_GET['link_id']);

            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'cr_reset_clicks_nonce_' . $post_id)) {
                wp_die('A verificação de segurança falhou. Por favor, tente novamente.');
            }

            if (!current_user_can('edit_post', $post_id)) {
                wp_die('Você não tem permissão para realizar esta ação.');
            }
            
            global $wpdb;
            $table_name = $wpdb->prefix . 'redirect_clicks';
            $wpdb->delete($table_name, ['link_id' => $post_id], ['%d']);

            $current_time = current_time('mysql');
            $current_time_gmt = current_time('mysql', 1);

            wp_update_post([
                'ID'                => $post_id,
                'post_date'         => $current_time,
                'post_date_gmt'     => $current_time_gmt,
                'post_modified'     => $current_time,
                'post_modified_gmt' => $current_time_gmt,
            ]);
            
            $redirect_url = admin_url('edit.php?post_type=redirect_link&cr_reset_success=1');
            wp_safe_redirect($redirect_url);
            exit;
        }
    }

    public static function show_reset_success_notice() {
        if (isset($_GET['cr_reset_success']) && $_GET['cr_reset_success'] == '1') {
            echo '<div class="notice notice-success is-dismissible">
                    <p><strong>A contagem de acessos foi zerada e a data do link foi atualizada com sucesso!</strong></p>
                  </div>';
        }
    }
}