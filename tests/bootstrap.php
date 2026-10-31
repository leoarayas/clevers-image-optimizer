<?php

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!function_exists('add_filter')) {
    function add_filter(...$args)
    {
        return null;
    }
}

if (!function_exists('add_action')) {
    function add_action(...$args)
    {
        return null;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(...$args)
    {
        return null;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(...$args)
    {
        return null;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(...$args)
    {
        return null;
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        global $mock_options;
        if (is_array($mock_options) && array_key_exists($option, $mock_options)) {
            return $mock_options[$option];
        }
        return $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value)
    {
        global $mock_options;
        if (!is_array($mock_options)) {
            $mock_options = [];
        }
        $mock_options[$option] = $value;
        return true;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(...$args)
    {
        return null;
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event(...$args)
    {
        return null;
    }
}

if (!function_exists('wp_get_attachment_metadata')) {
    function wp_get_attachment_metadata(...$args)
    {
        return null;
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir(...$args)
    {
        return null;
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(...$args)
    {
        return null;
    }
}

if (!function_exists('absint')) {
    function absint($maybeint)
    {
        return abs((int) $maybeint);
    }
}

if (!function_exists('get_attached_file')) {
    function get_attached_file($attachment_id, $unfiltered = false)
    {
        return '';
    }
}

if (!function_exists('get_post')) {
    function get_post($post = null, $output = 'OBJECT', $filter = 'raw')
    {
        return null;
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title($post = 0)
    {
        return '';
    }
}

if (!function_exists('wp_is_writable')) {
    function wp_is_writable($path)
    {
        return is_writable($path);
    }
}

if (!function_exists('is_executable')) {
    function is_executable($path)
    {
        return function_exists('is_executable') ? \is_executable($path) : false;
    }
}

if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null)
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = null)
    {
        echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr_e')) {
    function esc_attr_e($text, $domain = null)
    {
        echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit($value)
    {
        return rtrim($value, '/\\') . '/';
    }
}

require_once dirname(__DIR__) . '/includes/class-cio-utils.php';
require_once dirname(__DIR__) . '/includes/class-cio-logger.php';
require_once dirname(__DIR__) . '/includes/class-cio-optimizer.php';
require_once dirname(__DIR__) . '/includes/class-cio-diagnostics.php';
require_once dirname(__DIR__) . '/includes/nginx.php';
require_once dirname(__DIR__) . '/includes/class-cio-brizy-adapter.php';
