<?php

use PHPUnit\Framework\TestCase;

final class CIOOptimizerTest extends TestCase
{
    public function test_sanitize_quality_bounds_values(): void
    {
        $optimizer = new Clevers_IO_Optimizer();

        $this->assertSame(0, $optimizer->sanitize_quality(-10));
        $this->assertSame(80, $optimizer->sanitize_quality(80));
        $this->assertSame(100, $optimizer->sanitize_quality(999));
    }

    public function test_normalize_attachment_id_list_converts_negatives_and_removes_zeros(): void
    {
        $optimizer = new Clevers_IO_Optimizer();

        $ids = $optimizer->normalize_attachment_id_list([10, '20', 0, -5, 10, 'abc']);
        $this->assertSame([10, 20, 5], $ids);

        $ids_negative = $optimizer->normalize_attachment_id_list([-3, -7, 3]);
        $this->assertContains(3, $ids_negative);
        $this->assertContains(7, $ids_negative);

        $ids_zeros = $optimizer->normalize_attachment_id_list([0, 0, 5]);
        $this->assertNotContains(0, $ids_zeros);
        $this->assertSame([5], $ids_zeros);
    }

    public function test_is_supported_image_path_accepts_raster_image_formats(): void
    {
        $optimizer = new Clevers_IO_Optimizer();

        $this->assertTrue($optimizer->is_supported_image_path('/tmp/photo.jpg'));
        $this->assertTrue($optimizer->is_supported_image_path('/tmp/photo.JPEG'));
        $this->assertTrue($optimizer->is_supported_image_path('/tmp/photo.png'));
        $this->assertTrue($optimizer->is_supported_image_path('/tmp/photo.webp'));
        $this->assertFalse($optimizer->is_supported_image_path('/tmp/photo.gif'));
    }

    public function test_resize_file_scales_down_webp_and_preserves_format(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD no incluye soporte WebP.');
        }

        $path = tempnam(sys_get_temp_dir(), 'cio-webp-');
        $image = imagecreatetruecolor(1600, 1200);
        imagefill($image, 0, 0, imagecolorallocate($image, 100, 150, 200));
        imagewebp($image, $path, 85);
        if (PHP_VERSION_ID < 80500) {
            imagedestroy($image);
        }

