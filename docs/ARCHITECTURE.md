# Architecture — Bubuku Post View Count

Detalle arquitectónico del plugin. Este documento complementa `AGENTS.md` con la información que un agente o desarrollador necesita para razonar sobre el código actual.

## Estado actual: PSR-4 por responsabilidad

El plugin usa autoload PSR-4 vía un autoloader propio (sin Composer en runtime — ver más abajo). Las clases viven en `src/{Core,Api,Frontend,Admin}/`, con namespace `Bubuku\Plugins\PostViewCount\{Core,Api,Frontend,Admin}` y nombres de clase sin prefijo (`Plugin`, `Db`, `RestApi`, `Assets`, `Settings`, `SettingsPage`) — ver `docs/MIGRATION-PSR4.md` para el mapeo histórico aplicado a las 4 clases originales.

Desde la 1.2.0 tiene 5 clases: además de las 4 originales, `Core\Schema` gestiona dos tablas propias y dos eventos de WP-Cron (ver `docs/ANALYTICS-PLAN.md`, Fase 1). La Fase 2 de ese plan añade `Admin\Settings` y `Admin\SettingsPage`: página de ajustes con CPT seleccionables, roles excluidos y filtro de bots. La 1.2.1 completa la Fase 3: `Core\Query` (§4.1) es la capa de consultas puras de solo lectura (más leído, contenido sin visitas recientes, estadísticas de un post, tendencia y resumen del sitio); `Mcp\SatelliteConnector` y las tools bajo `Mcp\Tools\` (§4.2–4.3) exponen esas consultas como satélite opcional del hub `bubuku-mcp-conex` — inerte si el hub no está activo, cero dependencia dura. La misma 1.2.1 añade también los dos extras marcados como opcionales en el plan: `Admin\PostListColumns` (§3.5, columnas ordenables «Views»/«Last view» en los listados admin) y `Cli\ViewsCommand` (§4.4, comandos `wp bbk-views`). También incluye la parte backend de la Fase F4 (tendencias): `Api\TrendsApi` (endpoint REST de lectura `GET /trends`, capability `edit_posts`) y la tool `Mcp\Tools\GetContentTrends`, ambas sobre `Core\Query::trend()` (ya con cache de objeto de 5 min, igual que `most_viewed()`). Una versión posterior sin publicar cierra el resto de F4: la UI (gráfica en el admin, comparativa de periodo, shortcode, bloque Gutenberg) y los listados «en alza»/«en caída» — `Core\Query::momentum()`, la ruta `GET /trends/momentum` y la tool `Mcp\Tools\ListMomentum`. La misma versión implementa la Fase F5 (dimensiones de sesión): tabla `bbk_post_view_dims`, `Core\Dimensions` (lista blanca), `Core\Query::dims_breakdown()`, la ruta `GET /trends/dims`, la tool `Mcp\Tools\GetDimsBreakdown` y la sección «Dispositivo y procedencia» en Ajustes → Post View Count — ver `docs/ANALYTICS-PLAN.md` §5/F5 y `docs/CHANGELOG.md` → `[Unreleased]`.

## Autoload — sin vendor/ en producción

`bubuku-post-view-count.php` registra un autoloader manual (`bbk_autoload`, vía `spl_autoload_register`) que resuelve `Bubuku\Plugins\PostViewCount\{Sub\Namespace}\{Clase}` → `src/{Sub/Namespace}/{Clase}.php` (convierte `\` en `/`). Esto evita distribuir `vendor/autoload.php` en el zip de producción, ya que el plugin no tiene dependencias runtime.

Composer (`composer.json`) se usa **solo como tooling de desarrollo**: PHPCS, PHPCompatibility y el autoload PSR-4 declarado (`Bubuku\Plugins\PostViewCount\` → `src/`) que Composer usa igualmente para los tests locales vía `vendor/autoload.php` cuando está instalado.

## PHP — clases en `src/`

`Core\Plugin` es el punto de entrada, instanciado en el bootstrap de `bubuku-post-view-count.php`. En `plugins_loaded` arranca los dos subsistemas del plugin.

| Clase | Archivo | Responsabilidad |
|---|---|---|
| `Core\Plugin` | `src/Core/Plugin.php` | Punto de entrada — registra `plugins_loaded`, activación/desactivación (delega en `Core\Schema`), arranca `Frontend\Assets` y `Api\RestApi` |
| `Frontend\Assets` | `src/Frontend/Assets.php` | Encola `assets/js/common.js` solo en `single` de tipo `post`, para visitantes sin `edit_posts`; localiza `bbk_post_view` con la URL REST y el `post_id` |
| `Api\RestApi` | `src/Api/RestApi.php` | Ruta REST `POST /bbk_postview/v1/set-post-views` — validación de `post_id`, control de origen same-site y deduplicación por transient |
| `Api\TrendsApi` | `src/Api/TrendsApi.php` | Rutas REST `GET /bbk_postview/v1/trends`, `GET /bbk_postview/v1/trends/momentum` y `GET /bbk_postview/v1/trends/dims`, capability `edit_posts` (`docs/ANALYTICS-PLAN.md` §4, F4/§5) — delegan en `Core\Query::trend()`, `Core\Query::momentum()` y `Core\Query::dims_breakdown()` respectivamente. Separada de `Api\RestApi` a propósito: esa clase es el contador público anónimo con su propio modelo de seguridad; esta es de lectura y con capability, un concern distinto |
| `Core\Db` | `src/Core/Db.php` | Acceso a datos — upserts atómicos en las tablas propias (incluidas las dimensiones de sesión opcionales de `record_view()`), espejo en post meta, lectura de estadísticas, borrado (meta y tablas) al desinstalar |
| `Core\Schema` | `src/Core/Schema.php` | Esquema de BD — `dbDelta()`, versionado, migración desde `postmeta` por lotes vía cron, purga programada del agregado diario y de las dimensiones de sesión, alta en sitios nuevos de multisitio |
| `Core\Dimensions` | `src/Core/Dimensions.php` | Única fuente de verdad de la lista blanca de dimensiones de sesión (`DIMENSIONS`, `VIEWPORT_BUCKETS`, `REFERRER_CLASSES`, `values_for()`) — consumida por `Api\RestApi` al escribir y por `Core\Query`/la tool MCP al leer, sin duplicación (`docs/ANALYTICS-PLAN.md` F5) |
| `Core\Query` | `src/Core/Query.php` | Consultas de solo lectura sobre las tablas propias — `most_viewed()`, `stale()`, `post_stats()`, `trend()`, `summary()`, `momentum()`, `dims_breakdown()`. `post_types` siempre intersectado con `Settings::enabled_post_types()`, `limit` con tope duro de 100 (salvo `dims_breakdown()`, de cardinalidad fija y pequeña), resultados con cache corta de objeto (5 min) donde aplica. Sin dependencia de MCP — reutilizable por la página de ajustes, WP-CLI o una futura tool (`docs/ANALYTICS-PLAN.md` §4.1). `momentum()` compara dos periodos consecutivos de igual longitud sobre `bbk_post_views_daily` y ordena por variación **absoluta**, no porcentual (§5). `dims_breakdown()` agrega `bbk_post_view_dims` por dimensión/valor, site-wide (F5) |
| `Admin\Settings` | `src/Admin/Settings.php` | Lectura/sanitización de la option `bbk_postview_settings` — CPT habilitados, roles excluidos, filtro de bots, retención, borrado al desinstalar. Única fuente de `enabled_post_types()`, consumida por `Frontend\Assets` y `Api\RestApi` |
| `Admin\SettingsPage` | `src/Admin/SettingsPage.php` | Página **Ajustes → Post View Count** (Settings API clásica, sin build step) y el botón «Eliminar todos los datos ahora» |
| `Admin\PostListColumns` | `src/Admin/PostListColumns.php` | Columnas «Views»/«Last view» en el listado admin de cada CPT habilitado (`docs/ANALYTICS-PLAN.md` §3.5). Muestra el espejo en post meta (sin queries extra — ya precargado por la lista de WP); ordena con un `LEFT JOIN` directo a `bbk_post_views` vía `posts_join`/`posts_orderby`, no con un `CAST` sobre `postmeta` |
| `Cli\ViewsCommand` | `src/Cli/ViewsCommand.php` | Comandos `wp bbk-views top/stale/post <id>` (`docs/ANALYTICS-PLAN.md` §4.4) — envoltorios de formato (`WP_CLI\Utils\format_items()`) sobre `Core\Query`, registrados solo si `WP_CLI` está definido |
| `Mcp\SatelliteConnector` | `src/Mcp/SatelliteConnector.php` | Conector satélite del hub `bubuku-mcp-conex` — detecta el hub (`class_exists('\BubukuConex\Registry')`), declara el satélite, registra las tools (carga perezosa) y aporta la entrada de catálogo. Sin hub activo: no hace nada, ni fatal ni aviso — diverge del skill `wp-mcp-conex` a propósito (plugin público, hub siempre opcional). Se cablea en `Core\Plugin::init_mcp_satellite()`, hookeado a `init` (no a `plugins_loaded`, para no cargar el text domain demasiado pronto) |
| `Mcp\Tools\ListMostViewed` | `src/Mcp/Tools/ListMostViewed.php` | Tool `bubuku-views/list-most-viewed` — delega en `Core\Query::most_viewed()` |
| `Mcp\Tools\ListStaleContent` | `src/Mcp/Tools/ListStaleContent.php` | Tool `bubuku-views/list-stale-content` — delega en `Core\Query::stale()` |
| `Mcp\Tools\GetPostViews` | `src/Mcp/Tools/GetPostViews.php` | Tool `bubuku-views/get-post-views` — resuelve `post_id` o `url` (`url_to_postid()`), delega en `Core\Query::post_stats()` |
| `Mcp\Tools\GetViewsSummary` | `src/Mcp/Tools/GetViewsSummary.php` | Tool `bubuku-views/get-views-summary` — delega en `Core\Query::summary()` |
| `Mcp\Tools\GetContentTrends` | `src/Mcp/Tools/GetContentTrends.php` | Tool `bubuku-views/get-content-trends` — delega en `Core\Query::trend()` |
| `Mcp\Tools\ListMomentum` | `src/Mcp/Tools/ListMomentum.php` | Tool `bubuku-views/list-momentum` — delega en `Core\Query::momentum()` (§5, listados «en alza»/«en caída») |
| `Mcp\Tools\GetDimsBreakdown` | `src/Mcp/Tools/GetDimsBreakdown.php` | Tool `bubuku-views/get-dims-breakdown` — delega en `Core\Query::dims_breakdown()` (F5, desglose por dispositivo/procedencia) |
| `Frontend\ViewsDisplay` | `src/Frontend/ViewsDisplay.php` | Renderizado "N vistas" (+ fecha de última visita opcional) compartido por el shortcode y el bloque — sin hooks propios, un único método estático. Cadena vacía si el CPT no está habilitado o el post no es público |
| `Frontend\Shortcode` | `src/Frontend/Shortcode.php` | Registra `[bbk_post_views]` (`post_id`, `show_last_viewed`); delega en `Frontend\ViewsDisplay` |
| `Frontend\Block` | `src/Frontend/Block.php` | Registra el bloque `bubuku/post-views` desde `assets/blocks/post-views/` en `init`; el `render_callback` (en `render.php`) delega en `Frontend\ViewsDisplay` |

Las siete tools extienden `BubukuConex\Abstract_Satellite_Tool` (clase del hub), así que solo pueden cargarse con el hub presente — el autoloader propio del plugin (`bbk_autoload`) ya es perezoso por diseño (`spl_autoload_register`), así que basta con instanciarlas solo dentro de `SatelliteConnector::register_tools()` para cumplir esa regla del contrato, sin mecanismo adicional.

No hay carpeta `includes/` — toda la lógica cabe en `src/`, incluida la capa MCP.

## Flujo de una vista

1. `Frontend\Assets::enqueue_front_assets()` decide si encolar el script (solo en `single` de un CPT habilitado — `Admin\Settings::enabled_post_types()`, visitante que no pertenece a un rol excluido — `Settings::is_current_user_excluded()`) y localiza `post_id` + URL del endpoint.
2. `assets/js/common.js` hace `fetch`/`sendBeacon` a `bbk_postview/v1/set-post-views` con el `post_id`, tras un pequeño delay (evita contar rebotes inmediatos), más `viewport` (bucket de ancho de pantalla) y `referrer` (clasificación de `document.referrer`, F5) calculados en el cliente.
3. `Api\RestApi::register_routes()` valida `post_id` (`validate_post_id` — debe pertenecer a un CPT habilitado y ser publicado y visible) y comprueba el `permission_callback` (`check_request_origin`). `viewport`/`referrer` son args opcionales sin `validate_callback` — un valor inválido no tumba la petición, se descarta más tarde.
4. `check_request_origin()` compara el origin/host normalizado de la petición con `home_url()` — solo acepta peticiones same-site; es intencionalmente anónimo (sin nonce) para funcionar detrás de full-page caching con visitantes deslogueados.
5. `set_post_views()` comprueba deduplicación (`is_deduped` — transient `bbk_view_{md5(post_id|ip|user_agent)}`, TTL `DEDUPE_TTL` = 30 minutos) y, si `exclude_bots` está activo, el User-Agent contra `Settings::is_bot_user_agent()`. Si cualquiera de las dos aplica, devuelve el estado actual (`Core\Db::get_stats()`) sin incrementar — ni la vista ni las dimensiones.
6. Si no, marca el transient (`mark_deduped`), valida `viewport`/`referrer` contra `Core\Dimensions::values_for()` (descartando en silencio cualquier valor fuera de la lista blanca) y delega en `Core\Db::record_view()`, que hace dos `INSERT ... ON DUPLICATE KEY UPDATE` atómicos (agregado + diario, ambos en UTC) más un tercer upsert por cada dimensión válida (`bbk_post_view_dims`), refleja el total y la fecha en post meta (`mirror_post_meta`, filtrable con `bbk_postview_mirror_meta`), e invalida la cache de objeto (`wp_cache_delete`). La respuesta REST incluye `count` y `last_viewed_at`.

## Constantes del plugin

Definidas directamente en `bubuku-post-view-count.php` (no en una clase, a diferencia de otros plugins Bubuku). Usar siempre en lugar de paths o URLs hardcodeados.

| Constante | Uso |
|---|---|
| `BBK_PLUGIN_FILE` | Ruta absoluta al archivo principal del plugin |
| `BBK_PLUGIN_PATH` | Ruta absoluta al directorio del plugin |
| `BBK_PLUGIN_URL` | URL al directorio del plugin |
| `BBK_PLUGIN_ASSETS_PATH` | Ruta absoluta a `assets/` |
| `BBK_PLUGIN_ASSETS_URL` | URL a `assets/` |
| `BBK_PLUGIN_ENDPOINTS_URL` | `'bbk_postview/v1'` — namespace REST |
| `BBK_PLUGIN_VERSION` | Leída de la cabecera `Version:` del PHP principal vía `get_file_data()` |

## Datos — tablas propias, con espejo en post meta

Desde la 1.2.0 la fuente de verdad son dos tablas propias, creadas por `Core\Schema` vía `dbDelta()`:

| Tabla | Clave primaria | Columnas | Uso |
|---|---|---|---|
| `{prefix}bbk_post_views` | `post_id` | `views`, `first_viewed_at`, `last_viewed_at` (UTC) | Total histórico por post — nunca se purga |
| `{prefix}bbk_post_views_daily` | `(post_id, day)` | `views` | Agregado diario — permite consultas exactas con ventana temporal; se purga por retención (`bbk_postview_purge_daily`, 400 días por defecto, opción `bbk_postview_settings['retention_days']`) |
| `{prefix}bbk_post_view_dims` | `(post_id, day, dimension, value)` + `KEY day_dimension_value (day, dimension, value)` | `views` | Dimensiones de sesión agregadas por día — `dimension` ∈ `viewport`\|`referrer` (lista blanca cerrada, `Core\Dimensions`), `value` ya clasificado (nunca host ni ancho exacto). Nunca por evento individual (F5, `docs/ANALYTICS-PLAN.md`). Se purga con la misma retención que el agregado diario |

Post meta (espejo, no fuente de verdad — filtrable con `bbk_postview_mirror_meta`):

| Meta key | Estructura | Dónde se gestiona |
|---|---|---|
| `views` | Entero, copia del total de la tabla | `Core\Db::mirror_post_meta()` (escritura), `Core\Db::remove_all_post_meta()` (borrado vía `delete_post_meta_by_key()`, llamado desde `uninstall.php`) |
| `views_last` | Fecha/hora UTC de la última visita, copia de la tabla | Igual que `views` |

Options: `bbk_schema_version` (versión de esquema instalada) y `bbk_postview_settings` (array gestionado por `Admin\Settings`/`Admin\SettingsPage` — Fase 2 de `docs/ANALYTICS-PLAN.md`: `post_types`, `excluded_roles`, `exclude_bots`, `retention_days`, `delete_data_on_uninstall`).

Migración desde la 1.1.x: `Core\Schema::migrate_batch()` copia `postmeta.views` a la tabla en lotes de 500 vía un evento cron reencolable, con `INSERT ... ON DUPLICATE KEY UPDATE views = GREATEST(...)` (idempotente). Las filas migradas quedan con `first_viewed_at`/`last_viewed_at` a `NULL` — ese dato no existía antes.

Deduplicación de visitas: transients `bbk_view_{md5(post_id|ip|user_agent)}` con TTL de 30 minutos (`Api\RestApi::DEDUPE_TTL`), no post meta ni tabla.

## JavaScript — `assets/js/`

No hay build step ni `assets/src/` — todo el JS del plugin es plano, servido directamente, sin proceso de compilación:

- `assets/js/common.js` — el script de conteo frontend, encolado con `strategy => defer` e `in_footer => true`. Calcula `viewport` (bucket de `window.innerWidth`) y `referrer` (clasificación de `document.referrer` vs `location.host`, F5) antes de enviar el POST — nunca el ancho exacto ni el host/URL en crudo.
- `assets/js/admin-stats.js` + `assets/css/admin-stats.css` — gráfica de evolución, comparativa de periodo, listados «en alza»/«en caída» y desglose por dispositivo/procedencia en Ajustes → Post View Count (`docs/ANALYTICS-PLAN.md` F4/F5 UI). `fetch()` contra `GET /bbk_postview/v1/trends`, `GET /bbk_postview/v1/trends/momentum` y `GET /bbk_postview/v1/trends/dims` con el nonce de `wp_rest`; el gráfico se pinta a mano con la Canvas 2D API, sin librería externa. Cada sección se carga y falla de forma independiente. Encolados solo en esa página admin.
- `assets/blocks/post-views/index.js` — editor script del bloque `bubuku/post-views` (ver más abajo).

## Bloque de Gutenberg — `assets/blocks/post-views/`

Bloque `bubuku/post-views`, deliberadamente **sin build step** (decisión tomada junto con el
usuario al implementar la UI de F4 — ver `docs/ANALYTICS-PLAN.md`):

| Archivo | Rol |
|---|---|
| `block.json` | Metadatos estáticos; `editorScript: "file:./index.js"`, `render: "file:./render.php"` |
| `index.js` | JS plano (sin JSX): `wp.blocks.registerBlockType` + `wp.element.createElement`; usa el componente core `ServerSideRender` para previsualizar en el editor el mismo render que ve el frontend |
| `index.asset.php` | Manifiesto de dependencias escrito a mano (`wp-blocks`, `wp-element`, `wp-block-editor`, `wp-components`, `wp-server-side-render`, `wp-i18n`) — el equivalente manual de lo que generaría `@wordpress/scripts`; WordPress ya sabe leerlo junto a un `editorScript` de tipo `file:` sin configuración adicional |
| `render.php` | `render_callback` del bloque — delega en `Frontend\ViewsDisplay::render()`, igual que el shortcode |

## Tests

`Tests/` contiene tests automatizados sin dependencias externas (sin PHPUnit) que simulan un subconjunto mínimo de WordPress (`Tests/bootstrap.php` define `TestWpdb`, `TestState`, y stubs de funciones WP en el namespace global —para que el fallback de PHP los resuelva sin importar el subnamespace del código que los llama— usados por `Core\Db` y `Api\RestApi`).

```bash
php Tests/run.php          # o: composer run-script test
```

Cubren: los dos upserts atómicos de `Core\Db::record_view()` (primera visita, incremento posterior, agregado diario por/entre días), `set_post_views()` como alias entero, idempotencia de `Core\Schema::migrate_batch()`, validación de origen same-site del endpoint REST, deduplicación de vistas repetidas (incluyendo el nuevo `last_viewed_at` en la respuesta), `Admin\Settings::enabled_post_types()`/`sanitize()` (descarta tipos y roles inexistentes, `retention_days` con `max(1, …)`), `validate_post_id()` frente a un CPT habilitado/deshabilitado, y el filtro `exclude_bots` en `set_post_views()`. `Core\Schema` no simula `dbDelta()` ni WP-Cron/multisitio en los tests — esa parte solo se valida manualmente en el entorno local (`AGENTS.md`). Igual para `Core\Query`: los tests cubren que un post type no habilitado nunca llega a consultarse (`most_viewed()`, `stale()`, `summary()`, `momentum()` devuelven vacío/cero sin tocar `$wpdb`) y que `post_stats()` refleja lo ya escrito por `Core\Db`; las consultas con `JOIN` a `{$wpdb->posts}` (filtrado real por tipo, `trend()`/`momentum()`) no tienen una tabla `wp_posts` simulada en el harness y se validan manualmente en `test.wp.local` (para `momentum()`: dos posts reales, uno subiendo de 5 a 50 vistas y otro bajando de 40 a 2 entre dos periodos de 30 días, insertados por WP-CLI). Para las tools MCP (`Mcp\Tools\*`), `Tests/bootstrap.php` define un stub mínimo de `BubukuConex\Abstract_Satellite_Tool` (namespace `BubukuConex`) solo con los métodos que estas tools sobrescriben, para poder instanciarlas y ejercer `execute_callback()` en aislado; los tests verifican que cada tool delega correctamente en su método de `Core\Query` correspondiente y da forma a la respuesta (incluido el caso de error de `GetPostViews` sin `post_id` ni `url`). `Mcp\SatelliteConnector` no tiene test automatizado — su integración real con el hub (declaración del satélite, registro de tools, entrada de catálogo) se valida manualmente contra el `BubukuConex\Registry` real en `test.wp.local`. `Admin\PostListColumns` y `Cli\ViewsCommand` tampoco tienen test automatizado — dependen de `WP_Query`/`WP_CLI` reales (ordenar por `posts_join`/`posts_orderby` requiere el propio motor de consultas de `WP_Query`, y `WP_CLI\Utils\format_items()` no tiene stub), así que tocan la misma categoría que `dbDelta()`/WP-Cron: se validan manualmente en `test.wp.local` (confirmado: `ORDER BY bbk_v.views DESC`/`bbk_v.last_viewed_at ASC` contra la query principal real, y los tres subcomandos `wp bbk-views top/stale/post` con datos reales). `Api\TrendsApi` sí tiene test automatizado (`check_permission()` frente a `current_user_can()`, y que `get_trends()`/`get_momentum()` delegan en `Core\Query::trend()`/`momentum()`) gracias a los stubs `current_user_can()` y `WP_REST_Response::header()` añadidos a `Tests/bootstrap.php`; se validó además en vivo contra `rest_get_server()->dispatch()` (401 sin capability, 200 con datos reales con ella, en ambas rutas). `Core\Query::dims_breakdown()` (F5) sigue el mismo tratamiento que `momentum()`/`trend()`: los tests cubren solo los corto-circuitos que nunca tocan `$wpdb` (dimensión desconocida, tipo de contenido deshabilitado); la agregación real con `JOIN`/`GROUP BY` contra `wp_posts` no tiene tabla simulada en el harness y se valida manualmente en `test.wp.local`. El upsert de `bbk_post_view_dims` en `Core\Db::record_view()` sí tiene test automatizado (agregación por repetición, ausencia de escritura cuando no se pasan dims) gracias a una nueva rama regex en `TestWpdb::query()` y a `TestState::$dims`, mismo patrón que `TestState::$daily`. La validación/descarte silencioso de un valor de dimensión inválido en `Api\RestApi::set_post_views()`, y que el guard de dedupe protege también las dims, tienen test dedicado.

Al añadir lógica nueva a `Core\Db`, `Core\Schema`, `Core\Query`, `Api\RestApi`, `Api\TrendsApi`, `Admin\Settings` o una tool `Mcp\Tools\*`, extender `Tests/run.php` con el mismo patrón (`bbk_test_same`, `bbk_test_error_status`) antes de dar el cambio por terminado.

## CI

`.github/workflows/validate.yml` corre en cada push a `main`/`develop` y en cada PR:

- **phpcs** — `composer run-script lint` (WordPress Coding Standards, `phpcs.xml`).
- **php-syntax** — `php -l` sobre todo el PHP en PHP 7.4, 8.1, 8.2 y 8.3, más `php Tests/run.php`.

`.github/workflows/deploy.yml` gestiona el release (ver `wp-build` para el detalle de empaquetado).

## Estructura de directorios

```
bubuku-post-view-count/
├─ skills/                       Symlinks a /Users/bubuku/dev/bubuku-plugins-wp/skills/
│  ├─ git-conventions/
│  ├─ wordpress-router/
│  ├─ wp-admin/
│  ├─ wp-build/
│  ├─ wp-coding/
│  ├─ wp-frontend/
│  ├─ wp-performance/
│  ├─ wp-php/
│  ├─ wp-plugin-development/
│  ├─ wp-rest-api/
│  ├─ wp-scaffold/
│  └─ wp-security/
├─ .claude/skills   ➜  ../skills
├─ .codex/skills    ➜  ../skills
├─ .gemini/skills   ➜  ../skills
├─ src/                          Clases PHP (PSR-4, por responsabilidad)
│  ├─ Core/
│  │  ├─ Plugin.php
│  │  ├─ Db.php
│  │  ├─ Schema.php
│  │  ├─ Query.php
│  │  ├─ Dimensions.php
│  │  └─ index.php
│  ├─ Api/
│  │  ├─ RestApi.php
│  │  ├─ TrendsApi.php
│  │  └─ index.php
│  ├─ Frontend/
│  │  ├─ Assets.php
│  │  ├─ ViewsDisplay.php
│  │  ├─ Shortcode.php
│  │  ├─ Block.php
│  │  └─ index.php
│  ├─ Admin/
│  │  ├─ Settings.php
│  │  ├─ SettingsPage.php
│  │  ├─ PostListColumns.php
│  │  └─ index.php
│  ├─ Mcp/
│  │  ├─ SatelliteConnector.php
│  │  ├─ Tools/
│  │  │  ├─ ListMostViewed.php
│  │  │  ├─ ListStaleContent.php
│  │  │  ├─ GetPostViews.php
│  │  │  ├─ GetViewsSummary.php
│  │  │  ├─ GetContentTrends.php
│  │  │  ├─ ListMomentum.php
│  │  │  ├─ GetDimsBreakdown.php
│  │  │  └─ index.php
│  │  └─ index.php
│  ├─ Cli/
│  │  ├─ ViewsCommand.php
│  │  └─ index.php
│  └─ index.php
├─ assets/
│  ├─ js/
│  │  ├─ common.js               Sin build step — JS plano
│  │  └─ admin-stats.js          Gráfica/comparativa/dims de F4-F5 — JS plano
│  ├─ css/
│  │  └─ admin-stats.css
│  └─ blocks/
│     └─ post-views/             Bloque bubuku/post-views — sin build step
│        ├─ block.json
│        ├─ index.js
│        ├─ index.asset.php      Manifiesto de dependencias escrito a mano
│        └─ render.php
├─ Tests/                        Tests dependency-free (sin PHPUnit)
│  ├─ bootstrap.php               Stubs mínimos de WordPress
│  ├─ run.php
│  └─ index.php
├─ vendor/                       Solo tooling de dev (PHPCS) — no se distribuye
├─ docs/
│  ├─ ARCHITECTURE.md            (este archivo)
│  ├─ CHANGELOG.md
│  ├─ MIGRATION-PSR4.md          (implementado — histórico del mapeo aplicado)
│  ├─ IMPROVEMENT-PLAN.md
│  └─ ANALYTICS-PLAN.md
├─ scripts/
│  ├─ build.sh
│  └─ setup-skills.sh
├─ .github/workflows/
│  ├─ validate.yml               phpcs + php-syntax + tests
│  └─ deploy.yml
├─ .wordpress-org/               Assets del listing de WordPress.org
├─ AGENTS.md
├─ CLAUDE.md                     @AGENTS.md
├─ GEMINI.md                     @AGENTS.md
├─ .github/copilot-instructions.md
├─ composer.json / composer.lock
├─ phpcs.xml
├─ readme.txt
├─ uninstall.php                 Borra el post meta `views` al desinstalar
├─ .distignore
└─ bubuku-post-view-count.php    Punto de entrada, constantes, autoloader, bootstrap
```

## Migración a PSR-4 sin prefijo

Implementada: `src/{Core,Api,Frontend}/` con clases sin prefijo (`Plugin`, `Db`, `RestApi`, `Assets`). Ver `docs/MIGRATION-PSR4.md` para el mapeo aplicado y las decisiones tomadas.
