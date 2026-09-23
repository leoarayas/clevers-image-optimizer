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

    public function test_is_supported_image_path_accepts_jpg_jpeg_png_only(): void
    {
        $optimizer = new Clevers_IO_Optimizer();

        $this->assertTrue($optimizer->is_supported_image_path('/tmp/photo.jpg'));
        $this->assertTrue($optimizer->is_supported_image_path('/tmp/photo.JPEG'));
        $this->assertTrue($optimizer->is_supported_image_path('/tmp/photo.png'));
        $this->assertFalse($optimizer->is_supported_image_path('/tmp/photo.webp'));
        $this->assertFalse($optimizer->is_supported_image_path('/tmp/photo.gif'));
    }
}