        try {
            $optimizer = new Clevers_IO_Optimizer();

            $this->assertTrue($optimizer->resize_file($path, 1200));
            [$width, $height, $type] = getimagesize($path);
            $this->assertSame(1200, $width);
            $this->assertSame(900, $height);
            $this->assertSame(IMAGETYPE_WEBP, $type);
        } finally {
            @unlink($path);
        }
    }

    public function test_is_recompress_webp_enabled_returns_boolean_according_to_option(): void
    {
        $optimizer = new Clevers_IO_Optimizer();

        update_option(Clevers_IO_Optimizer::OPTION_RECOMPRESS_WEBP, '0');
        $this->assertFalse($optimizer->is_recompress_webp_enabled());

        update_option(Clevers_IO_Optimizer::OPTION_RECOMPRESS_WEBP, '1');
        $this->assertTrue($optimizer->is_recompress_webp_enabled());
    }

    public function test_recompress_webp_creates_backup_and_replaces_when_candidate_is_smaller(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD no incluye soporte WebP.');
        }

        $path = tempnam(sys_get_temp_dir(), 'cio-recompress-') . '.webp';
        $bak_path = $path . '.bak';

        // Generar una imagen con ruido/gradiente en calidad 98 para que pese bastante
        $image = imagecreatetruecolor(600, 400);
        for ($x = 0; $x < 600; $x += 10) {
            for ($y = 0; $y < 400; $y += 10) {
                $color = imagecolorallocate($image, ($x * 3) % 255, ($y * 5) % 255, ($x + $y) % 255);
                imagefilledrectangle($image, $x, $y, $x + 10, $y + 10, $color);
            }
        }
        imagewebp($image, $path, 98);
        if (PHP_VERSION_ID < 80500) {
            imagedestroy($image);
        }

        $original_size = filesize($path);
        $this->assertGreaterThan(0, $original_size);

        // Calidad baja para que el archivo candidato sea notoriamente menor
        update_option('clevers_io_webp_quality', 30);

        try {
            $optimizer = new Clevers_IO_Optimizer();
            $replaced  = $optimizer->recompress_webp($path);

            $this->assertTrue($replaced);
            $this->assertFileExists($bak_path);
            $this->assertSame($original_size, filesize($bak_path));

            clearstatcache(true, $path);
            $new_size = filesize($path);
            $this->assertLessThan($original_size, $new_size);
        } finally {
            @unlink($path);
            @unlink($bak_path);
            update_option('clevers_io_webp_quality', 80);
        }
    }

    public function test_recompress_webp_preserves_original_and_does_not_backup_when_not_smaller(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD no incluye soporte WebP.');
        }

        $path = tempnam(sys_get_temp_dir(), 'cio-keep-') . '.webp';
        $bak_path = $path . '.bak';

        // Generar una imagen con gradiente en calidad baja (20)
        $image = imagecreatetruecolor(400, 400);
        for ($x = 0; $x < 400; $x += 10) {
            for ($y = 0; $y < 400; $y += 10) {
                $color = imagecolorallocate($image, ($x * 3) % 255, ($y * 5) % 255, ($x + $y) % 255);
                imagefilledrectangle($image, $x, $y, $x + 10, $y + 10, $color);
            }
        }
        imagewebp($image, $path, 20);
        if (PHP_VERSION_ID < 80500) {
            imagedestroy($image);
        }

        $original_size = filesize($path);

        // Calidad alta (95) para que el nuevo archivo sea necesariamente mas pesado
        update_option('clevers_io_webp_quality', 95);

        try {
            $optimizer = new Clevers_IO_Optimizer();
            $replaced  = $optimizer->recompress_webp($path);

            $this->assertFalse($replaced);
            $this->assertFileDoesNotExist($bak_path);

            clearstatcache(true, $path);
            $this->assertSame($original_size, filesize($path));
        } finally {
            @unlink($path);
            @unlink($bak_path);
            update_option('clevers_io_webp_quality', 80);
        }
    }

    public function test_recompress_webp_preserves_an_existing_first_backup(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD no incluye soporte WebP.');
        }

        $path = tempnam(sys_get_temp_dir(), 'cio-existing-backup-') . '.webp';
        $bak_path = $path . '.bak';
        $image = imagecreatetruecolor(500, 300);
        for ($x = 0; $x < 500; $x += 10) {
            for ($y = 0; $y < 300; $y += 10) {
                $color = imagecolorallocate($image, ($x * 7) % 255, ($y * 11) % 255, ($x + $y * 3) % 255);
                imagefilledrectangle($image, $x, $y, $x + 10, $y + 10, $color);
            }
        }
        imagewebp($image, $path, 98);
        if (PHP_VERSION_ID < 80500) {
            imagedestroy($image);
        }

        $first_backup = 'first-backup-must-not-change';
        file_put_contents($bak_path, $first_backup);
        update_option('clevers_io_webp_quality', 30);

        try {
            $optimizer = new Clevers_IO_Optimizer();
            $this->assertTrue($optimizer->recompress_webp($path));
            $this->assertSame($first_backup, file_get_contents($bak_path));
        } finally {
            @unlink($path);
            @unlink($bak_path);
            update_option('clevers_io_webp_quality', 80);
        }
    }

    public function test_generate_webp_respects_recompress_option(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD no incluye soporte WebP.');
        }

        $path = tempnam(sys_get_temp_dir(), 'cio-gen-') . '.webp';
        $bak_path = $path . '.bak';

        $image = imagecreatetruecolor(300, 300);
        for ($i = 0; $i < 300; $i += 10) {
            $color = imagecolorallocate($image, $i % 255, ($i * 2) % 255, 120);
            imagefilledrectangle($image, $i, 0, $i + 10, 300, $color);
        }
        imagewebp($image, $path, 95);
        if (PHP_VERSION_ID < 80500) {
            imagedestroy($image);
        }

        $original_size = filesize($path);

        try {
            $optimizer = new Clevers_IO_Optimizer();

            // Deshabilitado: no debe tocar el archivo ni crear backup
            update_option(Clevers_IO_Optimizer::OPTION_RECOMPRESS_WEBP, '0');
            update_option('clevers_io_webp_quality', 20);
            $optimizer->generate_webp($path);

            $this->assertFileDoesNotExist($bak_path);
            clearstatcache(true, $path);
            $this->assertSame($original_size, filesize($path));

            // Habilitado: debe recomprimir y crear backup
            update_option(Clevers_IO_Optimizer::OPTION_RECOMPRESS_WEBP, '1');
            $optimizer->generate_webp($path);

            $this->assertFileExists($bak_path);
            $this->assertSame($original_size, filesize($bak_path));
            clearstatcache(true, $path);
            $this->assertLessThan($original_size, filesize($path));
        } finally {
            @unlink($path);
            @unlink($bak_path);
            update_option(Clevers_IO_Optimizer::OPTION_RECOMPRESS_WEBP, '0');
            update_option('clevers_io_webp_quality', 80);
        }
    }
}
