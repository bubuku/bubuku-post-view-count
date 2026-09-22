# Plan — Tarjetas resumen + widget de escritorio

## Contexto

Hoy la pestaña **Estadísticas** del admin (`StatsPanel.js`) arranca directamente con la gráfica de 3 meses y cuatro `bk-dashboard-card` de tablas. Falta una lectura de un vistazo: no hay ninguna cifra global visible sin interpretar una tabla, y los totales que el plugin ya sabe calcular (`Query::summary()`, `Query::ai_traffic()`) solo se exponen por MCP y WP-CLI, nunca en la UI.

Además no existe presencia en el escritorio de WordPress: quien entra a `/wp-admin/` no ve nada del plugin hasta que navega a Ajustes.

Este plan añade dos cosas, sin penalizar el rendimiento del admin:

1. Una fila de **tarjetas resumen** encima de las `bk-dashboard-card` actuales.
2. Un **widget de escritorio** con esas mismas cifras y un sparkline de 7 días, al estilo de `Bubuku-Media-Library` (`src/BML_widget_dashboard.php`).

Decisiones ya cerradas con el usuario: la gráfica de 7 días va **solo en el widget**; el widget se alimenta de un **snapshot en option + cron** (patrón BML, render server-side instantáneo); capability **`edit_posts`**, coherente con `TrendsApi::check_permission()`.

Métricas elegidas (4 tarjetas): vistas totales all-time · vistas 30 días con variación vs los 30 previos · AI referrals (3 meses) · AI bot crawling (3 meses).

---

## 1. Backend: `Query::overview()`

**Archivo:** [src/Core/Query.php](../src/Core/Query.php)

Un único método que devuelve todo lo que consumen la fila de tarjetas y el widget, para no multiplicar peticiones ni consultas:

```php
public static function overview( array $post_types = array(), int $days = 30 ): array
```

Devuelve:

```
total_views            int     // all-time
posts_with_traffic     int
posts_without_traffic  int
current_views          int     // últimos $days días
previous_views         int     // los $days anteriores
delta                  int
delta_pct              ?float  // null si previous_views === 0
ai_referral_views      int     // últimos 3 meses
ai_crawler_views       int     // últimos 3 meses
last_7_days            array   // [{ day: 'Y-m-d', views: int }] x7, con ceros
period                 array   // { current:{from,to}, previous:{from,to} }
```

Reutilizar lo que ya existe, no reimplementar:

