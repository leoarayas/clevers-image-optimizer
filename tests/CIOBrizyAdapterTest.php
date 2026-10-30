<?php

use PHPUnit\Framework\TestCase;

final class CIOBrizyAdapterTest extends TestCase
{
    private $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/cio-brizy-' . uniqid('', true);
        mkdir($this->directory . '/2026/05', 0777, true);
        file_put_contents($this->directory . '/2026/05/photo.webp', 'webp');
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/2026/05/photo.webp');
        @rmdir($this->directory . '/2026/05');
        @rmdir($this->directory . '/2026');
        @rmdir($this->directory);
    }

    public function test_replaces_local_image_url_when_sidecar_exists(): void
    {
        $adapter = $this->adapter();
        $content = '<img src="https://example.test/wp-content/uploads/2026/05/photo.jpg?ver=1">';

        $result = $adapter->filter_compiled_content($content);

        $this->assertSame('<img src="https://example.test/wp-content/uploads/2026/05/photo.webp?ver=1">', $result);
    }

    public function test_preserves_local_image_url_when_sidecar_is_missing(): void
    {
        $adapter = $this->adapter();
        $content = 'url(/wp-content/uploads/2026/05/missing.png)';

        $this->assertSame($content, $adapter->filter_compiled_content($content));
    }

    public function test_does_not_replace_external_image_url(): void
    {
        $adapter = $this->adapter();
        $content = '<img src="https://cdn.example.test/wp-content/uploads/2026/05/photo.jpg">';

        $this->assertSame($content, $adapter->filter_compiled_content($content));
    }

    private function adapter(): Clevers_IO_Brizy_Adapter
    {
        return new Clevers_IO_Brizy_Adapter([
            'basedir' => $this->directory,
            'baseurl' => 'https://example.test/wp-content/uploads',
            'site_url' => 'https://example.test/',
        ]);
    }
}
