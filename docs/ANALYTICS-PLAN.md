# Plan de analítica y exposición MCP — Bubuku Post View Count

> Hoja de ruta por fases para convertir el contador de vistas actual en una capa de
> analítica consultable, con página de ajustes y exposición de los datos a las IAs vía
> el hub `bubuku-mcp-conex`.
>
> **Este documento no modifica código.** Es la hoja de ruta a ejecutar.
>
> Estado del código analizado: rama `feature/v.1.1.1`, versión `1.1.0` (header PHP) en el
> momento de escribir este documento. **Fase 1 implementada en la versión `1.2.0`** — ver
> nota al inicio de esa sección.

## Relación con los otros documentos

| Documento | Relación |
|---|---|
| `docs/IMPROVEMENT-PLAN.md` | **Este documento reemplaza sus fases 6 y 7** (P3-6 tabla propia, P3-8 superficie de lectura). Las fases 0–5 de aquel documento siguen vigentes tal cual. |
| `docs/MIGRATION-PSR4.md` | Compatible. La fase 3 de aquí necesita subcarpetas en `src/`, lo que adelanta parcialmente esa migración (ver §4.0). |
| `docs/ARCHITECTURE.md` | Deberá actualizarse al cerrar cada fase (tablas nuevas, clases nuevas, flujo de una vista). |
| `bubuku-mcp-conex/docs/0.0.2-plan-arquitectura-hub-satelites-mcp-wordpress.md` | **Fuente de verdad** del contrato hub-satélite. Este documento lo consume, no lo duplica. |
| `bubuku-mcp-seo/docs/0.0.2-conexion-satelite-hub-mcp-conex.md` | Implementación de referencia del lado satélite. Ver en §4.2 las divergencias justificadas. |

### Paso previo

La fase 3 necesita el skill del ecosistema MCP, hoy no enlazado en este plugin:

```bash
bash scripts/setup-skills.sh --add wp-mcp-conex
```

Trae la plantilla ya escrita del conector (`assets/class-satellite-connector.php`,
`assets/class-tool-example.php`) y `references/implementation.md`.

---

## 0. Alcance y decisiones cerradas

### Qué se pide

1. Registrar la **fecha y hora de la última visita**, junto al contador.
2. **Página de ajustes** para elegir qué CPT cuentan visitas (hoy solo `post`).
3. Poder **preguntar a la IA** por lo más leído en los últimos 6 meses, qué páginas no
   se visitan desde hace 6 meses, etc.

Y como backlog posterior: histórico y tendencias, tamaño de pantalla, procedencia del
usuario, y tráfico procedente de ChatGPT/Claude y otras IAs.

### Decisiones ya tomadas (no reabrir sin motivo)

| # | Decisión | Detalle |
|---|---|---|
| 1 | **Tabla propia desde el principio** | No se amplía el post meta: la fase 1 introduce el modelo de datos definitivo. |
| 2 | **El agregado diario se adelanta a la fase 1** | Dos tablas en una sola migración de esquema, en lugar de dos migraciones. Es lo que permite que "lo más leído en los últimos 6 meses" sea una respuesta **exacta** y no una aproximación (ver §1.1). |
| 3 | **Los CPT seleccionables solo afectan hacia adelante** | Desmarcar un CPT deja de contar, pero **no borra** los datos ya registrados. |
| 4 | **La capa MCP son satélites de `bubuku-mcp-conex`** | El plugin no monta servidor MCP propio ni OAuth: solo declara tools. |
| 5 | **Integración MCP opcional, en el mismo zip** | Viaja en el plugin público pero está inerte si no detecta el hub. Sin `Requires Plugins`. |
| 6 | **Las versiones son propuestas, no decididas** | Este documento **sugiere** una versión por fase, marcada *(a confirmar)*. Según `AGENTS.md`, el bump lo decide siempre el usuario. |

### Por qué el modelo actual no da más de sí

