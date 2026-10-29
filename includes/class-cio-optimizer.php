<?php

if (!defined('ABSPATH')) {
    exit;
}

use Spatie\ImageOptimizer\OptimizerChainFactory;

class Clevers_IO_Optimizer
{
    const OPTION_QUEUE = 'clevers_io_pending_queue';
    const CRON_HOOK = 'clevers_io_process_queue';
    const LOCK_KEY = 'clevers_io_process_queue_lock';
    const OPTION_MAX_DIMENSION = 'clevers_io_max_dimension';

    public function __construct()
    {
        add_filter('wp_generate_attachment_metadata', [$this, 'queue_on_upload'], 10, 2);
        add_action(self::CRON_HOOK, [$this, 'process_queue']);
        // Compatibilidad con tareas programadas antiguas
        add_action('cio_process_queue', [$this, 'process_queue']);
    }

    public function queue_on_upload($metadata, $attachment_id)
    {
        $this->enqueue_attachment($attachment_id);

        return $metadata;
    }

    public function enqueue_attachment($attachment_id)
    {
        $attachment_id = absint($attachment_id);
        if (!$attachment_id) {
            return false;
        }

        $queue = $this->get_queue();
        if (!in_array($attachment_id, $queue, true)) {
            $queue[] = $attachment_id;
            $this->save_queue($queue);
        }

        $this->schedule_queue_processing();

        return true;
    }

    public function enqueue_attachments(array $attachment_ids)
    {
        $valid_ids = $this->normalize_attachment_id_list($attachment_ids);
        if (empty($valid_ids)) {
            return 0;
        }

        $queue = $this->get_queue();
        $merged = array_values(array_unique(array_merge($queue, $valid_ids)));
        $new_items_count = count($merged) - count($queue);

        if ($new_items_count > 0) {
            $this->save_queue($merged);
            $this->schedule_queue_processing();
        }

        return count($valid_ids);
    }

    public function process_queue()
    {
        $this->run_batch();
    }

    /**
     * Procesa hasta batch_limit items de la cola respetando el time_limit.
     * Devuelve un resumen con conteos para que llamadores AJAX puedan
     * reportar progreso al usuario.
     *
     * @return array{processed:int, remaining:int, stopped_early:bool}
     */
    public function run_batch()
    {
        if (get_transient(self::LOCK_KEY)) {
            return ['processed' => 0, 'remaining' => $this->get_queue_count(), 'stopped_early' => false];
        }

        set_transient(self::LOCK_KEY, 1, 60);

        $start_time = time();
        $time_limit = $this->get_time_limit();
        $batch_limit = $this->get_batch_limit();

        $queue = $this->get_queue();
        if (empty($queue)) {
            delete_transient(self::LOCK_KEY);
            return ['processed' => 0, 'remaining' => 0, 'stopped_early' => false];
        }

        $processed = 0;
        $stopped_early = false;

        while (!empty($queue) && $processed < $batch_limit) {
            if ((time() - $start_time) >= $time_limit) {
                $stopped_early = true;
                break;
            }

            $attachment_id = array_shift($queue);
            $this->optimize_attachment($attachment_id);
            $processed++;

            $this->save_queue($queue);
        }

        delete_transient(self::LOCK_KEY);

        if (!empty($queue)) {
            $this->schedule_queue_processing();
        }

        return [
            'processed' => $processed,
            'remaining' => count($queue),
            'stopped_early' => $stopped_early,
        ];
    }

