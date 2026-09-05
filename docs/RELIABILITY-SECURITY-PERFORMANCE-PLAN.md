# Plan de fiabilidad, seguridad y rendimiento

> Auditoría técnica y hoja de ruta para Bubuku Post View Count.
> Código analizado: versión `1.2.2` más cambios de `[Unreleased]`, a 2026-09-05.
> Documento vivo de implementación. No modifica la versión pública del plugin.

## Estado de implementación

Actualizado durante la sesión de 2026-09-05:

| Fase | Estado | Completado / pendiente |
|---|---|---|
| 0 | 🟡 En curso | Contrato de 5 segundos y versión de medida implementados. Entorno real accesible; baseline repetible y `EXPLAIN` sobre MySQL/MariaDB siguen pendientes porque Studio usa SQLite. |
| 1 | ✅ Implementada | Escrituras transaccionales comprobadas, errores 503 estables, espejo posterior al commit y reset con pausa de ingesta, limpieza e invalidación de cache. |
| 2 | 🟡 En curso | Tabla dedupe v4, HMAC sin IP/UA en claro, claim atómico portable, purga y rate limits filtrables. Dedupe secuencial validado en Studio; quedan concurrencia y proxies contra MySQL/MariaDB real. |
| 3 | ✅ Implementada | Buffer volátil retirado del runtime, ajustes, cron, tests y distribución; escritura directa fiable conservada. |
| 4 | 🟡 En curso | 5 segundos visibles acumulados, estado explícito, lifecycle/BFCache, fallback/reintento, cobertura y versión de medida implementados; falta historial formal de taxonomía e ID de diagnóstico. |
| 5 | 🟡 En curso | Límites REST/retención, cache versionada y purga por lotes implementados; optimizaciones de consultas requieren baseline/`EXPLAIN`. |
| 6 | 🟡 En curso | Cursor `meta_id`, estado/watchdog de migración y verificación de tablas implementados; ampliación WP-CLI y Site Health pendientes. |
| 7 | 🟡 En curso | PHPCS, 74 tests PHP, 11 JS, build, esquema v4 y REST validados en Studio. Plugin Check del ZIP no informa de `Schema.php` ni consultas directas; quedan hallazgos de distribución anteriores y la prueba de carga/concurrencia. |

Último artefacto verificado: `dist/bubuku-post-view-count-1.2.2.zip` (168 KiB). No se ha
modificado la versión pública. `https://test.wp.local/` responde mediante WordPress Studio
(WordPress 7.1, SQLite); WP-CLI confirma el esquema v4 y las cinco tablas del plugin.

## 1. Objetivo y relación con los planes existentes

Este plan revisa el sistema ya construido, con tres prioridades:

1. que una vista aceptada no se pierda, duplique ni reaparezca después de un borrado;
2. que el endpoint público soporte abuso razonable sin convertir cada visita en presión
   innecesaria sobre la base de datos;
3. que los informes comuniquen qué miden, con qué cobertura y qué grado de confianza.

Complementa `docs/ANALYTICS-PLAN.md` y `docs/IMPROVEMENT-PLAN.md`. En particular, la
recomendación sobre `Core\WriteBuffer` de este documento **reemplaza el criterio de F7**:
el buffer actual puede servir para experimentar con rendimiento, pero no debe considerarse
un modo fiable de persistencia hasta que sea rediseñado.

## 2. Alcance y evidencia disponible

Se han revisado el bootstrap, las clases de `src/`, las rutas REST, el contador público,
el esquema, las consultas analíticas, la migración, el borrado de datos, los consumidores
REST/MCP/WP-CLI y los tests existentes.

Comprobaciones ejecutadas durante la auditoría:

