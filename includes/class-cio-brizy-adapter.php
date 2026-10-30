<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reemplaza en el HTML compilado de Brizy las URLs locales JPG/PNG por sus
 * sidecars WebP existentes. Se ejecuta al renderizar, por lo que no altera
 * GUIDs, post_content ni la estructura interna de Brizy.
 */
class Clevers_IO_Brizy_Adapter
{
    private $uploads;
    private $site_host;
    private $replacement_cache = [];

    /**
     * @param array|null $config Override para tests.
     */
    public function __construct($config = null)
    {
        if ($config === null) {
            $config = wp_upload_dir();
            $config['site_url'] = home_url('/');
        }

        $this->uploads = [
            'basedir' => isset($config['basedir']) ? (string) $config['basedir'] : '',
            'baseurl' => isset($config['baseurl']) ? (string) $config['baseurl'] : '',
        ];
        $site_url = isset($config['site_url']) ? (string) $config['site_url'] : $this->uploads['baseurl'];
        $this->site_host = (string) parse_url($site_url, PHP_URL_HOST);
    }

    public function register()
    {
        add_filter('brizy_content', [$this, 'filter_compiled_content'], 20, 4);
    }

    /**
     * @param string $content HTML, CSS o JSON compilado por Brizy.
     * @return string
     */
    public function filter_compiled_content($content)
    {
        if (!is_string($content) || $content === '' || strpos($content, '/wp-content/uploads/') === false) {
            return $content;
        }

        return (string) preg_replace_callback(
            '#(?P<url>(?:(?:https?:)?//[^\s"\'()]+)?/wp-content/uploads/[^\s"\'(),<>]+?\.(?:jpe?g|png))(?P<suffix>(?:\?[^\s"\'(),<>]*)?(?:\#[^\s"\'(),<>]*)?)#i',
            [$this, 'replace_url_with_webp_sidecar'],
            $content
        );
    }

    /**
     * @param array $match
     * @return string
     */
    public function replace_url_with_webp_sidecar($match)
    {
        $url = $match['url'];
        $suffix = isset($match['suffix']) ? $match['suffix'] : '';

        if (isset($this->replacement_cache[$url])) {
            return $this->replacement_cache[$url] . $suffix;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $host = parse_url($url, PHP_URL_HOST);
        if (!$path || ($host && $this->site_host && strcasecmp($host, $this->site_host) !== 0)) {
            return $url . $suffix;
        }

        $base_path = (string) parse_url($this->uploads['baseurl'], PHP_URL_PATH);
        $base_path = rtrim($base_path, '/');
        if ($base_path === '' || strpos($path, $base_path . '/') !== 0) {
            return $url . $suffix;
        }

        $relative = ltrim(substr($path, strlen($base_path)), '/');
        $candidate = rtrim($this->uploads['basedir'], '/\\') . '/' . preg_replace('/\.(?:jpe?g|png)$/i', '.webp', rawurldecode($relative));
        if (!is_file($candidate)) {
            return $url . $suffix;
        }

        $this->replacement_cache[$url] = preg_replace('/\.(?:jpe?g|png)$/i', '.webp', $url);

        return $this->replacement_cache[$url] . $suffix;
    }
}
