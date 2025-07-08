<?php

class CR_Activator {

    /**
     * Activation hook.
     */
    public static function activate() {
        self::create_tracking_table();
        flush_rewrite_rules();
    }

    /**
     * Creates the database table for tracking clicks.
     */
    private static function create_tracking_table() {
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
}