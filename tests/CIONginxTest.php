<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/nginx.php';

final class CIONginxTest extends TestCase
{
    public function test_detects_nginx_from_server_software_header(): void
    {
        $this->assertTrue(clevers_io_is_nginx('nginx/1.25.1'));
        $this->assertTrue(clevers_io_is_nginx('nginx'));
        $this->assertFalse(clevers_io_is_nginx('Apache/2.4.57 (Ubuntu)'));
        $this->assertFalse(clevers_io_is_nginx('LiteSpeed/6.7'));
        $this->assertFalse(clevers_io_is_nginx(''));
    }

    public function test_get_nginx_config_block_returns_complete_snippet(): void
    {
        $block = clevers_io_get_nginx_config_block();

        $this->assertIsString($block);
        $this->assertStringContainsString('map $http_accept', $block);
        $this->assertStringContainsString('image/webp', $block);
        $this->assertStringContainsString('image/avif', $block);
        $this->assertStringContainsString('try_files', $block);
        $this->assertStringContainsString('location ~', $block);
    }

    public function test_get_nginx_config_block_has_documentation_comments(): void
    {
        $block = clevers_io_get_nginx_config_block();

        $this->assertStringContainsString('#', $block);
    }

    public function test_get_nginx_map_block_returns_separate_snippet(): void
    {
        $map = clevers_io_get_nginx_map_block();

        $this->assertIsString($map);
        $this->assertStringContainsString('map $http_accept', $map);
        $this->assertStringContainsString('clever_image_suffix', $map);
    }

    public function test_get_nginx_location_block_returns_separate_snippet(): void
    {
        $location = clevers_io_get_nginx_location_block();

        $this->assertIsString($location);
        $this->assertStringContainsString('location ~*', $location);
        $this->assertStringContainsString('try_files', $location);
        $this->assertStringContainsString('add_header Vary', $location);
    }
}
