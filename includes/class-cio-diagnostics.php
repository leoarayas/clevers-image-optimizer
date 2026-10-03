<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Diagnostico del entorno del servidor para Clevers Image Optimizer.
 *
 * Devuelve una lista de checks (PHP extensions, binarios locales, permisos)
 * con un estado (ok/warning/error) que la pagina de ajustes muestra como
 * badges. Pensado para que un usuario sin conocimientos tecnicos sepa de
 * un vistazo que capacidades tiene su servidor.
 *
 * Testabilidad: cada check respeta variables de entorno CIO_TEST_*
 * para forzar resultados en tests PHPUnit sin tocar el entorno real.
 */
class Clevers_IO_Diagnostics
{
    /**
     * Binarios externos opcionales que el plugin detecta si estan instalados.
     * Si estan, mejora la compresion; si no, el plugin funciona igual via GD.
     *
     * @var array<string, string>
     */
    private static $binaries = [
        'binary-jpegoptim' => 'jpegoptim',
        'binary-optipng' => 'optipng',
        'binary-pngquant' => 'pngquant',
        'binary-cwebp' => 'cwebp',
        'binary-gifsicle' => 'gifsicle',
        'binary-svgo' => 'svgo',
    ];

    /**
     * Ejecuta todos los checks de diagnostico y devuelve un array de resultados.
     *
     * Cada entrada tiene la forma:
     *   [
     *     'id'      => string  identificador unico,
     *     'label'   => string  titulo visible al usuario,
     *     'status'  => string  'ok' | 'warning' | 'error',
     *     'message' => string  explicacion corta,
     *     'detail'  => string|null  informacion tecnica adicional (version, ruta, etc.),
     *   ]
     *
     * @return array<int, array{id:string,label:string,status:string,message:string,detail:?string}>
     */
    public static function run_diagnostics()
    {
        return [
            self::check_php_gd(),
            self::check_php_imagick(),
            self::check_proc_open(),
            self::check_uploads_writable(),
            self::check_binary('binary-jpegoptim', 'jpegoptim', __('Util para comprimir JPG con perdida.', 'clevers-image-optimizer')),
            self::check_binary('binary-optipng', 'optipng', __('Compresion PNG sin perdida.', 'clevers-image-optimizer')),
            self::check_binary('binary-pngquant', 'pngquant', __('Compresion PNG con perdida.', 'clevers-image-optimizer')),
            self::check_binary('binary-cwebp', 'cwebp', __('Convierte imagenes a WebP localmente.', 'clevers-image-optimizer')),
            self::check_binary('binary-gifsicle', 'gifsicle', __('Optimizador de GIFs animados.', 'clevers-image-optimizer')),
            self::check_binary('binary-svgo', 'svgo', __('Optimizador de SVG.', 'clevers-image-optimizer')),
        ];
    }

    /**
     * Resume el resultado de run_diagnostics() en conteos por estado.
     *
     * @param array<int, array{status:string}> $checks
     * @return array{ok:int, warning:int, error:int}
     */
    public static function summarize(array $checks)
    {
        $summary = ['ok' => 0, 'warning' => 0, 'error' => 0];
        foreach ($checks as $check) {
            if (isset($summary[$check['status']])) {
                $summary[$check['status']]++;
            }
        }
        return $summary;
    }

    /**
     * @return array{id:string,label:string,status:string,message:string,detail:?string}
     */
    private static function check_php_gd()
    {
        $loaded = self::env_flag('CIO_TEST_GD_LOADED', extension_loaded('gd'));
        $webp_support = self::env_flag('CIO_TEST_GD_WEBP', function_exists('imagewebp'));
        $avif_support = self::env_flag('CIO_TEST_GD_AVIF', function_exists('imageavif'));

        if (!$loaded) {
            return [
                'id' => 'php-gd',
                'label' => __('Extension PHP GD', 'clevers-image-optimizer'),
                'status' => 'error',
                'message' => __('La extension GD no esta disponible. Es indispensable para optimizar imagenes.', 'clevers-image-optimizer'),
                'detail' => null,
            ];
        }

        $features = [];
        if ($webp_support) {
            $features[] = 'WebP';
        }
        if ($avif_support) {
            $features[] = 'AVIF';
        }

        $status = !empty($features) ? 'ok' : 'warning';
        $message = !empty($features)
            ? sprintf(
                /* translators: %s: lista separada por comas de formatos soportados */
                __('GD activa con soporte para %s.', 'clevers-image-optimizer'),
                implode(', ', $features)
            )
            : __('GD activa pero sin soporte para WebP ni AVIF. Actualiza PHP o la extension GD.', 'clevers-image-optimizer');

        return [
            'id' => 'php-gd',
            'label' => __('Extension PHP GD', 'clevers-image-optimizer'),
            'status' => $status,
            'message' => $message,
            'detail' => self::php_extension_version('gd'),
        ];
    }

    /**
     * @return array{id:string,label:string,status:string,message:string,detail:?string}
     */
    private static function check_php_imagick()
    {
        $loaded = self::env_flag('CIO_TEST_IMAGICK_LOADED', extension_loaded('imagick'));

        if (!$loaded) {
            return [
                'id' => 'php-imagick',
                'label' => __('Extension PHP Imagick', 'clevers-image-optimizer'),
                'status' => 'warning',
                'message' => __('Imagick no esta instalada. El plugin funciona con GD, pero algunos formatos (TIFF, HEIC) requieren Imagick.', 'clevers-image-optimizer'),
                'detail' => null,
            ];
        }

        return [
            'id' => 'php-imagick',
            'label' => __('Extension PHP Imagick', 'clevers-image-optimizer'),
            'status' => 'ok',
            'message' => __('Imagick instalada.', 'clevers-image-optimizer'),
            'detail' => self::php_extension_version('imagick'),
        ];
    }

