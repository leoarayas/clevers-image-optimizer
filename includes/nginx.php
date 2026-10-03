<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helpers para detectar Nginx y generar las directivas equivalentes a
 * las reglas .htaccess que Clevers Image Optimizer publica para Apache.
 *
 * Nginx ignora .htaccess, asi que el usuario debe pegar las directivas
 * dentro del bloque `server { ... }` de su configuracion de sitio.
 */
function clevers_io_is_nginx($server_software = null)
{
    if ($server_software === null) {
        $server_software = isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
    }

    return stripos($server_software, 'nginx') !== false;
}

/**
 * Devuelve el bloque `map` que define la variable `$webp_suffix` segun el
 * header Accept del navegador. Va dentro del bloque `http { ... }`, no del
 * `server { ... }`. Si el sitio no soporta AVIF, el cliente no lo recibe.
 */
function clevers_io_get_nginx_map_block()
{
    return implode(
        "\n",
        [
            '# Clever Image Optimizer — bloque map (http context)',
            '# Pegar DENTRO del bloque http { ... } del nginx.conf, una sola vez por servidor.',
            'map $http_accept $clever_image_suffix {',
            '    default         "";',
            '    "~image/avif"   ".avif";',
            '    "~image/webp"   ".webp";',
            '}',
        ]
    );
}

/**
 * Devuelve el bloque `location` que reescribe las peticiones JPG/PNG a su
 * equivalente AVIF/WebP cuando el navegador lo soporta y el archivo existe.
 * Va DENTRO del bloque `server { ... }` del sitio, idealmente cerca de las
 * reglas existentes de WordPress.
 */
function clevers_io_get_nginx_location_block()
{
    return implode(
        "\n",
        [
            '# Clever Image Optimizer — bloque location (server context)',
            '# Pegar DENTRO del bloque server { ... } del sitio, debajo de las reglas de WordPress.',
            'location ~* ^.+\.(jpe?g|png)$ {',
            '    add_header Vary "Accept";',
            '    try_files $uri$clever_image_suffix $uri =404;',
            '}',
        ]
    );
}

/**
 * Devuelve el snippet completo que el usuario debe copiar a su nginx.conf.
 * Combina el bloque `map` (http context) y el bloque `location` (server context)
 * con notas indicando donde va cada uno.
 */
function clevers_io_get_nginx_config_block()
{
    return clevers_io_get_nginx_map_block() . "\n\n" . clevers_io_get_nginx_location_block();
}
