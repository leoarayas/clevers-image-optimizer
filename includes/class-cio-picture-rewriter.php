<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reemplaza dinámicamente etiquetas <img> por etiquetas <picture> con fuentes
 * WebP y AVIF en el frontend (the_content, wp_get_attachment_image, post_thumbnail_html,
 * widget_text_content).
 *
 * Modo fallback ideal para servidores Nginx, proxies CDN (Cloudflare) o entornos
 * donde no es posible usar reglas de reescritura en servidor (.htaccess).
 */
class Clevers_IO_Picture_Rewriter
{
    const OPTION_PICTURE_FALLBACK = 'clevers_io_picture_fallback';

    /**
     * @var array{basedir: string, baseurl: string}
     */
    private $uploads;

    /**
     * @var string
     */
    private $site_host;

    /**
     * @var bool
     */
    private $is_enabled;

    /**
     * @var bool
     */
    private $is_avif_enabled;

    /**
     * @var array<string, bool>
     */
    private $file_exists_cache = [];

    /**
     * @var array<string, string|null>
     */
    private $sidecar_cache = [];

    /**
     * @param array|null $config Override para pruebas unitarias.
     */
    public function __construct($config = null)
    {
        if ($config === null) {
            $config = wp_upload_dir();
            $config['site_url'] = function_exists('home_url') ? home_url('/') : '';
            $config['enabled'] = get_option(self::OPTION_PICTURE_FALLBACK, '0') === '1';
            $config['avif_enabled'] = get_option('clevers_io_enable_avif', '0') === '1';
        }

        $this->uploads = [
            'basedir' => isset($config['basedir']) ? (string) $config['basedir'] : '',
            'baseurl' => isset($config['baseurl']) ? (string) $config['baseurl'] : '',
        ];
        $site_url = isset($config['site_url']) ? (string) $config['site_url'] : $this->uploads['baseurl'];
        $this->site_host = (string) wp_parse_url($site_url, PHP_URL_HOST);
        $this->is_enabled = !empty($config['enabled']);
        $this->is_avif_enabled = !empty($config['avif_enabled']);
    }

    /**
     * Registra los filtros de contenido si la opción está activa.
     */
    public function register(): void
    {
        if (!$this->is_enabled) {
            return;
        }

        add_filter('the_content', [$this, 'filter_content'], 20);
        add_filter('wp_get_attachment_image', [$this, 'filter_attachment_image'], 20, 5);
        add_filter('post_thumbnail_html', [$this, 'filter_post_thumbnail'], 20, 5);
        add_filter('widget_text_content', [$this, 'filter_content'], 20);
    }

    /**
     * Filtro para the_content y texto de widgets.
     *
     * @param mixed $content
     * @return mixed
     */
    public function filter_content($content)
    {
        if (!is_string($content) || $content === '' || strpos($content, '<img') === false) {
            return $content;
        }

        if (function_exists('is_feed') && is_feed()) {
            return $content;
        }

        return $this->rewrite_html($content);
    }

    /**
     * Filtro para wp_get_attachment_image.
     *
     * @param mixed $html
     * @param int|string $attachment_id
     * @param mixed $size
     * @param bool $icon
     * @param mixed $attr
     * @return mixed
     */
    public function filter_attachment_image($html, $attachment_id = 0, $size = '', $icon = false, $attr = '')
    {
        if (!is_string($html) || $html === '' || strpos($html, '<img') === false) {
            return $html;
        }

        if (function_exists('is_feed') && is_feed()) {
            return $html;
        }

        return $this->rewrite_html($html);
    }

    /**
     * Filtro para post_thumbnail_html.
     *
     * @param mixed $html
     * @param int|string $post_id
     * @param int|string $post_thumbnail_id
     * @param mixed $size
     * @param mixed $attr
     * @return mixed
     */
    public function filter_post_thumbnail($html, $post_id = 0, $post_thumbnail_id = 0, $size = '', $attr = '')
    {
        return $this->filter_attachment_image($html);
    }

    /**
     * Reescribe las etiquetas <img> en el HTML por <picture> cuando existen sidecars WebP/AVIF.
     *
     * @param string $html
     * @return string
     */
    public function rewrite_html(string $html): string
    {
        if (strpos($html, '<img') === false) {
            return $html;
        }

        // Empareja <picture>...</picture> para no intervenir imágenes ya contenidas en picture,
        // o etiquetas <img ...> independientes.
        $pattern = '/(<picture\b[^>]*>[\s\S]*?<\/picture>)|(<img\b[^>]*>)/i';

        return (string) preg_replace_callback($pattern, [$this, 'process_match'], $html);
    }

