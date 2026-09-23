<?php

if (!defined('ABSPATH')) {
    exit;
}

class Clevers_IO_Media_Library
{
    private $optimizer;

    public function __construct(Clevers_IO_Optimizer $optimizer)
    {
        $this->optimizer = $optimizer;

        add_filter('manage_media_columns', [$this, 'add_column']);
        add_action('manage_media_custom_column', [$this, 'manage_column'], 10, 2);

        add_filter('bulk_actions-upload', [$this, 'register_bulk_action']);
        add_filter('handle_bulk_actions-upload', [$this, 'handle_bulk_action'], 10, 3);

        add_action('admin_notices', [$this, 'bulk_action_admin_notice']);

        add_filter('media_row_actions', [$this, 'add_reoptimize_row_action'], 10, 2);
        add_action('wp_ajax_clevers_io_reoptimize_single', [$this, 'ajax_reoptimize_single']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function enqueue_scripts($hook)
    {
        if ($hook !== 'upload.php') {
            return;
        }

        wp_enqueue_script(
            'clevers-io-media-library',
            plugins_url('assets/js/media-library.js', dirname(__FILE__)),
            ['jquery'],
            '1.0.2',
            true
        );

        wp_localize_script('clevers-io-media-library', 'cleversIoMedia', [
            'reoptimizing'    => __('Re-optimizando…', 'clevers-image-optimizer'),
            'queued'          => __('✓ Encolada', 'clevers-image-optimizer'),
            'errorUnknown'    => __('Error desconocido.', 'clevers-image-optimizer'),
            'errorConnection' => __('Error de conexión.', 'clevers-image-optimizer'),
        ]);
    }

    public function add_column($columns)
    {
        $columns['clevers_io_optimization'] = __('Optimización', 'clevers-image-optimizer');

        return $columns;
    }

    public function manage_column($column_name, $post_id)
    {
        if ($column_name !== 'clevers_io_optimization' && $column_name !== 'cio_optimization') {
            return;
        }

        $mime = get_post_mime_type($post_id);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            echo '<span style="color:#999;">—</span>';
            return;
        }

        $stats = get_post_meta($post_id, '_clevers_io_stats', true);
        if (!$stats) {
            $stats = get_post_meta($post_id, '_cio_stats', true);
        }

        if ($stats && !empty($stats['original_size'])) {
            $original = (int) $stats['original_size'];
            $optimized = isset($stats['optimized_size']) ? (int) $stats['optimized_size'] : $original;

            $savings = $original - $optimized;
            $percentage = ($original > 0) ? round(($savings / $original) * 100, 1) : 0;

            echo '<div style="font-size: 12px;">';
            echo '<strong>' . esc_html__('Original:', 'clevers-image-optimizer') . '</strong> ' . esc_html(size_format($original)) . '<br>';

            if ($savings > 0) {
                echo '<strong>' . esc_html__('Optimizado:', 'clevers-image-optimizer') . '</strong> ' . esc_html(size_format($optimized) . " (-{$percentage}%)") . '<br>';
            } else {
                echo '<strong>' . esc_html__('Optimizado:', 'clevers-image-optimizer') . '</strong> ' . esc_html(size_format($optimized) . ' (0%)') . '<br>';
            }

            if (!empty($stats['webp_size'])) {
                $webp_size = (int) $stats['webp_size'];
                $webp_savings = $original - $webp_size;
                $webp_percentage = ($original > 0) ? round(($webp_savings / $original) * 100, 1) : 0;
                echo '<strong>WebP:</strong> ' . esc_html(size_format($webp_size) . " (-{$webp_percentage}%)") . '<br>';
            }

            if (!empty($stats['avif_size'])) {
                $avif_size = (int) $stats['avif_size'];
                $avif_savings = $original - $avif_size;
                $avif_percentage = ($original > 0) ? round(($avif_savings / $original) * 100, 1) : 0;
                echo '<strong>AVIF:</strong> ' . esc_html(size_format($avif_size) . " (-{$avif_percentage}%)") . '<br>';
            }
            echo '</div>';

            return;
        }

        echo '<span style="color: #999;">' . esc_html__('Pendiente o no optimizado', 'clevers-image-optimizer') . '</span>';
    }

    public function register_bulk_action($bulk_actions)
    {
        $bulk_actions['clevers_io_optimize'] = __('Optimizar imágenes (background)', 'clevers-image-optimizer');

        return $bulk_actions;
    }

    public function handle_bulk_action($redirect_to, $doaction, $post_ids)
    {
        if ($doaction !== 'clevers_io_optimize' && $doaction !== 'cio_optimize') {
            return $redirect_to;
        }

        $processed = $this->optimizer->enqueue_attachments($post_ids);

        return add_query_arg('clevers_io_bulk_optimized', $processed, $redirect_to);
    }

    public function bulk_action_admin_notice()
    {
        if (!isset($_GET['clevers_io_bulk_optimized'])) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'bulk-media')) {
            return;
        }

        $count = absint(wp_unslash($_GET['clevers_io_bulk_optimized']));

        printf(
            '<div id="message" class="updated notice is-dismissible"><p>%s</p></div>',
            esc_html(
                sprintf(
                    /* translators: %d: Number of images queued for background optimization. */
                    __('%d imágenes encoladas para optimización en background.', 'clevers-image-optimizer'),
                    $count
                )
            )
        );
    }

    public function add_reoptimize_row_action(array $actions, $post)
    {
        $mime = get_post_mime_type($post->ID);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return $actions;
        }

        if (!current_user_can('upload_files') && !current_user_can('manage_options')) {
            return $actions;
        }

        $nonce = wp_create_nonce('clevers_io_reoptimize_' . $post->ID);
        $label = esc_html__('Re-optimizar', 'clevers-image-optimizer');

        $actions['clevers_io_reoptimize'] = sprintf(
            '<a href="#" class="clevers-io-reoptimize-single" data-id="%d" data-nonce="%s">%s</a>',
            esc_attr($post->ID),
            esc_attr($nonce),
            $label
        );

        return $actions;
    }

    public function ajax_reoptimize_single()
    {
        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;

        if (!$attachment_id) {
            wp_send_json_error(['message' => __('ID de adjunto inválido.', 'clevers-image-optimizer')]);
        }

        if (!current_user_can('upload_files') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permiso para realizar esta acción.', 'clevers-image-optimizer')]);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'clevers_io_reoptimize_' . $attachment_id) && !wp_verify_nonce($nonce, 'cio_reoptimize_' . $attachment_id)) {
            wp_send_json_error(['message' => __('Nonce inválido.', 'clevers-image-optimizer')]);
        }

        $queued = $this->optimizer->enqueue_attachment($attachment_id);

        if ($queued) {
            wp_send_json_success(['message' => __('Imagen encolada para re-optimización en background.', 'clevers-image-optimizer')]);
        } else {
            wp_send_json_error(['message' => __('No se pudo encolar la imagen (ya estaba en cola o ID inválido).', 'clevers-image-optimizer')]);
        }
    }
}

if (!class_exists('CIO_Media_Library', false)) {
    class_alias('Clevers_IO_Media_Library', 'CIO_Media_Library');
}
