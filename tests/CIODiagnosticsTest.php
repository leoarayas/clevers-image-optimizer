<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-cio-diagnostics.php';

final class CIODiagnosticsTest extends TestCase
{
    private static array $originalEnv = [];

    public static function setUpBeforeClass(): void
    {
        self::$originalEnv = [
            'PATH' => getenv('PATH') ?: '',
            'DISALLOWED_FUNCTIONS' => getenv('CIO_TEST_DISALLOWED') ?: '',
        ];
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$originalEnv as $key => $value) {
            putenv($key . '=' . $value);
        }
    }

    public function test_run_diagnostics_returns_array_of_checks(): void
    {
        $checks = Clevers_IO_Diagnostics::run_diagnostics();

        $this->assertIsArray($checks);
        $this->assertNotEmpty($checks);

        foreach ($checks as $check) {
            $this->assertArrayHasKey('id', $check);
            $this->assertArrayHasKey('label', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('message', $check);
            $this->assertContains($check['status'], ['ok', 'warning', 'error']);
        }
    }

    public function test_includes_required_checks(): void
    {
        $checks = Clevers_IO_Diagnostics::run_diagnostics();
        $ids = array_column($checks, 'id');

        $this->assertContains('php-gd', $ids);
        $this->assertContains('php-imagick', $ids);
        $this->assertContains('proc-open', $ids);
        $this->assertContains('uploads-writable', $ids);
        $this->assertContains('binary-jpegoptim', $ids);
        $this->assertContains('binary-pngquant', $ids);
        $this->assertContains('binary-cwebp', $ids);
    }

    public function test_php_gd_check_reports_status_with_webp_support(): void
    {
        putenv('CIO_TEST_GD_LOADED=true');
        putenv('CIO_TEST_GD_WEBP=true');

        $checks = Clevers_IO_Diagnostics::run_diagnostics();
        $gd = $this->findCheck($checks, 'php-gd');

        $this->assertSame('ok', $gd['status']);
    }

    public function test_php_imagick_check_warns_when_extension_missing(): void
    {
        putenv('CIO_TEST_IMAGICK_LOADED=false');

        $checks = Clevers_IO_Diagnostics::run_diagnostics();
        $imagick = $this->findCheck($checks, 'php-imagick');

        $this->assertContains($imagick['status'], ['warning', 'error']);
    }

    public function test_uploads_writable_check_fails_when_path_not_writable(): void
    {
        putenv('CIO_TEST_UPLOADS_PATH=/nonexistent/path/that/does/not/exist');

        $checks = Clevers_IO_Diagnostics::run_diagnostics();
        $uploads = $this->findCheck($checks, 'uploads-writable');

        $this->assertSame('error', $uploads['status']);
    }

    public function test_binary_check_uses_path_lookup(): void
    {
        putenv('CIO_TEST_BINARY_BINARY_JPEGOPTIM=true');

        $checks = Clevers_IO_Diagnostics::run_diagnostics();
        $jpegoptim = $this->findCheck($checks, 'binary-jpegoptim');

        $this->assertSame('ok', $jpegoptim['status']);
    }

    public function test_statuses_aggregate_to_summary(): void
    {
        $checks = Clevers_IO_Diagnostics::run_diagnostics();
        $summary = Clevers_IO_Diagnostics::summarize($checks);

        $this->assertArrayHasKey('ok', $summary);
        $this->assertArrayHasKey('warning', $summary);
        $this->assertArrayHasKey('error', $summary);
        $this->assertGreaterThanOrEqual(0, $summary['ok']);
        $this->assertGreaterThanOrEqual(0, $summary['warning']);
        $this->assertGreaterThanOrEqual(0, $summary['error']);
    }

    private function findCheck(array $checks, string $id): array
    {
        foreach ($checks as $check) {
            if ($check['id'] === $id) {
                return $check;
            }
        }

        $this->fail("Check with id '{$id}' not found.");
    }
}
