<?php

class CR_CPT {

    public static function init() {
        add_action('init', [self::class, 'register_redirect_link_cpt']);
        add_action('init', [self::class, 'register_origin_taxonomy']);
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
            'rewrite'         => false,
            'taxonomies'      => ['cr_origin'], // Associate taxonomy
        ];
        register_post_type('redirect_link', $args);
    }

    /**
     * Registers the 'Origin' taxonomy.
     */
    public static function register_origin_taxonomy() {
        $labels = [
            'name'              => 'Origens',
            'singular_name'     => 'Origem',
            'search_items'      => 'Procurar Origens',
            'all_items'         => 'Todas as Origens',
            'edit_item'         => 'Editar Origem',
            'update_item'       => 'Atualizar Origem',
            'add_new_item'      => 'Adicionar Nova Origem',
            'new_item_name'     => 'Nome da Nova Origem',
            'menu_name'         => 'Origens',
        ];

        $args = [
            'hierarchical'      => false, // Behaves like tags (autocomplete)
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => false, // We will create a custom column
            'query_var'         => true,
            'rewrite'           => ['slug' => 'origem'],
            'show_in_rest'      => true,
        ];

        register_taxonomy('cr_origin', ['redirect_link'], $args);
    }
}