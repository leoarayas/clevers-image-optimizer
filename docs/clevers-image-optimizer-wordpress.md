# Clevers Image Optimizer — Plan de evolución del plugin WordPress

## 1. Objetivo

Evolucionar el plugin existente `clevers/clevers-image-optimizer` para convertirlo en una capa de seguridad dentro de WordPress.

La optimización principal debería ocurrir antes de subir las imágenes mediante el CLI Node.js + Sharp.

Este plugin debe encargarse de los casos en que:

- un usuario sube directamente un JPG/PNG;
- se suben fotografías demasiado grandes;
- un producto de WooCommerce recibe imágenes sin optimizar;
- se cargan imágenes directamente desde Brizy;
- un editor omite el flujo previo de optimización.

La prioridad es intervenir en el pipeline estándar de WordPress antes de que Brizy/WooCommerce generen sus derivados.

---

# 2. Arquitectura general

```text
Flujo principal

Figma → WebP
               ┐
Sharp CLI → WebP
               ├──→ WordPress
               │
               ▼
      Clevers Image Optimizer
          safety net
```

El plugin no debe competir con Sharp.

Debe actuar solamente cuando sea necesario.

---

# 3. Composer actual

Base existente:

```json
{
    "name": "clevers/clevers-image-optimizer",
    "description": "Optimización local de imágenes para WordPress (WebP/AVIF).",
    "type": "wordpress-plugin",
    "require": {
        "spatie/image-optimizer": "^1.8",
        "symfony/process": "^5.4",
        "symfony/filesystem": "^5.4"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6"
    },
    "scripts": {
        "lint": "find . -path './vendor' -prune -o -path './.git' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l",
        "test": "phpunit --configuration phpunit.xml.dist"
    },
    "config": {
        "platform": {
            "php": "7.4.0"
        }
    }
}
```

---

# 4. Cambio de enfoque

Separar responsabilidades:

```text
WP_Image_Editor
    │
    ├── resize
    └── JPG/PNG → WebP
             │
             ▼
Spatie Image Optimizer
             │
             └── optimización adicional opcional
```

No usar Spatie como mecanismo principal de conversión de formato.

Spatie puede seguir utilizándose para:

- optimizar WebP;
- optimizar JPEG si por algún motivo se conserva;
- optimizar PNG;
- ejecutar binarios externos instalados en el servidor;
- diagnóstico de herramientas disponibles.

---

# 5. Requisitos de servidor

El plugin debe revisar capacidades, no asumirlas.

Diagnóstico inicial:

```text
PHP
WordPress
GD
Imagick
WebP support
AVIF support
cwebp
jpegoptim
pngquant
avifenc
```

WebP debe ser obligatorio para el flujo principal.

AVIF debe ser opcional.

---

# 6. Compatibilidad PHP

Para una nueva versión del plugin, definir un baseline moderno.

Sugerencia:

```json
"require": {
    "php": ">=8.2",
    "spatie/image-optimizer": "^1.8",
    "symfony/filesystem": "^7.0"
}
```

Mantener `symfony/process` explícitamente solo si el plugin usa `Process` directamente.

Si el código propio no llama a Symfony Process, dejar que Spatie resuelva su dependencia.

Antes de cambiar versiones:

1. revisar los servidores administrados;
2. confirmar versión mínima real de PHP;
3. actualizar PHPUnit acorde al baseline;
4. ejecutar tests.

No cambiar el baseline de producción sin auditar primero el parque instalado.

---

# 7. Estructura recomendada

```text
clevers-image-optimizer/
├── clevers-image-optimizer.php
├── composer.json
├── vendor/
├── src/
│   ├── Plugin.php
│   ├── Upload/
│   │   ├── UploadInterceptor.php
│   │   ├── ImageConverter.php
│   │   └── ImageValidator.php
│   ├── Optimization/
│   │   ├── Optimizer.php
│   │   └── OptimizerFactory.php
│   ├── Media/
│   │   ├── AttachmentService.php
│   │   └── BulkOptimizer.php
│   ├── Settings/
│   │   ├── Settings.php
│   │   └── AdminPage.php
│   ├── Diagnostics/
│   │   └── Diagnostics.php
│   └── Support/
│       ├── Logger.php
│       └── Files.php
├── tests/
└── languages/
```

Usar namespaces:

```php
Clevers\ImageOptimizer
```

---

# 8. Configuración v1

Opciones:

```text
enabled: true
format: webp
quality: 82
max_width: 2560
max_height: 2560
convert_jpeg: true
convert_png: true
skip_webp: true
delete_original: true
run_spatie_after_conversion: false
```

