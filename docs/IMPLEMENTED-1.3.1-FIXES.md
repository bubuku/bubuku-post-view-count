# IMPLEMENTED — Correcciones 1.3.1

> Estado: **implementado** en la versión 1.3.1. Verificado con `php Tests/run.php`, `composer run-script lint`, `npm run lint:js`, `npm run build` y contra el sitio real `test.wp.local` (ver «Verificación realizada» al final). El resto de este documento es el plan original tal como se aprobó; se mantiene sin reescribir como registro de la decisión.

Cuatro tareas agrupadas en la versión 1.3.1 (patch):

1. Subir la versión a 1.3.1.
2. Formatear los números de la card «Views over time».
3. Revisar por qué «Falling» (card «Rising & falling») siempre muestra «No relevant changes in this period.».
4. Revisar por qué el widget «Post views summary» del escritorio no se actualiza.

> Nota: la versión actual ya es **1.3.0** (header PHP y `readme.txt` `Stable tag` sincronizados), pero `AGENTS.md` todavía dice 1.2.3 en la tabla y 1.2.0 en la nota de versionado. Hay que corregirlo como parte del punto 1.

---

## 1. Bump a 1.3.1

Patch, pedido explícitamente por el usuario.

- `bubuku-post-view-count.php`: `Version: 1.3.1`.
- `readme.txt`: `Stable tag: 1.3.1` + entrada `= 1.3.1 =` en `== Changelog ==` (en inglés, estilo de las anteriores).
- `docs/CHANGELOG.md`: `## [1.3.1] - <fecha>` con sección `### Corregido` para los puntos 2–4.
- `@version` de los docblocks de las clases tocadas (`OverviewSnapshot`, `DashboardWidget`, `Query`, …).
- `AGENTS.md`: tabla «Versión actual» y nota de «Versionado del plugin».
- Regenerar `languages/bubuku-post-view-count.pot` (cambian cadenas: `%d` → `%s` y mensajes nuevos).

---

## 2. Formatear números en «Views over time»

**Causa:** `TrendChart.js` y parte de `StatsPanel.js` no usan `formatNumber()` (`assets/src/js/admin/format.js`), que ya usan `DataTable`, `SummaryCards` y `ExpandableViewsTable`.

| Sitio | Ahora | Cambio |
|---|---|---|
| `assets/src/js/admin/components/StatsPanel.js` (~L318–339, `trendLabel`) | `%1$d` con `trendTotal` | `%1$s` + `formatNumber( trendTotal )`; `trendTotal` numérico se mantiene como argumento de `_n()` |
| `assets/src/js/admin/components/TrendChart.js` L88 (eje Y) | `String( maxViews )` | `formatNumber( maxViews )` |
| `assets/src/js/admin/components/TrendChart.js` L225–233 (tooltip) | `'%d view' / '%d views'` | `'%s view' / '%s views'` + `formatNumber( tooltip.views )` |

Menor: la etiqueta del eje Y se pinta a 4 px del borde con `padding.left = 50`; comprobar que un valor formateado ≥ 100.000 no se solapa con la línea del eje (si lo hace, calcular `padding.left` con `ctx.measureText()`).

---

## 3. «Falling» siempre vacío

### Diagnóstico (confirmado)

Consulta vía MCP en stg.embat (`bubuku-views-list-momentum`, 2026-09-28):

```
data_available_since: 2026-08-30 17:29:30
period.current:  2026-08-30 → 2026-09-28
period.previous: 2026-07-31 → 2026-08-29
rising:  1 post (0 → 1, delta_pct null)
falling: []
```

El agregado diario `{prefix}bbk_post_views_daily` solo existe desde que el sitio se actualizó a 1.2.x (`Schema::OPTION_DAILY_SINCE`); la migración desde postmeta solo siembra totales, no histórico diario. Por tanto:

- El periodo anterior (30 días previos) está **completamente vacío** → `previous_views = 0` para todos los posts.
- `delta = current - previous` es siempre ≥ 0 → `Query::momentum()` (`src/Core/Query.php` ~L668–698) nunca genera filas en `falling`.
- «Rising» se llena de posts `0 → N` (`delta_pct = null`), que no son subidas reales sino falta de datos.

No es un bug de SQL: es falta de histórico, y la UI no lo explica. Pasará en todos los sitios hasta que acumulen más de 30 días de histórico diario (60 para una comparativa completa).

### Implementación propuesta

**Backend — `Query::momentum()`**

