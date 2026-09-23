<?php
/**
 * Uninstall script for Clevers Image Optimizer.
 *
 * Fired when the plugin is uninstalled via WordPress admin.
 * Removes all plugin options and transients from the database.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

(function () {
    $options = [
        'clevers_io_webp_quality',
        'clevers_io_enable_avif',
        'clevers_io_avif_quality',
        'clevers_io_batch_limit',
        'clevers_io_time_limit',
        'clevers_io_pending_queue',
        'cio_webp_quality',
        'cio_enable_avif',
        'cio_avif_quality',
        'cio_batch_limit',
        'cio_time_limit',
        'cio_pending_queue',
    ];

    foreach ($options as $option) {
        delete_option($option);
    }

    delete_transient('clevers_io_process_queue_lock');
    delete_transient('cio_process_queue_lock');

    delete_post_meta_by_key('_clevers_io_stats');
    delete_post_meta_by_key('_cio_stats');

    $timestamp = wp_next_scheduled('clevers_io_process_queue');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'clevers_io_process_queue');
    }
    $legacy_timestamp = wp_next_scheduled('cio_process_queue');
    if ($legacy_timestamp) {
        wp_unschedule_event($legacy_timestamp, 'cio_process_queue');
    }
})();