- `total_views` / `posts_with_traffic` / `posts_without_traffic` → llamada directa a **`Query::summary()`** ([Query.php:330](../src/Core/Query.php#L330)).
- `current_views` / `previous_views` → una sola consulta sobre `bbk_post_views_daily` con el mismo patrón `SUM(CASE WHEN d.day >= %s THEN d.views ELSE 0 END)` que ya usa **`momentum()`** ([Query.php:463](../src/Core/Query.php#L463)), pero **sin `GROUP BY post_id`** (una fila total). `delta_pct` con la misma fórmula y el mismo contrato `null` que `momentum()` ([Query.php:496](../src/Core/Query.php#L496)).
- `ai_referral_views` → suma de **`dims_breakdown( 'ai_assistant', ... )`** ([Query.php:539](../src/Core/Query.php#L539)) sobre la ventana de 3 meses. Es la misma fuente que alimenta `referrals.by_assistant` en `ai_traffic()`, pero sin el desglose por post (que es lo caro).
- `ai_crawler_views` → consulta nueva mínima: `SELECT COALESCE(SUM(c.views),0) FROM {ai_crawls} c INNER JOIN {posts} p ON p.ID = c.post_id WHERE p.post_type IN (...) AND p.post_status='publish' AND c.day >= %s`. Usa el índice `day_bot` + PK de posts.
- `last_7_days` → `SELECT d.day, SUM(d.views) ... WHERE d.day >= %s GROUP BY d.day` (índice `day_views`), y relleno de días sin filas con `0` en PHP (no inventar días anteriores a `Schema::daily_data_since()`, igual criterio que `completeTrend()` en [StatsPanel.js:109](../assets/src/js/admin/components/StatsPanel.js#L109)).

**Caché:** envolver todo el método con `wp_cache_get`/`wp_cache_set` en `self::CACHE_GROUP` con `self::cache_key( 'overview_' . md5( wp_json_encode( [...] ) ) )` y `self::CACHE_TTL`, exactamente como `trend()` ([Query.php:231](../src/Core/Query.php#L231)). Queda invalidado automáticamente por la generación que ya incrementan `save_post` / `deleted_post` / `POST /settings`.

Reutilizar `resolve_post_types()` ([Query.php:845](../src/Core/Query.php#L845)) y `placeholders()` ([Query.php:873](../src/Core/Query.php#L873)). Mantener los mismos `phpcs:ignore` justificados del resto del archivo.

## 2. REST: `GET /stats/overview`

**Archivo:** [src/Api/TrendsApi.php](../src/Api/TrendsApi.php)

Quinta ruta en `register_routes()`, mismo patrón que las cuatro existentes:

- Ruta `stats/overview`, método GET, `permission_callback => check_permission` (`edit_posts`).
- Args: `post_types` (array de string, `maxItems` 20) y `days` (int, 1..`Query::MAX_PERIOD_DAYS`, default 30).
- Respuesta: `Query::overview()` + `'meta' => Query::measurement_metadata()`, y header `Cache-Control: private, max-age=300` como el resto ([TrendsApi.php:252](../src/Api/TrendsApi.php#L252)).

No se toca `Api\RestApi` (endpoint público de escritura) ni su modelo de seguridad.

## 3. Admin React: fila de tarjetas resumen

**Archivos nuevos:**
- `assets/src/js/admin/components/SummaryCards.js`
- `assets/src/js/admin/components/StatCard.js`
- `assets/src/scss/admin/components/_summary-cards.scss`

**Archivos modificados:**
- [assets/src/js/admin/components/StatsPanel.js](../assets/src/js/admin/components/StatsPanel.js)
- [assets/src/scss/admin/style.scss](../assets/src/scss/admin/style.scss) (añadir el `@use` del parcial nuevo)

`StatCard` es una tarjeta compacta (no un `DashboardCard`): icono, etiqueta, cifra grande y una línea de contexto opcional (el `+X (+Y%)` de la variación, con modificador `--up` / `--down` / neutro). Reutiliza `formatNumber()` de [assets/src/js/admin/format.js](../assets/src/js/admin/format.js).

`SummaryCards` hace **una** llamada con el helper existente `bbkFetch` ([App.js:23](../assets/src/js/admin/App.js#L23)) a `/stats/overview`, en su propio `useEffect`, y falla de forma aislada como los otros cuatro bloques del panel. Mientras carga, muestra las tarjetas en estado esqueleto (no desplaza el layout). Las cuatro tarjetas:

| Tarjeta | Valor | Línea de contexto |
|---|---|---|
| Vistas totales | `total_views` | `posts_with_traffic` de N con tráfico |
| Últimos 30 días | `current_views` | `delta` y `delta_pct` vs los 30 previos |
| AI referrals | `ai_referral_views` | «Últimos 3 meses» |
| AI bot crawling | `ai_crawler_views` | «Últimos 3 meses», o «Desactivado en ajustes» si `context.ai_crawler_tracking` es false |

Se monta en `StatsPanel` como primer hijo de `<div className="bbk-stats-panel">`, **antes** del primer `DashboardCard` ([StatsPanel.js:342](../assets/src/js/admin/components/StatsPanel.js#L342)), recibiendo `context` por props.

SCSS: grid `repeat(auto-fit, minmax(200px, 1fr))` con `gap` de los tokens existentes; reutilizar las variables de `assets/src/scss/admin/config/_tokens.scss` (`--brand`, `--text-muted`, `--border`, `--green-500`, `--red-500`). Prefijo de clases `bbk-summary-cards` / `bbk-stat-card` (el panel de stats ya usa `bbk-`; `bk-` queda reservado a las cards grandes existentes).

## 4. Snapshot para el widget

**Archivo nuevo:** `src/Admin/OverviewSnapshot.php`

Réplica del patrón de [BML_reports.php:88-134](/Users/bubuku/dev/Bubuku-Media-Library/src/BML_reports.php), que es el que garantiza que el dashboard no pague nunca una consulta:

```php
const OPTION_SNAPSHOT  = 'bbk_postview_overview_snapshot';   // autoload 'no'
const LOCK_TRANSIENT   = 'bbk_postview_overview_snapshot_lock';
const REFRESH_HOOK     = 'bbk_postview_overview_snapshot_refresh';
const TTL              = 900;  // 15 min
const LOCK_TTL         = 120;  // 2 min
```

- `get_cached(): array` — lee la option; si `time() - generated_at < TTL` la devuelve; si está caducada devuelve **igualmente la vieja** marcada `stale` y programa `wp_schedule_single_event( time() + 5, REFRESH_HOOK )`; si no hay snapshot y hay lock, devuelve payload vacío con estado `warming`; si no hay snapshot ni lock, toma el lock, construye, persiste y libera.
- `build(): array` — `Query::overview()` + `Settings::ai_crawler_tracking()` + cifras ya formateadas con `number_format_i18n()` junto a los enteros crudos (así el render del widget no formatea nada).
- `refresh()` — callback de `REFRESH_HOOK`.
- `persist()` con `update_option( ..., false )` (autoload `no`, obligatorio: es un payload que solo se lee en `index.php`).
- `mark_stale()` — engancharlo junto a las invalidaciones que ya existen en [src/Core/Plugin.php:69-70](../src/Core/Plugin.php#L69) (`save_post`, `deleted_post`) y tras `POST /settings` / `DELETE /settings/data` en [src/Api/SettingsApi.php](../src/Api/SettingsApi.php), al lado de `Query::invalidate_cache()`. Idempotente por request con un `static $already_marked`, como hace BML, para no escribir la option N veces en un bucle de importación.
- Limpieza: borrar la option y desprogramar el evento en `Schema::deactivate()` y en `DELETE /settings/data`.

## 5. Widget de escritorio

**Archivo nuevo:** `src/Admin/DashboardWidget.php`
**Modificado:** [src/Admin/Admin.php](../src/Admin/Admin.php) (instanciarlo desde `register()`, junto al resto de hooks de admin)

- `add_action( 'wp_dashboard_setup', ... )` → guard `current_user_can( 'edit_posts' )` → `wp_add_dashboard_widget( 'bbk_postview_dashboard_summary', $title, [ $this, 'render' ] )`. Título = `<span class="brand-dot"></span>` + `esc_html__( 'Post views summary', ... )`, igual que BML ([BML_widget_dashboard.php:20-27](/Users/bubuku/dev/Bubuku-Media-Library/src/BML_widget_dashboard.php#L20)).
- `render()` — lee `OverviewSnapshot::get_cached()`, con fallback defensivo a guiones/ceros si el estado es `warming`. Estructura:

```
.bbk-widget
├─ .bbk-widget-stats            → 2×2 de .bbk-widget-stat (mismas 4 cifras que la fila del admin)
├─ .bbk-widget-section          → «Últimos 7 días»
│   └─ .bbk-widget-spark        → <svg> polyline + área, generado en PHP
└─ .bbk-widget-footer           → total + enlace a options-general.php?page=bubuku-post-view-count
```

- **Sparkline sin JS**: mismo enfoque que el donut de BML (SVG puro). Calcular los puntos en PHP a partir de `last_7_days`, normalizando al máximo de la serie; `viewBox="0 0 100 30"` con `preserveAspectRatio="none"` para que escale al ancho del postbox. Todo atributo pasa por `esc_attr()`. Si los 7 días suman 0, mostrar el mensaje vacío en vez del SVG.
- Escaping e i18n exactamente como BML: `esc_html__` / `esc_attr`, `number_format_i18n()` (ya viene hecho en el snapshot), `wp_kses` con allowlist `[ 'strong' => [] ]` para la línea del footer, `esc_url( admin_url( ... ) )`, y el buffer `ob_start()` / `echo ob_get_clean()` con el `phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` justificado.

## 6. Assets del widget

**Nuevos:** `assets/src/scss/widget/style.scss` (+ parciales si hace falta)
**Modificados:** [webpack.config.js](../webpack.config.js), [package.json](../package.json), [src/Admin/AdminPage.php](../src/Admin/AdminPage.php)

- Entry CSS-only `widget` en `webpack.config.js` → produce `assets/build/style-widget.css` (y un `widget.js` residual de wp-scripts que no se encola nunca), igual que en BML.
- Encolado: en `AdminPage::enqueue_assets()` ([AdminPage.php:80](../src/Admin/AdminPage.php#L80)) hoy hay un early-return por `$hook`. Añadir una rama previa: si `'index.php' === $hook`, encolar **solo** `style-widget.css` con guard `file_exists()` y devolver. El bundle React del admin no se carga nunca en el dashboard.
- Tokens duplicados y scopeados a `#bbk_postview_dashboard_summary` (el widget no comparte scope con `#bbk-postview-app`), con el mismo comentario explicativo que BML.

## 7. Tests

**Archivo:** [Tests/run.php](../Tests/run.php) — obligatorio por AGENTS.md al tocar `Core\Query`.

- `Query::overview()`: ventana de 30 días con datos sembrados en `bbk_post_views_daily` → `current_views` / `previous_views` / `delta_pct` correctos; `delta_pct === null` cuando el periodo previo es 0.
- `last_7_days`: devuelve siempre 7 entradas, con `0` en los días sin filas, y no inventa días anteriores a `daily_data_since()`.
- `ai_crawler_views`: suma solo filas de posts publicados de los post types habilitados.
- `OverviewSnapshot`: snapshot fresco se devuelve tal cual; snapshot caducado se devuelve marcado `stale` y programa el evento; sin snapshot y con lock devuelve `warming` sin consultar.

---

## Notas de rendimiento

- El dashboard (`index.php`) ejecuta **una** `get_option()` y **cero** consultas a las tablas del plugin en el camino crítico; todo el cómputo vive en el cron de refresco. Sin JS ni peticiones REST añadidas al dashboard.
- La option del snapshot va con `autoload = 'no'`: no entra en el `alloptions` de cada request del front.
- `Query::overview()` es una llamada cacheada en object cache (TTL 300 + generación), y todas sus consultas usan índices existentes (`PRIMARY(post_id)`, `day_views`, `day_dimension_value`, `day_bot`).
- La fila de tarjetas del admin añade **una** petición REST a la pestaña de estadísticas (hoy ya hace 4), con `Cache-Control: private, max-age=300`.

## Verificación

1. `npm run build` — comprobar que aparecen `assets/build/style-widget.css` y que `admin.js` sigue compilando.
2. `./vendor/bin/phpcs` sin errores (o `composer run-script lint`).
3. `php Tests/run.php` sin fallos, incluidos los casos nuevos.
4. En `https://test.wp.local/wp-admin/`:
   - El widget aparece en el escritorio con cifras y sparkline; primera carga puede salir en `warming` y rellenarse en la siguiente (verificar que el evento `bbk_postview_overview_snapshot_refresh` se programa: `wp cron event list`).
   - Con un usuario de rol Suscriptor, el widget **no** aparece.
   - Query Monitor: el dashboard no muestra consultas a `bbk_post_views*` en el render del widget.
5. En `Ajustes → Bubuku Post View Count → Estadísticas`: las 4 tarjetas resumen aparecen sobre la gráfica, con cifras coherentes con las tablas de abajo; desactivar «AI crawler tracking» en Ajustes y confirmar que la cuarta tarjeta lo refleja y que el snapshot se marca stale.
6. `curl` con cookie de admin a `/wp-json/bbk_postview/v1/stats/overview` → 200 con el payload; sin sesión → 401.

## Fuera de alcance

- **No se sube la versión.** El cambio justifica un bump **minor** (1.2.3 → 1.3.0: feature nueva + ruta REST nueva); queda pendiente de que lo pidas explícitamente, junto con `readme.txt` y `docs/CHANGELOG.md`.
- No se toca `assets/src/js/public/common.js` ni `Api\RestApi`.
- No se añade selector de rango 7d a la gráfica grande del panel (decidido: sparkline solo en el widget).
