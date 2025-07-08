<?php
/**
 * Plugin Name:       Redirecionador Customizado com Rastreamento
 * Description:       Cria URLs personalizadas no formato /go/[slug] que redirecionam para um destino e rastreiam os cliques diários, com detecção de bots.
 * Version:           2.1.0
 * Author:            Seu Nome (Revisado por Especialista)
 */

if (!defined('ABSPATH')) exit; // Previne acesso direto

// Carrega as classes
require_once plugin_dir_path(__FILE__) . 'includes/class-cr-activator.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cr-cpt.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cr-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cr-redirector.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cr-campaign-reports.php';

// Ativação do Plugin
register_activation_hook(__FILE__, ['CR_Activator', 'activate']);

// Inicializa as funcionalidades
CR_CPT::init();
CR_Admin::init();
CR_Redirector::init();
CR_Campaign_Reports::init();