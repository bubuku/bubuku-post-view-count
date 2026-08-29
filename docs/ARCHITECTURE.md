# Architecture — Bubuku Post View Count

Detalle arquitectónico del plugin. Este documento complementa `AGENTS.md` con la información que un agente o desarrollador necesita para razonar sobre el código actual.

## Estado actual: PSR-4 por responsabilidad

El plugin usa autoload PSR-4 vía un autoloader propio (sin Composer en runtime — ver más abajo). Las clases viven en `src/{Core,Api,Frontend,Admin}/`, con namespace `Bubuku\Plugins\PostViewCount\{Core,Api,Frontend,Admin}` y nombres de clase sin prefijo (`Plugin`, `Db`, `RestApi`, `Assets`, `Settings`, `SettingsPage`) — ver `docs/MIGRATION-PSR4.md` para el mapeo histórico aplicado a las 4 clases originales.

Desde la 1.2.0 tiene 5 clases: además de las 4 originales, `Core\Schema` gestiona dos tablas propias y dos eventos de WP-Cron (ver `docs/ANALYTICS-PLAN.md`, Fase 1). La Fase 2 de ese plan añade `Admin\Settings` y `Admin\SettingsPage`: página de ajustes con CPT seleccionables, roles excluidos y filtro de bots. La 1.2.1 añade `Core\Query` (§4.1 de la Fase 3): capa de consultas puras de solo lectura (más leído, contenido sin visitas recientes, estadísticas de un post, tendencia y resumen del sitio) sobre las mismas dos tablas — sin ninguna dependencia de MCP todavía. El conector satélite y las tools MCP (resto de la Fase 3) siguen pendientes.

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
| `Core\Db` | `src/Core/Db.php` | Acceso a datos — upserts atómicos en las tablas propias, espejo en post meta, lectura de estadísticas, borrado (meta y tablas) al desinstalar |
| `Core\Schema` | `src/Core/Schema.php` | Esquema de BD — `dbDelta()`, versionado, migración desde `postmeta` por lotes vía cron, purga programada del agregado diario, alta en sitios nuevos de multisitio |
| `Core\Query` | `src/Core/Query.php` | Consultas de solo lectura sobre las tablas propias — `most_viewed()`, `stale()`, `post_stats()`, `trend()`, `summary()`. `post_types` siempre intersectado con `Settings::enabled_post_types()`, `limit` con tope duro de 100, resultados con cache corta de objeto (5 min) donde aplica. Sin dependencia de MCP — reutilizable por la página de ajustes, WP-CLI o una futura tool (`docs/ANALYTICS-PLAN.md` §4.1) |
| `Admin\Settings` | `src/Admin/Settings.php` | Lectura/sanitización de la option `bbk_postview_settings` — CPT habilitados, roles excluidos, filtro de bots, retención, borrado al desinstalar. Única fuente de `enabled_post_types()`, consumida por `Frontend\Assets` y `Api\RestApi` |
| `Admin\SettingsPage` | `src/Admin/SettingsPage.php` | Página **Ajustes → Post View Count** (Settings API clásica, sin build step) y el botón «Eliminar todos los datos ahora» |

No hay carpeta `includes/` ni capa de tools — toda la lógica cabe en `src/`.

## Flujo de una vista

1. `Frontend\Assets::enqueue_front_assets()` decide si encolar el script (solo en `single` de un CPT habilitado — `Admin\Settings::enabled_post_types()`, visitante que no pertenece a un rol excluido — `Settings::is_current_user_excluded()`) y localiza `post_id` + URL del endpoint.
2. `assets/js/common.js` hace `fetch`/`sendBeacon` a `bbk_postview/v1/set-post-views` con el `post_id`, tras un pequeño delay (evita contar rebotes inmediatos).
3. `Api\RestApi::register_routes()` valida `post_id` (`validate_post_id` — debe pertenecer a un CPT habilitado y ser publicado y visible) y comprueba el `permission_callback` (`check_request_origin`).
4. `check_request_origin()` compara el origin/host normalizado de la petición con `home_url()` — solo acepta peticiones same-site; es intencionalmente anónimo (sin nonce) para funcionar detrás de full-page caching con visitantes deslogueados.
5. `set_post_views()` comprueba deduplicación (`is_deduped` — transient `bbk_view_{md5(post_id|ip|user_agent)}`, TTL `DEDUPE_TTL` = 30 minutos) y, si `exclude_bots` está activo, el User-Agent contra `Settings::is_bot_user_agent()`. Si cualquiera de las dos aplica, devuelve el estado actual (`Core\Db::get_stats()`) sin incrementar.
6. Si no, marca el transient (`mark_deduped`) y delega en `Core\Db::record_view()`, que hace dos `INSERT ... ON DUPLICATE KEY UPDATE` atómicos (agregado + diario, ambos en UTC), refleja el total y la fecha en post meta (`mirror_post_meta`, filtrable con `bbk_postview_mirror_meta`), e invalida la cache de objeto (`wp_cache_delete`). La respuesta REST incluye `count` y `last_viewed_at`.

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
| `{prefix}bbk_post_views_daily` | `(post_id, day)` | `views` | Agregado diario — permite consultas exactas con ventana temporal (fases futuras); se purga por retención (`bbk_postview_purge_daily`, 400 días por defecto, opción `bbk_postview_settings['retention_days']`) |

