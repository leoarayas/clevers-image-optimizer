<?php

use PHPUnit\Framework\TestCase;

final class CIOPictureRewriterTest extends TestCase
{
    private $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/cio-picture-' . uniqid('', true);
        mkdir($this->directory . '/2026/05', 0777, true);
        file_put_contents($this->directory . '/2026/05/photo.webp', 'webp-content');
        file_put_contents($this->directory . '/2026/05/photo-300x200.webp', 'webp-thumb-content');
        file_put_contents($this->directory . '/2026/05/photo.avif', 'avif-content');
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/2026/05/photo.webp');
        @unlink($this->directory . '/2026/05/photo-300x200.webp');
        @unlink($this->directory . '/2026/05/photo.avif');
        @rmdir($this->directory . '/2026/05');
        @rmdir($this->directory . '/2026');
        @rmdir($this->directory);
    }

    public function test_wraps_image_in_picture_when_webp_sidecar_exists(): void
    {
        $rewriter = $this->rewriter();
        $html = '<img src="https://example.test/wp-content/uploads/2026/05/photo.jpg" alt="Test Photo" class="aligncenter" width="800" height="600" loading="lazy">';

        $result = $rewriter->filter_content($html);

        $expected = '<picture><source type="image/webp" srcset="https://example.test/wp-content/uploads/2026/05/photo.webp"><img src="https://example.test/wp-content/uploads/2026/05/photo.jpg" alt="Test Photo" class="aligncenter" width="800" height="600" loading="lazy"></picture>';
        $this->assertSame($expected, $result);
    }

    public function test_wraps_srcset_and_sizes_when_available(): void
    {
        $rewriter = $this->rewriter();
        $html = '<img src="https://example.test/wp-content/uploads/2026/05/photo.jpg" srcset="https://example.test/wp-content/uploads/2026/05/photo-300x200.jpg 300w, https://example.test/wp-content/uploads/2026/05/photo.jpg 800w" sizes="(max-width: 800px) 100vw, 800px" alt="Responsive">';

        $result = $rewriter->filter_content($html);

        $this->assertStringStartsWith('<picture>', $result);
        $this->assertStringEndsWith('</picture>', $result);
        $this->assertStringContainsString('<source type="image/webp" srcset="https://example.test/wp-content/uploads/2026/05/photo-300x200.webp 300w, https://example.test/wp-content/uploads/2026/05/photo.webp 800w" sizes="(max-width: 800px) 100vw, 800px">', $result);
        $this->assertStringContainsString($html, $result);
    }

    public function test_includes_avif_source_when_avif_enabled_and_file_exists(): void
    {
        $rewriter = $this->rewriter(['avif_enabled' => true]);
        $html = '<img src="https://example.test/wp-content/uploads/2026/05/photo.jpg" alt="AVIF Test">';

        $result = $rewriter->filter_content($html);

        $this->assertStringContainsString('<source type="image/avif" srcset="https://example.test/wp-content/uploads/2026/05/photo.avif">', $result);
        $this->assertStringContainsString('<source type="image/webp" srcset="https://example.test/wp-content/uploads/2026/05/photo.webp">', $result);
    }

    public function test_does_not_wrap_if_already_inside_picture(): void
    {
        $rewriter = $this->rewriter();
        $html = '<picture><source type="image/webp" srcset="https://example.test/wp-content/uploads/2026/05/photo.webp"><img src="https://example.test/wp-content/uploads/2026/05/photo.jpg" alt="Already Picture"></picture>';

        $result = $rewriter->filter_content($html);

        $this->assertSame($html, $result);
    }

    public function test_does_not_wrap_if_sidecar_does_not_exist(): void
    {
        $rewriter = $this->rewriter();
        $html = '<img src="https://example.test/wp-content/uploads/2026/05/missing.png" alt="Missing Sidecar">';

        $result = $rewriter->filter_content($html);

        $this->assertSame($html, $result);
    }

    public function test_does_not_wrap_external_images(): void
    {
        $rewriter = $this->rewriter();
        $html = '<img src="https://cdn.otherdomain.test/uploads/photo.jpg" alt="External">';

        $result = $rewriter->filter_content($html);

        $this->assertSame($html, $result);
    }

    public function test_does_not_wrap_already_webp_images(): void
    {
        $rewriter = $this->rewriter();
        $html = '<img src="https://example.test/wp-content/uploads/2026/05/photo.webp" alt="Already WebP">';

        $result = $rewriter->filter_content($html);

        $this->assertSame($html, $result);
    }

    public function test_filter_attachment_image_and_post_thumbnail(): void
    {
        $rewriter = $this->rewriter();
        $html = '<img src="https://example.test/wp-content/uploads/2026/05/photo.jpg" class="attachment-post-thumbnail size-post-thumbnail wp-post-image" alt="Thumbnail">';

        $result1 = $rewriter->filter_attachment_image($html, 42);
        $result2 = $rewriter->filter_post_thumbnail($html, 10, 42);

        $this->assertStringStartsWith('<picture>', $result1);
        $this->assertSame($result1, $result2);
    }

    private function rewriter(array $extra_config = []): Clevers_IO_Picture_Rewriter
    {
        return new Clevers_IO_Picture_Rewriter(array_merge([
            'basedir' => $this->directory,
            'baseurl' => 'https://example.test/wp-content/uploads',
            'site_url' => 'https://example.test/',
            'enabled' => true,
            'avif_enabled' => false,
        ], $extra_config));
    }
}
