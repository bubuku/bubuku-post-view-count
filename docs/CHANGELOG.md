# Changelog

Todos los cambios relevantes de este proyecto se documentan aquí.
Formato basado en [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]
### Añadido
- UI de la Fase F4 de `docs/ANALYTICS-PLAN.md`: gráfica de evolución (día/semana/mes) y comparativa de vistas del periodo actual vs. el anterior en Ajustes → Post View Count, dibujada a mano con la Canvas API (sin librería de gráficos ni CDN) sobre el endpoint `GET /bbk_postview/v1/trends` ya existente.
- Shortcode `[bbk_post_views]` (atributos `post_id`, `show_last_viewed`) y bloque de Gutenberg equivalente `bubuku/post-views` (`assets/blocks/post-views/`), ambos delegando en la nueva `Frontend\ViewsDisplay` para no duplicar el renderizado. El bloque se registra sin build step: `index.js` a mano (sin JSX) con un `index.asset.php` manual como equivalente del manifiesto que generaría `@wordpress/scripts`, y `render.php` como `render_callback` server-side.
- Cierre de la Fase F4: listados «en alza»/«en caída» entre dos periodos consecutivos. `Core\Query::momentum()` compara los últimos N días contra los N anteriores sobre `bbk_post_views_daily`, ordenando por variación absoluta de vistas (`min_views` filtra el ruido de posts que pasan de 0 a 1 vista). Expuesto en tres superficies sin duplicar SQL: `GET /bbk_postview/v1/trends/momentum` (`Api\TrendsApi`), la tool MCP `bubuku-views/list-momentum`, y dos listas nuevas en Ajustes → Post View Count pintadas por `assets/js/admin-stats.js`. Ver la nota `✅ implementados` en `docs/ANALYTICS-PLAN.md` (F4) para el criterio elegido y su validación.

## [1.2.1] - 2026-08-29
### Añadido
- Fase 3 de `docs/ANALYTICS-PLAN.md` completa: `Core\Query`, capa de consultas de solo lectura sobre las tablas propias — `most_viewed()`, `stale()`, `post_stats()`, `trend()`, `summary()`. `post_types` siempre intersectado con los tipos habilitados, `limit` con tope duro de 100, cache corta de objeto (5 min) en `most_viewed()`.
- Satélite opcional del hub `bubuku-mcp-conex`: `Mcp\SatelliteConnector` y cuatro tools MCP (`bubuku-views/list-most-viewed`, `bubuku-views/list-stale-content`, `bubuku-views/get-post-views`, `bubuku-views/get-views-summary`), todas delegando en `Core\Query`. Inerte si el hub no está activo — sin cabecera `Requires Plugins`, sin admin notice; el estado de la conexión no se muestra todavía en la página de ajustes (extra pendiente, ver §3.5 del plan). Validado contra el hub real: registro sin rechazos, satélite declarado compatible, catálogo servido.
- `Core\Schema::daily_data_since()`: fecha desde la que el agregado diario tiene datos (nueva option `bbk_postview_daily_since`, escrita una sola vez en la instalación), expuesta por la tool `list-most-viewed` para que el consumidor sepa cuándo una ventana de fechas puede estar incompleta.
- `Admin\PostListColumns`: columnas «Views»/«Last view» ordenables en el listado admin de cada tipo de contenido habilitado — el valor se lee del espejo en post meta (sin queries extra), el orden usa un `LEFT JOIN` indexado contra la tabla propia.
- `Cli\ViewsCommand`: comandos `wp bbk-views top`, `wp bbk-views stale` y `wp bbk-views post <id>`, envoltorios de formato sobre `Core\Query`; solo se registran si WP-CLI está presente.
- Backend de la Fase F4 (tendencias): `Api\TrendsApi` (`GET /bbk_postview/v1/trends`, capability `edit_posts`, cacheable) y la tool MCP `bubuku-views/get-content-trends`, ambas sobre `Core\Query::trend()` (que ahora también tiene cache de objeto de 5 min, como `most_viewed()`). La UI de F4 (gráfica admin, shortcode, bloque Gutenberg) queda para una iteración aparte.
### Arreglado
- Warnings de Plugin Check en el zip de `dist/`: nonce verification en la lectura del flag `bbk_postview_reset` de `Admin\SettingsPage` (falso positivo, es solo lectura para mostrar un notice), y direct-DB-call / no-caching / unescaped-parameter en las consultas propias de `Core\Db`, `Core\Schema` y `uninstall.php` sobre las tablas propias del plugin — documentadas con `phpcs:ignore` justificado, sin cambios de comportamiento.

