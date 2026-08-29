# Changelog

Todos los cambios relevantes de este proyecto se documentan aquí.
Formato basado en [Keep a Changelog](https://keepachangelog.com/).

## [1.1.0] - 2026-08-28
### Corregido
- El zip de producción no incluía `vendor/`, provocando un fatal error al activar el plugin (autoloader ausente). Solución definitiva: el plugin ya no depende de Composer en runtime — usa un autoloader PSR-4 propio (`spl_autoload_register`) de ~15 líneas, por lo que `vendor/` (dependencias de desarrollo: PHPCS, WPCS) nunca se empaqueta ni hace falta en producción.
- Condición de carrera en el contador de vistas: el incremento ahora es atómico vía `UPDATE ... SET meta_value = meta_value + 1`.
- `wp_enqueue_script()` recibía el parámetro `$in_footer` mal posicionado: el script se imprimía en el `<head>` con jQuery como dependencia innecesaria.
- `uninstall.php` no limpiaba multisitio.
### Seguridad
- El endpoint `POST /set-post-views` validaba mal el nonce (condición `&&` en vez de `||`, comparación directa en vez de `wp_verify_nonce()`) y aceptaba cualquier `post_id`, incluidos IDs inexistentes o de contenido no público.
- Se sustituyó el nonce (ineficaz detrás de caché de página) por validación estricta del `post_id` (post publicado y públicamente visible), deduplicación por visitante vía transient, y una comprobación de origen same-site (`check_request_origin`) como `permission_callback`.
### Cambiado
- La versión del plugin (`BBK_PLUGIN_VERSION`) se deriva ahora de la cabecera del archivo principal en lugar de estar duplicada en tres sitios.
- `Requires PHP` subido a 7.4 y `Requires at least` a 5.7 (uso de `is_post_publicly_viewable()`).
- El script del cliente usa `navigator.sendBeacon()` (con *fallback* a `fetch` con `keepalive`) y descarta pestañas en segundo plano.
- Se eliminó la carga de traducciones (`load_plugin_textdomain()`): WordPress.org las sirve automáticamente desde 4.6 para plugins públicos, y la implementación previa cargaba desde una ruta incorrecta (nunca funcionó).
- Migración PSR-4 completa (`docs/MIGRATION-PSR4.md`): clases movidas a `src/{Core,Api,Frontend}/` y renombradas sin prefijo (`Plugin`, `Db`, `RestApi`, `Assets`). El autoloader propio (`bbk_autoload`) ahora resuelve subnamespaces.
- Añadidos tests automatizados sin dependencias externas (`Tests/run.php`, sin PHPUnit) que cubren el incremento atómico, la validación de origen y la deduplicación; integrados en CI (`validate.yml`).
- `phpcs.xml` excluye los skills enlazados (`.claude/`, `.codex/`, `.gemini/`, `skills/`) para que `composer run-script lint` no falle por código de referencia ajeno al plugin.

## [1.0.4] - 2024-05-24
### Cambiado
- Compatibilidad: WordPress 6.2 – WordPress 6.5.3

## [1.0.3]
### Cambiado
- Compatibilidad: WordPress 6.1 – WordPress 6.2
### Corregido
- Errores de PHP

## [1.0.2]
### Cambiado
- Actualizado para WordPress 6.1
### Corregido
- Problemas de internacionalización

## [1.0.1]
### Corregido
- Se contaba también en categorías

## [1.0.0]
### Añadido
- Versión inicial.
