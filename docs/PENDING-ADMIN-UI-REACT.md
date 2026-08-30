# IMPLEMENTADO — Migración de la página de admin a `wp-admin` + `wp-frontend`

> Hoja de ruta por fases para reconstruir **Ajustes → Post View Count** siguiendo los skills
> `wp-admin` (PHP: menú, enqueue, notices) y `wp-frontend` (React + SCSS con el design system
> Bubuku DS 2026), que no se aplicaron cuando la página se construyó.
>
> Plan ejecutado. Se conserva como registro de las decisiones y verificaciones de la migración.
> La URL canónica de la pantalla registrada con `add_options_page()` es
> `/wp-admin/options-general.php?page=bubuku-post-view-count`.
>
> Estado analizado: rama `feature/v.1.2.2`, versión `1.2.2` (header PHP), con las fases F1–F7 de
> `docs/ANALYTICS-PLAN.md` ya implementadas.

## Por qué

La página de ajustes nació en la Fase 2 de `docs/ANALYTICS-PLAN.md` y fue creciendo con cada fase
(F4 añadió la gráfica y los listados de momentum, F5 el desglose de dimensiones, F6 el tráfico de
IA, F7 dos ajustes más). En ningún momento se cargaron los skills `wp-admin` ni `wp-frontend`, que
son la convención Bubuku para páginas de administración. El resultado funciona, pero diverge del
resto de plugins del ecosistema:

| Hoy | Lo que piden los skills |
|---|---|
| Settings API clásica (`register_setting` + `options.php`) | React + REST `GET`/`POST` `/settings` |
| `Admin\SettingsPage`, 441 líneas de `printf` + `add_settings_field` | `Admin\Admin` + `Admin\AdminPage`; los estados se muestran dentro de React |
| `assets/js/admin-stats.js`, 416 líneas de JS plano | `assets/src/js/admin/` con componentes React |
| `assets/css/admin-stats.css`, 79 líneas | SCSS con los design tokens (`config/_tokens.scss` + `_aliases.scss`) |
| Sin build step | `@wordpress/scripts` + webpack → `assets/build/` |

## Decisiones cerradas (no reabrir sin motivo)

Tomadas explícitamente por el usuario antes de escribir este documento:

| # | Decisión | Elegido |
|---|---|---|
| 1 | **Build step** (npm + `@wordpress/scripts` + webpack) | **Sí**, build completo con React |
| 2 | **Alcance** | **Todo**: formulario de ajustes **y** las 4 secciones de estadísticas |
| 3 | **Guardado de ajustes** | **REST `GET`/`POST` `/settings`**, sustituyendo la Settings API |
| 4 | **Bloque Gutenberg** | **Migrarlo también al build** (JSX + `index.asset.php` generado) |

### ⚠️ Esto contradice `AGENTS.md` — hay que actualizarlo

`AGENTS.md` lista hoy, en **«Lo que nunca debes hacer»**:

> Introducir un build step (webpack, npm) para `assets/js/common.js` sin que el usuario lo pida —
> hoy es JS plano intencionalmente.

El usuario **lo ha pedido**, así que la prohibición deja de aplicar tal cual está escrita. Pero
mientras `AGENTS.md` no se reescriba (Fase 8), cualquier sesión que lea el repo encontrará una regla
que contradice este plan y se bloqueará. **Si se ejecuta este plan por fases y se interrumpe a
medias, conviene adelantar la nota de `AGENTS.md` a la Fase 1** en vez de dejarla para el final.

Redacción propuesta para la regla nueva: el plugin **sí** tiene build step para el admin y los
bloques (`assets/src/` → `assets/build/`), pero `assets/js/common.js` sigue siendo JS plano
deliberadamente (ver decisión de la Fase 1).

---

## Hallazgos previos (leer antes de empezar)

Puntos no obvios detectados al analizar el repo y las plantillas del skill. Sin ellos la ejecución
tropieza:

1. **`webpack.config.js` va en la raíz del plugin, no dentro de `assets/`.** La plantilla del skill
   está guardada bajo `skills/wp-frontend/assets/` pero usa `__dirname` + `'assets/src/...'`, así que
   solo resuelve correctamente desde la raíz.
