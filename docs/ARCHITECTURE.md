# Architecture — Bubuku Post View Count

Detalle arquitectónico del plugin. Este documento complementa `AGENTS.md` con la información que un agente o desarrollador necesita para razonar sobre el código actual.

## Estado actual: PSR-4 con nombres de clase legacy

El plugin **ya usa autoload PSR-4** vía un autoloader propio (sin Composer en runtime — ver más abajo). Las clases viven en `src/PCV_*.php`, con namespace `Bubuku\Plugins\PostViewCount` correcto, pero mantienen el prefijo plano `PCV_` heredado del estilo pre-namespace en el nombre de la clase.

El objetivo a largo plazo es eliminar el prefijo `PCV_` y organizar `src/` en subcarpetas por responsabilidad (`Core/`, `Api/`, `Frontend/`) — ver `docs/MIGRATION-PSR4.md` para el mapeo completo clase a clase. No aplicado automáticamente por tratarse de un cambio de riesgo medio (renombrado de clases usadas en hooks/callbacks).

Es un plugin deliberadamente pequeño (4 clases): no tiene capa de tools/abilities, ni WP-Cron, ni tabla propia en BD — solo un post meta (`views`) y un endpoint REST.

## Autoload — sin vendor/ en producción

`bubuku-post-view-count.php` registra un autoloader manual (`bbk_autoload`, vía `spl_autoload_register`) que resuelve `Bubuku\Plugins\PostViewCount\{Clase}` → `src/{Clase}.php`. Esto evita distribuir `vendor/autoload.php` en el zip de producción, ya que el plugin no tiene dependencias runtime.

Composer (`composer.json`) se usa **solo como tooling de desarrollo**: PHPCS, PHPCompatibility y el autoload PSR-4 declarado (`Bubuku\Plugins\PostViewCount\` → `src/`) que Composer usa igualmente para los tests locales vía `vendor/autoload.php` cuando está instalado.

## PHP — clases en `src/`

`PCV_plugin` es el punto de entrada, instanciado en el bootstrap de `bubuku-post-view-count.php`. En `plugins_loaded` arranca los dos subsistemas del plugin.

| Clase | Archivo | Responsabilidad |
|---|---|---|
| `PCV_plugin` | `PCV_plugin.php` | Punto de entrada — registra `plugins_loaded`, activación/desactivación, arranca `PCV_assets` y `PCV_restapi` |
| `PCV_assets` | `PCV_assets.php` | Encola `assets/js/common.js` solo en `single` de tipo `post`, para visitantes sin `edit_posts`; localiza `bbk_post_view` con la URL REST y el `post_id` |
| `PCV_restapi` | `PCV_restapi.php` | Ruta REST `POST /bbk_postview/v1/set-post-views` — validación de `post_id`, control de origen same-site y deduplicación por transient |
| `PCV_db` | `PCV_db.php` | Acceso a datos — incremento atómico del post meta `views` vía `$wpdb->query()` con fallback a `add_post_meta()`, y borrado global del meta al desinstalar |

No hay carpeta `includes/` ni capa de tools — toda la lógica cabe en `src/`.

## Flujo de una vista

1. `PCV_assets::enqueue_front_assets()` decide si encolar el script (solo en `single` de `post`, visitante no editor) y localiza `post_id` + URL del endpoint.
2. `assets/js/common.js` hace `fetch` a `bbk_postview/v1/set-post-views` con el `post_id`, tras un pequeño delay (evita contar rebotes inmediatos).
3. `PCV_restapi::register_routes()` valida `post_id` (`validate_post_id` — debe ser un `post` publicado y visible) y comprueba el `permission_callback` (`check_request_origin`).
4. `check_request_origin()` compara el origin/host normalizado de la petición con `home_url()` — solo acepta peticiones same-site; es intencionalmente anónimo (sin nonce) para funcionar detrás de full-page caching con visitantes deslogueados.
5. `set_post_views()` comprueba deduplicación (`is_deduped` — transient `bbk_view_{md5(post_id|ip|user_agent)}`, TTL `DEDUPE_TTL` = 30 minutos). Si ya está deduplicado, devuelve el contador actual sin incrementar.
6. Si no está deduplicado, marca el transient (`mark_deduped`) y delega en `PCV_db::set_post_views()`, que hace un `UPDATE` atómico de `wp_postmeta.meta_value` (o crea el meta con `add_post_meta()` si no existía), invalida la cache de objeto (`wp_cache_delete`) y devuelve el nuevo total.

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

## Datos — post meta, sin tabla propia ni options

El plugin no crea tablas ni opciones. Todo el estado vive en un único post meta:

| Meta key | Estructura | Dónde se gestiona |
|---|---|---|
| `views` | Entero, contador acumulado por post | `PCV_db::set_post_views()` (incremento), `PCV_db::remove_all_post_meta()` (borrado global vía `delete_post_meta_by_key()`, llamado desde `uninstall.php`) |

Deduplicación de visitas: transients `bbk_view_{md5(post_id|ip|user_agent)}` con TTL de 30 minutos (`PCV_restapi::DEDUPE_TTL`), no post meta ni tabla.

## JavaScript — `assets/js/`

No hay build step ni `assets/src/` — `assets/js/common.js` es JS plano servido directamente, sin proceso de compilación. Se encola con `strategy => defer` e `in_footer => true`.

## Tests

`Tests/` contiene tests automatizados sin dependencias externas (sin PHPUnit) que simulan un subconjunto mínimo de WordPress (`Tests/bootstrap.php` define `TestWpdb`, `TestState`, stubs de funciones WP usadas por `PCV_db` y `PCV_restapi`).

```bash
php Tests/run.php          # o: composer run-script test
```

Cubren: incremento atómico de `PCV_db`, creación del primer contador, validación de origen same-site del endpoint REST, y deduplicación de vistas repetidas. Al añadir lógica nueva a `PCV_db` o `PCV_restapi`, extender `Tests/run.php` con el mismo patrón (`bbk_test_same`, `bbk_test_error_status`) antes de dar el cambio por terminado.

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
├─ src/                          Clases PHP (PCV_*, PSR-4, sin subcarpetas)
│  ├─ PCV_plugin.php
│  ├─ PCV_assets.php
│  ├─ PCV_restapi.php
│  ├─ PCV_db.php
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
│  ├─ MIGRATION-PSR4.md          (plan futuro — eliminar prefijo PCV_)
│  └─ IMPROVEMENT-PLAN.md
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

## Migración a PSR-4 sin prefijo (futuro)

Estado actual: `src/PCV_*.php` con namespace correcto pero nombre de clase con prefijo plano. Estado objetivo: `src/{Core,Api,Frontend}/` con clases sin prefijo (`Plugin`, `Db`, `RestApi`, `Assets`). Ver `docs/MIGRATION-PSR4.md` para el mapeo completo, el orden de migración recomendado y los riesgos identificados.
