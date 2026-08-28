# Plan de mejoras — Bubuku Post View Count

> Documento de análisis y plan de implementación por fases.
> Estado del código analizado: rama `feature/migrate`, versión `1.0.4`.
> **Este documento no modifica código.** Es la hoja de ruta a ejecutar.

---

## 1. Resumen ejecutivo

El plugin es pequeño (283 líneas PHP, 4 clases, 1 archivo JS) y su idea de fondo es
buena: contar vistas de forma diferida vía REST para no penalizar el TTFB ni los Core
Web Vitals. Sin embargo, la implementación actual tiene **un bug de release que impide
que el zip de producción arranque**, **un endpoint público de escritura sin ninguna
protección real**, y varios defectos de rendimiento y corrección que se manifiestan
justo cuando el sitio tiene tráfico (pérdida de conteos por condición de carrera,
jQuery cargado sin necesidad, script en el `<head>`).

Hallazgos por severidad:

| Sev. | Nº | Áreas |
|---|---|---|
| P0 — Crítico | 4 | Release rota, endpoint abierto, nonce inoperante, escritura arbitraria en `postmeta` |
| P1 — Alto | 8 | Carrera en el contador, `enqueue` mal invocado, hook incorrecto, i18n rota, constantes fuera de sitio |
| P2 — Medio | 9 | jQuery innecesario, respuesta REST no estándar, bots y prefetch contados, versionado disperso |
| P3 — Bajo / Mejora | 10 | Tests, escalabilidad a tabla propia, admin UI, documentación, CI |

Las fases están ordenadas para que **cada una sea publicable por sí sola** y para que
el riesgo se reduzca antes de tocar arquitectura.

---

## 2. Inventario de hallazgos

### 2.1 P0 — Crítico

**P0-1. El zip de distribución no incluye `vendor/` pero el plugin lo exige.**
`bubuku-post-view-count.php:31` hace `require_once 'vendor/autoload.php'`, y
`.distignore` excluye `/vendor/`. Tanto `scripts/build.sh` como la acción
`10up/action-wordpress-plugin-deploy` respetan `.distignore`, así que el paquete
publicado no lleva autoloader → **fatal error al activar**. Además la ruta es relativa
(depende de `include_path`/CWD), no `__DIR__`.
Mismo problema en `uninstall.php:10`.

**P0-2. Endpoint REST de escritura totalmente abierto.**
`src/PCV_restapi.php:49` usa `'permission_callback' => '__return_true'` sobre un método
`POST` que escribe en base de datos, sin límite de frecuencia, sin deduplicación por
IP/sesión y sin verificación efectiva. Cualquiera puede inflar el contador de cualquier
post con un bucle de `curl`, y en volumen es un vector de saturación de escrituras en
`wp_postmeta`.

**P0-3. La verificación de nonce no verifica nada.**
`src/PCV_restapi.php:64`:
```php
if ( BBK_PLUGIN_NONCE !== $nonce && empty( $post_id ) ) {
```
Tres defectos acumulados:
- El operador es `&&` en lugar de `||`: con un `post_id` presente la condición **nunca**
  se cumple, así que un nonce inválido pasa igual.
- Compara contra la constante en vez de usar `wp_verify_nonce()`, así que ignora la
  ventana de validez y el usuario asociado.
- `BBK_PLUGIN_NONCE` se genera con `wp_create_nonce()` en cada petición y se imprime en
  el HTML vía `wp_localize_script`; con page caching el HTML servido lleva un nonce ya
  caducado. Un nonce en un endpoint anónimo, además, no aporta protección CSRF real.

**P0-4. Escritura arbitraria en `postmeta` sin validar el objeto.**
`set_post_views()` acepta cualquier valor numérico y lo pasa a `add_post_meta()` /
`update_post_meta()` sin comprobar que el ID exista, que sea un post publicado, público
y del tipo `post`. Se pueden crear filas de `postmeta` para IDs inexistentes de forma
ilimitada (crecimiento descontrolado de tabla) y se contabilizan borradores, privados,
revisiones y adjuntos.

### 2.2 P1 — Alto

**P1-1. Condición de carrera en el contador.**
`PCV_db::set_post_views()` hace leer → incrementar en PHP → escribir. Con visitas
concurrentes se pierden incrementos. Debe resolverse con un `UPDATE ... SET meta_value =
meta_value + 1` preparado (atómico en el motor), o con tabla propia.

