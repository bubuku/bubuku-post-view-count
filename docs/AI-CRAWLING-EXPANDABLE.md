# Acordeón de páginas en "AI bot crawling"

> Estado: **implementada**. El plan de abajo se ejecutó tal cual (backend, componente
> React generalizado a `ExpandableViewsTable.js`, estilos y tests). Se conserva como
> registro de las decisiones tomadas. También se resolvió, en un pase posterior, el
> "trabajo muerto" de `referrals.posts` mencionado en la sección de Backend: esa clave
> se eliminó de `Query::ai_traffic()` (docblock, cache y tests actualizados) por no
> tener consumidor.

## Objetivo

Que la tabla **AI bot crawling** de la card *AI traffic* se comporte como
**AI referrals**: clic en el nombre del bot → despliega las páginas que ese bot ha
rastreado, con enlace al post y número de rastreos. Además, la lista desplegada de
**ambas** tablas debe tener scroll vertical propio cuando crece.

## Decisiones cerradas

- Scroll vertical: en **las dos** tablas (referrals incluida).
- Tope de páginas por bot/asistente: **se mantiene en 10** (`limit` actual de
  `TrendsApi`). No se toca el parámetro ni su default.
- Títulos de página en bots: **enlazados** al permalink, igual que en referrals.

## Situación actual

| | AI referrals | AI bot crawling |
|---|---|---|
| Componente | `AiReferralsTable.js` (acordeón single-open) | `DataTable.js` genérico |
| Dato | `referrals.by_assistant` → `{assistant, views, posts:[…]}` | `crawlers` → `{bot, views}` |
| Tabla BD | `bbk_post_view_dims` (`dimension='ai_assistant'`) | `bbk_post_ai_crawls` |
| Expandible | Sí | No |
| Scroll vertical | No | — |

**Clave:** `bbk_post_ai_crawls` ya guarda `post_id`
(`src/Core/Schema.php`, `create_tables()`), así que el desglose por página solo
necesita cambiar el `GROUP BY` y la agrupación en PHP. No hay migración de esquema.

## Cambios

### 1. Backend — `src/Core/Query.php`, bloque de crawlers dentro de `ai_traffic()`

Hoy la consulta agrupa solo por bot:

```sql
SELECT c.bot, SUM(c.views) AS total_views
FROM {ai_crawls} c INNER JOIN {posts} p ON p.ID = c.post_id
WHERE p.post_type IN (…) AND p.post_status = 'publish'
  AND c.day BETWEEN %s AND %s
GROUP BY c.bot ORDER BY total_views DESC
```

Pasa a agrupar también por `post_id`, replicando exactamente el patrón que ya usa
el bloque `by_assistant` de la misma función:

```sql
SELECT c.bot, c.post_id, SUM(c.views) AS total_views
FROM {ai_crawls} c INNER JOIN {posts} p ON p.ID = c.post_id
WHERE p.post_type IN (…) AND p.post_status = 'publish'
  AND c.day BETWEEN %s AND %s
GROUP BY c.bot, c.post_id
ORDER BY c.bot ASC, total_views DESC
```

Agrupación en PHP **calcada del bloque `by_assistant`**:

- Acumular `views` de **todas** las filas del bot (el total no se trunca).
- Meter en `posts` solo las primeras `$limit` (`count(...) < $limit`), hidratando
  con `get_the_title()` / `get_permalink()` — como ya se hace para referrals.
- `usort` final por `views` desc.
- Sin bucket sintético `unknown`: aquí no aplica (todas las filas tienen `post_id`).

Forma resultante de cada fila de `crawlers`:

```php
array( 'bot' => 'GPTBot', 'views' => 120, 'posts' => array(
    array( 'id' => 12, 'title' => '…', 'url' => '…', 'views' => 40 ), … ) )
```

`views` sigue siendo el total agregado del bot → el footer de la tabla no cambia
de valor y el contrato existente se mantiene retrocompatible (solo se **añade**
`posts`).

Actualizar el docblock de contrato de `ai_traffic()` con la nueva clave.

**Invalidar la caché:** el resultado se guarda bajo la key `ai_crawlers_<md5>`
(`self::CACHE_GROUP`, TTL 5 min). Como la forma del payload cambia, cambiar el
prefijo de la key (p. ej. `ai_crawler_posts_<md5>`) para no servir estructuras
viejas durante los 5 minutos posteriores al deploy.

**Trabajo muerto detectado (opcional, decidir en la sesión de implementación):**
`referrals.posts` se calcula en cada request no cacheada (query + hidratación con
`get_the_title`/`get_permalink`) y **ningún componente lo consume**. Si se elimina,
comprobar antes que `src/Mcp/Tools/GetAiTraffic.php` tampoco lo usa. No es parte
del alcance pedido — sugerirlo al usuario, no hacerlo por iniciativa propia.