| Comprobación | Resultado |
|---|---|
| `composer run-script lint` | Correcto, 15 archivos comprobados |
| `php Tests/run.php` | Correcto antes (75); implementación actual: 74 tests reforzados |
| `npm run test:js` | Correcto antes (7); implementación actual: 11 tests |
| `https://test.wp.local/` | Disponible; HTTP 200 y REST ejercitado en WordPress Studio |
| Esquema real | Versión 4; presentes las cinco tablas propias |
| Dedupe REST real | Primera petición aceptada; repetición idéntica rechazada sin incrementar |
| Plugin Check del ZIP | Sin hallazgos en `Schema.php`, consultas directas, guardas de acceso, nombre o licencia. Pendientes únicamente avisos de prefijo `bbk` que requieren política/supresión documentada. |

La integración funcional ya tiene evidencia real, pero todavía no existe una línea base
repetible de latencia, consultas por petición, concurrencia, tamaño de tablas ni planes
`EXPLAIN` sobre MySQL/MariaDB. Ninguna fase debe afirmar una mejora porcentual hasta medirla
en el mismo entorno y con el mismo conjunto de datos antes y después.

## 3. Fortalezas que deben conservarse

- El contador se ejecuta después de la carga y el script se sirve con `defer`, sin jQuery.
- El endpoint valida que el contenido exista, sea públicamente visible y pertenezca a un
  tipo habilitado.
- Los incrementos individuales de cada tabla usan `INSERT ... ON DUPLICATE KEY UPDATE`,
  evitando el antiguo ciclo leer-incrementar-escribir.
- Las tablas agregadas evitan guardar un evento completo por visita.
- Las dimensiones tienen cardinalidad cerrada; no se persiste el referrer completo ni el
  ancho exacto de pantalla.
- Las rutas administrativas comprueban capacidades y el cliente envía `X-WP-Nonce`.
- Las consultas SQL variables están preparadas y los nombres de tabla proceden del plugin.
- Los bots de IA están separados de las vistas humanas y DNT/GPC puede omitir dimensiones.
- El buffer es opt-in y está desactivado por defecto.

Estas propiedades son invariantes: una fase futura no debe debilitarlas para ganar
rendimiento.

## 4. Definición acordada: qué es una “vista”

El comportamiento auditado originalmente no medía una carga de página: medía una visita
que seguía visible cuando vencía un temporizador de ocho segundos. Si la pestaña estaba oculta justo en ese
instante, no se registra aunque después vuelva a estar visible. Si el usuario abandona antes,
tampoco se registra. Bloqueadores, CSP, REST deshabilitado o fallos de red producen más
subconteo.

Se acuerda medir una **vista interesada**: la página singular debe acumular **cinco
segundos de visibilidad real**. El tiempo deja de avanzar mientras la pestaña está oculta
y continúa si el visitante regresa. Este umbral conserva la intención de excluir aperturas
accidentales, pero reduce el subconteo de contenidos breves respecto a los ocho segundos
actuales.

El nombre mostrado al usuario debería ser “vista interesada” o explicar el umbral de cinco
segundos; llamarlo simplemente “personas” o “pageviews” sobrestima la precisión.

También se debe fijar que:

- el día y `last_viewed_at` corresponden al momento de aceptación en UTC, no al inicio de
  la navegación;
- una vista es única por contenido y visitante aproximado durante 30 minutos;
- los totales anteriores y posteriores a cualquier cambio de semántica no son plenamente
  comparables sin exponer una fecha/versión de medición.

## 5. Inventario priorizado de hallazgos

### P0 — Integridad de datos

#### R-01. El buffer puede perder incrementos y depende de un cron no garantizado

**Evidencia:** `src/Core/WriteBuffer.php:101-153` vacía primero la option índice, después
lee cada contador del object cache y lo borra antes de confirmar la escritura en BD.
Además, lectura y borrado no son atómicos. WP-Cron solo se dispara cuando llega tráfico.

**Impacto:** una carrera puede perder más de una vista; un error de BD después del borrado,
un desalojo/reinicio de Redis/Memcached o un cron que no se ejecute puede perder todo lo
pendiente. Una actualización concurrente de la option índice también puede pisar claves.
`first_viewed_at` y `last_viewed_at` representan la hora del flush, no la visita.