**P1-2. `wp_enqueue_script()` invocado con la firma equivocada.**
`src/PCV_assets.php:34-39` pasa `true` en la cuarta posición, que es `$ver`, no
`$in_footer`. Resultado real: versión del asset = `"1"` (no invalidable entre releases) y
el script se imprime en el `<head>`, justo lo contrario de lo que persigue el plugin.

**P1-3. Hook de encolado incorrecto.**
Se usa `enqueue_block_assets`, que también dispara en el editor de bloques y en el admin
(de ahí el `!is_admin()` defensivo). Para assets de front debe ser `wp_enqueue_scripts`.

**P1-4. La carga de traducciones nunca funciona.**
`PCV_plugin.php:37` pasa `BBK_PLUGIN_BASE`, que es una **URL**
(`plugin_dir_url()`), donde `load_plugin_textdomain()` espera una ruta relativa a
`WP_PLUGIN_DIR`. Además la carpeta `languages/` declarada en el header no existe.

**P1-5. Textdomain cargado demasiado pronto.**
Se carga en `plugins_loaded`; desde WP 6.7 esto dispara un aviso
`_doing_it_wrong` de "Translation loading triggered too early". Debe ir en `init`
(o eliminarse: WP.org carga las traducciones automáticamente desde 4.6).

**P1-6. Constantes definidas dentro de `plugins_loaded`.**
No están disponibles en `uninstall.php`, ni en los hooks de activación/desactivación, ni
para código que corra antes. Deben declararse en el archivo principal, guardadas con
`defined()`.

**P1-7. `$the_plugin` puede quedar indefinido.**
`bubuku-post-view-count.php:35-40`: si `class_exists()` falla, las llamadas a
`register_activation_hook()` reciben `[ null, 'activate' ]` → fatal. Los `register_*_hook`
deben ir dentro de la guarda, y conviene comprobar la existencia del autoloader antes de
requerirlo, con un `admin_notice` si falta.

**P1-8. `$post` global usado sin comprobación.**
`PCV_assets.php:29,44` accede a `$post->ID` sin verificar que `$post` sea un `WP_Post`.
Mejor `get_queried_object_id()`.

### 2.3 P2 — Medio

**P2-1. jQuery declarado como dependencia sin usarse.** `common.js` usa `fetch` nativo;
la dependencia fuerza la carga de jQuery en todas las entradas singulares.

**P2-2. El callback REST no devuelve una respuesta REST.** Usa `wp_send_json_success()` +
`die()`, que cortocircuita la pila REST (se saltan `rest_post_dispatch`, cabeceras,
`_envelope`, manejo de errores). Debe devolver `WP_REST_Response` / `WP_Error`.

**P2-3. Sin exclusión de bots, prefetch ni usuarios internos.** Se cuentan crawlers,
`<link rel=prefetch>`, y las propias visitas de editores/administradores.

**P2-4. `remove_all_post_meta()` con SQL directo.** Funciona, pero
`delete_post_meta_by_key( 'views' )` es la API correcta, respeta la caché de objetos y
los hooks. La consulta actual, además, deja la caché de metadatos obsoleta.

**P2-5. `uninstall.php` no contempla multisitio.** En una red, borra sólo el sitio actual.

**P2-6. La versión vive en tres sitios y ya están desincronizados.** Header `1.0.4`,
`BBK_PLUGIN_VERSION` hardcodeada `1.0.4`, `composer.json` `1.0.0`. Debe derivarse del
header en tiempo de ejecución (`get_file_data`) o dejar una única constante.

**P2-7. `window.addEventListener("load", bk_postview_main.init())`** ejecuta `init()`
inmediatamente y registra `undefined` como listener. Funciona por accidente (el propio
`setTimeout` interno salva el caso), pero es incorrecto.

**P2-8. `fetch` sin `keepalive` ni `navigator.sendBeacon`.** Si el usuario navega antes
de los 8 s, la petición se cancela y la vista se pierde.

**P2-9. Comparaciones laxas y código muerto.** `$count == ''`, `PCV_db::init()` vacío,
`return $data` inalcanzable tras `die()`, `PCV_restapi::$_namespace` duplicando
`BBK_PLUGIN_ENDPOINTS_URL`.

### 2.4 P3 — Bajo / Mejora

- **P3-1.** Sin tests automatizados (ni PHPUnit ni `wp-env`).
- **P3-2.** `readme.txt` `Tested up to: 6.5.3` — desactualizado frente a las versiones
  actuales de WordPress; `License` del readme (GPLv3) contradice el header (EUPL v1.2) y
  el `composer.json` (GPL-3.0+).