Para WooCommerce se podría añadir posteriormente un preset específico.

---

# 9. Regla principal de upload

Flujo:

```text
WordPress recibe archivo
        │
        ▼
¿es imagen?
        │
        ├── no → ignorar
        │
        ▼
¿JPEG / PNG?
        │
        ├── no
        │    │
        │    ├── WebP → validar
        │    ├── SVG → ignorar
        │    └── GIF → ignorar inicialmente
        │
        ▼
normalizar orientación
        │
        ▼
redimensionar si excede máximo
        │
        ▼
convertir a WebP
        │
        ▼
reemplazar archivo del upload
        │
        ▼
WordPress registra attachment WebP
        │
        ▼
Brizy / WooCommerce trabajan desde WebP
```

---

# 10. Hook recomendado

Intervenir lo suficientemente temprano para que WordPress registre el WebP como archivo subido.

Candidato principal:

```php
add_filter('wp_handle_upload', ...);
```

También estudiar:

```text
wp_handle_sideload
```

si se requieren importaciones externas.

La implementación debe probarse con:

- Media Library.
- Brizy.
- WooCommerce.
- Gutenberg.
- Featured Image.

---

# 11. Ejemplo conceptual de interceptor

```php
<?php

namespace Clevers\ImageOptimizer\Upload;

class UploadInterceptor
{
    public function register(): void
    {
        add_filter('wp_handle_upload', [$this, 'handleUpload'], 20);
    }

    public function handleUpload(array $upload): array
    {
        if (!empty($upload['error'])) {
            return $upload;
        }

        if (empty($upload['file']) || empty($upload['type'])) {
            return $upload;
        }

        $supported = [
            'image/jpeg',
            'image/png',
        ];

        if (!in_array($upload['type'], $supported, true)) {
            return $upload;
        }

        return $this->convertToWebp($upload);
    }

    private function convertToWebp(array $upload): array
    {
        // Implementar mediante servicio ImageConverter.
        return $upload;
    }
}
```

No incluir toda la lógica de conversión dentro del hook.

---

# 12. ImageConverter

Responsabilidades:

- validar archivo;
- abrir `WP_Image_Editor`;
- corregir orientación si corresponde;
- obtener dimensiones;
- reducir dimensiones;
- configurar calidad;
- guardar WebP;
- eliminar temporal/original cuando sea seguro;
- devolver path, URL y MIME correctos.

Interfaz conceptual:

```php
interface ImageConverterInterface
{
    public function convert(
        string $sourcePath,
        ConversionOptions $options
    ): ConversionResult;
}
```

Resultado:

```php
final class ConversionResult
{
    public string $path;
    public string $mimeType;
    public int $originalBytes;
    public int $optimizedBytes;
    public int $width;
    public int $height;
}
```

---

# 13. Conversión con WordPress

Base conceptual:

```php
$editor = wp_get_image_editor($sourcePath);

if (is_wp_error($editor)) {
    return $original;
}

$size = $editor->get_size();

if (
    $size['width'] > $maxWidth ||
    $size['height'] > $maxHeight
) {
    $editor->resize(
        $maxWidth,
        $maxHeight,
        false
    );
}

$editor->set_quality($quality);

$result = $editor->save(
    $destinationPath,
    'image/webp'
);
```

Nunca asumir que `save()` funcionará.

Manejar:

```php
is_wp_error($result)
```

En caso de error:

- conservar original;
- registrar error;
- no romper el upload.

---

# 14. Política de fallback

Principio:

> Nunca impedir una subida válida solo porque falló la optimización.

Flujo:

```text
optimización correcta
→ usar WebP

optimización falla
→ conservar JPG/PNG original
→ logger
→ mostrar warning administrativo opcional
```

El frontend/editor no debe romperse.

---

# 15. WebP existentes

No recomprimir automáticamente todos los WebP.

Regla inicial:

```text
WebP dentro de dimensiones máximas
→ SKIP

WebP demasiado grande
→ opcionalmente resize

WebP muy pesado
→ optimización opcional
```

Evitar:

```text
Figma WebP 82
    ↓
nuevo encode
    ↓
pérdida generacional
```

Por defecto:

```text
skip_webp = true
```

---

# 16. PNG con transparencia

WebP soporta alpha.

Debe existir test específico para:

```text
producto-transparente.png
```

y validar que el WebP final conserva transparencia.

No aplanar sobre fondo blanco.

---

# 17. GIF

En v1:

```text
image/gif
→ skip
```