    public function optimize_attachment($attachment_id)
    {
        $attachment_id = absint($attachment_id);
        if (!$attachment_id) {
            return false;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (empty($metadata['file'])) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $base_dir = trailingslashit($upload_dir['basedir']);

        $stats = [
            'original_size' => 0,
            'optimized_size' => 0,
            'webp_size' => 0,
            'avif_size' => 0,
        ];

        $original = $base_dir . $metadata['file'];

        $upload_basedir = realpath($upload_dir['basedir']);
        $real_original  = realpath($original);
        if ($real_original === false || strpos($real_original, $upload_basedir) !== 0) {
            return new WP_Error('invalid_path', 'Path fuera del directorio de uploads.');
        }

        if ($this->is_supported_image_path($original) && file_exists($original)) {
            $stats['original_size'] = (int) filesize($original);

            $this->resize_file($original, $this->get_max_dimension());
            $this->optimize_file($original);
            $stats['optimized_size'] = (int) filesize($original);

            $this->generate_webp($original);
            $webp_path = $this->get_webp_path($original);
            if (file_exists($webp_path)) {
                $stats['webp_size'] = (int) filesize($webp_path);
            }

            if ($this->is_avif_enabled()) {
                $this->generate_avif($original);
                $avif_path = $this->get_avif_path($original);
                if (file_exists($avif_path)) {
                    $stats['avif_size'] = (int) filesize($avif_path);
                }
            }
        }

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $subdir = pathinfo($metadata['file'], PATHINFO_DIRNAME);

            foreach ($metadata['sizes'] as $size) {
                if (empty($size['file'])) {
                    continue;
                }

                $file = $base_dir . $subdir . '/' . $size['file'];
                if (!$this->is_supported_image_path($file) || !file_exists($file)) {
                    continue;
                }

                $this->optimize_file($file);
                $this->generate_webp($file);

                if ($this->is_avif_enabled()) {
                    $this->generate_avif($file);
                }
            }
        }

        update_post_meta($attachment_id, '_clevers_io_stats', $stats);
        update_post_meta($attachment_id, '_cio_stats', $stats);

        return true;
    }

    public function optimize_file($file)
    {
        if (!file_exists($file)) {
            return;
        }

        try {
            $optimizerChain = OptimizerChainFactory::create();
            $optimizerChain->optimize($file);
        } catch (\Throwable $e) {
            Clevers_IO_Logger::warn('optimize_file failed', [
                'file'  => $file,
                'error' => $e->getMessage(),
            ], 'optimizer');
        }
    }

    public function generate_webp($file)
    {
        if (!function_exists('imagewebp')) {
            return;
        }

        $webp = $this->get_webp_path($file);

        if (file_exists($webp)) {
            return;
        }

        $img = $this->create_image_resource($file);
        if (!$img) {
            return;
        }

        $quality = $this->get_webp_quality();
        imagewebp($img, $webp, $quality);
        $this->destroy_image_resource($img);
    }

    public function generate_avif($file)
    {
        if (!function_exists('imageavif')) {
            return;
        }

        $avif = $this->get_avif_path($file);

        if (file_exists($avif)) {
            return;
        }

        $img = $this->create_image_resource($file);
        if (!$img) {
            return;
        }

        $quality = $this->get_avif_quality();
        imageavif($img, $avif, $quality);
        $this->destroy_image_resource($img);
    }

    public function get_queue_count()
    {
        return count($this->get_queue());
    }

    public function normalize_attachment_id_list(array $attachment_ids)
    {
        $normalized = array_map('absint', $attachment_ids);
        $normalized = array_filter($normalized);

        return array_values(array_unique($normalized));
    }

    public function sanitize_quality($value)
    {
        return Clevers_IO_Utils::sanitize_quality($value, 0, 100, 80);
    }

    public function is_supported_image_path($file)
    {
        return (bool) preg_match('/\.(jpe?g|png|webp)$/i', (string) $file);
    }

    /**
     * Redimensiona una imagen existente solo cuando se configuró un límite
     * explícito. El valor 0 conserva las dimensiones originales.
     *
     * @param string $file Ruta absoluta dentro de uploads.
     * @param int $max_dimension Máxima anchura o altura permitida.
     * @return bool True si se redimensionó correctamente.
     */
    public function resize_file($file, $max_dimension)
    {
        $max_dimension = absint($max_dimension);
        if ($max_dimension === 0 || !file_exists($file)) {
            return false;
        }

        $info = @getimagesize($file);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return false;
        }

        [$width, $height] = $info;
        if ($width <= $max_dimension && $height <= $max_dimension) {
            return false;
        }

        $source = $this->create_image_resource($file);
        if (!$source) {
            return false;
        }

        $ratio = $width >= $height ? $max_dimension / $width : $max_dimension / $height;
        $target = imagescale($source, (int) round($width * $ratio), (int) round($height * $ratio));
        $this->destroy_image_resource($source);
        if ($target === false) {
            return false;
        }

        $temporary = $file . '.clevers-io-resize';
        $saved = $this->save_image_resource($target, $temporary, $info[2]);
        $this->destroy_image_resource($target);
        if (!$saved) {
            @unlink($temporary);
            return false;
        }