2. **El skill no trae plantilla de `package.json`.** Hay que tomarla de un plugin hermano del
   monorepo: `bubuku-plugins-wp/media-ranger/package.json` es el más parecido a este caso
   (`@wordpress/scripts ^31.2.0`, sin `dependencies` de runtime — React y `@wordpress/*` son
   externos que resuelve `admin.asset.php`). Para la doble entrada admin + bloques, el patrón está
   en `bubuku-plugins-wp/bubuku-manager/package.json`.
3. **Las plantillas contienen tokens literales que no compilan tal cual.** Hay que sustituirlos:
   `{prefix}` (en `index.js`, `_tokens.scss`, `_aliases.scss`, `style.scss`), `{PluginGlobal}` (en
   `Dashboard.js`, escrito como `{ PluginGlobal }.api_url`, que es JS inválido) y `{plugin-slug}`
   (namespace REST y textdomain).
4. **El `style.scss` del skill trae resets globales agresivos.** Oculta con `display: none !important`
   **todos** los admin notices (`.wp-core-ui .notice.is-dismissible`, `#wpbody-content > .notice`,
   `.notice.notice-warning.update-nag`) y `#wpfooter`. En un plugin público de WordPress.org eso es
   un riesgo en revisión (esconde avisos de otros plugins y del core) y además taparía nuestro propio
   notice de «datos eliminados». **Recomendación: conservar los resets de layout
   (`#wpcontent`, `#wpbody-content`), eliminar el ocultado de notices.**
5. **No existe un componente `AdminTabs.js`.** Las tabs son solo un patrón JSX documentado en el
   `SKILL.md` más su partial `_admin-tabs.scss`. Hay que escribir el componente.
6. **`deploy.yml` despliega directamente desde el checkout** con
   `10up/action-wordpress-plugin-deploy`, sin ejecutar npm. Con build step, **el release a
   WordPress.org subiría sin los assets compilados** salvo que se añada el paso de build antes de la
   action. Es el riesgo más serio de todo el plan.
7. **`.distignore` ya anticipa un build** (excluye `/assets/src`, `package.json`,
   `webpack.config.js`, `/node_modules/`), pero **`scripts/build.sh` no ejecuta npm** — solo
   comprime lo que encuentre.
8. **`.gitignore` no ignora `assets/build/`.** Hay que decidir si los artefactos se commitean o se
   generan en CI (ver Fase 1).
9. **El plugin no llama a `wp_set_script_translations()`.** Sin esa llamada, los `__()` de React no
   se traducen nunca, por muy bien que esté el `.pot`.
10. **Los endpoints de estadísticas ya existen.** `Api\TrendsApi` sirve `/trends`,
    `/trends/momentum`, `/trends/dims` y `/trends/ai-traffic`. La UI de estadísticas **no necesita
    backend nuevo**: solo se reescribe el cliente. El único endpoint nuevo de todo el plan es
    `/settings`.
11. **El nuevo transform de JSX requiere WordPress 6.6.** El manifiesto generado depende de
    `react-jsx-runtime`, incorporado en Core en esa versión. Por tanto el header PHP y el
    `Requires at least` de `readme.txt` quedan sincronizados en 6.6.

---

## Fase 1 — Toolchain (build step)

**Bloquea todas las demás fases.**

### Cambios

| Archivo | Cambio |
|---|---|
| `package.json` | **Nuevo.** Scripts `build`, `start`, `lint:js`, `lint:css`, `packages-update`. `devDependencies`: `@wordpress/scripts ^31.2.0`. Sin `dependencies` de runtime. |
| `webpack.config.js` | **Nuevo, en la raíz** (hallazgo 1). Dos entradas: `admin` (JS + SCSS) y la del bloque (Fase 7). Alias `@` → `assets/`. |
| `.gitignore` | Añadir `/assets/build/` (ver decisión de artefactos, abajo). |
| `.distignore` | **Dejar de excluir `/assets/src`** (ver decisión de WordPress.org, abajo). |
| `scripts/build.sh` | Añadir `npm ci && npm run build` antes de comprimir, y fallar si `assets/build/` no existe tras el build. |
| `.github/workflows/validate.yml` | Nuevo job Node: `npm ci`, `npm run lint:js`, `npm run lint:css`, `npm run build`. |
| `.github/workflows/deploy.yml` | **Crítico (hallazgo 6).** `actions/setup-node` + `npm ci && npm run build` **antes** de la action de deploy. |