No intentar procesar GIF animados con `WP_Image_Editor` sin una estrategia específica.

---

# 18. SVG

En v1:

```text
image/svg+xml
→ skip
```

La seguridad y sanitización SVG es un problema distinto al optimizador.

---

# 19. AVIF

No establecer AVIF como formato predeterminado.

Mantener arquitectura preparada:

```text
format:
- webp
- avif
```

pero usar:

```text
webp
```

como estándar operativo mientras Brizy siga siendo parte relevante del stack.

---

# 20. Spatie Image Optimizer

Spatie debe quedar desacoplado.

Servicio conceptual:

```php
namespace Clevers\ImageOptimizer\Optimization;

use Spatie\ImageOptimizer\OptimizerChainFactory;

class Optimizer
{
    public function optimize(string $path): void
    {
        $optimizer = OptimizerChainFactory::create();
        $optimizer->optimize($path);
    }
}
```

Configuración:

```text
run_spatie_after_conversion = false
```

por defecto.

Primero medir si una segunda pasada sobre WebP produce ahorro suficiente.

---

# 21. Diagnóstico

Agregar página:

```text
Herramientas
→ Clevers Image Optimizer
→ Diagnostics
```

Mostrar:

```text
PHP                    ✓ 8.x
WordPress              ✓
WP_Image_Editor        Imagick
JPEG                   ✓
PNG                    ✓
WebP read              ✓
WebP write             ✓
AVIF read              opcional
AVIF write             opcional

cwebp                  disponible/no disponible
jpegoptim              disponible/no disponible
pngquant                disponible/no disponible
avifenc                 disponible/no disponible
```

Esto facilita soporte en múltiples servidores.

---

# 22. Bulk optimizer

Agregar herramienta para biblioteca histórica.

Flujo:

```text
Media Library
    │
    ▼
seleccionar attachments JPG/PNG
    │
    ▼
procesar en batches
    │
    ▼
convertir
    │
    ▼
actualizar attachment metadata
```

No hacer un proceso monolítico sobre miles de archivos desde una sola petición HTTP.

Opciones:

- WP-CLI.
- Action Scheduler.
- WP Cron.
- requests AJAX por batches.

Recomendación:

1. implementar primero WP-CLI;
2. posteriormente añadir interfaz administrativa.

---

# 23. WP-CLI

Comando recomendado:

```bash
wp clevers-images analyze
```

```bash
wp clevers-images optimize --dry-run
```

```bash
wp clevers-images optimize --batch=100
```

Esto es especialmente útil en servidores administrados por la agencia.

---

# 24. Metadata de WordPress

Si se convierte un attachment histórico de:

```text
foto.jpg
```

a:

```text
foto.webp
```

no basta con cambiar el archivo.

Hay que considerar:

- `_wp_attached_file`;
- `_wp_attachment_metadata`;
- MIME del post attachment;
- thumbnails;
- URLs existentes;
- referencias en contenido.

Por eso la optimización histórica debe tratarse como un módulo separado del upload interceptor.

---

# 25. Brizy

El objetivo específico es que Brizy reciba WebP desde el principio.

Flujo deseado:

```text
Brizy
  │
  ▼
Upload JPG
  │
  ▼
WordPress upload pipeline
  │
  ▼
Clevers Image Optimizer
  │
  ├── resize
  └── JPG → WebP
          │
          ▼
      WordPress
          │
          ▼
        Brizy
          │
          ▼
genera sus derivados desde WebP
```

La prueba con Brizy es obligatoria antes del release.

---

# 26. WooCommerce

Probar:

- imagen principal de producto;
- galería;
- importación manual;
- creación de producto desde admin;
- variaciones con imágenes;
- thumbnails generados por WordPress/WooCommerce.

El objetivo es que WooCommerce registre como attachment el WebP resultante.

---

# 27. Settings

Página simple:

```text
Clevers Image Optimizer

[✓] Activar optimización al subir

Formato
(WebP)

Calidad
[82]

Ancho máximo
[2560]

Alto máximo
[2560]

[✓] Convertir JPEG
[✓] Convertir PNG
[✓] Omitir WebP existentes
[ ] Optimizar WebP con binarios externos
[✓] Eliminar original después de conversión exitosa
```

Usar Settings API de WordPress.

---

# 28. Logging

Logger configurable.

No generar ruido en producción.

Niveles:

```text
error
warning
info
debug
```

Ejemplo:

```text
[2026-08-16 21:10:12]
Converted:
uploads/2026/08/producto.jpg
→
uploads/2026/08/producto.webp

Original: 4.8 MB
Final:    382 KB
```