### 2. Frontend — generalizar `AiReferralsTable.js`

`AiReferralsTable` ya es exactamente el componente que se necesita; lo único
específico de referrals son dos cosas: la clave de la fila (`assistant`) y el mapa
`AI_ASSISTANT_LABELS`. Generalizarlo en lugar de duplicarlo:

- Renombrar el archivo a `ExpandableViewsTable.js`.
- Nuevas props: `labelKey` (nombre de la propiedad que identifica la fila:
  `'assistant'` o `'bot'`), `labels` (mapa slug→etiqueta, opcional; si no hay
  entrada se pinta el valor crudo) y `headerLabel` (texto de la primera columna:
  `AI` / `Bot`).
- El mapa `AI_ASSISTANT_LABELS` se queda como está pero se pasa desde
  `StatsPanel.js` como prop `labels` para el caso de referrals; los bots usan su
  nombre canónico (`GPTBot`, `ClaudeBot`, …) sin mapa.
- Todo lo demás no se toca: `useState(null)` single-open, `hasPosts` para el
  `disabled` del toggle, `aria-expanded`, footer con total.
- Ajustar la clase modificadora: mantener `bbk-ai-referrals-table` como clase base
  compartida (los estilos ya existen) o renombrarla a
  `bbk-expandable-views-table` y actualizar el SCSS en el mismo commit — decidir
  en implementación, pero **no dejar dos juegos de estilos**.

En `StatsPanel.js`, sustituir el `DataTable` de la columna de crawling por el
componente generalizado, y eliminar `crawlerColumns` si deja de usarse.
`DataTable.js` sigue en uso por las otras cards: **no borrarlo**.

Ojo al pasar de `DataTable` a este componente: el `emptyLabel` condicional
(`context?.ai_crawler_tracking` → "AI bot tracking is disabled in settings.") debe
conservarse tal cual.

### 3. Estilos — `assets/src/scss/admin/components/_data-table.scss`

En `&__detail ul` (bloque `.bbk-ai-referrals-table`), añadir:

```scss
max-height: 180px;   // ~6 filas visibles con font-size 12px
overflow-y: auto;
```

Al ser la misma regla compartida, el scroll aplica automáticamente a las dos
tablas, que es lo pedido. Mantener el `overflow-x: auto` de `.bk-data-table` sin
cambios. Verificar que el scroll interno no rompe el `overflow-x` del contenedor
padre en pantallas estrechas (< 782px, donde `.bbk-two-columns` colapsa).

## Archivos afectados

| Archivo | Cambio |
|---|---|
| `src/Core/Query.php` | `GROUP BY c.bot, c.post_id` + agrupación en PHP + docblock + cache key |
| `assets/src/js/admin/components/AiReferralsTable.js` | Generalizar → `ExpandableViewsTable.js` |
| `assets/src/js/admin/components/StatsPanel.js` | Usar el componente en la columna de crawling |
| `assets/src/scss/admin/components/_data-table.scss` | `max-height` + `overflow-y` en la lista desplegada |
| `docs/CHANGELOG.md`, `docs/ARCHITECTURE.md` | Solo si el usuario aprueba bump de versión |

## Verificación

1. `./vendor/bin/phpcs` sin errores.
2. `php Tests/run.php` sin fallos. Se toca `Core\Query` (no `Db`/`Schema`/`RestApi`),
   pero conviene añadir un test que compruebe que cada fila de `crawlers` trae
   `posts` como array y que `views` ≥ `sum(posts[].views)`.
3. `npm run build` para regenerar `assets/build/admin.js`.
4. En `https://test.wp.local/`: activar `ai_crawler_tracking` en ajustes, simular
   hits con UA de bot (`curl -A "GPTBot/1.0" …`) sobre varias entradas, y comprobar
   en la card *AI traffic* que el bot se despliega, lista las páginas con enlace
   correcto y que con >6 páginas aparece scroll vertical dentro del desplegable.
5. Repetir la comprobación de scroll en *AI referrals*.
6. Probar en < 782px que las dos columnas colapsan bien y el scroll sigue usable.

## Notas

- No subir la versión del plugin sin pedirlo el usuario. Si al terminar parece
  justificar un bump, **sugerir patch/minor** y esperar confirmación.
- Skills a cargar en la sesión de implementación: `wp-php` + `wp-coding` para
  `Query.php`, `wp-frontend` para el componente y el SCSS, `git-conventions`
  para el commit.
- `docs/IMPLEMENTED-ADMIN-UI-REACT.md` (tabla de Fase 6) ya está actualizado: refleja
  que el tráfico de IA usa `ExpandableViewsTable` ×2.