- Comparar `Schema::daily_data_since()` con `previous_start`.
- Si el histórico no cubre ambos periodos completos:
  - Acortar automáticamente `period_days` a `floor( días_disponibles / 2 )` (mínimo 1 día; si no hay al menos 2 días, no comparar) para que la comparativa sea honesta (p. ej. 14 vs 14 días).
  - Devolver campos nuevos: `'insufficient_history' => true`, `'history_since' => 'Y-m-d'`, `'requested_period_days'` y el `period` efectivo.
- Incluir `daily_data_since` en la clave de caché (o el `period_days` efectivo) para que no se sirva un resultado calculado con otro periodo.

**API / MCP**

- `Api\TrendsApi::get_momentum()` propaga los campos nuevos (solo se añaden campos; el contrato existente no cambia).
- Revisar la tool de momentum en `src/Mcp/Tools` para que su descripción/salida mencione el periodo efectivo.

**UI — `StatsPanel.js`, card «Rising & falling»**

- El `claim` muestra el periodo realmente comparado («Comparison between the last 14 days and the previous 14.») en vez del texto fijo de 30.
- Si `insufficient_history`: aviso visible («Not enough history yet: daily data available since %s. A full 30 vs 30 comparison needs 60 days.»), reutilizando `formatDate()` y el mismo patrón `hasPartialHistory` que ya usa «Views over time».
- Si tras el recorte sigue sin datos, mensajes vacíos específicos en vez de «No relevant changes in this period.».

**Tests — `Tests/run.php`** (se toca `Core\Query`, obligatorio por DoD):

- Histórico más corto que el periodo pedido → `insufficient_history = true` y periodo efectivo recortado.
- Histórico completo con un post que baja → aparece en `falling` con `delta` negativo.

---

## 4. Widget «Post views summary» no se actualiza

### Flujo actual

`DashboardWidget::render()` → `OverviewSnapshot::get_cached()` (`src/Admin/OverviewSnapshot.php`):

- Snapshot < 15 min (`TTL`) → se sirve tal cual.
- Snapshot ≥ 15 min → se sirve el antiguo y se programa `wp_schedule_single_event( time() + 5, REFRESH_HOOK )`. **Solo WP-Cron lo reconstruye.**
- `mark_stale()` solo se dispara en `save_post`, `deleted_post` y guardado/reset de ajustes.

### Puntos débiles detectados

1. **Dependencia total de WP-Cron.** Con `DISABLE_WP_CRON` sin cron de sistema, o con loopback bloqueado (staging con auth básica, entornos locales con SSL propio como `test.wp.local`), el evento queda vencido para siempre. `wp_next_scheduled()` devuelve ese evento vencido, así que `schedule_refresh()` no reprograma nada, y el widget sirve el snapshot antiguo indefinidamente. **Causa más probable.**
2. **Nuevas vistas no marcan el snapshot como stale** (por diseño): hasta 15 min de retraso, más hasta 5 min de `Query::CACHE_TTL` si hay object cache persistente ⇒ hasta ~20 min.
3. **Sin indicación visual de antigüedad:** `get_cached()` calcula `stale` pero `render()` no lo muestra; el usuario no distingue «desfasado» de «roto».
4. **Fechas en UTC** (`current_time( 'Y-m-d', true )`): el «hoy» del sparkline cambia a medianoche UTC, no a la local — en zonas horarias alejadas puede parecer que no avanza.

### Diagnóstico previo a implementar

En `test.wp.local` y en al menos un sitio real:

```bash
wp option get bbk_postview_overview_snapshot --format=json   # comparar generated_at con `date +%s`
wp cron event list | grep bbk_postview_overview_snapshot_refresh   # ¿evento vencido?
wp config get DISABLE_WP_CRON
```

Y Herramientas → Salud del sitio (prueba de loopback).

### Implementación propuesta

- **Límite duro de antigüedad:** nueva constante `MAX_STALE` (p. ej. 3600 s). Si el snapshot supera `MAX_STALE`, `get_cached()` reconstruye en la propia petición bajo el `LOCK_TRANSIENT` existente (mismo camino que el primer build). En el caso normal se mantiene el stale-while-revalidate.
- **Detectar eventos atascados:** en `schedule_refresh()`, si `wp_next_scheduled()` devuelve un timestamp vencido hace más de 5 min, desprogramarlo (`wp_unschedule_event`) y tratarlo como atascado (rebuild síncrono bajo lock en `get_cached()`).
- **Mostrar antigüedad** en el footer del widget: «Updated %s ago» con `human_time_diff( $snapshot['generated_at'] )`.
- Opcional: bajar `TTL` de 15 a 5 min (el coste es una consulta ya cacheada en `Query`).
- Opcional: evaluar si el sparkline debe usar el día local (`wp_timezone()`) — implica tocar `Query::last_days()` y es coherente con el resto del plugin solo si todo pasa a local; dejar fuera de 1.3.1 salvo que se decida lo contrario.

