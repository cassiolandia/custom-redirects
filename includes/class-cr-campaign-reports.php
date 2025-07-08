<?php

if (!defined('ABSPATH')) exit;

class CR_Campaign_Reports {

    public static function init() {
        add_action('admin_menu', [self::class, 'register_report_page']);
        add_filter('cr_campaign_row_actions', [self::class, 'add_report_link'], 10, 2);
    }

    public static function register_report_page() {
        add_submenu_page(
            null, // Parent slug - null makes it hidden
            'Relatório da Campanha',
            'Relatório da Campanha',
            'manage_options',
            'cr_campaign_report', // Page slug
            [self::class, 'render_report_page']
        );
    }

    public static function add_report_link($actions, $term) {
        $url = admin_url('admin.php?page=cr_campaign_report&campaign_id=' . $term->term_id);
        $actions['report'] = sprintf('<a href="%s">Relatório</a>', esc_url($url));
        return $actions;
    }

    public static function render_report_page() {
        if (!isset($_GET['campaign_id']) || !absint($_GET['campaign_id'])) {
            wp_die('ID da campanha inválido.');
        }

        $campaign_id = absint($_GET['campaign_id']);
        $term = get_term($campaign_id, 'cr_campaign');

        if (!$term || is_wp_error($term)) {
            wp_die('Campanha não encontrada.');
        }

        global $wpdb;
        $total_clicks = 0;

        $post_ids = get_posts([
            'post_type' => 'redirect_link',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => [
                [
                    'taxonomy' => 'cr_campaign',
                    'field' => 'term_id',
                    'terms' => $campaign_id,
                ],
            ],
        ]);

        if (!empty($post_ids)) {
            $clicks_table = $wpdb->prefix . 'redirect_clicks';
            $post_ids_placeholder = implode(', ', array_fill(0, count($post_ids), '%d'));
            
            $sql = $wpdb->prepare(
                "SELECT SUM(click_count) FROM {$clicks_table} WHERE link_id IN ({$post_ids_placeholder})",
                $post_ids
            );
            
            $total_clicks = $wpdb->get_var($sql);
        }

        ?>
        <div class="wrap">
            <h1>Relatório para a Campanha: <?php echo esc_html($term->name); ?></h1>
            <p><a href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=cr_campaign')); ?>">← Voltar para todas as campanhas</a></p>
            
            <div id="poststuff">
                <div class="postbox">
                    <div class="postbox-header"><h2>Resumo de Acessos</h2></div>
                    <div class="inside">
                        <p style="font-size: 1.5em;">
                            <strong>Total de acessos:</strong>
                            <?php echo number_format_i18n($total_clicks ?? 0); ?>
                        </p>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }
}