**Decisión inmediata:** mantener `write_buffer` desactivado. En la UI debe figurar como
experimental/no exacto o retirarse hasta completar la Fase 3. No promocionarlo como mejora
segura de rendimiento.

#### R-02. Una vista se escribe en varias representaciones sin unidad de éxito

**Evidencia:** `src/Core/Db.php:78-136` actualiza agregado, diario y hasta tres dimensiones;
después hace un `SELECT` y dos actualizaciones de post meta. No comprueba el retorno de
`$wpdb->query()`, `$wpdb->last_error` ni el resultado del espejo.

**Impacto:** un fallo parcial puede dejar `total != SUM(daily)`, dimensiones incompletas,
meta desfasada o una respuesta 200 con un conteo incorrecto. El transient de deduplicación
se crea antes de persistir (`src/Api/RestApi.php:150`), de modo que un reintento legítimo
puede bloquearse tras un fallo.

#### R-03. “Eliminar todos los datos” no aísla la ingestión ni limpia el estado pendiente

**Evidencia:** `src/Api/SettingsApi.php:137-145` elimina tablas/meta y las recrea, pero no
bloquea nuevas vistas, no elimina el índice/counters del buffer, no invalida caches de
consultas y no reinicia `bbk_postview_daily_since`.

**Impacto:** una vista concurrente puede fallar o quedar parcialmente escrita; un flush
posterior puede resucitar vistas borradas; los informes pueden seguir mostrando cache
antigua y una fecha de disponibilidad histórica falsa.

### P1 — Alto

#### R-04. La deduplicación genera amplificación de escritura y no es atómica

Sin object cache persistente, cada `set_transient()` crea normalmente valor y timeout en
`wp_options`. Es decir, la protección contra duplicados puede añadir hasta dos escrituras
de alta cardinalidad por visitante/post antes de contar la vista. `get_transient()` seguido
de `set_transient()` permite que dos peticiones concurrentes pasen ambas.

El identificador `md5(post_id|REMOTE_ADDR|User-Agent)` también introduce sesgos:

- muchos visitantes detrás de una misma IP y con el mismo UA pueden colisionar y
  subcontarse;
- IPs rotatorias o UAs variables facilitan duplicados;
- detrás de un proxy, `REMOTE_ADDR` puede ser la IP común del proxy;
- no se deben confiar `X-Forwarded-For`/`CF-Connecting-IP` sin una lista explícita de
  proxies de confianza.

La deduplicación y el rate limit son problemas diferentes y deben implementarse por
separado.

#### R-05. El origen same-site reduce abuso de navegador, pero no autentica al emisor

`Origin` y `Referer` pueden ser falsificados por clientes no navegador. La validación
actual es una capa útil contra envíos cross-site casuales, no una prueba de que hubo una
visita. Un atacante puede rotar IP/UA y forzar escrituras válidas o carga de BD.

Además, comparaciones estrictas de esquema/host/puerto pueden producir falsos negativos en
instalaciones con proxy, dominio canónico diferente o terminación TLS mal configurada.
Debe conservarse el fallo cerrado, añadiendo diagnóstico y configuración explícita, no una
aceptación indiscriminada de cabeceras reenviadas.

#### R-06. La recogida del cliente pierde visitas válidas de forma silenciosa

`assets/js/common.js:108-143` usa un único temporizador. Si a los ocho segundos la pestaña
no está visible, no vuelve a intentarlo. `navigator.sendBeacon()` devuelve un booleano que
no se comprueba, por lo que un rechazo de cola no cae al fallback `fetch`. Tampoco existe un
estado explícito que impida futuros dobles envíos si se añaden listeners de ciclo de vida.

#### R-07. La migración puede quedar incompleta sin dejar un estado recuperable

