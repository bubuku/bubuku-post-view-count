# Plan de migración a PSR-4

> ✅ **Implementado.** El código ya vive en `src/{Core,Api,Frontend}/` con las clases
> renombradas (`Plugin`, `Db`, `RestApi`, `Assets`). El autoloader propio
> (`bbk_autoload` en `bubuku-post-view-count.php`) se actualizó para resolver
> subnamespaces (convierte `\` en `/` al construir la ruta del archivo — el plugin no
> depende de Composer en runtime, a diferencia de lo que asumía la §4 original de este
> documento). `Tests/bootstrap.php` y `Tests/run.php` se actualizaron para apuntar a las
> nuevas rutas/clases; los stubs de funciones WP se movieron al namespace global para
> que el fallback de PHP los resuelva desde cualquier subnamespace. El resto de este
> documento se conserva como referencia histórica del mapeo aplicado.

## Estado actual (antes de la migración)

- Autoload PSR-4 vía Composer ya configurado: `Bubuku\Plugins\PostViewCount\` → `src/`.
- Namespace correcto en los 4 archivos de `src/`, pero los nombres de clase mantienen el prefijo plano heredado del estilo pre-namespace (`PCV_*`).
- `src/` es plano, sin subcarpetas por responsabilidad (`Admin/`, `Api/`, `Core/`, `Frontend/`).
- Solo 4 clases, sin tests automatizados que verifiquen el comportamiento tras el movimiento.

## Estado objetivo

- Estructura PSR-4 por responsabilidad: `src/{Api,Core,Frontend}/`.
- Namespace base: `Bubuku\Plugins\PostViewCount\` (ya correcto, no cambia).
- Nombres de clase sin prefijo `PCV_`, en PascalCase (`Plugin`, `Db`, `RestApi`, `Assets`).

## Mapeo clase a clase

| Hoy | Mañana |
|---|---|
| `src/PCV_plugin.php` (clase `PCV_plugin`) | `src/Core/Plugin.php` (`Bubuku\Plugins\PostViewCount\Core\Plugin`) |
| `src/PCV_db.php` (clase `PCV_db`) | `src/Core/Db.php` (`Bubuku\Plugins\PostViewCount\Core\Db`) |
| `src/PCV_restapi.php` (clase `PCV_restapi`) | `src/Api/RestApi.php` (`Bubuku\Plugins\PostViewCount\Api\RestApi`) |
| `src/PCV_assets.php` (clase `PCV_assets`) | `src/Frontend/Assets.php` (`Bubuku\Plugins\PostViewCount\Frontend\Assets`) |

## Dependencias entre clases a actualizar

- `PCV_plugin::init()` instancia `new PCV_assets()` y `new PCV_restapi()` → pasan a `new Assets()` y `new RestApi()` con `use Bubuku\Plugins\PostViewCount\Frontend\Assets;` / `use ...\Api\RestApi;`.
- `PCV_restapi::__construct()` instancia `new PCV_db()` → pasa a `new Db()` con `use Bubuku\Plugins\PostViewCount\Core\Db;`.
- `bubuku-post-view-count.php` importa `use Bubuku\Plugins\PostViewCount\PCV_plugin;` y hace `new PCV_plugin()` → pasa a `use Bubuku\Plugins\PostViewCount\Core\Plugin;` / `new Plugin()`.

## Pasos de migración

1. Crear las carpetas `src/Core/`, `src/Api/`, `src/Frontend/`.
2. Mover y renombrar un archivo a la vez (empezar por `PCV_db.php`, que no depende de nadie, y terminar por `PCV_plugin.php`, que depende de las otras tres):
   - `PCV_db.php` → `Core/Db.php` (clase `Db`)
   - `PCV_assets.php` → `Frontend/Assets.php` (clase `Assets`)
   - `PCV_restapi.php` → `Api/RestApi.php` (clase `RestApi`), actualizar su `use` a `Core\Db`
   - `PCV_plugin.php` → `Core/Plugin.php` (clase `Plugin`), actualizar sus `use` a `Frontend\Assets` y `Api\RestApi`
3. Actualizar `bubuku-post-view-count.php`: `use` y `new` apuntando a `Core\Plugin`.
4. Composer ya resuelve el autoload por convención de carpetas — no requiere cambios en `composer.json` (el mapping `Bubuku\Plugins\PostViewCount\` → `src/` ya cubre las subcarpetas). Ejecutar `composer dump-autoload` tras mover archivos.
5. Probar manualmente en una instalación WordPress: activación/desactivación del plugin, incremento de vistas en un Post, endpoint REST (`register_routes`), y enqueue de assets en `enqueue_block_assets`.
6. Ejecutar `composer run-script lint` (phpcs) sobre los archivos movidos.

## Riesgos

- No hay hooks registrados con callbacks por string (todos usan `[ $this, 'method' ]`), así que el renombrado de clases no rompe callbacks de `add_action`/`add_filter` — riesgo bajo en ese punto concreto.
- Si algún plugin de terceros o tema hace `new \Bubuku\Plugins\PostViewCount\PCV_db()` o similar (extensión directa de las clases), se rompería. No hay evidencia de esto en el propio repo, pero no es descartable en instalaciones externas.
- Al ser solo 4 clases sin tests automatizados, cada movimiento debe verificarse manualmente en WordPress antes de continuar con el siguiente.
- No se requiere `class_alias` de retrocompatibilidad dado que el volumen es pequeño y el equipo controla ambos lados (plugin y cualquier integración); valorar mantenerlo 1 release si se confirma uso externo de las clases actuales.