### Decisión — WordPress.org y código minificado

La guideline 3 del directorio exige código legible por humanos, o acceso público a la fuente de
cualquier archivo comprimido/minificado (en el propio plugin o mediante enlace en el `readme.txt`).
`.distignore` excluye hoy `/assets/src`, así que el zip viajaría **solo con el JS minificado**.

**Recomendación: dejar de excluir `/assets/src` del zip.** Es la opción más segura y de cero
mantenimiento; la alternativa (enlazar el repo desde `readme.txt`) obliga a mantener el enlace vivo
y a que el repo sea público y esté sincronizado con cada release.

### Decisión — artefactos de build en git

Dos opciones, según cómo quede `deploy.yml`:

| Opción | Implica |
|---|---|
| **(a)** Commitear `assets/build/` | `deploy.yml` funciona sin tocarlo. Contra: ruido de artefactos en cada commit y conflictos de merge constantes. |
| **(b)** Ignorar `assets/build/` y construir en CI **(recomendada)** | Git queda limpio. Obliga a añadir el paso de build a `deploy.yml` **y** a `validate.yml`. La action de 10up copia el directorio de trabajo respetando `.distignore`, así que recoge lo construido. |

### Decisión — `assets/js/common.js` queda fuera del build

El contador público son 83 líneas de JS plano, sin dependencias, en la ruta crítica de Core Web
Vitals — que es la razón de ser del plugin. Pasarlo por webpack solo añadiría un wrapper y una
dependencia de build para cero beneficio. **Se mantiene tal cual, editándose directamente.**

### Verificación

- `npm run build` genera `assets/build/admin.js`, `assets/build/style-admin.css` y
  `assets/build/admin.asset.php`.
- `bash scripts/build.sh` produce un zip que **contiene** `assets/build/` y `assets/src/`.
- Los workflows pasan en verde en un PR de prueba.

---

## Fase 2 — REST de ajustes (`Api\SettingsApi`)

Sustituye a la Settings API (decisión 3). Es el único backend nuevo del plan.

### Clase nueva: `src/Api/SettingsApi.php`

**Separada de `Api\RestApi`** siguiendo el precedente ya documentado en `docs/ARCHITECTURE.md` para
`Api\TrendsApi`: `Api\RestApi` es el contador público anónimo con su propio modelo de seguridad;
esto es una superficie de administración con capability. Son concerns distintos.

| Ruta | Método | Responsabilidad |
|---|---|---|
| `/bbk_postview/v1/settings` | `GET` | Devuelve `Settings::get_all()` **más el contexto de solo lectura** que la UI necesita para pintarse |
| `/bbk_postview/v1/settings` | `POST` | Guarda, **reutilizando `Settings::sanitize()`** como sanitizador |
| `/bbk_postview/v1/settings/data` | `DELETE` | «Eliminar todos los datos ahora» |

`permission_callback` = `current_user_can( 'manage_options' )` en las tres.

**Contexto de solo lectura que debe devolver el `GET`** (hoy lo resuelve PHP al renderizar cada
campo; en React hace falta enviarlo):

- Tipos de contenido seleccionables con sus labels → `Settings::selectable_post_types()`
  (recordar que excluye `attachment` a propósito).
- Roles editables con sus nombres traducidos → `get_editable_roles()` + `translate_user_role()`.
- Ejemplos de firmas de bot → `Settings::bot_signature_examples()`.
- Si hay object cache persistente → `wp_using_ext_object_cache()` (lo necesita el aviso del buffer
  de escrituras).
- Desde cuándo hay datos diarios → `Schema::daily_data_since()`.