    /**
     * @return array{id:string,label:string,status:string,message:string,detail:?string}
     */
    private static function check_proc_open()
    {
        $available = self::env_flag('CIO_TEST_PROC_OPEN', function_exists('proc_open'));
        $disabled = ini_get('disable_functions');
        $disabled_list = $disabled ? array_map('trim', explode(',', $disabled)) : [];
        $proc_open_disabled = in_array('proc_open', $disabled_list, true);

        if ($available && !$proc_open_disabled) {
            return [
                'id' => 'proc-open',
                'label' => __('Ejecucion de procesos (proc_open)', 'clevers-image-optimizer'),
                'status' => 'ok',
                'message' => __('PHP puede ejecutar binarios locales. Los optimizadores opcionales funcionan.', 'clevers-image-optimizer'),
                'detail' => null,
            ];
        }

        $cause = $proc_open_disabled
            ? __('proc_open esta deshabilitado en disable_functions.', 'clevers-image-optimizer')
            : __('La funcion proc_open no existe en esta build de PHP.', 'clevers-image-optimizer');

        return [
            'id' => 'proc-open',
            'label' => __('Ejecucion de procesos (proc_open)', 'clevers-image-optimizer'),
            'status' => 'warning',
            'message' => sprintf(
                '%s Solo se podran usar los codecs internos de GD.',
                $cause
            ),
            'detail' => null,
        ];
    }

    /**
     * @return array{id:string,label:string,status:string,message:string,detail:?string}
     */
    private static function check_uploads_writable()
    {
        $uploads = wp_upload_dir();
        $path = self::env_string('CIO_TEST_UPLOADS_PATH', $uploads['basedir'] ?? '');
        $writable = false;

        if ($path && is_dir($path)) {
            $writable = wp_is_writable($path);
        }

        if (!$path) {
            return [
                'id' => 'uploads-writable',
                'label' => __('Permisos del directorio uploads', 'clevers-image-optimizer'),
                'status' => 'warning',
                'message' => __('No se pudo determinar el directorio de uploads.', 'clevers-image-optimizer'),
                'detail' => null,
            ];
        }

        if (!$writable) {
            return [
                'id' => 'uploads-writable',
                'label' => __('Permisos del directorio uploads', 'clevers-image-optimizer'),
                'status' => 'error',
                'message' => sprintf(
                    /* translators: %s: ruta del directorio de uploads */
                    __('El directorio %s no es escribible. WordPress no podra subir archivos multimedia.', 'clevers-image-optimizer'),
                    $path
                ),
                'detail' => $path,
            ];
        }

        return [
            'id' => 'uploads-writable',
            'label' => __('Permisos del directorio uploads', 'clevers-image-optimizer'),
            'status' => 'ok',
            'message' => __('El directorio de uploads es escribible.', 'clevers-image-optimizer'),
            'detail' => $path,
        ];
    }

    /**
     * @return array{id:string,label:string,status:string,message:string,detail:?string}
     */
    private static function check_binary($id, $command, $description)
    {
        $env_flag = 'CIO_TEST_BINARY_' . strtoupper(str_replace('-', '_', $id));
        $forced = getenv($env_flag);

        $available = null;
        if ($forced === 'true') {
            $available = true;
        } elseif ($forced === 'false') {
            $available = false;
        } else {
            $path = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
            $found = self::which($command, explode(':', $path));
            $available = $found !== null;
        }

        $label = isset(self::$binaries[$id]) ? self::$binaries[$id] : $command;

        if ($available) {
            return [
                'id' => $id,
                'label' => sprintf(__('Binario %s', 'clevers-image-optimizer'), $label),
                'status' => 'ok',
                'message' => sprintf(
                    /* translators: 1: nombre del binario, 2: descripcion corta */
                    __('%1$s disponible en el sistema. %2$s', 'clevers-image-optimizer'),
                    $label,
                    $description
                ),
                'detail' => self::which($command, explode(':', getenv('PATH') ?: '')) ?: $label,
            ];
        }

        return [
            'id' => $id,
            'label' => sprintf(__('Binario %s', 'clevers-image-optimizer'), $label),
            'status' => 'warning',
            'message' => sprintf(
                /* translators: 1: nombre del binario, 2: descripcion corta */
                __('%1$s no esta instalado. %2$s', 'clevers-image-optimizer'),
                $label,
                $description
            ),
            'detail' => null,
        ];
    }

    /**
     * Busca la ruta absoluta de un binario en las rutas dadas.
     *
     * @param string $command
     * @param array<int, string> $paths
     * @return string|null
     */
    private static function which($command, array $paths)
    {
        foreach ($paths as $path) {
            $candidate = rtrim($path, '/') . '/' . $command;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * @return string|null
     */
    private static function php_extension_version($extension)
    {
        $version = phpversion($extension);
        return $version === false ? null : $version;
    }

    /**
     * Lee un flag de entorno CIO_TEST_* o devuelve el valor por defecto.
     *
     * @param string $name
     * @param bool $default
     * @return bool
     */
    private static function env_flag($name, $default)
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return (bool) $default;
        }
        return $value === 'true' || $value === '1';
    }

    /**
     * Lee un string de entorno CIO_TEST_* o devuelve el valor por defecto.
     *
     * @param string $name
     * @param mixed $default
     * @return string
     */
    private static function env_string($name, $default)
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return (string) $default;
        }
        return $value;
    }
}

if (!class_exists('CIO_Diagnostics', false)) {
    class_alias('Clevers_IO_Diagnostics', 'CIO_Diagnostics');
}