El plugin guarda hoy un único entero acumulado en el post meta `views`
([src/PCV_db.php:25](../src/PCV_db.php#L25)). Sobre ese dato:

- No hay forma de saber **cuándo** se produjo ninguna visita — ni la última, ni ninguna otra.
- Ordenar por vistas obliga a un `CAST` sobre `meta_value` (LONGTEXT), sin índice útil.
- Cualquier pregunta con ventana temporal ("últimos 6 meses") es **irrespondible**: un
  contador acumulado no tiene dimensión de tiempo.

De ahí que la fase 1 sea un cambio de modelo de datos y no un campo más.

---

## 1. Modelo de datos objetivo

### 1.1 Las dos tablas

Se crean **en la misma migración** (decisión 2):

```sql
{prefix}bbk_post_views
  post_id          BIGINT UNSIGNED  NOT NULL,
  views            BIGINT UNSIGNED  NOT NULL DEFAULT 0,
  first_viewed_at  DATETIME         NULL,          -- UTC
  last_viewed_at   DATETIME         NULL,          -- UTC  ← requerimiento 1
  PRIMARY KEY (post_id),
  KEY views (views),
  KEY last_viewed_at (last_viewed_at)

{prefix}bbk_post_views_daily
  post_id  BIGINT UNSIGNED  NOT NULL,
  day      DATE             NOT NULL,              -- UTC
  views    INT UNSIGNED     NOT NULL DEFAULT 0,
  PRIMARY KEY (post_id, day),
  KEY day_views (day, views)
```

**Por qué el agregado diario ya en la fase 1.** Con solo `views` + `last_viewed_at` la
pregunta "lo más leído en los últimos 6 meses" no se puede responder: como mucho se
respondería "lo más leído *de siempre*, que además se ha visto alguna vez en los últimos
6 meses", que es una pregunta distinta y engañosa. La tabla diaria cuesta un upsert extra
por visita y una purga por cron, y a cambio hace exactas todas las consultas con ventana
temporal, más las tendencias de la F4. Hacerlo después obligaría a una segunda migración
de esquema sobre datos en producción.

`bbk_post_views_daily` crece como `posts_con_tráfico × días_de_retención`. Con 5 000
entradas activas y 400 días de retención son ~2 M de filas de 14 bytes útiles: perfectamente
manejable, y acotado por la purga (§1.5).

### 1.2 Escritura: dos upserts atómicos

Se conserva el espíritu de la query actual —el incremento atómico en el motor, sin leer
antes en PHP— generalizado a `INSERT … ON DUPLICATE KEY UPDATE`:

```sql
-- 1. Agregado
INSERT INTO {prefix}bbk_post_views (post_id, views, first_viewed_at, last_viewed_at)
VALUES (%d, 1, %s, %s)
ON DUPLICATE KEY UPDATE views = views + 1, last_viewed_at = VALUES(last_viewed_at);

-- 2. Diario
INSERT INTO {prefix}bbk_post_views_daily (post_id, day, views)
VALUES (%d, %s, 1)
ON DUPLICATE KEY UPDATE views = views + 1;
```

Ambas con `$wpdb->prepare()`. Sin condición de carrera y sin `CAST` al consultar.
`first_viewed_at` solo se escribe en el `INSERT`, nunca en el `UPDATE`.

### 1.3 Zona horaria

Todo se persiste en **UTC** (`current_time( 'mysql', true )`, y el `day` derivado del mismo
instante UTC). El formateo a la zona del sitio se hace **solo al mostrar**, con `wp_date()`.
Mezclar zonas en el almacenamiento es la vía rápida a huecos y duplicados en los cortes de día.

### 1.4 Espejo en post meta (retrocompatibilidad)

El post meta `views` es un **contrato público ya publicado**: temas y consultas de terceros
lo leen y lo usan en `orderby=meta_value_num`. Se sigue escribiendo como espejo, además de
la tabla, y se añade `views_last` con la fecha de última visita.

- Fuente de verdad: **la tabla**. El meta es una copia de conveniencia.
- Filtro `bbk_postview_mirror_meta` (default `true`) para desactivarlo en sitios que no lo
  necesiten y quieran ahorrar la escritura.

### 1.5 Retención y purga

- Option `bbk_postview_settings['retention_days']`, default **400** (cubre comparativas
  año contra año).
- Evento cron diario `bbk_postview_purge_daily` que borra de `bbk_post_views_daily` las
  filas con `day < UTC_DATE() - retention_days`, por lotes.
- El agregado `bbk_post_views` **nunca se purga**: es el total histórico.

### 1.6 Migración desde `postmeta`

- `dbDelta()` al activar, más `maybe_upgrade()` en `plugins_loaded` (cubre las
  actualizaciones vía FTP/zip, donde el hook de activación no se dispara).
- Versión de esquema en la option `bbk_schema_version`.
- Copia de `postmeta.views` → `bbk_post_views` **por lotes de 500** mediante un cron
  one-shot reencolable, idempotente (`INSERT … ON DUPLICATE KEY UPDATE views = GREATEST(views, VALUES(views))`).
- `last_viewed_at` y `first_viewed_at` quedan **`NULL`** en las filas migradas: el dato no
  existía. Las consultas deben tratar `NULL` explícitamente (importante para `stale()`, §4.1).
- El **histórico diario no es reconstruible**. Arranca vacío el día de la instalación de
  esta fase. Cualquier consulta con ventana anterior devuelve datos parciales, y así hay
  que declararlo en la UI y en las descripciones de las tools MCP (§4.3).

### 1.7 Multisitio

Una pareja de tablas **por blog** (prefijo de blog, no de red). `activate( $network_wide )`
recorre los sitios al activar en red, y `wp_initialize_site` crea el esquema en los sitios
creados después. Mismo patrón que ya usa `uninstall.php`
([uninstall.php:18](../uninstall.php#L18)).

### 1.8 Desinstalación

Requisito explícito: al desinstalar hay que eliminar las tablas, o al menos poder decidirlo.

> **Limitación real.** `uninstall.php` **no puede preguntar nada**. WordPress lo ejecuta sin
> interfaz ni interacción, en respuesta al borrado ya confirmado. La única forma de
> "preguntar" es que la decisión esté tomada **antes** y guardada en una option que
> `uninstall.php` lea.

Diseño:

- Casilla **«Eliminar todos los datos al desinstalar»** en la página de ajustes (fase 2),
  guardada en `bbk_postview_settings['delete_data_on_uninstall']`.
  **Default: activada** — es lo que exigen las guidelines de WordPress.org (un plugin no
  debe dejar basura) y lo que se ha pedido. Quien quiera conservar los datos para reinstalar
  la desmarca conscientemente.
- **Aviso al desactivar**: admin notice tras desactivar el plugin recordando el estado de
  esa casilla, con enlace a los ajustes. Es el último momento en que se puede cambiar de
  idea, y es lo más cerca de "preguntar" que permite la plataforma.
- `uninstall.php` **con la casilla activa**, por cada blog:
  - `DROP TABLE` de `bbk_post_views` y `bbk_post_views_daily`.
  - `delete_post_meta_by_key()` de `views` y `views_last` (ya lo hace para `views`).
  - `delete_option()` de `bbk_postview_settings` y `bbk_schema_version`.
  - Borrado de los transients `bbk_view_*` de deduplicación.
  - `wp_clear_scheduled_hook()` del cron de purga.
- `uninstall.php` **con la casilla desactivada**: no se borra nada salvo el evento cron
  (que si no queda huérfano). Una reinstalación posterior detecta el esquema por
  `bbk_schema_version` y sigue contando sobre los datos existentes, sin duplicar.
- **Botón «Eliminar todos los datos ahora»** en los ajustes, con confirmación: reset sin
  necesidad de desinstalar.

---

## 2. Fase 1 — Tabla propia, última visita y agregado diario

*Cubre el requerimiento 1 y la decisión 2.* Versión sugerida **`1.2.0`** *(a confirmar)*.

> ✅ **Implementada en la versión `1.2.0`.** Los archivos de esta sección se implementaron
> con los nombres de clase actuales (post-migración PSR-4): `Core\Schema` (no
> `PCV_schema`), `Core\Db`, `Api\RestApi`, `Core\Plugin` — ver `docs/ARCHITECTURE.md` para
> el detalle vigente. Los tests de la sección "Tests" de abajo están todos cubiertos en
> `Tests/run.php`, salvo la mecánica de `dbDelta()`/WP-Cron/multisitio (sin equivalente en
> el harness sin dependencias; se valida manualmente). Las Fases 2 y 3 siguen pendientes.

### Cambios

| Archivo | Cambio |
|---|---|
| `src/PCV_schema.php` | **Nueva.** `dbDelta()`, versionado de esquema, migración por lotes, purga programada, creación en sitios nuevos de multisitio. |
| `src/PCV_db.php` | Nuevo `record_view( int $post_id ): array` → `['views' => int, 'first_viewed_at' => ?string, 'last_viewed_at' => string]`. Los dos upserts de §1.2 más el espejo de meta. Nuevo `get_stats( int $post_id ): array`. |
| `src/PCV_db.php` | `set_post_views()` se conserva como **alias delgado** de `record_view()` que devuelve solo el entero — no romper `Tests/run.php` ni ningún consumidor. |
| `src/PCV_db.php` | `remove_all_post_meta()` se amplía a `views_last`; se añade `drop_tables()`. |
| `src/PCV_restapi.php` | `set_post_views()` devuelve también `last_viewed_at` en la respuesta JSON. Sin cambios en validación ni permisos. |
| `src/PCV_plugin.php` | Instancia `PCV_schema`; `activate()` deja de estar vacío. |
| `uninstall.php` | Reescrito según §1.8. |
| `assets/js/common.js` | **Sin cambios.** |

### Seguridad

No se toca ninguna de las tres capas del endpoint (`check_request_origin`,
`validate_post_id`, deduplicación por transient). El cambio es puramente de persistencia.
La regla de `AGENTS.md` se mantiene: nada de autenticación ni nonce en el endpoint público.

### Tests (`Tests/run.php`)

El stub `TestWpdb` no tiene motor SQL, así que hay que enseñarle a reconocer los dos
upserts y devolver el estado simulado. Casos mínimos:

- Primera visita crea la fila con `views = 1`, `first_viewed_at` y `last_viewed_at` iguales.
- Segunda visita incrementa `views` y **actualiza solo** `last_viewed_at`.
- Dos visitas el mismo día producen **una sola fila** en `daily` con `views = 2`.
- Dos visitas en días distintos producen dos filas.
- La migración desde `postmeta` es idempotente: ejecutarla dos veces no duplica ni suma.
- `set_post_views()` sigue devolviendo el entero (retrocompatibilidad).

### Riesgo y rollback

**Alto** — cambia el modelo de datos. El post meta `views` **no se borra** durante la
migración, así que el rollback a `1.1.0` es reinstalar la versión anterior: el contador
sigue ahí, congelado en el momento del rollback. Documentarlo en el changelog de la versión.

---

## 3. Fase 2 — Página de ajustes y CPT seleccionables

*Cubre el requerimiento 2 y la decisión 3.* Versión sugerida **`1.3.0`** *(a confirmar,
pendiente de bump — ver "Versionado del plugin" en `AGENTS.md`)*.

> ✅ **Implementada** (aún bajo la versión de header `1.2.0`, pendiente de bump explícito
> del usuario). Clases nuevas: `Admin\Settings` (lectura/sanitización de
> `bbk_postview_settings`, roles excluidos por defecto calculados desde
> `get_editable_roles()`, detección de user-agents de bot) y `Admin\SettingsPage`
> (Settings API clásica bajo Ajustes → Post View Count, sin build step — ver AGENTS.md).
> `Frontend\Assets` y `Api\RestApi` consumen `Settings::enabled_post_types()` en vez del
> `'post'` hardcodeado; `Api\RestApi` añade el filtro `exclude_bots` como capa adicional
> (nunca sustituye las tres capas de seguridad existentes). No implementada todavía la
> columna "Vistas"/"Última visita" ordenable en los listados admin (§3.5, extra de bajo
> coste marcado como opcional) ni el bloque de estado MCP (depende de la Fase 3).
> Tests nuevos en `Tests/run.php` cubren `enabled_post_types()`, `validate_post_id()` con
> CPT habilitado/deshabilitado, `Settings::sanitize()` (descarta tipos/roles inexistentes,
> retention_days con `max(1, …)`) y el filtro de bots en `set_post_views()`. Validado
> manualmente en `test.wp.local` (activación/reset de tablas, render de la página,
> sanitización real, filtrado de bot en el endpoint REST).

### 3.1 El punto clave: solo hay dos sitios que hardcodean `'post'`

- [src/PCV_assets.php:31](../src/PCV_assets.php#L31) — `! is_singular( 'post' )`
- [src/PCV_restapi.php:110](../src/PCV_restapi.php#L110) — `'post' === get_post_type( $post_id )`

Ambos pasan a consumir un único helper, `PCV_settings::enabled_post_types(): array`, con
filtro `bbk_postview_enabled_post_types` para desarrolladores. **No duplicar la lógica**
en ningún otro sitio (regla de `AGENTS.md`).

Que la validación del endpoint use la misma lista es lo que impide que alguien cuente
visitas en un CPT desactivado llamando al endpoint a mano.

### 3.2 La página

- Nueva clase `src/PCV_settings.php`.
- Submenú bajo **Ajustes → Post View Count** (`add_options_page`), capability
  `manage_options`, Settings API completa (`register_setting` + `settings_fields`, que ya
  aporta nonce y `check_admin_referer`).
- Option única `bbk_postview_settings` (array), para no ensuciar la tabla de opciones.

### 3.3 Campos

| Campo | Detalle |
|---|---|
| `post_types` | Casillas sobre `get_post_types( ['public' => true], 'objects' )`. **Default `['post']`** (retrocompatibilidad). |
| `excluded_roles` | Qué roles no cuentan. Hoy está hardcodeado como `current_user_can( 'edit_posts' )`; pasa a ser configurable, con ese mismo default. |
| `exclude_bots` | Excluir user-agents de bot conocidos (default **desactivado** desde la 1.2.2 — antes activado por defecto; ver `docs/CHANGELOG.md`). |
| `retention_days` | Retención del agregado diario (default 400). |
| `delete_data_on_uninstall` | Ver §1.8. Default **activado**. |

**Sanitización**: el `sanitize_callback` de `post_types` **intersecta** lo recibido con los
tipos públicos reales del sitio. Nunca confiar en el POST — un tipo inventado se descarta
en silencio. Igual para roles.

### 3.4 Aviso obligatorio en la UI

> Desmarcar un tipo de contenido detiene el conteo, **pero no borra las visitas ya
> registradas**. Volver a marcarlo reanuda el conteo sobre el total existente.

### 3.5 Extras de esta fase (bajo coste, alto valor)

- **Columna «Vistas» ordenable** en los listados admin de cada CPT activo, más una columna
  «Última visita». Con la tabla propia es un `JOIN` con índice, no el `CAST` sobre
  `postmeta` que habría hecho falta antes.

  > ✅ **Implementada en la versión `1.2.1`** como `Admin\PostListColumns`. El valor
  > mostrado se lee del espejo en post meta (`views`/`views_last`) — WP ya precarga esa
  > meta en bloque para toda la lista, cero queries extra por fila. El orden, en cambio, usa
  > exactamente el `JOIN` indexado que pedía esta nota (`posts_join`/`posts_orderby` contra
  > `bbk_post_views`, nunca `CAST` sobre `postmeta`), leyendo `order`/`orderby` desde
  > `WP_Query::get()` en vez de `$_GET` directamente. Solo se registra para los tipos de
  > contenido actualmente habilitados. Sin test automatizado — depende de `WP_Query` real
  > (`is_main_query()` solo es cierto para la query principal reencolada por
  > `wp_edit_posts_query()`, no para una `new WP_Query()` suelta); validado manualmente
  > contra la query principal de `test.wp.local`, confirmando el `LEFT JOIN` y el
  > `ORDER BY bbk_v.views DESC` / `bbk_v.last_viewed_at ASC` generados.

- **Bloque de estado de la integración MCP** (ver fase 3): si `bubuku-mcp-conex` está activo,
  mostrar que las tools están registradas; si no, una nota informativa. Este bloque es el
  sustituto deliberado del `admin_notices` del contrato satélite (§4.2).
- **Botón «Eliminar todos los datos ahora»** con confirmación.

### 3.6 Tests

- `validate_post_id()` acepta un post de un CPT marcado y **rechaza** uno de un CPT desmarcado.
- El `sanitize_callback` descarta tipos inexistentes y roles inventados.
- `enabled_post_types()` devuelve `['post']` cuando la option no existe todavía.

---

## 4. Fase 3 — Capa de consulta y satélite MCP

*Cubre el requerimiento 3 y las decisiones 4 y 5.* Versión sugerida **`1.4.0`** *(a confirmar)*.

### 4.0 Nota previa: el autoloader y las subcarpetas

`bbk_autoload()` ([bubuku-post-view-count.php:50](../bubuku-post-view-count.php#L50)) resuelve
la clase con `substr()` pero **no traduce `\` a `/`**, así que hoy `src/` no puede tener
subcarpetas. Esta fase añade varias clases MCP y conviene agruparlas.

**Recomendación**: añadir el `str_replace( '\\', '/', … )` —una línea— y colocar el conector
y las tools en `src/Mcp/`. Es el primer paso, ya inevitable, de `docs/MIGRATION-PSR4.md`, y
no toca ninguna clase existente.

### 4.1 `PCV_query` — consultas puras

> ✅ **Implementada en la versión `1.2.1`** como `Core\Query` (post-migración PSR-4, no
> `PCV_query`). Los cinco métodos de la tabla de abajo están implementados tal cual, con
> `post_types` siempre intersectado con `Settings::enabled_post_types()`, `LIMIT` con tope
> duro de 100, `$wpdb->prepare()` en cada consulta y cache corta de objeto (5 min) en
> `most_viewed()`. `stale()` trata explícitamente `last_viewed_at IS NULL` (posts nunca
> vistos) vía `LEFT JOIN`, como exige esta sección. Tests nuevos en `Tests/run.php` cubren
> que un post type no habilitado nunca llega a consultarse; las consultas con `JOIN` a
> `wp_posts` (filtrado real por tipo, `trend()`) no tienen tabla simulada en el harness sin
> dependencias y se validan manualmente en `test.wp.local`, mismo patrón que `dbDelta()`/
> WP-Cron en la Fase 1. El resto de la Fase 3 (conector satélite MCP, tools, WP-CLI) sigue
> pendiente.

Clase sin ninguna dependencia de MCP, reutilizable por las tools, por WP-CLI, por el admin
y por un futuro endpoint REST de lectura. **Toda la lógica SQL vive aquí y solo aquí.**

| Método | Responde a |
|---|---|
| `most_viewed( post_types, since, until, limit, page )` | «lo más leído del blog en los últimos 6 meses» — **exacto**, agregando sobre `bbk_post_views_daily` |
| `stale( not_viewed_since, published_before, post_types, limit )` | «qué páginas no se visitan desde hace 6 meses» — sobre `last_viewed_at`, incluyendo explícitamente `last_viewed_at IS NULL` |
| `post_stats( post_id )` | total, primera y última visita, serie diaria |
| `trend( post_ids\|post_types, granularity, from, to )` | evolución temporal (posible gracias a la decisión 2) |
| `summary( post_types, since )` | totales del sitio, número de posts con y sin tráfico |

Reglas comunes: `$wpdb->prepare()` siempre; `LIMIT` con **tope duro de 100**; `post_types`
intersectado con los tipos habilitados; caché corta en object cache (5 min) por firma de
argumentos; se devuelven IDs, títulos y URLs, nunca objetos `WP_Post` completos.

`stale()` merece cuidado: un post migrado de la 1.1.0 tiene `last_viewed_at = NULL` y **sí**
es contenido sin visitas recientes — debe aparecer, no filtrarse por el `WHERE`.

### 4.2 Conector satélite

> ✅ **Implementada en la versión `1.2.1`** como `Mcp\SatelliteConnector`. Cableada en
> `Core\Plugin::init_mcp_satellite()`, hookeada a `init` (no directamente en el `init()` del
> plugin, que corre en `plugins_loaded`): la config llama `__()` de forma síncrona al
> construirse, y WordPress solo permite cargar el text domain desde `init` en adelante —
> hacerlo antes producía el aviso `_load_textdomain_just_in_time`. Es seguro: el hub
> recolecta las tools de forma perezosa (`Registry::collect()`, llamado desde
> `Mcp\Server` en la primera petición MCP real), muy por detrás de `init`, así que
> registrar los hooks del contrato ahí nunca arriesga perderse esa recolección — verificado
> leyendo `bubuku-mcp-conex/src/Registry.php` y `src/Mcp/Server.php` en el hub real. Validado
> contra el `BubukuConex\Registry` real en `test.wp.local`: las 4 tools se registran sin
> rechazos, el satélite se declara `compatible: true` y la entrada de catálogo se sirve
> correctamente. Divergencias respecto al contrato genérico del skill `wp-mcp-conex`
> aplicadas tal como decía este documento (ver tabla más abajo): sin `Requires Plugins`, sin
> admin notice cuando falta el hub.

Copiar `skills/bubuku/wp-mcp-conex/assets/class-satellite-connector.php`, cambiar namespace
y configuración. El contrato del hub está implementado y estable
(`bubuku-mcp-conex` v0.2.0, `BUBUKU_CONEX_CONTRACT_VERSION = 1`).

```php
add_action( 'plugins_loaded', function () {
    ( new \Bubuku\Plugins\PostViewCount\Mcp\Satellite_Connector( [
        'slug'        => 'bubuku-post-view-count',
        'label'       => 'Bubuku Post Views',
        'version'     => BBK_PLUGIN_VERSION,
        'contract'    => 1,
        'tools'       => [ /* FQCNs — ver §4.3 */ ],
        'text_domain' => 'bubuku-post-view-count',
        'catalog'     => [
            'discovery_description' => '…',
            'capabilities'          => [ … ],
        ],
    ] ) )->init();
} );
```

Del contrato, sin cambios:

- Guard `class_exists( 'BubukuConex\Registry' )` antes de enganchar nada.
- **Carga perezosa de las tools dentro de `register_tools()`**: extienden una clase del hub,
  así que cargarlas en el bootstrap provocaría un fatal error cuando el hub no esté.
- Hooks: `bubuku_conex_satellites`, `bubuku_conex_register_tools`, `bubuku_conex_satellite_catalog`.
- Prefijo de tools `bubuku-views/` — el hub rechaza colisiones.

**Divergencias respecto al contrato del satélite SEO, y por qué:**

| Contrato SEO | Aquí | Motivo |
|---|---|---|
| Cabecera `Requires Plugins: bubuku-mcp-conex` | **No se añade** | `bubuku-post-view-count` es un plugin público de WordPress.org. Esa cabecera bloquearía la activación a todo el mundo que no tenga el hub, que son todos sus usuarios. |
| `admin_notices` «este plugin necesita el hub» | **No se añade** | Por lo mismo: aquí el hub es opcional, no una dependencia. El estado de la conexión se muestra dentro de la página de ajustes (§3.5). |

El guard de runtime se mantiene igualmente, y con más razón: es lo único que separa "hub
ausente" de un fatal error.

**Empaquetado**: el conector y las tools **viajan en el zip público** y no se excluyen en
`.distignore`. Sin el hub son código inerte —unas cuantas clases que nunca se cargan— y
mantener dos paquetes distintos no compensa.

### 4.3 Las tools

> ✅ **Implementadas en la versión `1.2.1`** en `src/Mcp/Tools/` (`ListMostViewed`,
> `ListStaleContent`, `GetPostViews`, `GetViewsSummary`), con los nombres y argumentos de
> la tabla de abajo tal cual. Cada una delega toda la lógica en el método correspondiente
> de `Core\Query` (§4.1) — ninguna repite SQL. `get_post_views` acepta `post_id` **o** `url`
> (resuelta con `url_to_postid()`) y devuelve `{'error': {...}}` si no se da ninguno de los
> dos, o si el post no existe/no es público. `data_available_since` (nota obligatoria de más
> abajo) se expone en `list-most-viewed` vía el nuevo `Core\Schema::daily_data_since()`,
> respaldado por la option `Schema::OPTION_DAILY_SINCE`, escrita una sola vez en la
> primera instalación del esquema. Tests en `Tests/run.php` cubren la delegación de cada
> tool y el caso de error de `get-post-views`; el registro real contra el hub (sin
> rechazos, satélite `compatible: true`, catálogo servido) se validó manualmente en
> `test.wp.local`. WP-CLI (§4.4) no implementado — extra opcional, no bloqueante.

Cada una extiende `BubukuConex\Abstract_Satellite_Tool` e implementa los seis métodos
abstractos. `permission_callback()` y `execute_ability()` son `final` en el hub: la tool
declara la capability y **nunca** llama a `current_user_can()` por su cuenta.

| Tool | Capability | Argumentos |
|---|---|---|
| `bubuku-views/list-most-viewed` | `edit_posts` | `post_types`, `since`, `until`, `limit`, `page` |
| `bubuku-views/list-stale-content` | `edit_posts` | `not_viewed_since`, `published_before`, `post_types`, `limit` |
| `bubuku-views/get-post-views` | `edit_posts` | `post_id` **o** `url` |
| `bubuku-views/get-views-summary` | `edit_posts` | `post_types`, `since` |

- `get_help()` con `examples` y `criteria`, para que el LLM elija bien entre
  `list-most-viewed` y `list-stale-content`.
- `get_log_summary()` implementado, **sin volcar los argumentos completos**.
- Errores de negocio → `return [ 'error' => '…' ]`. Nunca lanzar: el hub ya envuelve las
  excepciones, pero un error de negocio no es una excepción.
- **Nota obligatoria en `get_description()`**: los datos diarios empiezan el día en que se
  instaló la fase 1; una ventana anterior devuelve resultados parciales, y la respuesta debe
  incluir el campo `data_available_since` para que el modelo no presente como completo un
  periodo que no lo es.

### 4.4 Extra propuesto: WP-CLI

> ✅ **Implementada en la versión `1.2.1`** como `Cli\ViewsCommand`, registrada con
> `WP_CLI::add_command()` solo si `defined('WP_CLI') && WP_CLI` (nunca carga en una
> request normal). Los tres subcomandos son envoltorios de `WP_CLI\Utils\format_items()`
> sobre `Core\Query`, tal cual proponía esta nota — `top`, `stale` y `post <id>` (este
> último añade la serie diaria como una segunda tabla en formato `table`). Sin test
> automatizado — depende de `WP_CLI\Utils\format_items()` real, sin stub en el harness;
> validado manualmente en `test.wp.local` contra datos reales, incluida la exclusión
> correcta de un borrador en `stale` (filtra `post_status = 'publish'`).

Comandos `wp bbk-views top`, `wp bbk-views stale`, `wp bbk-views post <id>` sobre el mismo
`PCV_query`. Coste marginal (son envoltorios de formato) y dan a esta fase una superficie
verificable sin necesidad del hub ni de un cliente MCP.

### 4.5 Demo de cierre

Espejo de la demo del doc del hub:

1. Hub conectado a Claude, satélite desactivado → las tools `bubuku-views/*` no aparecen.
2. Activar el plugin → aparecen en la misma conexión, **sin reconectar el cliente**.
3. Preguntar «¿qué es lo más leído del blog en los últimos 6 meses?» → responde con posts reales.
4. Preguntar «¿qué páginas no se visitan desde hace 6 meses?» → incluye las que nunca se han visitado.
5. Usuario sin `edit_posts` → el hub deniega con 403 y lo registra como `denied`.
6. Desactivar el plugin → las tools desaparecen. Desactivar el **hub** con el plugin activo
   → ningún fatal error, el conteo de visitas sigue funcionando con normalidad.

---

## 5. Fases posteriores

### F4 — Tendencias y superficie de lectura

Gracias a la decisión 2 **no hace falta esquema nuevo**:

- Gráfica de evolución en el admin (día / semana / mes) sobre `trend()`.
- Comparativa periodo contra periodo y listados «en alza» / «en caída».
- Shortcode `[bbk_post_views]` y bloque de Gutenberg equivalente.
- Endpoint REST `GET` de lectura, cacheable, con `permission_callback` explícito.

  > ✅ **Implementado en la versión `1.2.1`** como `Api\TrendsApi`: `GET /bbk_postview/v1/trends`,
  > `permission_callback` = `current_user_can('edit_posts')`, args `post_ids`/`post_types`/
  > `granularity`/`from`/`to` sobre `Core\Query::trend()`. Cacheable en dos capas: `Core\Query::trend()`
  > ganó la misma cache de objeto de 5 min que ya tenía `most_viewed()`, y la respuesta añade
  > `Cache-Control: private, max-age=300` (privada porque el resultado varía según capability, no
  > apta para caché compartida/reverse-proxy). Clase separada de `Api\RestApi` a propósito — ver
  > nota en `docs/ARCHITECTURE.md`. Validado en vivo: 401 sin `edit_posts`, 200 con datos reales
  > con ella (`rest_get_server()->dispatch()`).

- Tool MCP adicional `bubuku-views/get-content-trends`.

  > ✅ **Implementada en la versión `1.2.1`** como `Mcp\Tools\GetContentTrends`, mismo patrón que
  > las cuatro tools de la Fase 3 — delega en `Core\Query::trend()`. Registrada y probada contra
  > el `BubukuConex\Registry` real en `test.wp.local`.

> **Nota de alcance (histórica)**: cuando se escribió esta sección, la gráfica en el admin, la
> comparativa periodo/periodo y el shortcode + bloque de Gutenberg no estaban implementados,
> deliberadamente: se empezó solo por el backend (endpoint REST + tool MCP), dejando la UI para
> una iteración aparte porque un bloque de Gutenberg típico necesita build step
> (`@wordpress/scripts`), lo que choca con la regla de `AGENTS.md` de que este plugin no tiene
> build step de JS/CSS a propósito. Esa tensión se resolvió tal como se anticipaba aquí — ver la
> nota `✅ Implementada` justo debajo.

> ✅ **UI implementada** (versión sin publicar, ver `docs/CHANGELOG.md` → `[Unreleased]`):
>
> - **Gráfica de evolución + comparativa**: nueva sección en `Admin\SettingsPage`, con un
>   `<canvas>` pintado a mano (Canvas 2D, sin librería de gráficos ni CDN) y un selector de
>   granularidad día/semana/mes, alimentados por `GET /bbk_postview/v1/trends` (ya existente,
>   sin cambios en `Api\TrendsApi` ni en `Core\Query`). La comparativa de periodo actual vs.
>   anterior (últimos 30 días vs. los 30 anteriores) se calcula **en el cliente**: una única
>   llamada al endpoint con `granularity=day` sobre la ventana de 60 días, sumando los buckets
>   a cada lado de la fecha de corte — no hace falta un endpoint nuevo. Nuevos assets
>   `assets/js/admin-stats.js` y `assets/css/admin-stats.css`, encolados solo en esa página
>   admin comprobando el `$hook_suffix`.
> - **Renderizado compartido `Frontend\ViewsDisplay`**: un único método (`render()`) que pinta
>   "N vistas" (y opcionalmente la fecha de última visita, en la zona horaria del sitio vía
>   `wp_date()`), usado tanto por el shortcode como por el bloque — ninguno de los dos repite
>   la lógica de formato.
> - **Shortcode `[bbk_post_views]`** (`Frontend\Shortcode`), atributos `post_id` (por defecto el
>   post actual) y `show_last_viewed`.
> - **Bloque de Gutenberg `bubuku/post-views`** (`Frontend\Block`, registrado en `init`, y
>   `assets/blocks/post-views/`): decisión tomada con el usuario de no introducir
>   `@wordpress/scripts`. En su lugar, `index.js` está escrito a mano (sin JSX, `wp.element.createElement`)
>   y usa el componente core `ServerSideRender` para previsualizar en el editor el mismo
>   `render.php` que se sirve en el frontend (ambos delegan en `ViewsDisplay`). Las dependencias
>   del script (`wp-blocks`, `wp-element`, `wp-block-editor`, `wp-components`,
>   `wp-server-side-render`, `wp-i18n`) se declaran a mano en `index.asset.php` — el equivalente
>   manual del manifiesto que generaría un build real, que WordPress ya sabe leer sin más
>   configuración cuando `block.json` referencia `"file:./index.js"`.
> - Tests nuevos en `Tests/run.php` cubren `ViewsDisplay::render()` (conteo, fecha de última
>   visita, y cadena vacía para un tipo de contenido deshabilitado). El registro del shortcode,
>   del bloque y el pintado real de la gráfica no son testeables sin WordPress/navegador real
>   (mismo patrón que `dbDelta()`/`WP_CLI`/`PostListColumns` en fases previas) — validados
>   manualmente en `test.wp.local`.
>
> ✅ **Listados "en alza"/"en caída" implementados** (versión sin publicar, ver
> `docs/CHANGELOG.md` → `[Unreleased]`): `Core\Query::momentum( post_types, period_days,
> limit, min_views )` compara los últimos `period_days` días contra el periodo
> inmediatamente anterior de igual longitud sobre `bbk_post_views_daily`. Criterio elegido:
> variación **absoluta** (`delta`) para ordenar cada lista — no porcentual, porque un post
> que pasa de 1 a 5 vistas tendría un `+400%` más llamativo que uno que pasa de 500 a 2000,
> que es el cambio que de verdad importa en un sitio con tráfico real. El `delta_pct` se
> devuelve igualmente como dato complementario, y es `null` cuando `previous_views` es 0 (el
> porcentaje no está definido). `min_views` (default 1, suma de ambos periodos) es el filtro
> de ruido acordado en la nota de alcance: sin él, cualquier post que pase de 0 a 1 vista
> aparecería en "en alza" sin significar nada. Expuesto en tres superficies, todas
> delegando en el mismo método — nunca se duplica el SQL: `GET
> /bbk_postview/v1/trends/momentum` en `Api\TrendsApi` (misma capability `edit_posts` y
> `Cache-Control: private, max-age=300` que `get_trends()`, más cache de objeto de 5 min en
> `Core\Query::momentum()`, igual que `most_viewed()`/`trend()`); tool MCP
> `bubuku-views/list-momentum` (`src/Mcp/Tools/ListMomentum.php`, registrada junto a las
> cinco tools existentes); y dos listas nuevas ("En alza"/"En caída") en `Admin\SettingsPage`,
> pintadas por `assets/js/admin-stats.js` sobre el endpoint anterior, sin librería ni build
> step, igual que el resto de esta fase. Tests nuevos en `Tests/run.php` cubren el caso de
> tipo de contenido deshabilitado (mismo patrón que el resto de `Core\Query`); el `JOIN` real
> contra `wp_posts` no tiene tabla simulada en el harness sin dependencias y se validó
> manualmente en `test.wp.local` con datos reales insertados por WP-CLI (un post subiendo de
> 5 a 50 vistas y otro bajando de 40 a 2 entre dos periodos de 30 días), confirmando también
> el registro sin rechazos de la tool en el `BubukuConex\Registry` real y el `401`/`200` del
> endpoint sin/con `edit_posts`.

### F5 — Dimensiones de sesión (pantalla y procedencia)

> ✅ **Implementada** (versión sin publicar, ver `docs/CHANGELOG.md` → `[Unreleased]`).
>
> - **`referrer`: se persiste la clasificación, nunca el host.** El propio §F6 de este
>   documento da la razón: dice que ve el tráfico de IA "sin infraestructura adicional"
>   porque ya es "la dimensión `source = 'ai'` de la F5" — es decir, esta fase debía
>   persistir ya el valor clasificado, no el host bruto. Además `value VARCHAR(100)` con
>   "lista blanca cerrada" es incompatible con un host arbitrario. El host se calcula
>   transitoriamente en `assets/js/common.js` solo para clasificar (`direct`/`internal`/
>   `search`/`social`/`ai`/`other`) y nunca se envía ni se guarda.
> - **`dpr` (device pixel ratio) no se envía ni se persiste.** La tabla no tiene columna
>   para él y ninguna consulta/UI/tool lo usa — inventar una tercera dimensión sin
>   consumidor habría contradicho el propio criterio de "lista blanca cerrada" de esta
>   fase.
> - **`dimension = 'source'`** (de la definición SQL de abajo) queda sin implementar: no
>   hay ningún escritor para ella en F5, reservada tal cual dice el propio documento para
>   la F6.
> - Índice `KEY day_dimension_value (day, dimension, value)` añadido sobre el SQL literal
>   de abajo: `Core\Query::dims_breakdown()` es una consulta site-wide por
>   `dimension`+`value` con filtro de `day` y sin `post_id` en el `WHERE`, así que no puede
>   aprovechar el prefijo `post_id` de la clave primaria.
> - Única fuente de verdad de la lista blanca: `Core\Dimensions` (`DIMENSIONS`,
>   `VIEWPORT_BUCKETS`, `REFERRER_CLASSES`, `values_for()`), consumida tanto por
>   `Api\RestApi` (valida los valores al escribir) como por `Core\Query`/la tool MCP
>   (validan el nombre de dimensión al leer) — ninguno de los dos duplica la lista del
>   otro.
> - `Db::record_view()` gana un segundo parámetro opcional `$dims` (vacío por defecto, sin
>   romper ningún llamador existente), reutilizando el mismo `$now`/`$day` ya calculado
>   para los dos upserts existentes — evita una segunda llamada a `current_time()` y que la
>   fila de dims caiga en un día distinto al de la vista por un cruce de medianoche.
>   `Api\RestApi::set_post_views()` construye `$dims` validando cada valor contra
>   `Dimensions::values_for()` **sin** `validate_callback` en el arg REST (un
>   `validate_callback` que falla tumbaría toda la petición con 400; el requisito es que un
>   valor inválido se ignore, nunca impida contar la vista) — un valor desconocido se
>   descarta en silencio, la vista se sigue contando igual. El guard de dedupe/bot ya
>   existente protege las dims de forma automática, sin cableado extra: ambos retornan
>   antes de llamar a `record_view()`.
> - `Core\Query::dims_breakdown( dimension, post_types, since, until )`: primer método de
>   esta clase sin `LIMIT`/`cap_limit()` — cardinalidad fija y pequeña (máx. 6 filas para
>   `referrer`, 4 para `viewport`). Mismo patrón de caché de objeto (5 min) y `JOIN` contra
>   `wp_posts` que `momentum()`/`trend()`.
> - `GET /bbk_postview/v1/trends/dims?dimension=viewport|referrer` en `Api\TrendsApi`
>   (misma `permission_callback` y `Cache-Control: private, max-age=300` que `/trends` y
>   `/trends/momentum`) y tool MCP `bubuku-views/get-dims-breakdown`
>   (`src/Mcp/Tools/GetDimsBreakdown.php`), ambos delegando en `Core\Query::dims_breakdown()`
>   sin duplicar SQL, con `data_available_since` en la respuesta de la tool igual que las
>   demás.
> - Nueva sección "Dispositivo y procedencia" en Ajustes → Post View Count
>   (`Admin\SettingsPage` + `assets/js/admin-stats.js`), dos listas independientes
>   (dispositivo/procedencia) que fallan por separado si un endpoint falla — mismo patrón
>   que la sección de momentum de la F4.
> - `assets/js/common.js` añade `getViewportBucket()` (basado en `window.innerWidth`) y
>   `getReferrerClass()` (basado en `document.referrer` vs `location.host`, comparado
>   contra listas cortas de buscadores/redes sociales/asistentes IA) al payload existente.
>   Nunca se envía el host bruto, `document.referrer` en crudo, ni el ancho de píxel
>   exacto.
> - Tests nuevos en `Tests/run.php` cubren: el upsert de dims (agregación, no duplicado),
>   que `record_view()` sin `$dims` no toca la tabla nueva, la validación/descarte
>   silencioso de un valor inválido en el endpoint REST, que el guard de dedupe protege
>   también las dims, y la delegación superficial de `Core\Query::dims_breakdown()`
>   (dimensión desconocida o tipo de contenido deshabilitado → `array()`), de la ruta REST y
>   de la tool MCP. La agregación real con `JOIN`/`GROUP BY` se deja **"validar
>   manualmente"**, mismo precedente ya establecido para `momentum()`/`trend()` — el stub
>   `TestWpdb` no tiene motor SQL real. Validado manualmente en `test.wp.local`: creación de
>   la tabla vía `dbDelta()` en la actualización de esquema, una visita real generando filas
>   `viewport`/`referrer`, deduplicación, clasificación de un referrer de buscador conocido,
>   render real de la sección de admin, `GET /trends/dims` con/sin `edit_posts`, registro de
>   la tool contra el hub real, y borrado de la tabla al desinstalar.

Una **única** tabla, agregada por día y dimensión — **nunca evento por evento** (privacidad
y volumen):

```sql
{prefix}bbk_post_view_dims
  post_id    BIGINT UNSIGNED NOT NULL,
  day        DATE            NOT NULL,
  dimension  VARCHAR(20)     NOT NULL,   -- 'viewport' | 'referrer' | 'source'
  value      VARCHAR(100)    NOT NULL,
  views      INT UNSIGNED    NOT NULL DEFAULT 0,
  PRIMARY KEY (post_id, day, dimension, value)
```

`assets/js/common.js` añade al POST:

- **`viewport`** en buckets (`<576`, `576-991`, `992-1399`, `>=1400`) más `dpr`.
  Nunca el ancho exacto en píxeles: es un vector de fingerprinting y no aporta nada.
- **`referrer`** normalizado a host y clasificado en
  `direct | internal | search | social | ai | other`. Se guarda el host, no la URL completa
  (las query strings de referrer arrastran datos personales).

El endpoint valida la dimensión contra una lista blanca cerrada antes de escribir nada.

### F6 — Tráfico procedente de IAs

> ✅ **Implementada** en la versión `1.2.2`. Los dos fenómenos se resolvieron exactamente
> como anticipa esta sección: los **referidos por IA** ya estaban cubiertos por la
> dimensión `referrer=ai` de la F5 (`Core\Dimensions::REFERRER_CLASSES`) — no se ha
> añadido infraestructura nueva para esa parte, solo se expone junto al rastreo en la
> misma tool/endpoint. El **rastreo por bots de IA** es la parte nueva: `Core\AiCrawlers`
> (lista blanca cerrada con los mismos nueve crawlers nombrados más abajo) detecta el
> User-Agent; `Frontend\AiCrawlerTracker`, enganchado a `template_redirect` (nunca a
> `Api\RestApi`, que estos bots no llegan a disparar por no ejecutar JavaScript), delega en
> `Core\Db::record_ai_crawl()`, que escribe en la tabla propia `bbk_post_ai_crawls`
> (agregada por día y bot), completamente separada de `bbk_post_views`/`bbk_post_views_daily`
> — nunca contamina el conteo humano, tal como pedía esta sección. **Desactivado por
> defecto** (`Admin\Settings::ai_crawler_tracking()`, checkbox en Ajustes → Post View
> Count), por el mismo motivo que anticipaba el documento: una escritura por petición de
> bot no es despreciable en un sitio con tráfico de crawlers alto. `Core\Query::ai_traffic()`
> reutiliza `dims_breakdown()` para los referidos (sin duplicar SQL) y agrega
> `bbk_post_ai_crawls` por bot para el rastreo, devolviendo ambos bloques "claramente
> separados" como pedía el documento; expuesto en `GET /bbk_postview/v1/trends/ai-traffic`
> (`Api\TrendsApi`), la tool MCP `bubuku-views/get-ai-traffic` (`src/Mcp/Tools/GetAiTraffic.php`)
> y una sección «Tráfico de IA» en Ajustes → Post View Count. Tests en `Tests/run.php`
> cubren `AiCrawlers::detect()`, el upsert de `record_ai_crawl()` (aislado de las tablas
> humanas), el default/sanitización de `ai_crawler_tracking` y la delegación superficial de
> `Query::ai_traffic()`/la tool/la ruta REST — la agregación real con `JOIN` no tiene tabla
> simulada en el harness (mismo precedente que `momentum()`/`dims_breakdown()`), y
> `Frontend\AiCrawlerTracker` se valida manualmente en `test.wp.local`, igual que
> `dbDelta()`/WP-Cron en fases anteriores.

El requerimiento mezcla dos fenómenos que técnicamente **no se capturan igual**, y
planificarlos como uno solo lleva a un callejón sin salida:

**1. Referidos por IA** — personas que llegan al sitio desde ChatGPT, Claude, Perplexity,
Copilot o Gemini. Se detectan por `document.referrer` (`chatgpt.com`, `claude.ai`,
`perplexity.ai`, `copilot.microsoft.com`, `gemini.google.com`) y por `utm_source` / `?ref=`.
**Sí** los ve el mecanismo actual: son navegadores reales ejecutando el script. Es la
dimensión `source = 'ai'` de la F5, sin infraestructura adicional.

**2. Rastreo por IA** — los crawlers `GPTBot`, `ClaudeBot`, `Claude-User`, `PerplexityBot`,
`CCBot`, `Google-Extended`, `Bytespider`, `Amazonbot`, `Applebot-Extended`.
**Estos crawlers no ejecutan JavaScript**, así que el endpoint REST del plugin **nunca los
verá**, por mucha dimensión que se añada. Contarlos exige una vía distinta:

- Contador server-side en `template_redirect` que inspecciona el `User-Agent`.
- Tabla o dimensión propia, separada del conteo de visitas humanas — mezclarlos
  contaminaría todas las métricas anteriores.
- **Desactivado por defecto**: añade una escritura en cada petición de bot, que en un sitio
  con tráfico de crawlers no es despreciable.

Tool MCP `bubuku-views/get-ai-traffic` que devuelve ambos bloques claramente separados.

### F7 — Rendimiento y privacidad

- **Buffer de escrituras** para sitios de alto tráfico: acumular incrementos en object cache
  y volcarlos por lotes vía cron. Solo si la medición lo justifica.
- Respetar `DNT` y `Sec-GPC` cuando estén presentes (opción configurable).
- **Sección de privacidad en `readme.txt`**: hoy el plugin no persiste ni IP ni user-agent
  —solo el `md5` efímero del transient de deduplicación— y **eso debe seguir siendo cierto**
  después de la F5 y la F6. Ninguna de las dimensiones propuestas se almacena a nivel de
  visita individual.
- Regenerar el `.pot` con las cadenas nuevas del admin.

---

## 6. Orden, dependencias y riesgo

```
F1 (datos)  ──►  F2 (ajustes)  ──►  F3 (consulta + MCP)  ──┬──►  F4 (tendencias)
                                                            ├──►  F5 (dimensiones)  ──►  F6 (IA)
                                                            └──►  F7 (rendimiento y privacidad)
```

| Fase | Riesgo | Publicable sola | Notas |
|---|---|---|---|
| F1 | **Alto** | Sí | Cambio de modelo de datos. Rollback = reinstalar 1.1.0 (el meta sigue ahí). |
| F2 | Bajo | Sí | Todo aditivo salvo el default de `post_types`, que preserva el comportamiento actual. |
| F3 | Medio | Sí | El grueso es aditivo; el único cambio en código existente es el `str_replace` del autoloader. |
| F4–F7 | Bajo/Medio | Sí | F6 depende de F5. F7 puede adelantarse si aparece un problema de carga. |

**F1 es bloqueante de todo lo demás**: sin dimensión temporal en los datos, ni la fase 3 ni
las tendencias tienen nada que consultar.

F2 y F3 tocan archivos distintos y podrían paralelizarse, pero F3 necesita el helper
`enabled_post_types()` de F2 para acotar las consultas — hacerlas en orden sale más barato.

---

## 7. Criterios de aceptación globales

- `composer run-script lint` en verde.
- `php Tests/run.php` sin fallos, con los tests nuevos de cada fase.
- **Plugin Check sobre el zip de `dist/`**, nunca sobre el checkout de desarrollo
  (regla de `AGENTS.md`).
- El zip se activa en un WordPress limpio **y migra correctamente** desde una instalación
  1.1.0 con datos reales, sin perder ni un conteo.
- Desinstalar **con la casilla activa** no deja tablas, metas, options, transients ni eventos
  cron — también en multisitio. **Con la casilla desactivada**, las tablas se conservan y una
  reinstalación posterior las reutiliza sin duplicar.
- El plugin se activa y cuenta visitas con `bubuku-mcp-conex` **ausente**.
- Desactivar el hub con el plugin activo **no provoca fatal error** (probar explícitamente).
- Ninguna de las tres capas de seguridad del endpoint público se debilita en ninguna fase.
- El plugin sigue sin persistir IP ni user-agent.