La migración avanza con `LIMIT/OFFSET`, actualiza la versión de esquema inmediatamente y
solo programa el siguiente lote desde el lote actual. Si se pierde un evento, se produce un
error o cambia el conjunto de metas mientras pagina, no hay cursor durable, estado de
“completa/fallida”, watchdog ni reconciliación. `dbDelta()` tampoco se verifica antes de
guardar `Schema::VERSION`.

#### R-08. “Referrer de IA” y “crawler de IA” no son datos verificados

- `utm_source` y `ref` proceden de la URL y pueden ser manipulados.
- `document.referrer` puede faltar por políticas del navegador, por lo que “direct” mezcla
  tráfico directo con procedencia no observable.
- las dimensiones viajan en un endpoint público: la lista blanca limita cardinalidad, pero
  no demuestra que el cliente diga la verdad;
- el User-Agent de un crawler se puede suplantar. La tabla mide “peticiones que declaran ese
  UA”, no bots verificados.

Los informes deben exponer esta procedencia como clasificación aproximada y mostrar
cobertura. Verificar bots de verdad requiere integración con logs/edge o verificación de
red específica, cacheada y opcional; no debe añadirse una consulta DNS a cada petición.

#### R-09. Falta cobertura explícita de las dimensiones

DNT/GPC, clientes antiguos, bloqueadores, payloads inválidos y fallos parciales hacen que
`SUM(dims.views)` sea menor que las vistas humanas. Sin denominador visible, un 60 % móvil
puede parecer representativo aunque solo haya dimensiones para una fracción pequeña de las
visitas. Cada desglose debe devolver `views_with_dimension`, `accepted_views` y
`coverage_pct`, además del periodo realmente disponible.

#### R-10. Los parámetros analíticos necesitan límites y fechas estrictas

Las rutas de `TrendsApi` declaran tipos, pero no validan formato/orden de fechas, longitud
de rango, cantidad de `post_ids`, máximo de `period_days` o cardinalidad de arrays. Solo
usuarios con `edit_posts` pueden acceder, lo cual reduce el riesgo, pero una cuenta de autor
o integración comprometida podría construir consultas y claves de cache excesivas.
`retention_days` tiene mínimo 1 y ningún máximo razonable.

#### R-11. Algunas consultas escalan con todas las filas antes de limitar en PHP

`Query::ai_traffic()` agrupa todos los pares bot/post y asistente/post del periodo, los
transfiere a PHP y aplica el límite por grupo después. `most_viewed()`, `stale()`,
`momentum()` y los desgloses obtienen IDs y luego llaman repetidamente a `get_the_title()` y
`get_permalink()` sin cebar la cache de posts, creando un posible N+1. La pantalla de
estadísticas lanza cinco peticiones REST y `ai_traffic()` vuelve a calcular el desglose de
referrer si no existe cache persistente.

### P2 — Medio

#### R-12. Cache de informes sin invalidación ni metadatos de frescura

Varias consultas tienen TTL fijo de cinco minutos y ninguna invalidación al escribir,
borrar, cambiar ajustes o publicar/borrar contenido. Otras consultas similares no se
cachean. El resultado es una mezcla de datos frescos y obsoletos que el consumidor no puede
distinguir. Cada respuesta debe incluir `generated_at`, `data_through` y, si aplica,
`is_partial`/`data_available_since`.

#### R-13. El espejo de post meta domina parte del coste por vista

Después de los upserts se hace una lectura del agregado, dos `update_post_meta()` y una
invalidación de cache. Es un contrato de compatibilidad real, pero mantenerlo síncrono en
cada visita multiplica escrituras. Ya existe el filtro `bbk_postview_mirror_meta`, aunque no
hay ajuste ni estrategia de coalescencia. No debe eliminarse por defecto sin una decisión de
compatibilidad y una migración documentada.

#### R-14. Purga y limpieza no están preparadas para tablas grandes

