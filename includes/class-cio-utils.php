<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Utilidades estáticas compartidas del plugin Clevers Image Optimizer.
 */
class Clevers_IO_Utils
{
    /**
     * Sanitiza un valor de calidad de imagen asegurando que quede dentro del rango [min, max].
     *
     * @param mixed $value   Valor a sanitizar.
     * @param int   $min     Valor mínimo permitido (por defecto 1).
     * @param int   $max     Valor máximo permitido (por defecto 100).
     * @param int   $default Valor por defecto si el resultado queda fuera del rango.
     * @return int
     */
    public static function sanitize_quality($value, $min = 1, $max = 100, $default = 80)
    {
        $value = (int) $value;

        if ($value < $min || $value > $max) {
            if ($value < $min) {
                return $min;
            }
            return $max;
        }

        return $value;
    }

    /**
     * Obtiene la ruta del archivo WebP correspondiente a una ruta de imagen original.
     *
     * @param string $file Ruta absoluta al archivo.
     * @return string
     */
    public static function get_webp_path($file)
    {
        $info = pathinfo($file);
        return $info['dirname'] . '/' . $info['filename'] . '.webp';
    }
}

if (!class_exists('CIO_Utils', false)) {
    class_alias('Clevers_IO_Utils', 'CIO_Utils');
}
