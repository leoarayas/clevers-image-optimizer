<?php

if (!defined('ABSPATH')) {
    exit;
}

class Clevers_IO_Admin
{
    private $optimizer;

    public function __construct(Clevers_IO_Optimizer $optimizer)
    {
        $this->optimizer = $optimizer;

        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'check_dependencies_notice']);
        add_action('admin_head-settings_page_clevers-image-optimizer', [$this, 'enqueue_diagnostics_styles']);
    }

    public function enqueue_diagnostics_styles()
    {
        echo '<style>
            .clevers-io-diagnostics { margin-top: 8px; }
            .clevers-io-badge { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 12px; font-weight: 600; color: #fff; }
            .clevers-io-badge-ok { background: #1a7f37; }
            .clevers-io-badge-warning { background: #b58105; }
            .clevers-io-badge-error { background: #c4233b; }
            .clevers-io-diagnostics-summary { margin-left: 8px; color: #50575e; font-style: italic; }
            .clevers-io-config-block { width: 100%; font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 12px; background: #f6f7f7; padding: 10px; border: 1px solid #c3c4c7; border-radius: 4px; }
        </style>';
    }

    public function check_dependencies_notice()
    {
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p><strong>%s:</strong> %s</p></div>',
                esc_html__('Clevers Image Optimizer', 'clevers-image-optimizer'),
                wp_kses_post(__('Se requiere la extensión PHP <strong>GD</strong> o <strong>Imagick</strong> para generar imágenes WebP/AVIF.', 'clevers-image-optimizer'))
            );
        }
    }

    public function add_settings_page()
    {
        add_options_page(
            __('Clevers Image Optimizer', 'clevers-image-optimizer'),
            __('Clevers Optimizer', 'clevers-image-optimizer'),
            'manage_options',
            'clevers-image-optimizer',
            [$this, 'settings_page']
        );
    }

    public function register_settings()
    {
        register_setting('clevers_io_settings_group', 'clevers_io_webp_quality', [
            'type' => 'integer',
            'default' => 80,
            'sanitize_callback' => [$this, 'sanitize_quality'],
        ]);

        register_setting('clevers_io_settings_group', 'clevers_io_enable_avif', [
            'type' => 'string',
            'default' => '0',
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
        ]);

        register_setting('clevers_io_settings_group', 'clevers_io_avif_quality', [
            'type' => 'integer',
            'default' => 80,
            'sanitize_callback' => [$this, 'sanitize_quality'],
        ]);

        register_setting('clevers_io_settings_group', 'clevers_io_batch_limit', [
            'type' => 'integer',
            'default' => 10,
            'sanitize_callback' => [$this, 'sanitize_batch_limit'],
        ]);

        register_setting('clevers_io_settings_group', 'clevers_io_time_limit', [
            'type' => 'integer',
            'default' => 20,
            'sanitize_callback' => [$this, 'sanitize_time_limit'],
        ]);

        register_setting('clevers_io_settings_group', 'clevers_io_safety_net_enabled', [
            'type' => 'string',
            'default' => '0',
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
        ]);

        register_setting('clevers_io_settings_group', 'clevers_io_safety_net_max_dimension', [
            'type' => 'integer',
            'default' => Clevers_IO_Safety_Net::DEFAULT_MAX_DIMENSION,
            'sanitize_callback' => [$this, 'sanitize_safety_net_dimension'],
        ]);
    }

    public function sanitize_safety_net_dimension($value)
    {
        $value = absint($value);
        if ($value < Clevers_IO_Safety_Net::MIN_MAX_DIMENSION) {
            return Clevers_IO_Safety_Net::MIN_MAX_DIMENSION;
        }
        if ($value > Clevers_IO_Safety_Net::MAX_MAX_DIMENSION) {
            return Clevers_IO_Safety_Net::MAX_MAX_DIMENSION;
        }
        return $value;
    }

    public function sanitize_quality($value)
    {
        return Clevers_IO_Utils::sanitize_quality($value, 0, 100, 80);
    }

    public function sanitize_batch_limit($value)
    {
        $value = absint($value);
        if ($value < 1) {
            $value = 1;
        }

        return min($value, 100);
    }

    public function sanitize_time_limit($value)
    {
        $value = absint($value);
        if ($value < 5) {
            $value = 5;
        }

        return min($value, 120);
    }

    public function sanitize_checkbox($value)
    {
        return $value === '1' || $value === 1 ? '1' : '0';
    }

    public function settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->handle_htaccess_actions();

        $rules_active = clevers_io_rules_exist();
        $avif_supported = function_exists('imageavif');
        $queue_count = $this->optimizer->get_queue_count();
        $diagnostics = Clevers_IO_Diagnostics::run_diagnostics();
        $diagnostics_summary = Clevers_IO_Diagnostics::summarize($diagnostics);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Clevers Image Optimizer', 'clevers-image-optimizer'); ?></h1>

            <?php settings_errors('clevers_io_messages'); ?>

            <?php if ($queue_count > 0) : ?>
                <div class="notice notice-info">
                    <p>
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %d: número de imágenes en cola */
                                _n(
                                    'Hay %d imagen pendiente de optimización en background.',
                                    'Hay %d imágenes pendientes de optimización en background.',
                                    $queue_count,
                                    'clevers-image-optimizer'
                                ),
                                $queue_count
                            )
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e('Diagnóstico del entorno', 'clevers-image-optimizer'); ?></h2>
            <p>
                <?php esc_html_e('Estado del servidor y de las herramientas que el plugin puede usar.', 'clevers-image-optimizer'); ?>
                <span class="clevers-io-diagnostics-summary">
                    <?php
                    printf(
                        /* translators: 1: ok count, 2: warning count, 3: error count */
                        esc_html__('OK: %1$d · Advertencias: %2$d · Errores: %3$d', 'clevers-image-optimizer'),
                        (int) $diagnostics_summary['ok'],
                        (int) $diagnostics_summary['warning'],
                        (int) $diagnostics_summary['error']
                    );
                    ?>
                </span>
            </p>
            <table class="widefat striped clevers-io-diagnostics">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Componente', 'clevers-image-optimizer'); ?></th>
                        <th scope="col"><?php esc_html_e('Estado', 'clevers-image-optimizer'); ?></th>
                        <th scope="col"><?php esc_html_e('Detalle', 'clevers-image-optimizer'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($diagnostics as $check) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($check['label']); ?></strong>
                                <?php if (!empty($check['detail'])) : ?>
                                    <br><code><?php echo esc_html($check['detail']); ?></code>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $badge_class = 'clevers-io-badge clevers-io-badge-' . sanitize_html_class($check['status']);
                                $badge_label = [
                                    'ok' => __('OK', 'clevers-image-optimizer'),
                                    'warning' => __('Advertencia', 'clevers-image-optimizer'),
                                    'error' => __('Error', 'clevers-image-optimizer'),
                                ][$check['status']] ?? $check['status'];
                                ?>
                                <span class="<?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge_label); ?></span>
                            </td>
                            <td><?php echo esc_html($check['message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p>
                <?php esc_html_e('Si algun componente muestra Error, el plugin no funcionara correctamente hasta resolverlo. Las Advertencias indican funciones opcionales que faltan pero el plugin sigue operativo.', 'clevers-image-optimizer'); ?>
            </p>

            <hr>

            <form method="post" action="options.php">
                <?php settings_fields('clevers_io_settings_group'); ?>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Calidad WebP (0-100)', 'clevers-image-optimizer'); ?></th>
                        <td>
                            <input type="number" name="clevers_io_webp_quality" value="<?php echo esc_attr(get_option('clevers_io_webp_quality', get_option('cio_webp_quality', 80))); ?>" min="0" max="100" />
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Habilitar AVIF', 'clevers-image-optimizer'); ?></th>
                        <td>
                            <input type="checkbox" name="clevers_io_enable_avif" value="1" <?php checked('1', (string) get_option('clevers_io_enable_avif', get_option('cio_enable_avif', '0'))); ?> />
                            <p class="description">
                                <?php if ($avif_supported) : ?>
                                    <span style="color:green;"><?php esc_html_e('Tu servidor soporta AVIF.', 'clevers-image-optimizer'); ?></span>
                                <?php else : ?>
                                    <span style="color:red;"><?php esc_html_e('Tu servidor no soporta AVIF (requiere imageavif en GD).', 'clevers-image-optimizer'); ?></span>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Calidad AVIF (0-100)', 'clevers-image-optimizer'); ?></th>
                        <td>
                            <input type="number" name="clevers_io_avif_quality" value="<?php echo esc_attr(get_option('clevers_io_avif_quality', get_option('cio_avif_quality', 80))); ?>" min="0" max="100" />
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Lote por ejecución', 'clevers-image-optimizer'); ?></th>
                        <td>
                            <input type="number" name="clevers_io_batch_limit" value="<?php echo esc_attr(get_option('clevers_io_batch_limit', get_option('cio_batch_limit', 10))); ?>" min="1" max="100" />
                            <p class="description"><?php esc_html_e('Cantidad máxima de adjuntos procesados por cada corrida en background.', 'clevers-image-optimizer'); ?></p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Límite de tiempo por ejecución (seg)', 'clevers-image-optimizer'); ?></th>
                        <td>
                            <input type="number" name="clevers_io_time_limit" value="<?php echo esc_attr(get_option('clevers_io_time_limit', get_option('cio_time_limit', 20))); ?>" min="5" max="120" />
                            <p class="description"><?php esc_html_e('Evita timeouts al procesar lotes grandes.', 'clevers-image-optimizer'); ?></p>
                        </td>
                    </tr>
                </table>

                <h3><?php esc_html_e('Safety Net: redimensionar antes de generar miniaturas', 'clevers-image-optimizer'); ?></h3>
                <p class="description">
                    <?php esc_html_e('Interviene en el momento de la subida. Si la imagen original supera la dimension maxima configurada, se redimensiona antes de que WordPress genere las miniaturas. Esto evita que constructores como Brizy o Elementor generen sub-sizes gigantes a partir de una imagen de camara sobredimensionada.', 'clevers-image-optimizer'); ?>
                </p>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Habilitar Safety Net', 'clevers-image-optimizer'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="clevers_io_safety_net_enabled" value="1" <?php checked('1', (string) get_option('clevers_io_safety_net_enabled', '0')); ?> />
                                <?php esc_html_e('Redimensionar imagenes sobredimensionadas al subir', 'clevers-image-optimizer'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Deshabilitado por defecto. Activalo solo si tenes constructores visuales que generan miniaturas gigantes.', 'clevers-image-optimizer'); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Dimension maxima (px)', 'clevers-image-optimizer'); ?></th>
                        <td>
                            <input type="number" name="clevers_io_safety_net_max_dimension" value="<?php echo esc_attr((string) get_option('clevers_io_safety_net_max_dimension', Clevers_IO_Safety_Net::DEFAULT_MAX_DIMENSION)); ?>" min="<?php echo (int) Clevers_IO_Safety_Net::MIN_MAX_DIMENSION; ?>" max="<?php echo (int) Clevers_IO_Safety_Net::MAX_MAX_DIMENSION; ?>" />
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: 1: min pixels, 2: max pixels */
                                    esc_html__('Rango permitido: %1$d - %2$d px. Recomendado: 2560 (sirve para pantallas 5K y reduce peso ~70%% en imagenes de camara).', 'clevers-image-optimizer'),
                                    (int) Clevers_IO_Safety_Net::MIN_MAX_DIMENSION,
                                    (int) Clevers_IO_Safety_Net::MAX_MAX_DIMENSION
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>

            <h2><?php esc_html_e('Servir WebP/AVIF al navegador', 'clevers-image-optimizer'); ?></h2>
            <?php if (clevers_io_is_nginx()) : ?>
                <p>
                    <strong><?php esc_html_e('Servidor detectado:', 'clevers-image-optimizer'); ?></strong>
                    <code><?php echo esc_html(isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : 'nginx'); ?></code>
                </p>
                <p><?php esc_html_e('Nginx ignora .htaccess. Pega las siguientes directivas dentro del bloque http/server de tu configuracion de nginx y recarga el servicio.', 'clevers-image-optimizer'); ?></p>
                <textarea readonly class="clevers-io-config-block" rows="14"><?php echo esc_textarea(clevers_io_get_nginx_config_block()); ?></textarea>
                <p class="description">
                    <?php esc_html_e('El bloque "map" va dentro de http { ... }. El bloque "location" va dentro del server { ... } del sitio.', 'clevers-image-optimizer'); ?>
                </p>
            <?php else : ?>
                <p>
                    <strong><?php esc_html_e('Estado:', 'clevers-image-optimizer'); ?></strong>
                    <?php if ($rules_active) : ?>
                        <span style="color:green;"><?php esc_html_e('Reglas activas.', 'clevers-image-optimizer'); ?></span>
                    <?php else : ?>
                        <span style="color:red;"><?php esc_html_e('Reglas no instaladas.', 'clevers-image-optimizer'); ?></span>
                    <?php endif; ?>
                </p>

                <form method="post">
                    <?php wp_nonce_field('clevers_io_htaccess_action', 'clevers_io_htaccess_nonce'); ?>
                    <p>
                        <input type="submit" name="clevers_io_add_rules" class="button button-secondary" value="<?php esc_attr_e('Activar reglas WebP/AVIF en .htaccess', 'clevers-image-optimizer'); ?>">
                        <input type="submit" name="clevers_io_remove_rules" class="button button-secondary" value="<?php esc_attr_e('Eliminar reglas del .htaccess', 'clevers-image-optimizer'); ?>" style="margin-left:10px;">
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function handle_htaccess_actions()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!isset($_POST['clevers_io_htaccess_nonce'])) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['clevers_io_htaccess_nonce']));
        if (!wp_verify_nonce($nonce, 'clevers_io_htaccess_action')) {
            return;
        }

        if (isset($_POST['clevers_io_add_rules'])) {
            if (clevers_io_add_htaccess_rules()) {
                add_settings_error('clevers_io_messages', 'clevers_io_message', __('Reglas añadidas correctamente al .htaccess.', 'clevers-image-optimizer'), 'updated');
            } else {
                add_settings_error('clevers_io_messages', 'clevers_io_message', __('No se pudo escribir en el .htaccess. Revisa permisos.', 'clevers-image-optimizer'), 'error');
            }
        }

        if (isset($_POST['clevers_io_remove_rules'])) {
            clevers_io_remove_htaccess_rules();
            add_settings_error('clevers_io_messages', 'clevers_io_message', __('Reglas eliminadas del .htaccess.', 'clevers-image-optimizer'), 'updated');
        }
    }
}

if (!class_exists('CIO_Admin', false)) {
    class_alias('Clevers_IO_Admin', 'CIO_Admin');
}