    /**
     * Callback para regex de coincidencia.
     *
     * @param array<int, string> $match
     * @return string
     */
    public function process_match(array $match): string
    {
        // Si coincidió con un bloque <picture>...</picture>, mantenerlo intacto
        if (!empty($match[1])) {
            return $match[1];
        }

        $img_tag = $match[2];

        // Extraer src
        if (!preg_match('/\bsrc=(["\'])([^"\']+)\1/i', $img_tag, $src_matches)) {
            return $img_tag;
        }

        $src_url = $src_matches[2];

        // Ignorar si ya es WebP, AVIF o formato no soportado (svg, gif)
        if (preg_match('/\.(?:webp|avif|svg|gif)(?:\?.*)?$/i', $src_url)) {
            return $img_tag;
        }

        // Extraer srcset y sizes si existen
        $has_srcset = (bool) preg_match('/\bsrcset=(["\'])([^"\']+)\1/i', $img_tag, $srcset_matches);
        $srcset_raw = $has_srcset ? $srcset_matches[2] : '';

        $has_sizes = (bool) preg_match('/\bsizes=(["\'])([^"\']+)\1/i', $img_tag, $sizes_matches);
        $sizes_attr = $has_sizes ? ' sizes="' . esc_attr($sizes_matches[2]) . '"' : '';

        // Generar candidate srcset para WebP
        $webp_srcset = $this->build_source_srcset($src_url, $srcset_raw, 'webp');
        if ($webp_srcset === null) {
            // No existe sidecar WebP en disco para esta imagen
            return $img_tag;
        }

        $sources_html = '';

        // Si AVIF está habilitado, intentar generar fuente AVIF
        if ($this->is_avif_enabled) {
            $avif_srcset = $this->build_source_srcset($src_url, $srcset_raw, 'avif');
            if ($avif_srcset !== null) {
                $sources_html .= '<source type="image/avif" srcset="' . esc_attr($avif_srcset) . '"' . $sizes_attr . '>';
            }
        }

        $sources_html .= '<source type="image/webp" srcset="' . esc_attr($webp_srcset) . '"' . $sizes_attr . '>';

        return '<picture>' . $sources_html . $img_tag . '</picture>';
    }

    /**
     * Construye el atributo srcset para la etiqueta <source>.
     *
     * @param string $src_url URL principal del atributo src.
     * @param string $srcset_raw Atributo srcset original si existe.
     * @param string $ext Extensión destino ('webp' o 'avif').
     * @return string|null Devuelve el nuevo srcset o null si ningún archivo existe.
     */
    private function build_source_srcset(string $src_url, string $srcset_raw, string $ext): ?string
    {
        if ($srcset_raw !== '') {
            $entries = array_map('trim', explode(',', $srcset_raw));
            $resolved_entries = [];

            foreach ($entries as $entry) {
                if ($entry === '') {
                    continue;
                }

                $parts = preg_split('/\s+/', $entry, 2);
                $entry_url = $parts[0];
                $descriptor = isset($parts[1]) ? ' ' . $parts[1] : '';

                $sidecar_url = $this->get_sidecar_url($entry_url, $ext);
                if ($sidecar_url !== null) {
                    $resolved_entries[] = $sidecar_url . $descriptor;
                }
            }

            if (!empty($resolved_entries)) {
                return implode(', ', $resolved_entries);
            }
        }

        // Si no hay srcset o ninguno se resolvió, intentar con el src principal
        $sidecar_url = $this->get_sidecar_url($src_url, $ext);
        return $sidecar_url;
    }

    /**
     * Resuelve la URL del sidecar si el archivo existe en disco.
     *
     * @param string $url URL original de la imagen.
     * @param string $target_ext 'webp' o 'avif'.
     * @return string|null URL del sidecar o null si no existe.
     */
    public function get_sidecar_url(string $url, string $target_ext): ?string
    {
        $cache_key = $target_ext . '|' . $url;
        if (array_key_exists($cache_key, $this->sidecar_cache)) {
            return $this->sidecar_cache[$cache_key];
        }

        $parsed_path = wp_parse_url($url, PHP_URL_PATH);
        $parsed_host = wp_parse_url($url, PHP_URL_HOST);

        if (!$parsed_path || ($parsed_host && $this->site_host && strcasecmp($parsed_host, $this->site_host) !== 0)) {
            $this->sidecar_cache[$cache_key] = null;
            return null;
        }

        $base_path = (string) wp_parse_url($this->uploads['baseurl'], PHP_URL_PATH);
        $base_path = rtrim($base_path, '/');
        if ($base_path === '' || strpos($parsed_path, $base_path . '/') !== 0) {
            $this->sidecar_cache[$cache_key] = null;
            return null;
        }

        $relative = ltrim(substr($parsed_path, strlen($base_path)), '/');
        $relative_sidecar = preg_replace('/\.(?:jpe?g|png)$/i', '.' . $target_ext, rawurldecode($relative));
        if ($relative_sidecar === null || $relative_sidecar === $relative) {
            $this->sidecar_cache[$cache_key] = null;
            return null;
        }

        $candidate_path = rtrim($this->uploads['basedir'], '/\\') . '/' . $relative_sidecar;

        if (!$this->check_file_exists($candidate_path)) {
            $this->sidecar_cache[$cache_key] = null;
            return null;
        }

        $sidecar_url = preg_replace('/\.(?:jpe?g|png)$/i', '.' . $target_ext, $url);
        $this->sidecar_cache[$cache_key] = $sidecar_url;

        return $sidecar_url;
    }

    /**
     * Comprueba la existencia del archivo localmente con caché en memoria.
     *
     * @param string $path
     * @return bool
     */
    private function check_file_exists(string $path): bool
    {
        if (isset($this->file_exists_cache[$path])) {
            return $this->file_exists_cache[$path];
        }

        $exists = is_file($path);
        $this->file_exists_cache[$path] = $exists;

        return $exists;
    }
}

if (!class_exists('CIO_Picture_Rewriter', false)) {
    class_alias('Clevers_IO_Picture_Rewriter', 'CIO_Picture_Rewriter');
}
