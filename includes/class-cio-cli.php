<?php
/**
 * WP-CLI commands for Clevers Image Optimizer.
 *
 * @package Clevers_Image_Optimizer
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manage image optimization, WebP generation, and queue via WP-CLI.
 */
class Clevers_IO_CLI
{
    /**
     * Show diagnostics, queue status, and active settings.
     *
     * ## EXAMPLES
     *
     *     wp clevers-io status
     *
     * @when after_wp_load
     */
    public function status($args, $assoc_args)
    {
        $optimizer = new Clevers_IO_Optimizer();
        $queue_count = $optimizer->get_queue_count();

        WP_CLI::line('Clevers Image Optimizer Status');
        WP_CLI::line('------------------------------');
        WP_CLI::line(sprintf('Queue size: %d pending attachments', $queue_count));
        WP_CLI::line(sprintf('WebP generation: %s', get_option('clevers_io_generate_webp', 1) ? 'Enabled' : 'Disabled'));
        WP_CLI::line(sprintf('WebP recompression: %s', $optimizer->is_recompress_webp_enabled() ? 'Enabled' : 'Disabled'));
        WP_CLI::line(sprintf('AVIF generation: %s', get_option('clevers_io_generate_avif', 0) ? 'Enabled' : 'Disabled'));
        WP_CLI::line(sprintf('WebP quality: %d', (int) get_option('clevers_io_quality_webp', 82)));

        if (class_exists('Clevers_IO_Diagnostics')) {
            WP_CLI::line('');
            WP_CLI::line('Environment Diagnostics:');
            $checks = Clevers_IO_Diagnostics::run_diagnostics();
            $summary = Clevers_IO_Diagnostics::summarize($checks);

            $rows = [];
            foreach ($checks as $id => $check) {
                $rows[] = [
                    'Component' => $check['title'] ?? $id,
                    'Status'    => $check['status'] ?? 'unknown',
                    'Message'   => $check['message'] ?? '',
                ];
            }
            WP_CLI\Utils\format_items('table', $rows, ['Component', 'Status', 'Message']);
            WP_CLI::line(sprintf('Summary: %d OK, %d Warning, %d Error', $summary['ok'], $summary['warning'], $summary['error']));
        }
    }

    /**
     * Process items currently pending in the optimization queue.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Number of queue items/batches to process.
     *
     * ## EXAMPLES
     *
     *     wp clevers-io process-queue
     *     wp clevers-io process-queue --limit=100
     *
     * @when after_wp_load
     */
    public function process_queue($args, $assoc_args)
    {
        $optimizer = new Clevers_IO_Optimizer();
        $initial_count = $optimizer->get_queue_count();

        if ($initial_count === 0) {
            WP_CLI::success('Optimization queue is already empty.');
            return;
        }

        $limit = isset($assoc_args['limit']) ? max(1, (int) $assoc_args['limit']) : 0;
        WP_CLI::line(sprintf('Processing queue (%d items pending)...', $initial_count));

        $processed = 0;
        $target_ticks = $limit > 0 ? min($limit, $initial_count) : $initial_count;
        $progress = WP_CLI\Utils\make_progress_bar('Processing items', $target_ticks);

        while ($optimizer->get_queue_count() > 0) {
            $prev_count = $optimizer->get_queue_count();
            $optimizer->run_batch();
            $new_count = $optimizer->get_queue_count();

            $batch_processed = max(1, $prev_count - $new_count);
            $processed += $batch_processed;
            $progress->tick($batch_processed);

            if ($limit > 0 && $processed >= $limit) {
                break;
            }
        }

        $progress->finish();
        WP_CLI::success(sprintf('Processed %d items. Remaining in queue: %d.', $processed, $optimizer->get_queue_count()));
    }

    /**
     * Optimize a single attachment by ID.
     *
     * ## OPTIONS
     *
     * <id>
     * : Attachment ID to optimize.
     *
     * ## EXAMPLES
     *
     *     wp clevers-io optimize-attachment 1234
     *
     * @when after_wp_load
     */
    public function optimize_attachment($args, $assoc_args)
    {
        $attachment_id = absint($args[0] ?? 0);
        if (!$attachment_id) {
            WP_CLI::error('Please provide a valid numeric attachment ID.');
            return;
        }

        $post = get_post($attachment_id);
        if (!$post || $post->post_type !== 'attachment') {
            WP_CLI::error(sprintf('Attachment #%d does not exist.', $attachment_id));
            return;
        }

        $optimizer = new Clevers_IO_Optimizer();
        WP_CLI::line(sprintf('Optimizing attachment #%d (%s)...', $attachment_id, get_the_title($attachment_id)));

        $optimizer->optimize_attachment($attachment_id);
        WP_CLI::success(sprintf('Attachment #%d optimized successfully.', $attachment_id));
    }