La purga diaria ejecuta un `DELETE` sin lotes sobre las tres tablas históricas. Puede
bloquear o generar picos de I/O. No hay limpieza al borrar permanentemente un post, por lo
que quedan filas huérfanas, aunque los `JOIN` con `wp_posts` las oculten en informes.

#### R-15. Los índices deben validarse contra consultas reales

Los índices actuales son razonables como punto de partida, pero los patrones
`dimension + rango de día + GROUP BY`, `bot + rango + post` y los rankings por ventana no
coinciden necesariamente con el orden de columnas disponible. No se propone cambiar índices
sin `EXPLAIN` sobre tamaños representativos: añadir índices “por intuición” penaliza cada
escritura y puede no mejorar las lecturas.

#### R-16. Las afirmaciones de privacidad del readme son demasiado absolutas

`readme.txt` afirma que el token de deduplicación “nunca se escribe en ninguna tabla”. Sin
object cache persistente, WordPress guarda el transient hash y su timeout en `wp_options`.
Además, un hash temporal derivado de IP+UA es un identificador seudónimo aunque no revele
directamente los valores originales. El texto debe describir implementación, retención y
limitaciones con precisión, evitando afirmar que “nada identifica” al visitante.

## 6. Hoja de ruta por fases

Cada fase debe vivir en una rama/PR independiente, conservar compatibilidad salvo que se
indique lo contrario y terminar con tests, medición antes/después y actualización de la
documentación afectada. La versión pública solo se cambia por decisión explícita del usuario.

### Fase 0 — Contrato de medida y línea base

**Objetivo:** saber qué se optimiza y poder detectar pérdida de datos.

- Documentar la definición acordada de cinco segundos visibles acumulados en
  UI/readme/API.
- Añadir una versión de semántica de medición y su fecha efectiva a los metadatos de los
  informes; no mezclar silenciosamente series incompatibles.
- Preparar un dataset local reproducible pequeño/medio/grande.
- Medir, con cache fría y caliente: consultas SQL por vista, tiempo del endpoint, tamaño de
  `wp_options`, tablas propias y postmeta, y latencia de cada informe.
- Ejecutar varias muestras y guardar medianas y percentil 95; documentar WordPress/PHP/DB,
  object cache y estado de WP-Cron.
- Obtener `EXPLAIN` de cada consulta de `Core\Query` y de la purga.
- Añadir una comprobación WP-CLI de coherencia:
  `aggregate total`, `SUM(daily retenido)`, cobertura de dimensiones, filas huérfanas,
  migración y buffer pendiente. El diario retenido no tiene por qué igualar el total
  histórico; la herramienta debe comparar solo periodos solapados.

**Criterio de salida:** existe un informe de baseline repetible. Sin baseline no se activa
ningún buffer ni se añade un índice.

### Fase 1 — Cortar pérdidas y resurrecciones

**Objetivo:** hacer seguro el camino síncrono actual antes de optimizarlo.

- Mantener el buffer apagado; ocultarlo o marcarlo experimental con una advertencia clara.
- Hacer que `Db::record_view()` devuelva éxito o `WP_Error` y compruebe todas las escrituras.
- Diseñar una unidad de persistencia para agregado + diario + dimensiones. Preferencia:
  transacción corta si todas las tablas son transaccionales y el entorno lo confirma;
  alternativa: registro durable de reparación y reconciliación idempotente.
- Actualizar el espejo meta solo después del commit. Un fallo del espejo no debe falsear el
  éxito de la fuente de verdad, pero debe quedar observable y ser reparable.
- Marcar la deduplicación **después** de persistir con éxito. En esta fase se acepta la
  pequeña ventana de duplicado, que se cerrará atómicamente en la Fase 2.
- Ante fallo, devolver 5xx/503 con un código estable y no responder 200 con datos antiguos.
- Convertir el borrado en operación aislada: lock corto de ingestión, limpiar buffer e
  índice, vaciar caches, borrar/recrear/verificar tablas y reiniciar
  `OPTION_DAILY_SINCE`. Liberar el lock incluso ante error.