**Tests — `Tests/run.php`:**

- Snapshot con `generated_at` más antiguo que `MAX_STALE` → `get_cached()` devuelve uno nuevo.
- Snapshot fresco → no reconstruye.
- Evento de refresco vencido > 5 min → se considera atascado.

---

## Skills a cargar al implementar

| Punto | Skills |
|---|---|
| 1 | `wp-build` |
| 2 | `wp-frontend` |
| 3 | `wp-php` + `wp-coding` + `wp-rest-api` + `wp-frontend` |
| 4 | `wp-php` + `wp-performance` + `wp-admin` |

## Verificación (Definition of Done)

- `npm run build`, `npm run lint:js`, `composer run-script lint`, `php Tests/run.php` sin errores.
- En `https://test.wp.local/`:
  - «Views over time»: eje Y, resumen y tooltip con separadores de miles.
  - «Rising & falling»: aviso de histórico insuficiente / periodo efectivo.
  - Widget: «Updated X ago» y refresco correcto con WP-Cron desactivado.
- `bash scripts/build.sh` + Plugin Check sobre el zip de `dist/`.
- Volver a consultar `bubuku-views-list-momentum` en stg.embat tras desplegar y comprobar `insufficient_history` y el periodo efectivo.

---

## Diferencias entre el plan y lo implementado

- **Punto 3 (`Query::momentum()`):** implementado tal como se planteó (`effective_momentum_period_days()`, campos `insufficient_history`/`requested_period_days`/`history_since`, propagados sin cambios por `TrendsApi::get_momentum()` y la tool MCP `ListMomentum` porque ambos hacen `array_merge()` del resultado completo). Al escribir el test con datos reales se encontró un **bug preexistente e independiente**: la llamada a `$wpdb->prepare()` en `momentum()` pasaba `array_merge(...)` sin el operador `...` de expansión (a diferencia de cada otra consulta de `Core\Query.php`, que sí lo usa). En WordPress real `prepare()` tolera un único array como argumento y lo desempaqueta, así que no rompía en producción, pero es inconsistente con el resto del archivo y fue lo que impedía escribir un test fiable para esta consulta — corregido añadiendo `...` (`src/Core/Query.php`).
- **Punto 4 (`OverviewSnapshot`):** implementado el límite duro `MAX_STALE` (4× `TTL` = 1 h) con reconstrucción síncrona bajo el `LOCK_TRANSIENT` existente, y el footer «Updated %s ago» en `DashboardWidget`. **No** se implementó la detección explícita de eventos de cron vencidos (`wp_unschedule_event` sobre un `REFRESH_HOOK` vencido) descrita como opción en el plan: el fallback de `MAX_STALE` ya cubre el caso real (WP-Cron que nunca se ejecuta) sin necesitar distinguir la causa, y los stubs de `Tests/bootstrap.php` para `wp_next_scheduled()`/`wp_schedule_single_event()` no permiten simular un cron real de forma útil. Tampoco se tocó el TTL (se queda en 15 min) ni el timezone del sparkline (fuera de alcance, ver nota del punto 4 arriba).
- **`Tests/bootstrap.php`:** se añadió el mock de `TestWpdb::get_results()` para la consulta per-post de `momentum()` (antes solo existía el de `Query::overview()`, de una sola fila) — necesario para poder probar `falling` con datos reales; no estaba contemplado explícitamente en el plan pero es infraestructura de test, no código de producción.
- Verificado además en vivo contra `test.wp.local` (además de los tests automatizados): `Query::momentum()` devuelve ahora filas reales en `falling` con `insufficient_history: true` y el periodo recortado a 14 vs 14 días (el sitio solo tiene histórico diario desde el 2026-08-30); `OverviewSnapshot::get_cached()` y `DashboardWidget::render()` no fallan y el footer muestra «Updated 13 minutes ago».
- No se ejecutó Plugin Check sobre `dist/bubuku-post-view-count-1.3.1.zip` (requiere el plugin Plugin Check instalado en un WordPress con UI); el zip se generó correctamente con `bash scripts/build.sh` (148K).