Post meta (espejo, no fuente de verdad — filtrable con `bbk_postview_mirror_meta`):

| Meta key | Estructura | Dónde se gestiona |
|---|---|---|
| `views` | Entero, copia del total de la tabla | `Core\Db::mirror_post_meta()` (escritura), `Core\Db::remove_all_post_meta()` (borrado vía `delete_post_meta_by_key()`, llamado desde `uninstall.php`) |
| `views_last` | Fecha/hora UTC de la última visita, copia de la tabla | Igual que `views` |

Options: `bbk_schema_version` (versión de esquema instalada) y `bbk_postview_settings` (array gestionado por `Admin\Settings`/`Admin\SettingsPage` — Fase 2 de `docs/ANALYTICS-PLAN.md`: `post_types`, `excluded_roles`, `exclude_bots`, `retention_days`, `delete_data_on_uninstall`).

Migración desde la 1.1.x: `Core\Schema::migrate_batch()` copia `postmeta.views` a la tabla en lotes de 500 vía un evento cron reencolable, con `INSERT ... ON DUPLICATE KEY UPDATE views = GREATEST(...)` (idempotente). Las filas migradas quedan con `first_viewed_at`/`last_viewed_at` a `NULL` — ese dato no existía antes.

Deduplicación de visitas: transients `bbk_view_{md5(post_id|ip|user_agent)}` con TTL de 30 minutos (`Api\RestApi::DEDUPE_TTL`), no post meta ni tabla.

## JavaScript — `assets/js/`

No hay build step ni `assets/src/` — `assets/js/common.js` es JS plano servido directamente, sin proceso de compilación. Se encola con `strategy => defer` e `in_footer => true`.

## Tests

`Tests/` contiene tests automatizados sin dependencias externas (sin PHPUnit) que simulan un subconjunto mínimo de WordPress (`Tests/bootstrap.php` define `TestWpdb`, `TestState`, y stubs de funciones WP en el namespace global —para que el fallback de PHP los resuelva sin importar el subnamespace del código que los llama— usados por `Core\Db` y `Api\RestApi`).

```bash
php Tests/run.php          # o: composer run-script test
```

Cubren: los dos upserts atómicos de `Core\Db::record_view()` (primera visita, incremento posterior, agregado diario por/entre días), `set_post_views()` como alias entero, idempotencia de `Core\Schema::migrate_batch()`, validación de origen same-site del endpoint REST, deduplicación de vistas repetidas (incluyendo el nuevo `last_viewed_at` en la respuesta), `Admin\Settings::enabled_post_types()`/`sanitize()` (descarta tipos y roles inexistentes, `retention_days` con `max(1, …)`), `validate_post_id()` frente a un CPT habilitado/deshabilitado, y el filtro `exclude_bots` en `set_post_views()`. `Core\Schema` no simula `dbDelta()` ni WP-Cron/multisitio en los tests — esa parte solo se valida manualmente en el entorno local (`AGENTS.md`). Igual para `Core\Query`: los tests cubren que un post type no habilitado nunca llega a consultarse (`most_viewed()`, `stale()`, `summary()` devuelven vacío/cero sin tocar `$wpdb`) y que `post_stats()` refleja lo ya escrito por `Core\Db`; las consultas con `JOIN` a `{$wpdb->posts}` (filtrado real por tipo, `trend()`) no tienen una tabla `wp_posts` simulada en el harness y se validan manualmente en `test.wp.local`. Al añadir lógica nueva a `Core\Db`, `Core\Schema`, `Core\Query`, `Api\RestApi` o `Admin\Settings`, extender `Tests/run.php` con el mismo patrón (`bbk_test_same`, `bbk_test_error_status`) antes de dar el cambio por terminado.

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
│  │  └─ index.php
│  ├─ Api/
│  │  ├─ RestApi.php
│  │  └─ index.php
│  ├─ Frontend/
│  │  ├─ Assets.php
│  │  └─ index.php
│  ├─ Admin/
│  │  ├─ Settings.php
│  │  ├─ SettingsPage.php
│  │  └─ index.php
│  └─ index.php
├─ assets/
│  └─ js/
│     └─ common.js               Sin build step — JS plano
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