        if (!@rename($temporary, $file)) {
            @unlink($temporary);
            return false;
        }

        return true;
    }

    private function get_queue()
    {
        $queue = get_option(self::OPTION_QUEUE, null);
        if ($queue === null) {
            $queue = get_option('cio_pending_queue', []);
        }

        return is_array($queue) ? $this->normalize_attachment_id_list($queue) : [];
    }

    private function save_queue(array $queue)
    {
        update_option(self::OPTION_QUEUE, $this->normalize_attachment_id_list($queue), false);
    }

    private function schedule_queue_processing()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 15, self::CRON_HOOK);
        }
    }

    private function get_batch_limit()
    {
        $limit = absint(get_option('clevers_io_batch_limit', get_option('cio_batch_limit', 10)));
        if ($limit < 1) {
            $limit = 1;
        }

        return min($limit, 100);
    }

    private function get_time_limit()
    {
        $limit = absint(get_option('clevers_io_time_limit', get_option('cio_time_limit', 20)));
        if ($limit < 5) {
            $limit = 5;
        }

        return min($limit, 120);
    }

    private function get_max_dimension()
    {
        $dimension = absint(get_option(self::OPTION_MAX_DIMENSION, 0));

        if ($dimension !== 0 && $dimension < 500) {
            return 500;
        }

        return min($dimension, 8192);
    }

    private function create_image_resource($file)
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $read_error = null;
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Used to capture image warnings without @.
        set_error_handler(static function ($errno, $errstr) use (&$read_error) {
            $read_error = $errstr;
            return true;
        });
        $content = file_get_contents($file);
        restore_error_handler();

        if ($content === false || $content === '') {
            if ($read_error !== null) {
                Clevers_IO_Logger::debug('create_image_resource file_get_contents failed', [
                    'file'  => $file,
                    'error' => $read_error,
                ], 'image_reader');
            }
            return null;
        }

        $parse_error = null;
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Used to capture image warnings without @.
        set_error_handler(static function ($errno, $errstr) use (&$parse_error) {
            $parse_error = $errstr;
            return true;
        });
        $img = imagecreatefromstring($content);
        restore_error_handler();

        if ($img === false) {
            if ($parse_error !== null) {
                Clevers_IO_Logger::debug('create_image_resource imagecreatefromstring failed', [
                    'file'  => $file,
                    'error' => $parse_error,
                ], 'image_reader');
            }
            return null;
        }

        return $img;
    }

    /**
     * @param resource $image
     * @param int $type IMAGETYPE_JPEG, IMAGETYPE_PNG o IMAGETYPE_WEBP.
     */
    private function save_image_resource($image, $path, $type)
    {
        if ($type === IMAGETYPE_JPEG) {
            return imagejpeg($image, $path, 85);
        }

        if ($type === IMAGETYPE_PNG) {
            imagesavealpha($image, true);
            return imagepng($image, $path, 6);
        }

        if ($type === IMAGETYPE_WEBP && function_exists('imagewebp')) {
            return imagewebp($image, $path, $this->get_webp_quality());
        }

        return false;
    }

    /**
     * PHP 8.5 libera los objetos GD automaticamente y depreca imagedestroy().
     * En PHP anteriores mantenemos la liberacion explicita de recursos.
     *
     * @param resource|object $image
     */
    private function destroy_image_resource($image)
    {
        if (PHP_VERSION_ID < 80500 && is_resource($image)) {
            imagedestroy($image);
        }
    }

    private function get_webp_path($file)
    {
        $info = pathinfo($file);

        return $info['dirname'] . '/' . $info['filename'] . '.webp';
    }

    private function get_avif_path($file)
    {
        $info = pathinfo($file);

        return $info['dirname'] . '/' . $info['filename'] . '.avif';
    }

    private function get_webp_quality()
    {
        return $this->sanitize_quality(get_option('clevers_io_webp_quality', get_option('cio_webp_quality', 80)));
    }

    private function get_avif_quality()
    {
        return $this->sanitize_quality(get_option('clevers_io_avif_quality', get_option('cio_avif_quality', 80)));
    }

    private function is_avif_enabled()
    {
        return get_option('clevers_io_enable_avif', get_option('cio_enable_avif', '0')) === '1';
    }
}

if (!class_exists('CIO_Optimizer', false)) {
    class_alias('Clevers_IO_Optimizer', 'CIO_Optimizer');
}
