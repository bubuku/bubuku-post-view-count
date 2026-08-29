# Changelog

Todos los cambios relevantes de este proyecto se documentan aquí.
Formato basado en [Keep a Changelog](https://keepachangelog.com/).

## [1.2.0] - 2026-08-29
### Añadido
- Fase 1 de `docs/ANALYTICS-PLAN.md`: modelo de datos propio. Nuevas tablas `{prefix}bbk_post_views` (total, primera y última visita) y `{prefix}bbk_post_views_daily` (agregado diario, base para consultas con ventana temporal en fases futuras).
- `Core\Schema`: creación/actualización de esquema (`dbDelta()`), versionado (`bbk_schema_version`), migración por lotes desde `postmeta` vía WP-Cron (`bbk_postview_migrate_batch`, idempotente), purga programada del agregado diario (`bbk_postview_purge_daily`, retención configurable, 400 días por defecto), y soporte multisitio (`activate( $network_wide )`, `wp_initialize_site`).
- `Core\Db::record_view()`: dos upserts atómicos (`INSERT ... ON DUPLICATE KEY UPDATE`) sin lectura previa en PHP. `Core\Db::get_stats()` para leer el estado actual desde la tabla.
- El post meta `views` se conserva como espejo de compatibilidad (temas/consultas de terceros que ya lo leen); se añade `views_last` con la fecha de la última visita. Desactivable con el filtro `bbk_postview_mirror_meta`.
- La respuesta del endpoint `POST /set-post-views` incluye ahora `last_viewed_at` junto al `count` ya existente.
### Cambiado
- `Core\Db::set_post_views()` se conserva como alias delgado de `record_view()` (compatibilidad con consumidores existentes).
- `uninstall.php` reescrito: además de la meta, borra las tablas propias, las options (`bbk_postview_settings`, `bbk_schema_version`) y los transients de deduplicación (`bbk_view_*`), siempre en multisitio. Por defecto borra todo (recomendación de las guidelines de WordPress.org); la opción para conservar los datos llegará con la página de ajustes de la Fase 2.
### Notas
- Cambio de modelo de datos (riesgo alto, ver `docs/ANALYTICS-PLAN.md` §1). El post meta `views` nunca se borra durante la migración: revertir a `1.1.x` es seguro, el contador sigue funcionando desde donde estaba.
- Ninguna de las tres capas de seguridad del endpoint (origen same-site, validación de `post_id`, deduplicación) se ha tocado.
- Fases 2 (página de ajustes y CPTs seleccionables) y 3 (consultas + satélite MCP) quedan para versiones posteriores (`1.3.0`, `1.4.0` sugeridas en el propio plan), no incluidas en esta versión.

## [1.1.1] - 2026-08-29
### Seguridad
- El endpoint `POST /set-post-views` ahora también comprueba el `Origin`/`Referer` de la petición contra la URL del propio sitio (`check_request_origin` como `permission_callback`), rechazando peticiones cross-origin desde el navegador, además de la validación de `post_id` y la deduplicación ya existentes.
### Cambiado
- Se eliminó la carga de traducciones (`load_plugin_textdomain()`): WordPress.org las sirve automáticamente desde 4.6 para plugins públicos, y la implementación previa cargaba desde una ruta incorrecta (nunca funcionó).
- Migración PSR-4 completa (`docs/MIGRATION-PSR4.md`): clases movidas a `src/{Core,Api,Frontend}/` y renombradas sin prefijo (`Plugin`, `Db`, `RestApi`, `Assets`). El autoloader propio (`bbk_autoload`) ahora resuelve subnamespaces. Sin cambio funcional para el visitante del sitio.
- Añadidos tests automatizados sin dependencias externas (`Tests/run.php`, sin PHPUnit) que cubren el incremento atómico, la validación de origen y la deduplicación; integrados en CI (`validate.yml`).
- `phpcs.xml` excluye los skills enlazados (`.claude/`, `.codex/`, `.gemini/`, `skills/`) para que `composer run-script lint` no falle por código de referencia ajeno al plugin.
- `Requires at least` subido a 6.2 en la cabecera del plugin, para que coincida con `readme.txt`.

## [1.1.0] - 2026-08-28
### Corregido
- El zip de producción no incluía `vendor/`, provocando un fatal error al activar el plugin (autoloader ausente). Solución definitiva: el plugin ya no depende de Composer en runtime — usa un autoloader PSR-4 propio (`spl_autoload_register`) de ~15 líneas, por lo que `vendor/` (dependencias de desarrollo: PHPCS, WPCS) nunca se empaqueta ni hace falta en producción.
- Condición de carrera en el contador de vistas: el incremento ahora es atómico vía `UPDATE ... SET meta_value = meta_value + 1`.
- `wp_enqueue_script()` recibía el parámetro `$in_footer` mal posicionado: el script se imprimía en el `<head>` con jQuery como dependencia innecesaria.
- Las traducciones nunca se cargaban por una ruta incorrecta en `load_plugin_textdomain()`.
- `uninstall.php` no limpiaba multisitio.
### Seguridad
- El endpoint `POST /set-post-views` validaba mal el nonce (condición `&&` en vez de `||`, comparación directa en vez de `wp_verify_nonce()`) y aceptaba cualquier `post_id`, incluidos IDs inexistentes o de contenido no público.
- Se sustituyó el nonce (ineficaz detrás de caché de página) por validación estricta del `post_id` (post publicado y públicamente visible) y deduplicación por visitante vía transient.
### Cambiado
- La versión del plugin (`BBK_PLUGIN_VERSION`) se deriva ahora de la cabecera del archivo principal en lugar de estar duplicada en tres sitios.
- `Requires PHP` subido a 7.4 y `Requires at least` a 5.7 (uso de `is_post_publicly_viewable()`).
- El script del cliente usa `navigator.sendBeacon()` (con *fallback* a `fetch` con `keepalive`) y descarta pestañas en segundo plano.

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