**`DELETE`**: reutiliza lo que ya hace `SettingsPage::handle_reset_data()` — `Db::drop_tables()`,
`Db::remove_all_post_meta()` y `Schema::activate( false )` para recrear las tablas vacías. Sustituye
al flujo actual por `admin-post.php`.

### Seguridad

- **No se toca `Api\RestApi`.** Sus tres capas (origen same-site vía `check_request_origin`,
  validación estricta de `post_id`, deduplicación por transient) quedan intactas, como exige
  `AGENTS.md`.
- Al pasar de la Settings API a REST **se pierde el nonce que daba `settings_fields()` gratis**. Lo
  sustituye el header `X-WP-Nonce` (`wp_create_nonce( 'wp_rest' )`), inyectado desde PHP en la Fase 3.
- El `DELETE` es destructivo e irreversible: mantener la confirmación explícita en el cliente, como
  hoy.

### Tests

Seguir el patrón ya existente de `Api\TrendsApi` en `Tests/run.php`: `check_permission()` frente a
`current_user_can()`, y que cada callback delega donde debe. Los stubs necesarios
(`current_user_can()`, `WP_REST_Response::header()`) **ya están** en `Tests/bootstrap.php`.

---

## Fase 3 — PHP del admin (skill `wp-admin`)

### Clases

| Clase | Responsabilidad |
|---|---|
| `Admin\Admin` | Orquestador: registra los hooks de admin. Instanciado desde `Core\Plugin`. |
| `Admin\AdminPage` | `add_options_page()`, `render()` (solo el `<div id="bbk-postview-app">`), y `enqueue_assets()` leyendo versión y dependencias de `admin.asset.php`, scopeado por `$hook_suffix`. |
| Mensajes de estado React | Muestran el resultado de guardar o eliminar datos sin recargar la página. |

**Se mantiene el namespace del plugin**: `Bubuku\Plugins\PostViewCount\Admin`. El
`Bubuku\{ClassName}\Admin` del skill es un token de plantilla, y `docs/MIGRATION-PSR4.md` es la
convención vigente aquí.

### Objeto global inyectado

Con `wp_add_inline_script`, nombre `BbkPostViewCount` (PascalCase del slug):

```
api_url      → rest_url( BBK_PLUGIN_ENDPOINTS_URL )
rest_nonce   → wp_create_nonce( 'wp_rest' )
```

### i18n en JS (hallazgo 9)

Añadir `wp_set_script_translations( 'bbk-postview-admin', 'bubuku-post-view-count' )`. Sin esto los
`__()` de React nunca se traducen.

### Notas

- `Admin\PostListColumns` **no se toca**: son las columnas del listado de posts, no esta página.
- `Admin\SettingsPage` sigue viva hasta la Fase 8 (se borra cuando React ya cubre todo).

---

## Fase 4 — Scaffold React

Copiar las plantillas de `skills/wp-frontend/assets/src/` sustituyendo los tres tokens (hallazgo 3):

| Token | Valor |
|---|---|
| `{prefix}` | `bbk-postview` (el `id` del div debe coincidir con el de `AdminPage::render()`) |
| `{PluginGlobal}` | `BbkPostViewCount` |
| `{plugin-slug}` | `bubuku-post-view-count` |

### Estructura

```
assets/src/
├─ js/admin/
│  ├─ index.js              createRoot sobre #bbk-postview-app
│  ├─ App.js                HeaderMain + tabs + panel activo + FooterMain
│  └─ components/
│     ├─ AdminTabs.js       ← escribir (hallazgo 5): tabs Ajustes / Estadísticas
│     ├─ HeaderMain.js
│     ├─ FooterMain.js
│     ├─ DashboardCard.js
│     ├─ DataTable.js
│     ├─ SaveBar/index.js
│     ├─ SettingsPanel.js   ← Fase 5
│     └─ StatsPanel.js      ← Fase 6
└─ scss/admin/
   ├─ style.scss
   ├─ config/{_tokens,_aliases}.scss
   ├─ base/{_animations,_buttons,_field,_notices}.scss
   └─ components/{_app,_admin-tabs,_dashboard-card,_data-table,_header-main,_footer-main,_save-bar}.scss
```