## [1.2.0] - 2026-08-29
### Añadido
- Fase 1 de `docs/ANALYTICS-PLAN.md`: modelo de datos propio. Nuevas tablas `{prefix}bbk_post_views` (total, primera y última visita) y `{prefix}bbk_post_views_daily` (agregado diario, base para consultas con ventana temporal en fases futuras).
- `Core\Schema`: creación/actualización de esquema (`dbDelta()`), versionado (`bbk_schema_version`), migración por lotes desde `postmeta` vía WP-Cron (`bbk_postview_migrate_batch`, idempotente), purga programada del agregado diario (`bbk_postview_purge_daily`, retención configurable, 400 días por defecto), y soporte multisitio (`activate( $network_wide )`, `wp_initialize_site`).
- `Core\Db::record_view()`: dos upserts atómicos (`INSERT ... ON DUPLICATE KEY UPDATE`) sin lectura previa en PHP. `Core\Db::get_stats()` para leer el estado actual desde la tabla.
- El post meta `views` se conserva como espejo de compatibilidad (temas/consultas de terceros que ya lo leen); se añade `views_last` con la fecha de la última visita. Desactivable con el filtro `bbk_postview_mirror_meta`.
- La respuesta del endpoint `POST /set-post-views` incluye ahora `last_viewed_at` junto al `count` ya existente.
- Fase 2 de `docs/ANALYTICS-PLAN.md`: página de ajustes en Ajustes → Post View Count (`Admin\Settings`, `Admin\SettingsPage`). Permite elegir qué tipos de contenido cuentan visitas (por defecto solo `post`, igual que antes), excluir roles de usuario (por defecto los que ya pueden editar contenido), excluir bots y crawlers conocidos (Googlebot, Bingbot, GPTBot, ClaudeBot, etc.), configurar la retención del agregado diario y eliminar todos los datos registrados con un botón, sin desinstalar el plugin.
### Cambiado
- `Core\Db::set_post_views()` se conserva como alias delgado de `record_view()` (compatibilidad con consumidores existentes).
- `uninstall.php` reescrito: además de la meta, borra las tablas propias, las options (`bbk_postview_settings`, `bbk_schema_version`) y los transients de deduplicación (`bbk_view_*`), siempre en multisitio. Si borrar los datos al desinstalar ahora es configurable desde la página de ajustes (activado por defecto, según las guidelines de WordPress.org).
- `Frontend\Assets` y `Api\RestApi` ya no hardcodean `'post'` ni `current_user_can('edit_posts')`: consumen `Admin\Settings::enabled_post_types()` e `is_current_user_excluded()`.
### Notas
- Cambio de modelo de datos (riesgo alto, ver `docs/ANALYTICS-PLAN.md` §1). El post meta `views` nunca se borra durante la migración: revertir a `1.1.x` es seguro, el contador sigue funcionando desde donde estaba.
- Ninguna de las tres capas de seguridad del endpoint (origen same-site, validación de `post_id`, deduplicación) se ha tocado; el filtro de bots es una capa adicional, no un reemplazo.
- La retención del agregado diario solo afecta a mostrar el desglose diario de cada contenido (tendencias, ventanas temporales); el total de vistas nunca se ve afectado ni se borra por esta retención.
- Fase 3 (consultas + satélite MCP) queda para una versión posterior (`1.4.0` sugerida en el propio plan), no incluida en esta versión.

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