---

# 29. Métricas opcionales

Guardar únicamente estadísticas agregadas:

```text
processed
converted
skipped
failed
bytes_before
bytes_after
```

No es necesario guardar un registro permanente por archivo en v1.

---

# 30. Seguridad

Validar:

- MIME real;
- existencia del archivo;
- ruta dentro de uploads;
- permisos;
- extensiones permitidas;
- errores del image editor.

Nunca construir comandos shell concatenando nombres de archivo proporcionados por usuario.

Si se usan binarios mediante Symfony Process, pasar argumentos como array.

---

# 31. Atomicidad

No eliminar el original antes de confirmar que:

1. el WebP fue escrito;
2. el archivo tiene tamaño > 0;
3. WordPress reconoce el MIME;
4. no existe `WP_Error`.

Flujo:

```text
source.jpg
   │
   ▼
crear source.tmp.webp
   │
   ▼
validar
   │
   ├── error → eliminar tmp / conservar source.jpg
   │
   ▼
renombrar/mover
   │
   ▼
actualizar upload
   │
   ▼
eliminar source.jpg
```

---

# 32. Tests

## Unitarios

### ImageValidator

- JPEG válido.
- PNG válido.
- WebP válido.
- MIME incorrecto.
- archivo inexistente.

### ImageConverter

- JPG → WebP.
- PNG → WebP.
- transparencia.
- resize.
- no enlargement.
- error del editor.

### Settings

- defaults.
- sanitización.
- valores fuera de rango.

---

## Integración

### Media Library

Subir:

```text
6000 × 4000 JPG
```

Esperado:

```text
WebP
<= 2560px
attachment válido
```

### Brizy

Subir directamente desde Brizy:

```text
foto.jpg
```

Validar:

- Media Library registra WebP.
- Brizy la muestra.
- frontend funciona.
- responsive funciona.
- no aparecen 404.

### WooCommerce

Validar:

- producto.
- galería.
- thumbnails.
- frontend.
- carrito no afectado.

---

# 33. Tests de regresión

Validar que no se rompan:

- PDF uploads.
- ZIP uploads.
- SVG si el sitio lo permite mediante otro plugin.
- uploads de documentos.
- imágenes ya WebP.
- imágenes pequeñas.
- REST API uploads.
- Elementor/Gutenberg si algún cliente los usa.

---

# 34. Criterios de aceptación v2

- [ ] JPG se convierte a WebP al subir.
- [ ] PNG se convierte a WebP al subir.
- [ ] PNG transparente conserva alpha.
- [ ] imágenes grandes se reducen.
- [ ] imágenes pequeñas no se agrandan.
- [ ] WebP existentes se omiten por defecto.
- [ ] GIF se ignora.
- [ ] SVG se ignora.
- [ ] si la conversión falla, el upload original continúa.
- [ ] el attachment tiene MIME correcto.
- [ ] la URL final termina en `.webp`.
- [ ] Media Library funciona.
- [ ] Brizy funciona.
- [ ] WooCommerce funciona.
- [ ] no hay 404 en thumbnails.
- [ ] existe diagnóstico del servidor.
- [ ] settings son sanitizados.
- [ ] logging no expone rutas sensibles innecesariamente.
- [ ] tests automáticos pasan.

---

# 35. Roadmap

## Fase 1

Refactor:

- namespaces;
- servicios;
- tests;
- settings;
- diagnostics.

## Fase 2

Upload pipeline:

- resize;
- conversión WebP;
- fallback;
- logging.

## Fase 3

Compatibilidad:

- Brizy;
- WooCommerce;
- Gutenberg.

## Fase 4

WP-CLI:

```text
analyze
optimize
```

## Fase 5

Bulk optimizer UI.

## Fase 6

AVIF opcional si el stack lo justifica.

---

# 36. Resultado final esperado

El ecosistema queda dividido en dos capas.

```text
ANTES DEL UPLOAD
──────────────────────────────────────

Figma
   │
   └── WebP
             ┐
Cliente      │
JPG/PNG      │
   │         │
   ▼         │
Node + Sharp │
   │         │
   └── WebP ─┘
       │
       ▼

WORDPRESS
──────────────────────────────────────

Clevers Image Optimizer
       │
       ├── WebP válido → skip
       │
       └── JPG/PNG → resize + WebP
                         │
                         ▼
                Brizy / WooCommerce
```

El flujo previo con Sharp reduce carga del servidor y normaliza el material.

El plugin garantiza que una imagen no optimizada que llegue directamente a WordPress no rompa el estándar definido por la agencia.
