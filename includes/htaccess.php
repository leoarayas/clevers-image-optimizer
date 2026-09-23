<?php

if (!defined('ABSPATH')) {
    exit;
}

function clevers_io_get_htaccess_path()
{
    return ABSPATH . '.htaccess';
}

function clevers_io_get_rules_block()
{
    return implode(
        "\n",
        [
            '# BEGIN Clever_Image_Optimizer',
            '<IfModule mod_rewrite.c>',
            'RewriteEngine On',
            '',
            '# Servir AVIF cuando el navegador lo soporta y el archivo existe (mayor compresión que WebP)',
            'RewriteCond %{HTTP_ACCEPT} image/avif',
            'RewriteCond %{REQUEST_FILENAME} \.(jpe?g|png)$',
            'RewriteCond %{REQUEST_FILENAME}.avif -f',
            'RewriteRule ^(.+)\.(jpe?g|png)$ $1.avif [T=image/avif,E=accept_avif:1,L]',
            '',
            '# Servir WebP como fallback cuando el navegador lo soporta y el archivo existe',
            'RewriteCond %{HTTP_ACCEPT} image/webp',
            'RewriteCond %{REQUEST_FILENAME} \.(jpe?g|png)$',
            'RewriteCond %{REQUEST_FILENAME}.webp -f',
            'RewriteRule ^(.+)\.(jpe?g|png)$ $1.webp [T=image/webp,E=accept_webp:1,L]',
            '</IfModule>',
            '',
            '<IfModule mod_headers.c>',
            'Header append Vary Accept env=accept_webp',
            'Header append Vary Accept env=accept_avif',
            '</IfModule>',
            '# END Clever_Image_Optimizer',
        ]
    );
}

function clevers_io_rules_exist()
{
    $path = clevers_io_get_htaccess_path();
    if (!file_exists($path)) {
        return false;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return false;
    }

    return strpos($contents, '# BEGIN Clever_Image_Optimizer') !== false;
}

function clevers_io_add_htaccess_rules()
{
    $path = clevers_io_get_htaccess_path();
    $rules = clevers_io_get_rules_block();

    if (!file_exists($path)) {
        return false !== file_put_contents($path, $rules . PHP_EOL);
    }

    if (clevers_io_rules_exist()) {
        return true;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return false;
    }
    $contents .= PHP_EOL . $rules . PHP_EOL;

    return false !== file_put_contents($path, $contents);
}

function clevers_io_remove_htaccess_rules()
{
    $path = clevers_io_get_htaccess_path();
    if (!file_exists($path)) {
        return;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return;
    }

    $pattern = '/# BEGIN Clever_Image_Optimizer[\s\S]*?# END Clever_Image_Optimizer/';
    $cleaned = preg_replace($pattern, '', $contents);

    if ($cleaned !== null) {
        file_put_contents($path, $cleaned);
    }
}
