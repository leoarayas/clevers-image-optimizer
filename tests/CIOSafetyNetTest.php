<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-cio-safety-net.php';

ini_set('memory_limit', '512M');

final class CIOSafetyNetTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cio-safety-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    public function test_should_intervene_returns_false_when_feature_disabled(): void
    {
        $safety = new Clevers_IO_Safety_Net(['enabled' => false]);

        $this->assertFalse($safety->should_intervene([]));
    }

    public function test_should_intervene_returns_false_for_non_image_files(): void
    {
        $safety = new Clevers_IO_Safety_Net(['enabled' => true]);

        $this->assertFalse($safety->should_intervene([
            'type' => 'application/pdf',
            'name' => 'document.pdf',
            'tmp_name' => '/tmp/doc',
        ]));
        $this->assertFalse($safety->should_intervene([
            'type' => 'image/svg+xml',
            'name' => 'vector.svg',
            'tmp_name' => '/tmp/vector',
        ]));
    }

    public function test_should_intervene_returns_true_for_jpeg_when_enabled(): void
    {
        $safety = new Clevers_IO_Safety_Net(['enabled' => true]);

        $this->assertTrue($safety->should_intervene([
            'type' => 'image/jpeg',
            'name' => 'photo.jpg',
            'tmp_name' => '/tmp/photo',
        ]));
    }

    public function test_max_dimension_default_is_2560(): void
    {
        $safety = new Clevers_IO_Safety_Net(['enabled' => true]);

        $this->assertSame(2560, $safety->get_max_dimension());
    }

    public function test_max_dimension_uses_option_when_provided(): void
    {
        $safety = new Clevers_IO_Safety_Net([
            'enabled' => true,
            'max_dimension' => 1920,
        ]);

        $this->assertSame(1920, $safety->get_max_dimension());
    }

    public function test_max_dimension_clamps_to_minimum_500(): void
    {
        $safety = new Clevers_IO_Safety_Net([
            'enabled' => true,
            'max_dimension' => 100,
        ]);

        $this->assertSame(500, $safety->get_max_dimension());
    }

    public function test_resize_file_returns_input_unchanged_when_image_smaller_than_max(): void
    {
        $source = $this->createTestJpeg(800, 600);
        $safety = new Clevers_IO_Safety_Net(['enabled' => true, 'max_dimension' => 2560]);

        $result = $safety->resize_file($source);

        $this->assertFileExists($result);
        [$width, $height] = getimagesize($result);
        $this->assertSame(800, $width);
        $this->assertSame(600, $height);
    }

    public function test_resize_file_scales_down_oversized_image(): void
    {
        $source = $this->createTestJpeg(3840, 2560);
        $safety = new Clevers_IO_Safety_Net(['enabled' => true, 'max_dimension' => 1920]);

        $result = $safety->resize_file($source);

        [$width, $height] = getimagesize($result);
        $this->assertSame(1920, $width);
        $this->assertSame(1280, $height);
    }

    public function test_resize_file_returns_false_when_source_missing(): void
    {
        $safety = new Clevers_IO_Safety_Net(['enabled' => true]);

        $this->assertFalse($safety->resize_file('/nonexistent/file.jpg'));
    }

    public function test_filter_upload_returns_array_unchanged_when_disabled(): void
    {
        $safety = new Clevers_IO_Safety_Net(['enabled' => false]);
        $original = ['type' => 'image/jpeg', 'name' => 'a.jpg', 'tmp_name' => '/tmp/a'];

        $result = $safety->filter_upload($original);

        $this->assertSame($original, $result);
    }

    private function createTestJpeg(int $width, int $height): string
    {
        $path = $this->tmpDir . '/' . $width . 'x' . $height . '.jpg';
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 100, 150, 200));
        imagejpeg($image, $path, 85);
        unset($image);

        return $path;
    }
}
