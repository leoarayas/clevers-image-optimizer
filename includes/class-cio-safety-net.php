<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Safety Net: interviene en `wp_handle_upload_prefilter` para redimensionar
 * imagenes sobredimensionadas ANTES de que WordPress genere los sub-sizes.
 *
 * Esto evita que constructores como Brizy, Elementor, Divi o plugins de
 * ecommerce generen miniaturas gigantes (3000x3000+) a partir de una subida
 * de 6000x4000, consumiendo memoria y disco innecesariamente.
 *
 * Feature flag: solo actua si `clevers_io_safety_net_enabled` esta en '1'.
 * Default: '0' (deshabilitado). El usuario debe activarlo explicitamente en
 * Ajustes para que tenga efecto.
 */
class Clevers_IO_Safety_Net
{
    const OPTION_ENABLED = 'clevers_io_safety_net_enabled';
    const OPTION_MAX_DIMENSION = 'clevers_io_safety_net_max_dimension';

    const DEFAULT_MAX_DIMENSION = 2560;
    const MIN_MAX_DIMENSION = 500;
    const MAX_MAX_DIMENSION = 8192;

    private $config;

    /**
     * @param array{enabled?:bool,max_dimension?:int}|null $config Override para tests.
     */
    public function __construct($config = null)
    {
        if ($config === null) {
            $config = [
                'enabled' => get_option(self::OPTION_ENABLED, '0') === '1',
                'max_dimension' => (int) get_option(self::OPTION_MAX_DIMENSION, self::DEFAULT_MAX_DIMENSION),
            ];
        }

        $this->config = [
            'enabled' => !empty($config['enabled']),
            'max_dimension' => $this->clamp_dimension(isset($config['max_dimension']) ? (int) $config['max_dimension'] : self::DEFAULT_MAX_DIMENSION),
        ];
    }

    public function get_max_dimension()
    {
        return $this->config['max_dimension'];
    }

    /**
     * Decide si un archivo del upload debe ser intervenido.
     *
     * @param array $file Entrada tipo `$_FILES` normalizada (type, name, tmp_name).
     * @return bool
     */
    public function should_intervene($file)
    {
        if (!$this->config['enabled']) {
            return false;
        }

        if (!isset($file['type'], $file['name'])) {
            return false;
        }

        $type = strtolower((string) $file['type']);
        if (!in_array($type, ['image/jpeg', 'image/jpg', 'image/png'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Hook `wp_handle_upload_prefilter`. Devuelve el array del archivo
     * (modificado o intacto). Si decide intervenir, reemplaza el archivo
     * tmp por una version redimensionada antes de que WP lo mueva.
     *
     * @param array $file
     * @return array
     */
    public function filter_upload($file)
    {
        if (!$this->should_intervene($file)) {
            return $file;
        }

        $tmp = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($tmp === '' || !file_exists($tmp)) {
            return $file;
        }

        $resized = $this->resize_file($tmp);
        if ($resized !== false && $resized !== $tmp) {
            $file['tmp_name'] = $resized;
        }

        return $file;
    }

    /**
     * Redimensiona un archivo de imagen si excede la dimension maxima.
     * Devuelve la ruta original si la imagen ya es pequena o si la operacion
     * falla. Devuelve `false` solo si la fuente no existe.
     *
     * @param string $source_path Ruta absoluta al archivo en disco.
     * @return string|false Ruta nueva (puede coincidir con $source_path) o false si falla.
     */
    public function resize_file($source_path)
    {
        if (!file_exists($source_path)) {
            return false;
        }

        $info = @getimagesize($source_path);
        if ($info === false) {
            return $source_path;
        }

        [$width, $height] = $info;
        $max = $this->config['max_dimension'];

        if ($width <= $max && $height <= $max) {
            return $source_path;
        }

        $image = $this->load_image($source_path, $info[2]);
        if ($image === false) {
            return $source_path;
        }

        $ratio = $width > $height ? $max / $width : $max / $height;
        $new_width = (int) round($width * $ratio);
        $new_height = (int) round($height * $ratio);

        $resized = imagescale($image, $new_width, $new_height);
        if ($resized === false) {
            unset($image);
            return $source_path;
        }

        $target = $source_path . '.tmp';
        $saved = $this->save_image($resized, $target, $info[2]);

        unset($image, $resized);

        if ($saved === false) {
            return $source_path;
        }

        if (!@rename($target, $source_path)) {
            @unlink($target);
            return $source_path;
        }

        return $source_path;
    }

    /**
     * @param int $type IMAGETYPE_JPEG o IMAGETYPE_PNG
     * @return resource|false
     */
    private function load_image($path, $type)
    {
        if ($type === IMAGETYPE_JPEG) {
            return @imagecreatefromjpeg($path);
        }
        if ($type === IMAGETYPE_PNG) {
            return @imagecreatefrompng($path);
        }
        return false;
    }

    /**
     * @param resource $image
     * @return bool
     */
    private function save_image($image, $path, $type)
    {
        if ($type === IMAGETYPE_JPEG) {
            return imagejpeg($image, $path, 90);
        }
        if ($type === IMAGETYPE_PNG) {
            return imagepng($image, $path, 6);
        }
        return false;
    }

    /**
     * @param int $value
     * @return int
     */
    private function clamp_dimension($value)
    {
        $value = (int) $value;
        if ($value < self::MIN_MAX_DIMENSION) {
            return self::MIN_MAX_DIMENSION;
        }
        if ($value > self::MAX_MAX_DIMENSION) {
            return self::MAX_MAX_DIMENSION;
        }
        return $value;
    }
}

if (!class_exists('CIO_Safety_Net', false)) {
    class_alias('Clevers_IO_Safety_Net', 'CIO_Safety_Net');
}
