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
        $clicks_by_origin = self::get_clicks_by_origin_for_campaign($campaign_id);
        $total_clicks = array_sum(wp_list_pluck($clicks_by_origin, 'total_clicks'));
        
        $daily_clicks = self::get_daily_clicks_for_campaign($campaign_id);
        $pivoted_data = self::pivot_daily_data($daily_clicks);
        $origins = array_keys(reset($pivoted_data) ? reset($pivoted_data) : []);

        ?>
        <div class="wrap">
            <h1>Relatório para a Campanha: <?php echo esc_html($term->name); ?></h1>
            <p><a href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=cr_campaign&post_type=redirect_link')); ?>">← Voltar para todas as campanhas</a></p>
            
            <div id="poststuff">
                <div class="postbox">
                    <div class="postbox-header"><h2>Resumo de Acessos</h2></div>
                    <div class="inside">
                        <p style="font-size: 1.5em;">
                            <strong>Total de acessos (humanos):</strong>
                            <?php echo number_format_i18n($total_clicks ?? 0); ?>
                        </p>
                    </div>
                </div>

                <div class="postbox">
                    <div class="postbox-header"><h2>Detalhes por Origem</h2></div>
                    <div class="inside">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th scope="col">Origem</th>
                                    <th scope="col">Total de Acessos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($clicks_by_origin)) : ?>
                                    <?php foreach ($clicks_by_origin as $origin_data) : ?>
                                        <tr>
                                            <td><?php echo esc_html($origin_data->origin_name); ?></td>
                                            <td><?php echo number_format_i18n($origin_data->total_clicks); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <tr>
                                        <td colspan="2">Nenhum acesso registrado para esta campanha ainda.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="postbox">
                    <div class="postbox-header"><h2>Relatório Diário por Origem</h2></div>
                    <div class="inside">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th scope="col">Dia</th>
                                    <?php foreach ($origins as $origin_name) : ?>
                                        <th scope="col"><?php echo esc_html($origin_name); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($pivoted_data)) : ?>
                                    <?php foreach ($pivoted_data as $date => $origins_data) : ?>
                                        <tr>
                                            <td><?php echo date_i18n('d/m/Y', strtotime($date)); ?></td>
                                            <?php foreach ($origins as $origin_name) : ?>
                                                <td><?php echo number_format_i18n($origins_data[$origin_name] ?? 0); ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <tr>
                                        <td colspan="<?php echo count($origins) + 1; ?>">Nenhum acesso diário registrado.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    private static function get_daily_clicks_for_campaign($campaign_id) {
        global $wpdb;

        $clicks_table = $wpdb->prefix . 'redirect_clicks';
        $term_relationships_table = $wpdb->prefix . 'term_relationships';
        $term_taxonomy_table = $wpdb->prefix . 'term_taxonomy';
        $terms_table = $wpdb->prefix . 'terms';

        $sql = $wpdb->prepare(
            "SELECT
                rc.click_date,
                t.name AS origin_name,
                SUM(rc.click_count) AS daily_clicks
            FROM
                {$clicks_table} AS rc
            INNER JOIN
                {$term_relationships_table} AS tr_campaign ON rc.link_id = tr_campaign.object_id
            INNER JOIN
                {$term_relationships_table} AS tr_origin ON rc.link_id = tr_origin.object_id
            INNER JOIN
                {$term_taxonomy_table} AS tt ON tr_origin.term_taxonomy_id = tt.term_taxonomy_id
            INNER JOIN
                {$terms_table} AS t ON tt.term_id = t.term_id
            WHERE
                tr_campaign.term_taxonomy_id = %d
                AND tt.taxonomy = 'cr_origin'
            GROUP BY
                rc.click_date, t.term_id
            ORDER BY
                rc.click_date DESC, daily_clicks DESC",
            $campaign_id
        );

        return $wpdb->get_results($sql);
    }

    private static function pivot_daily_data($daily_clicks) {
        $pivoted_data = [];
        $all_origins = [];

        // First, get all unique origins
        foreach ($daily_clicks as $click) {
            if (!in_array($click->origin_name, $all_origins)) {
                $all_origins[] = $click->origin_name;
            }
        }

        // Now, pivot the data
        foreach ($daily_clicks as $click) {
            $date = $click->click_date;
            if (!isset($pivoted_data[$date])) {
                $pivoted_data[$date] = array_fill_keys($all_origins, 0);
            }
            $pivoted_data[$date][$click->origin_name] = $click->daily_clicks;
        }

        return $pivoted_data;
    }

    private static function get_clicks_by_origin_for_campaign($campaign_id) {
        global $wpdb;

        $clicks_table = $wpdb->prefix . 'redirect_clicks';
        $term_relationships_table = $wpdb->prefix . 'term_relationships';
        $term_taxonomy_table = $wpdb->prefix . 'term_taxonomy';
        $terms_table = $wpdb->prefix . 'terms';

        $sql = $wpdb->prepare(
            "SELECT
                t.name AS origin_name,
                SUM(rc.click_count) AS total_clicks
            FROM
                {$clicks_table} AS rc
            INNER JOIN
                {$term_relationships_table} AS tr_campaign ON rc.link_id = tr_campaign.object_id
            INNER JOIN
                {$term_relationships_table} AS tr_origin ON rc.link_id = tr_origin.object_id
            INNER JOIN
                {$term_taxonomy_table} AS tt ON tr_origin.term_taxonomy_id = tt.term_taxonomy_id
            INNER JOIN
                {$terms_table} AS t ON tt.term_id = t.term_id
            WHERE
                tr_campaign.term_taxonomy_id = %d
                AND tt.taxonomy = 'cr_origin'
            GROUP BY
                t.term_id
            ORDER BY
                total_clicks DESC",
            $campaign_id
        );

        return $wpdb->get_results($sql);
    }
}