- Añadir tests de fallo inyectado en cada upsert y de reset concurrente/pending buffer.

**Criterio de salida:** no existe un caso probado donde el endpoint devuelva éxito tras una
escritura parcial; un reset no puede resucitar vistas.

### Fase 2 — Deduplicación atómica y control de abuso

**Objetivo:** quitar alta cardinalidad de `wp_options` y separar calidad de seguridad.

- Diseñar una tabla pequeña de deduplicación con hash opaco, expiración indexada y clave
  única, o una operación equivalente que ofrezca `add-if-absent` atómico y fallback durable.
- Usar HMAC con sal de WordPress y rotación/ventana temporal; no persistir IP ni UA en claro.
- Ejecutar adquisición de dedupe y escritura de la vista como una operación recuperable:
  si la vista falla, liberar/invalidar la marca para permitir reintento.
- Purgar expirados por lotes y comprobar que no hay autoload ni crecimiento permanente.
- Implementar rate limits separados por post/sitio y origen de red, con respuesta 429 y
  `Retry-After`; los límites deben ser configurables/filtrables y medidos para no castigar
  NATs o proxies grandes.
- Conservar `Origin`/`Referer` como capa del navegador, documentando que no autentica. Añadir
  diagnóstico de mismatch y soporte de proxies solo mediante allowlist de confianza.
- Limitar tamaño de cuerpo y rechazar tipos de contenido inesperados.
- Añadir tests de 50-100 solicitudes concurrentes con el mismo identificador, rotación de
  UA/IP, NAT simulado, cabeceras falsificadas y expiración.

**Criterio de salida:** N solicitudes simultáneas equivalentes cuentan exactamente una;
visitantes únicos aceptados cuentan N; no aparecen transients `bbk_view_*` en `wp_options`.

### Fase 3 — Persistencia diferida fiable o retirada del buffer

**Objetivo:** decidir con datos si hace falta buffering y, si hace falta, no perder vistas.

Solo continuar si la Fase 0 demuestra que el camino síncrono no cumple el objetivo de carga.

- Comparar tres alternativas: escritura directa optimizada, cola durable en tabla propia y
  primitivas atómicas específicas del backend de object cache.
- No usar el object cache como única copia de una vista aceptada. Si se conserva como
  acelerador, la copia durable debe existir antes de responder éxito.
- Sustituir el índice serializado en una option por filas reclamables con estado
  `pending/processing`, lease y reintentos idempotentes.
- Reclamar lotes atómicamente, confirmar BD antes de borrar, recuperar leases caducados y
  aplicar backoff a fallos.
- Guardar primer/último timestamp reales del lote, no la hora de flush.
- Añadir runner alternativo WP-CLI/cron del sistema para sitios con WP-Cron deshabilitado.
- Exponer salud: pendientes, edad del más antiguo, último flush, fallos y reintentos.
- Probar caída/reinicio del cache, worker muerto a mitad, dos workers simultáneos, cambio de
  día UTC, reset durante flush y desactivación/desinstalación.

**Criterio de salida:** apagar Redis/Memcached o matar el worker no pierde vistas aceptadas;
reintentar un lote no duplica contadores. Si no se consigue sin complejidad desproporcionada,
retirar el buffer y mantener la escritura directa.

### Fase 4 — Cliente y cobertura de recogida

**Objetivo:** aplicar exactamente la semántica decidida y hacer visibles los huecos.

- Acumular cinco segundos de tiempo visible mediante
  `visibilitychange`, `pageshow` y un reloj monotónico; no depender de un único timeout.
- Mantener un estado `not-eligible/pending/sent` para garantizar un solo envío por carga.
- Si `sendBeacon()` devuelve `false`, usar `fetch(..., keepalive: true)`; no duplicar si
  devuelve `true`, porque “en cola” no equivale a respuesta confirmada.
