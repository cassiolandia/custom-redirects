<?php

class CR_CPT {

    public static function init() {
        add_action('init', [self::class, 'register_redirect_link_cpt']);
    }

    /**
     * Registers the Custom Post Type for redirect links.
     */
    public static function register_redirect_link_cpt() {
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
}