**Aplicar aquí la decisión del hallazgo 4**: quitar del `style.scss` copiado el bloque que oculta
notices y `#wpfooter`; conservar los resets de layout.

### Reglas del skill a respetar

- Componentes funcionales con hooks, nunca clases.
- `createRoot` de `@wordpress/element`, nunca `ReactDOM.render`.
- No usar `@wordpress/components` — los componentes de UI son propios.
- Usar solo los tokens de rol de `_aliases.scss`, nunca hex ni los nombres de `_tokens.scss`.
- Para inputs propios en el admin, aplicar el patrón de especificidad documentado en el skill
  (`input[type="x"].clase` anidado en el contenedor) — WordPress Admin gana si no.

---

## Fase 5 — UI de ajustes

`SettingsPanel` consumiendo `GET`/`POST /settings` de la Fase 2, con `BkSaveBar` para guardar sin
recargar. Los 8 campos actuales:

| Campo | Control | Default |
|---|---|---|
| `post_types` | Checkboxes | `['post']` |
| `excluded_roles` | Checkboxes | Roles con `edit_posts` |
| `exclude_bots` | Checkbox | **Activado** |
| `ai_crawler_tracking` | Checkbox | Desactivado |
| `respect_dnt` | Checkbox | **Activado** |
| `write_buffer` | Checkbox | Desactivado |
| `retention_days` | Número (min 1) | 400 |
| `delete_data_on_uninstall` | Checkbox | **Activado** |

Más el botón **«Eliminar todos los datos ahora»** (`DELETE /settings/data`), con confirmación.

### Requisito explícito: preservar los textos de ayuda

Los `<p class="description">` de los `field_*()` actuales de `SettingsPage.php` **no son
decorativos**: documentan decisiones tomadas a lo largo del plan de analítica y deben migrar
íntegros. En particular:

- Que desmarcar un tipo de contenido **detiene el conteo pero no borra** las visitas ya registradas.
- Por qué `ai_crawler_tracking` viene desactivado (una escritura por petición de bot).
- Que `respect_dnt` **no afecta al conteo**, solo a las dimensiones de sesión.
- El aviso del buffer según haya o no object cache persistente (los dos textos, se elige en cliente
  con el flag que envía el `GET`).
- Que la retención **solo afecta al histórico diario**, nunca al total de vistas.
- La lista de ejemplos de bots de `exclude_bots`.

---

## Fase 6 — UI de estadísticas

`StatsPanel` sobre los endpoints **ya existentes** de `Api\TrendsApi` (hallazgo 10). Cero backend
nuevo.

| Sección | Endpoint | Componente |
|---|---|---|
| Evolución de vistas (día/semana/mes) + comparativa | `/trends` | Componente Canvas propio |
| En alza / En caída | `/trends/momentum` | `DataTable` ×2 |
| Dispositivo y procedencia | `/trends/dims` | `DataTable` ×2 |
| Tráfico de IA (referidos + rastreo) | `/trends/ai-traffic` | `DataTable` + dato suelto |

### La gráfica

**Portar el Canvas 2D de `admin-stats.js` a un componente React** (`useRef` sobre el `<canvas>` +
`useEffect` para repintar al cambiar datos o granularidad), **reutilizando la matemática de dibujo
verbatim** — ejes, escalado, `stepX`, etiquetas. Está probada y no hay razón para reescribirla. Lo
único que cambia es de dónde salen los datos y cuándo se repinta.

La comparativa de periodo (últimos 30 días vs. los 30 anteriores) se sigue calculando **en cliente**
a partir de una sola llamada con `granularity=day` sobre 60 días, como hoy — no hace falta endpoint
nuevo.

### Comportamiento a conservar

Cada sección **carga y falla de forma independiente**: si un endpoint falla, las demás se pintan
igual. Es el comportamiento actual y está documentado en `ARCHITECTURE.md`.

---

## Fase 7 — Bloque Gutenberg al build

