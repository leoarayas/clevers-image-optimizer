<?php

namespace {
    use PHPUnit\Framework\TestCase;

    if (!class_exists('WP_CLI')) {
        class WP_CLI
        {
            public static array $lines = [];
            public static array $successes = [];
            public static array $warnings = [];
            public static array $errors = [];

            public static function reset(): void
            {
                self::$lines = [];
                self::$successes = [];
                self::$warnings = [];
                self::$errors = [];
            }

            public static function line($message)
            {
                self::$lines[] = (string) $message;
            }

            public static function success($message)
            {
                self::$successes[] = (string) $message;
            }

            public static function warning($message)
            {
                self::$warnings[] = (string) $message;
            }

            public static function error($message)
            {
                self::$errors[] = (string) $message;
            }

            public static function add_command($name, $callable)
            {
            }
        }
    }
}

namespace WP_CLI\Utils {
    if (!function_exists('WP_CLI\Utils\format_items')) {
        function format_items($format, $items, $fields)
        {
        }
    }

    if (!function_exists('WP_CLI\Utils\make_progress_bar')) {
        function make_progress_bar($message, $count)
        {
            return new class {
                public function tick($n = 1)
                {
                }
                public function finish()
                {
                }
            };
        }
    }
}

namespace {
    use PHPUnit\Framework\TestCase;

    require_once dirname(__DIR__) . '/includes/class-cio-cli.php';

    final class CIOCLITest extends TestCase
    {
        private Clevers_IO_CLI $cli;

        protected function setUp(): void
        {
            WP_CLI::reset();
            $this->cli = new Clevers_IO_CLI();
            $GLOBALS['mock_options'] = [];
        }

        public function test_status_displays_header_and_settings(): void
        {
            $this->cli->status([], []);

            $this->assertNotEmpty(WP_CLI::$lines);
            $joined = implode("\n", WP_CLI::$lines);
            $this->assertStringContainsString('Clevers Image Optimizer Status', $joined);
            $this->assertStringContainsString('Queue size:', $joined);
            $this->assertStringContainsString('WebP generation:', $joined);
        }

        public function test_process_queue_reports_when_empty(): void
        {
            $this->cli->process_queue([], []);

            $this->assertNotEmpty(WP_CLI::$successes);
            $this->assertStringContainsString('Optimization queue is already empty', WP_CLI::$successes[0]);
        }

        public function test_optimize_attachment_validates_numeric_id(): void
        {
            $this->cli->optimize_attachment([], []);

            $this->assertNotEmpty(WP_CLI::$errors);
            $this->assertStringContainsString('Please provide a valid numeric attachment ID', WP_CLI::$errors[0]);
        }

        public function test_recompress_webp_handles_non_existent_attachment(): void
        {
            $this->cli->recompress_webp([], ['attachment-id' => 999999]);

            $this->assertNotEmpty(WP_CLI::$successes);
            $this->assertStringContainsString('Recompression finished', WP_CLI::$successes[0]);
        }
    }
}
