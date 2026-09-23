<?php
/**
 * Plugin Name: Clevers Image Optimizer
 * Description: Optimización local de imágenes + WebP + AVIF para sitios gestionados por Clever.
 * Author: Clevers.dev
 * Version: 1.0.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: clevers-image-optimizer
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

require_once plugin_dir_path(__FILE__) . 'includes/htaccess.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cio-utils.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cio-logger.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cio-optimizer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cio-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cio-media-library.php';

function clevers_io_init()
{
    $optimizer = new Clevers_IO_Optimizer();

    if (is_admin()) {
        new Clevers_IO_Admin($optimizer);
        new Clevers_IO_Media_Library($optimizer);
    }
}



add_action('plugins_loaded', 'clevers_io_init', 20);

register_activation_hook(__FILE__, function () {
    // Activación limpia sin llamadas a wp_die() conforme a directrices de WordPress.org.
    // La comprobación de extensiones opcionales se informa en el panel vía admin_notices.
});

register_deactivation_hook(__FILE__, function () {
    $timestamp = wp_next_scheduled(Clevers_IO_Optimizer::CRON_HOOK);
    if ($timestamp) {
        wp_unschedule_event($timestamp, Clevers_IO_Optimizer::CRON_HOOK);
    }
    $legacy_timestamp = wp_next_scheduled('cio_process_queue');
    if ($legacy_timestamp) {
        wp_unschedule_event($legacy_timestamp, 'cio_process_queue');
    }
});