Hoy el bloque está escrito a mano sin JSX (`wp.element.createElement`) con un `index.asset.php`
manual, precisamente para no tener build. Con la decisión 4, eso deja de ser necesario.

- Mover `assets/blocks/post-views/` → `assets/src/blocks/post-views/`, con JSX real.
- Segunda entrada de webpack (patrón `--webpack-src-dir` de `bubuku-manager`).
- **Borrar el `index.asset.php` escrito a mano** — lo genera el build.
- Actualizar la ruta de registro en `Frontend\Block`.
- `block.json` sigue apuntando a `render.php`, que se mantiene server-side delegando en
  `Frontend\ViewsDisplay` (igual que el shortcode, sin duplicar el renderizado).

---

## Fase 8 — Limpieza, i18n y documentación

### Borrar

- `src/Admin/SettingsPage.php`
- `assets/js/admin-stats.js`
- `assets/css/admin-stats.css`

### i18n

- Regenerar `languages/bubuku-post-view-count.pot` (`wp i18n make-pot .`).
- **Generar los `.json` de traducción de JS** (`wp i18n make-json`) — sin ellos
  `wp_set_script_translations()` no tiene qué cargar.

### Documentación

| Documento | Qué actualizar |
|---|---|
| `AGENTS.md` | **La regla del build step** (ver aviso al inicio de este documento). También la tabla de skills activos y la delegación por tipo de tarea. |
| `docs/ARCHITECTURE.md` | Clases nuevas (`Admin\Admin`, `Admin\AdminPage`, `Api\SettingsApi`), estructura `assets/src` → `assets/build`, y la sección de assets. |
| `docs/CHANGELOG.md` | Entrada en `[Unreleased]`. |
| `docs/ANALYTICS-PLAN.md` | Nota en F4/F5/F6/F7 apuntando a que su UI se reconstruyó aquí. |
| `readme.txt` | Solo si se opta por enlazar la fuente en vez de incluir `/assets/src` (Fase 1). |

---

## Verificación global

Por fase, y de nuevo al cerrar:

- `composer run-script lint` en verde.
- `php Tests/run.php` sin fallos, con los tests nuevos de `Api\SettingsApi`.
- `npm run build`, `npm run lint:js` y `npm run lint:css` sin errores.
- **Plugin Check sobre el zip de `dist/`**, nunca sobre el checkout de desarrollo (regla de
  `AGENTS.md`) — prestando atención a si marca el JS minificado de `assets/build/`.
- Manual en `test.wp.local`: render de la página, guardado sin recarga, las 4 secciones de
  estadísticas con datos reales, el borrado de datos, y el bloque tanto en el editor como en el
  front.
- **El plugin sigue contando visitas con normalidad**: no se ha tocado `Api\RestApi` ni
  `assets/js/common.js`.
- Ninguna de las tres capas de seguridad del endpoint público se debilita en ninguna fase.

## Orden y dependencias

```
F1 (toolchain)  ──►  F2 (REST settings)  ──►  F3 (PHP admin)  ──►  F4 (scaffold React)
                                                                     │
                                                                     ├──►  F5 (UI ajustes)
                                                                     └──►  F6 (UI estadísticas)

F7 (bloque) depende solo de F1.        F8 (limpieza) cierra todo.
```

| Fase | Riesgo | Notas |
|---|---|---|
| F1 | **Alto** | Toca release y CI. Un fallo aquí rompe el despliegue a WordPress.org (hallazgo 6). |
| F2 | Medio | Cambia el mecanismo de guardado; el endpoint público no se toca. |
| F3 | Bajo | Aditivo — `SettingsPage` sigue viva hasta F8. |
| F4 | Bajo | Solo scaffold, sin lógica de negocio. |
| F5–F6 | Medio | Reescritura de UI; el riesgo es perder textos de ayuda o comportamiento (fallo independiente por sección). |
| F7 | Bajo | Aislado. |
| F8 | Bajo | Borrado y documentación, una vez todo lo demás está verificado. |

**F1 es bloqueante de todo lo demás**, y es también la fase con más riesgo operativo: conviene
validarla con un PR de prueba y un release de prueba antes de seguir.