- Definir comportamiento para prerender, BFCache, navegación rápida, offline y reintento.
- Añadir cabecera/versión de cliente y devolver un ID de aceptación no identificable para
  diagnóstico, sin crear un log por visita.
- Calcular y mostrar cobertura de dimensiones y periodo disponible. Un porcentaje sin
  cobertura no se publica.
- Etiquetar `direct`, referrer e IA como clasificaciones aproximadas. Registrar cambios de
  taxonomía para que una serie no cambie de significado sin aviso.
- Ampliar tests JS con tiempo visible acumulado, ocultación al segundo 8, retorno a primer
  plano, `sendBeacon=false`, pagehide, BFCache y doble disparo.

**Criterio de salida:** los tests de ciclo de vida producen exactamente cero o una vista
según la definición elegida; el dashboard muestra cobertura y versión de medición.

### Fase 5 — Optimización de escritura y lectura

**Objetivo:** reducir coste sin relajar integridad.

- Medir el coste del espejo meta. Proponer al usuario una de estas políticas: síncrono,
  coalescido por intervalo o desactivado para nuevas instalaciones. Mantener compatibilidad
  para instalaciones existentes y documentar la frescura de `views`/`views_last`.
- Evitar el `SELECT` posterior al upsert cuando la técnica elegida pueda devolver/derivar el
  total de forma segura; no sacrificar exactitud por ahorrar una consulta.
- Agrupar upserts de dimensiones cuando la BD/compatibilidad lo permita.
- Cebar la cache de posts en bloque antes de obtener títulos/permalinks.
- Reescribir las consultas por asistente/bot para limitar en SQL o trabajar por grupos de
  cardinalidad fija, evitando traer todos los pares a PHP.
- Evaluar un endpoint de snapshot para el dashboard que evite cinco bootstraps REST y
  trabajo duplicado. Mantener endpoints granulares para consumidores externos.
- Añadir límites REST: máximo de IDs, periodo, rango de fechas y retención; validar
  `Y-m-d`, `from <= to` y datos disponibles.
- Versionar las keys de cache e invalidarlas en escritura relevante, reset, cambio de
  ajustes y cambios de estado/título de posts. Incluir metadatos de frescura.
- Purga por lotes con continuación idempotente y limpieza de huérfanos en eventos seguros.
- Cambiar índices solo según `EXPLAIN` y medición de coste de escritura.

**Criterio de salida:** mejora demostrada frente al baseline, mismos resultados de
coherencia y límites constantes de memoria/filas para las consultas administrativas.

### Fase 6 — Migraciones, esquema y operación

**Objetivo:** que upgrades y mantenimiento sean observables y recuperables.

- Separar `schema_version` de `migration_status`; no marcar completado solo por programar
  un cron.
- Sustituir OFFSET por cursor estable (`meta_id` o clave equivalente), guardar progreso y
  última actividad, y añadir watchdog/reanudación manual.
- Verificar existencia/columnas/índices después de `dbDelta()` antes de actualizar versión.
- Reconciliar postmeta y tabla con una política explícita de autoridad, sin decrementar por
  accidente datos nuevos.
- Hacer purga, migración, reset, activación/desactivación y uninstall correctos por sitio en
  multisitio, incluidos eventos y pendientes.
- Añadir comandos WP-CLI `status`, `verify`, `repair --dry-run`, `repair`, `flush` y
  `migrate --resume`, con confirmación para acciones destructivas.
- Añadir Site Health con fallos de escritura recientes, migración detenida, cron atrasado,
  incoherencias y tamaño de tablas, sin exponer identificadores de visitantes.

**Criterio de salida:** interrumpir y reanudar cada operación produce el mismo resultado que
una ejecución continua; el estado explica qué falta y cómo repararlo.

### Fase 7 — Validación adversarial y release gradual

**Objetivo:** demostrar el sistema completo en condiciones reales.

- Tests de integración contra MySQL/MariaDB real; el harness actual valida lógica, pero no
  locks, aislamiento, errores ni planes de ejecución.