- **P3-3.** `composer.json` declara `"php": ">=5.4.0"` frente al `Requires PHP: 7.2` del
  header. Debe alinearse (y subirse a 7.4/8.0 como mínimo realista).
- **P3-4.** El CI sólo corre PHPCS sobre PHP 7.4; falta matriz de versiones PHP y el
  chequeo `PHPCompatibilityWP` que ya está instalado pero no se aplica en `phpcs.xml`.
- **P3-5.** `phpcs.xml` degrada a *warning* precisamente las reglas de SQL preparado
  (`PreparedSQL.*`), que son las relevantes en este plugin.
- **P3-6.** Escalabilidad: `postmeta` con `meta_key = 'views'` y `meta_value` LONGTEXT
  hace que "los más vistos" requiera `CAST` y no aproveche índices. Valorar tabla propia
  o, como mínimo, documentar la consulta recomendada.
- **P3-7.** Sin cabecera `Update URI` en el header del plugin.
- **P3-8.** Sin ninguna superficie de lectura: ni shortcode, ni bloque, ni columna de
  vistas en el listado de entradas, ni API de lectura. El dato se guarda pero el usuario
  final no tiene forma de verlo sin código.
- **P3-9.** Falta `index.php` "silence is golden" en `assets/` y `assets/js/`.
- **P3-10.** Migración PSR-4 pendiente, ya planificada en `docs/MIGRATION-PSR4.md`.

---

## 3. Plan de implementación por fases

Cada fase termina en un estado consistente, con lint en verde y una versión publicable.

### Fase 0 — Desbloquear la release (P0-1) — ✅ Implementada

**Objetivo:** que el zip generado arranque. Sin esto, ninguna mejora llega al usuario.

**Decisión tomada:** Opción B — eliminar la dependencia de Composer en runtime con un
autoloader `spl_autoload_register()` propio de ~15 líneas en el archivo principal
(`bbk_autoload()`), dejando Composer únicamente para herramientas de desarrollo (PHPCS/WPCS).
Con solo 4 clases planas bajo un único namespace, no se justifica empaquetar `vendor/`
(dependencias de dev ≈ 18 MB) ni siquiera un `vendor/autoload.php` de producción (~72 KB):
la alternativa evita por completo esa clase de bug de packaging.

1. ~~Opción A (mantener Composer, incluir `vendor/` con `--no-dev` en el zip)~~ — descartada:
   añade un paso de build obligatorio y sigue arrastrando una carpeta que no aporta nada
   en tiempo de ejecución.
2. `uninstall.php` y el archivo principal usan `require`/rutas basadas en `__DIR__`, sin
   pasar por Composer.
3. `.distignore` mantiene `/vendor/` excluido del paquete (ahora es, de nuevo, solo una
   dependencia de desarrollo).
4. **Verificación:** generar el zip con `bash scripts/build.sh`, instalarlo en un
   WordPress limpio y activarlo.

**Riesgo:** bajo. **Entregable:** `1.1.0`.

---

### Fase 1 — Seguridad del endpoint (P0-2, P0-3, P0-4, P2-2)

**Objetivo:** que el endpoint deje de ser un vector de escritura arbitraria, sin romper
la compatibilidad con page caching (el endpoint debe seguir siendo anónimo).

1. **Validación estricta del `post_id`** en `args`: `type => integer`,
   `sanitize_callback => absint`, y un `validate_callback` que compruebe
   `get_post_status( $id ) === 'publish'`, `get_post_type( $id ) === 'post'` y
   `is_post_publicly_viewable( $id )`. Rechazar con `WP_Error` 404 en otro caso.
2. **Sustituir el nonce roto.** Dos caminos, no excluyentes:
   - Eliminar `BBK_PLUGIN_NONCE` (que además es incompatible con caché) y no simular una
     protección que no existe.
   - Si se quiere conservar un token, usar el nonce estándar `wp_rest` enviado en la
     cabecera `X-WP-Nonce`, asumiendo que sólo es fiable para usuarios logueados.
3. **Rate limiting / deduplicación**, que es la protección que realmente aplica a un
   endpoint anónimo: transient con clave derivada de `hash( post_id + IP + user agent )` y
   TTL configurable (p. ej. 30 min). Si existe, se responde 200 sin incrementar. Con
   caché de objetos persistente esto no toca la base de datos.