    /**
     * Enqueue all media library image attachments for optimization.
     *
     * ## OPTIONS
     *
     * [--batch-size=<n>]
     * : Number of attachments per query page (default: 500).
     *
     * ## EXAMPLES
     *
     *     wp clevers-io queue-all
     *
     * @when after_wp_load
     */
    public function queue_all($args, $assoc_args)
    {
        global $wpdb;
        $optimizer = new Clevers_IO_Optimizer();

        $ids = $wpdb->get_col("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_mime_type IN ('image/jpeg', 'image/png', 'image/webp')
            ORDER BY ID ASC
        ");

        if (empty($ids)) {
            WP_CLI::warning('No image attachments found in the media library.');
            return;
        }

        $ids = array_map('intval', (array) $ids);
        $total = count($ids);
        WP_CLI::line(sprintf('Found %d image attachments. Enqueuing...', $total));

        $optimizer->enqueue_attachments($ids);
        WP_CLI::success(sprintf('Successfully queued %d attachments. Total in queue: %d.', $total, $optimizer->get_queue_count()));
    }

    /**
     * Recompress existing WebP images safely.
     *
     * Scans specified attachment or recent attachments, checks existing WebP files,
     * and recompresses them safely with backup and size check.
     *
     * ## OPTIONS
     *
     * [--attachment-id=<id>]
     * : Specific attachment ID to target.
     *
     * [--limit=<n>]
     * : Maximum number of attachments to inspect (default: 100, 0 for all).
     *
     * ## EXAMPLES
     *
     *     wp clevers-io recompress-webp --attachment-id=542
     *     wp clevers-io recompress-webp --limit=50
     *
     * @when after_wp_load
     */
    public function recompress_webp($args, $assoc_args)
    {
        $optimizer = new Clevers_IO_Optimizer();

        if (isset($assoc_args['attachment-id'])) {
            $attachment_ids = [absint($assoc_args['attachment-id'])];
        } else {
            global $wpdb;
            $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 100;
            $limit_sql = $limit > 0 ? "LIMIT {$limit}" : '';

            $attachment_ids = $wpdb->get_col("
                SELECT ID FROM {$wpdb->posts}
                WHERE post_type = 'attachment'
                AND (post_mime_type LIKE 'image/%')
                ORDER BY ID DESC
                {$limit_sql}
            ");
            $attachment_ids = array_map('intval', (array) $attachment_ids);
        }

        if (empty($attachment_ids)) {
            WP_CLI::warning('No attachments found matching criteria.');
            return;
        }

        $total = count($attachment_ids);
        WP_CLI::line(sprintf('Inspecting %d attachment(s) for WebP recompression...', $total));

        $recompressed = 0;
        $skipped = 0;
        $bytes_saved = 0;

        $progress = WP_CLI\Utils\make_progress_bar('Recompressing WebP', $total);

        foreach ($attachment_ids as $id) {
            $file = get_attached_file($id);
            if (!$file || !file_exists($file)) {
                $progress->tick();
                $skipped++;
                continue;
            }

            $webp_path = Clevers_IO_Utils::get_webp_path($file);
            if (file_exists($webp_path)) {
                $size_before = filesize($webp_path);
                $result = $optimizer->recompress_webp($webp_path);
                if ($result && file_exists($webp_path)) {
                    $size_after = filesize($webp_path);
                    if ($size_after < $size_before) {
                        $recompressed++;
                        $bytes_saved += ($size_before - $size_after);
                    } else {
                        $skipped++;
                    }
                } else {
                    $skipped++;
                }
            } else {
                $skipped++;
            }

            $progress->tick();
        }

        $progress->finish();
        $formatted_bytes = function_exists('size_format') ? size_format($bytes_saved, 2) : $bytes_saved . ' bytes';
        WP_CLI::success(sprintf(
            'Recompression finished. %d recompressed, %d skipped/unchanged. Bytes saved: %s.',
            $recompressed,
            $skipped,
            $formatted_bytes
        ));
    }
}