- Matriz con y sin object cache, WP-Cron normal/deshabilitado, proxy inverso, multisitio,
  PHP soportado y WordPress mínimo/actual.
- Prueba de carga escalonada sobre staging, nunca producción sin autorización. Medir
  accepted/deduped/rate-limited/errors, p50/p95/p99, consultas y crecimiento de tablas.
- Pruebas de fallo: BD no disponible, tabla ausente, deadlock, cache reiniciada, worker
  duplicado, red cliente interrumpida y reset concurrente.
- Desplegar cambios de ingestión detrás de flags, con rollback que no requiera borrar datos.
- Generar zip con `scripts/build.sh`, ejecutar Plugin Check sobre el zip, activar en un
  WordPress limpio y repetir PHPCS/tests/mediciones.
- Actualizar `readme.txt`, `docs/ARCHITECTURE.md`, `docs/CHANGELOG.md` y este documento con
  resultados reales. Corregir las afirmaciones absolutas de privacidad indicadas en R-16.

**Criterio de salida:** no hay pérdida/duplicado en las pruebas de concurrencia y fallo,
los objetivos de latencia definidos en la Fase 0 se cumplen y el rollback está probado.

## 7. Dependencias y orden recomendado

```text
Fase 0 (definición + baseline)
  └─ Fase 1 (integridad síncrona)
       └─ Fase 2 (dedupe + abuso)
            ├─ Fase 3 (buffer durable, solo si las métricas lo exigen)
            └─ Fase 4 (cliente + cobertura)
                  └─ Fase 5 (optimización medida)
                        └─ Fase 6 (operación y migraciones)
                              └─ Fase 7 (validación y release)
```

Las fases 3 y 4 pueden prepararse en paralelo después de estabilizar la persistencia, pero
no deben mezclarse en una misma release: si cambia a la vez qué se cuenta y cómo se guarda,
será difícil atribuir una desviación.

## 8. Criterios globales de aceptación

- Una respuesta 2xx significa que la vista está persistida de forma durable o que una
  marca de dedupe ya persistida demuestra que fue aceptada antes.
- Cualquier fallo parcial es detectable y reparable; nunca se oculta con un 200.
- Total y diario se reconcilian para el periodo común; las dimensiones nunca exceden el
  total aceptado de su periodo.
- Reset, uninstall y cambio de día no permiten resurrección de datos.
- N solicitudes únicas aceptadas producen N incrementos; N solicitudes concurrentes
  equivalentes producen uno dentro de la ventana de dedupe.
- Ningún identificador por visitante se guarda en claro, se incluye en logs o queda sin
  expiración; la documentación explica el hash temporal sin afirmaciones absolutas.
- El endpoint no crea transients por visitante en `wp_options`.
- Todos los parámetros tienen límites y esquemas; los informes declaran periodo, cobertura,
  frescura y versión de medición.
- Los objetivos de rendimiento proceden del baseline, no de cifras arbitrarias.
- PHPCS, tests PHP/JS, pruebas de integración, build, Plugin Check del zip y validación en
  `test.wp.local` están en verde antes de cerrar una fase.

## 9. Preguntas que requieren decisión del propietario

1. ¿Qué nivel de compatibilidad debe conservar el post meta `views`: tiempo real, retraso
   máximo documentado o posibilidad de desactivarlo por defecto en instalaciones nuevas?
2. ¿Los informes de IA deben limitarse a datos declarados/aproximados, o existe acceso a
   logs de servidor/CDN para validar bots y enriquecer procedencia?
3. ¿Cuál es el tamaño objetivo: visitas diarias, contenidos con tráfico y retención? Sin
   esos órdenes de magnitud no se puede decidir si el buffer o nuevos índices compensan.

Estas preguntas no bloquean las Fases 0-1: medir, reparar el flujo de error y asegurar el
reset son mejoras necesarias bajo cualquier respuesta.