4. **Respuesta REST correcta:** devolver `new WP_REST_Response( [ 'count' => $count ], 200 )`
   y `WP_Error` en los fallos; eliminar `wp_send_json_*` y los `die()`.
5. Corregir el `permission_callback` para que documente explícitamente la decisión
   (`__return_true` con comentario justificando el acceso anónimo + rate limit), no por
   omisión.

**Verificación:** pruebas manuales con `curl` — ID inexistente, borrador, adjunto,
repetición inmediata, ID negativo, `post_id` no numérico.

**Riesgo:** medio (cambia el contrato del endpoint). **Entregable:** `1.1.0`.

---

### Fase 2 — Corrección y rendimiento del conteo (P1-1, P2-4, P2-9)

1. **Incremento atómico.** Reemplazar el ciclo leer/incrementar/escribir por un
   `$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1
   WHERE post_id = %d AND meta_key = 'views'", $post_id ) )`, con `add_post_meta( ...,
   1, true )` como camino de creación cuando el `UPDATE` afecta a 0 filas, y
   `wp_cache_delete( $post_id, 'post_meta' )` a continuación.
2. Devolver el valor real actualizado (hoy `set_post_views()` devuelve el conteo **previo**
   al incremento, o `''` en la primera vista — la respuesta al cliente es incorrecta).
3. Sustituir el SQL de `remove_all_post_meta()` por `delete_post_meta_by_key( 'views' )`.
4. Comparaciones estrictas, borrado de `init()` vacío, del `return` inalcanzable y de la
   propiedad `$_namespace` duplicada.

**Verificación:** script que lance N peticiones concurrentes y compruebe que el contador
final es exactamente N.

**Riesgo:** bajo-medio. **Entregable:** `1.1.1`.

---

### Fase 3 — Assets y frontend (P1-2, P1-3, P1-8, P2-1, P2-3, P2-7, P2-8)

1. Cambiar el hook a `wp_enqueue_scripts`.
2. Corregir la llamada a `wp_enqueue_script()`: versión = constante de versión del plugin,
   y `array( 'in_footer' => true, 'strategy' => 'defer' )` como quinto argumento (WP 6.3+,
   con *fallback* a `true` si se mantiene compatibilidad con 5.2 — o subir el mínimo).
3. Eliminar la dependencia de `jquery`.
4. Sustituir `$post` global por `get_queried_object_id()`.
5. No encolar si `is_user_logged_in() && current_user_can( 'edit_posts' )` (configurable),
   ni cuando la navegación es un prefetch (`Sec-Purpose: prefetch`).
6. En `common.js`: pasar la referencia (`init` sin paréntesis) o usar `DOMContentLoaded`;
   usar `navigator.sendBeacon()` con *fallback* a `fetch( …, { keepalive: true } )`;
   abortar si la pestaña nunca ha estado visible (`document.visibilityState`), lo que
   descarta buena parte del tráfico de bots y de pestañas en segundo plano.
7. Sustituir `wp_localize_script` por `wp_add_inline_script` con `JSON.stringify` — no es
   una traducción, y evita el objeto global innecesario.

**Verificación:** comprobar en el HTML que el `<script>` sale en el footer con `defer`,
que jQuery ya no se carga por causa del plugin, y medir que el LCP no se ve afectado.

**Riesgo:** bajo. **Entregable:** `1.2.0`.

---

### Fase 4 — Bootstrap, constantes e i18n (P1-4, P1-5, P1-6, P1-7, P2-5, P2-6)

1. Mover todas las constantes `BBK_*` al archivo principal, con guardas `defined()`.
2. Derivar `BBK_PLUGIN_VERSION` del header con `get_file_data()` para tener una única
   fuente de verdad, y alinear `composer.json`.
3. Corregir `load_plugin_textdomain()`: ruta relativa a `WP_PLUGIN_DIR`
   (`dirname( plugin_basename( __FILE__ ) ) . '/languages'`) y movida al hook `init`.
   Crear la carpeta `languages/` con el `.pot` generado (`wp i18n make-pot`).
4. Mover los `register_*_hook` dentro de la guarda `class_exists`, o mejor, registrarlos
   con una función global `bbk_run()` según la convención de `AGENTS.md`.
5. `uninstall.php`: soporte multisitio recorriendo `get_sites()` con
   `switch_to_blog()`/`restore_current_blog()`, con límite razonable de sitios y aviso en
   redes grandes.
6. Sustituir los `die( 'Hello, Pepiño!' )` por `exit` sin mensaje.

**Riesgo:** bajo. **Entregable:** `1.2.1`.

---

### Fase 5 — Calidad, CI y tests (P3-1, P3-3, P3-4, P3-5)

1. Endurecer `phpcs.xml`: devolver `WordPress.DB.PreparedSQL.*` a nivel de error y añadir
   `<rule ref="PHPCompatibilityWP"/>` con `<config name="testVersion" value="7.4-"/>`.
2. Ampliar `validate.yml` a matriz de PHP (7.4, 8.1, 8.2, 8.3) y añadir un job de
   `composer validate` + comprobación de que el zip generado contiene el autoloader
   (regresión de la Fase 0).
3. Alinear `composer.json` (`"php": ">=7.4"`) con el header del plugin.
4. Introducir tests: `wp-env` + PHPUnit con casos para el incremento atómico, la
   validación de `post_id`, el rate limit y la desinstalación.

**Riesgo:** nulo sobre el runtime. **Entregable:** sin cambio de versión.

---

### Fase 6 — Arquitectura y escalabilidad (P3-6, P3-10)

1. Ejecutar la migración PSR-4 ya descrita en `docs/MIGRATION-PSR4.md` (`src/{Api,Core,Frontend}/`,
   clases sin prefijo `PCV_`), una clase por paso, empezando por `Db`.
2. Evaluar el cambio de almacenamiento. Criterio de decisión: si el objetivo son sitios
   con más de ~50 000 entradas o consultas frecuentes de "más vistos", `postmeta` deja de
   ser adecuado (`meta_value` es LONGTEXT y obliga a `CAST` para ordenar).
   - *Opción conservadora:* mantener `postmeta` y documentar la consulta recomendada.
   - *Opción escalable:* tabla propia `{prefix}bbk_post_views` con `post_id` (PK) y
     `views BIGINT UNSIGNED`, más índice por `views`, y sincronización opcional del
     `postmeta` para retrocompatibilidad. Requiere rutina de migración en `activate()` con
     `dbDelta()` y versionado de esquema.
3. Buffer de escritura opcional para sitios de alto tráfico: acumular incrementos en caché
   de objetos y volcarlos por lotes vía `wp_cron`.

**Riesgo:** alto (cambia el modelo de datos). Requiere versión mayor y nota de migración.
**Entregable:** `2.0.0`.

---

### Fase 7 — Superficie de usuario y documentación (P3-2, P3-7, P3-8, P3-9)

1. Columna "Vistas" ordenable en el listado de entradas del admin.
2. Shortcode `[bbk_post_views]` y bloque de Gutenberg equivalente.
3. Endpoint REST `GET` de lectura, cacheable, con `permission_callback` explícito.
4. Actualizar `readme.txt`: `Tested up to`, changelog, y **resolver la contradicción de
   licencia** entre header (EUPL v1.2), readme (GPLv3) y `composer.json` (GPL-3.0+) —
   elegir una y propagarla a `license.txt`.
5. Añadir `Update URI` al header y los `index.php` de silencio que faltan.

**Riesgo:** bajo (todo aditivo). **Entregable:** `2.1.0`.

---

## 4. Orden recomendado y dependencias

```
Fase 0  ──►  Fase 1  ──►  Fase 2  ──►  Fase 4  ──►  Fase 5  ──►  Fase 6  ──►  Fase 7
                │                                        ▲
                └──────────►  Fase 3  ───────────────────┘
```

- **Fase 0 es bloqueante de todo lo demás**: sin ella nada de lo que se corrija llega al
  usuario final.
- Fases 1 y 2 tocan el mismo archivo (`PCV_restapi` / `PCV_db`); conviene hacerlas
  seguidas o en la misma rama.
- Fase 3 es independiente y puede paralelizarse.
- Fase 6 debe hacerse **después** de la Fase 5, para tener tests que respalden el
  renombrado de clases y el cambio de esquema.

## 5. Criterios de aceptación globales

- `composer run-script lint` en verde con las reglas endurecidas de la Fase 5.
- El zip de `scripts/build.sh` se activa sin errores en un WordPress limpio.
- 1 000 peticiones concurrentes al endpoint producen exactamente el conteo esperado y no
  crean filas de `postmeta` para IDs inválidos.
- El script del plugin no aparece en el `<head>` ni arrastra jQuery.
- La desinstalación no deja ni una fila de `meta_key = 'views'` ni transients del rate
  limit, incluida una instalación multisitio.
