# Migrar datos antiguos a nuevos de forma automática al actualizar el plugin

> Guía genérica, extraída de la implementación real de este plugin
> (`src/Core/Schema.php`, Fase 1 de `docs/ANALYTICS-PLAN.md`). Pensada para
> reutilizarse en cualquier otro plugin Bubuku que necesite cambiar su modelo de
> almacenamiento (post meta → tabla propia, opción plana → esquema nuevo, etc.)
> sin perder datos y sin que el usuario tenga que hacer nada.

## El problema de fondo

Cuando un plugin cambia cómo guarda sus datos, alguien tiene que copiar lo
antiguo al formato nuevo. La tentación es hacerlo en `register_activation_hook()`
— pero eso **no cubre la actualización real de un plugin ya instalado**:

- Actualizar desde el listado de plugins de wp-admin (un clic) **no reactiva**
  el plugin. Solo se ejecuta el código nuevo en la siguiente carga.
- Actualizar subiendo un zip por FTP tampoco dispara `activate_{plugin}`.
- El hook de activación **sí** se dispara en una instalación nueva y al
  reactivar manualmente — casos que ya funcionan bien, pero son la minoría.

Conclusión: la migración no puede depender del hook de activación. Tiene que
comprobarse en cada carga del plugin y disparar sola cuando detecta que el
código es más nuevo que los datos.

## El patrón: versión de esquema + comprobación en `plugins_loaded`

Tres piezas que trabajan juntas:

1. **Una constante con la versión de esquema actual** en el código (no la
   versión del plugin — son cosas distintas: puedes sacar tres versiones del
   plugin sin tocar el esquema).
2. **Una `option` que guarda qué versión de esquema tiene instalada ese sitio**
   ahora mismo.
3. **Una comprobación en `plugins_loaded`**, en cada petición, que compara las
   dos y dispara la instalación/migración si la opción está por detrás.

```php
class Schema {

	const VERSION = 1; // Súbela cuando cambie la estructura, no cuando cambie la versión del plugin.
	const OPTION_SCHEMA_VERSION = 'miplugin_schema_version';

	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ) );
	}

	public function maybe_upgrade() {
		if ( (int) get_option( self::OPTION_SCHEMA_VERSION, 0 ) < self::VERSION ) {
			$this->install_current_site();
		}
	}

	private function install_current_site() {
		$is_new_install = (int) get_option( self::OPTION_SCHEMA_VERSION, 0 ) < self::VERSION;

		$this->create_tables(); // dbDelta() — idempotente, seguro de llamar siempre.

		if ( $is_new_install ) {
			$this->schedule_migration(); // Programa la copia de datos, no la hagas aquí síncrona.
			update_option( self::OPTION_SCHEMA_VERSION, self::VERSION );
		}
	}
}
```

`register_activation_hook()` sigue existiendo (para la instalación nueva y el
alta en red al activar multisitio), pero llama a la **misma** clase: no
dupliques la lógica de creación de esquema entre `activate()` y
`maybe_upgrade()`.

**Por qué marcar la opción como actualizada *antes* de que termine la
migración de datos** (no después): así `maybe_upgrade()` no vuelve a intentar
`install_current_site()` completo en cada petición mientras la migración de
datos (que puede tardar) sigue en marcha en segundo plano. La comprobación de
versión es sobre el *esquema* (tablas creadas), no sobre si ya se ha terminado
de copiar el histórico.

## Migrar los datos de verdad: por lotes, vía cron, nunca en la petición que lo detecta

Si el sitio tiene 50 000 filas que copiar, no lo hagas dentro de la petición
HTTP que disparó `maybe_upgrade()` — se comería el timeout y probablemente
dejaría el trabajo a medias sin que nadie se entere.

Patrón: programa un evento de cron único que se reencola solo hasta terminar.

```php
const MIGRATION_CRON_HOOK  = 'miplugin_migrate_batch';
const MIGRATION_BATCH_SIZE = 500;

private function schedule_migration() {
	if ( ! wp_next_scheduled( self::MIGRATION_CRON_HOOK ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::MIGRATION_CRON_HOOK, array( 0 ) );
	}
}

public function migrate_batch( int $offset = 0 ) {
	global $wpdb;

	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT ... FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT %d OFFSET %d", 'mi_meta_antiguo', self::MIGRATION_BATCH_SIZE, $offset )
	);

	if ( empty( $rows ) ) {
		return; // No queda nada por migrar.
	}

	foreach ( $rows as $row ) {
		// INSERT ... ON DUPLICATE KEY UPDATE — ver siguiente sección.
	}

	if ( count( $rows ) === self::MIGRATION_BATCH_SIZE ) {
		// Puede que queden más filas — reencola el siguiente lote.
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::MIGRATION_CRON_HOOK, array( $offset + self::MIGRATION_BATCH_SIZE ) );
	}
}
```

Registra el callback del hook igual que cualquier otro cron, siempre (no solo
al detectar la migración pendiente):

```php
add_action( self::MIGRATION_CRON_HOOK, array( $this, 'migrate_batch' ) );
```

**Matización real, no teórica**: WP-Cron es *pseudo-cron* — se dispara con una
visita real después de la hora programada, no es un cron de sistema. En un
sitio con tráfico normal esto pasa en minutos. En un sitio casi sin visitas, o
con `DISABLE_WP_CRON` sin un cron real sustituto, el lote programado puede
tardar en ejecutarse hasta la siguiente visita. No es un fallo del diseño —
es una propiedad de WP-Cron que hay que conocer y, si el plugin lo requiere,
documentar.

## La migración tiene que ser idempotente

Va a poder ejecutarse más de una vez sobre las mismas filas — por un cron que
se dispara dos veces, un reintento manual, una migración a medias que se
retoma. La forma más simple y robusta: `INSERT ... ON DUPLICATE KEY UPDATE`
con una regla de fusión explícita, nunca un `INSERT` a secas ni un
`+ VALUES(...)` que sumaría en cada repetición:

```php
$wpdb->query(
	$wpdb->prepare(
		"INSERT INTO {$tabla} (id, total) VALUES (%d, %d)
		 ON DUPLICATE KEY UPDATE total = GREATEST(total, VALUES(total))",
		$id,
		$total
	)
);
```

`GREATEST(total, VALUES(total))` es la clave: si se ejecuta dos veces con el
mismo dato de origen, el resultado es el mismo. Si se hubiera usado
`total = total + VALUES(total)`, la segunda ejecución duplicaría el conteo.

Prueba esto explícitamente en el test de migración: ejecutar el lote dos
veces seguidas y comprobar que el resultado no cambia la segunda vez.

## No borres el dato antiguo durante la migración

El formato viejo (`post meta`, `option` plana, lo que sea) se queda como
**espejo** de compatibilidad — no solo por si algo lee ese dato directamente
desde fuera del plugin (temas, otros plugins, `orderby=meta_value_num`...),
sino porque es tu plan de rollback gratis: si algo va mal en la versión nueva,
volver a la versión anterior del plugin simplemente vuelve a leer el dato
viejo, que nunca se tocó.

```php
private function mirror_old_format( int $id, array $stats ) {
	if ( ! apply_filters( 'miplugin_mirror_old_format', true ) ) {
		return; // Vía de escape para quien no lo necesite ni quiera la escritura extra.
	}

	update_post_meta( $id, 'mi_meta_antiguo', $stats['total'] );
}
```

Documenta en el changelog de esa versión, explícitamente, que el rollback es
seguro y por qué (`docs/CHANGELOG.md` de este plugin, entrada `1.2.0`, es un
ejemplo real de cómo redactarlo).

## Multisitio: una migración por sitio, no por red

Cada sitio de una red tiene sus propias tablas (con el prefijo de ese
blog). La migración y la creación de esquema tienen que ejecutarse **por
sitio**, no una vez para toda la red:

```php
public function activate( bool $network_wide ) {
	if ( is_multisite() && $network_wide ) {
		foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
			switch_to_blog( (int) $site_id );
			$this->install_current_site();
			restore_current_blog();
		}
		return;
	}

	$this->install_current_site();
}
```

Y para los sitios que se creen **después** de que esta versión ya esté activa
en red, engancha `wp_initialize_site` (con guarda de
`is_plugin_active_for_network()`, cargando
`wp-admin/includes/plugin.php` si hace falta — esa función no siempre está
disponible fuera de wp-admin):

```php
add_action( 'wp_initialize_site', array( $this, 'install_on_new_site' ) );
```

`maybe_upgrade()` en `plugins_loaded` ya cubre el resto: cada sitio, al
recibir su primera petición tras la actualización de red, migra solo.

## Desactivación: limpia el cron, no los datos

En `deactivate()`, limpia siempre los hooks de cron (purga, migración) — un
evento cron huérfano de un plugin desactivado es un bug silencioso clásico.
No toques tablas ni datos: eso es cosa de `uninstall.php`, y solo si el
usuario ha decidido borrarlo todo (ver la nota de desinstalación más abajo).

```php
public function deactivate() {
	wp_clear_scheduled_hook( self::MIGRATION_CRON_HOOK );
	wp_clear_scheduled_hook( self::PURGE_CRON_HOOK ?? '' );
}
```

## Desinstalación: la decisión tiene que estar tomada de antemano

`uninstall.php` no puede preguntar nada — WordPress lo ejecuta sin interfaz,
en respuesta a un borrado ya confirmado. Si el plugin va a decidir si borra
datos propios (tablas, options) al desinstalar, esa decisión tiene que vivir
en una `option` leída con un valor por defecto sensato (ver
`uninstall.php` de este plugin y `docs/ANALYTICS-PLAN.md` §1.8 para el patrón
completo, incluyendo el aviso al desactivar).

## Cómo probar esto sin una base de datos real

Si el plugin tiene un harness de tests sin dependencias (como
`Tests/run.php` de este plugin, sin PHPUnit ni WordPress real), el reto es que
el doble de `$wpdb` no tiene motor SQL. La técnica que ha funcionado aquí
(`Tests/bootstrap.php`, clase `TestWpdb`):

1. `prepare()` hace una sustitución de `%d`/`%s` real (no un atajo), así que
   devuelve la sentencia SQL final tal cual la generaría WordPress.
2. `query()`/`get_row()`/`get_results()` reconocen esa sentencia por patrón
   (`preg_match` sobre el nombre de la tabla y la forma de la consulta) y
   simulan el estado en arrays estáticos de una clase `TestState`.
3. Cada consulta distinta del plugin necesita su propio patrón — no intentes
   escribir un motor SQL genérico, es más caro que mantener 4-5 regexes atados
   a las consultas reales que existen en el código.

Esto permite probar exactamente la lógica que importa (upserts atómicos,
idempotencia de la migración, qué campos se tocan y cuáles no) sin necesitar
una base de datos real ni WordPress cargado. Lo que **no** cubre — `dbDelta()`,
WP-Cron real, multisitio — se queda fuera del harness y se valida a mano en el
entorno local (`AGENTS.md` → "Entorno local de pruebas").

## Checklist para la próxima vez

- [ ] Constante de versión de esquema, separada de la versión del plugin.
- [ ] Option que guarda la versión de esquema instalada en cada sitio.
- [ ] `maybe_upgrade()` en `plugins_loaded`, no solo en el hook de activación.
- [ ] Creación de esquema (`dbDelta()` u otro) idempotente, segura de llamar
      en cada carga si hace falta.
- [ ] Migración de datos en lotes vía cron reencolable, nunca síncrona en la
      petición que la detecta.
- [ ] `INSERT ... ON DUPLICATE KEY UPDATE` con una regla de fusión explícita
      (`GREATEST`, no `+`) — la migración tiene que poder ejecutarse dos veces.
- [ ] El formato antiguo se conserva como espejo — rollback gratis.
- [ ] Multisitio: por sitio, no por red; alta en sitios nuevos vía
      `wp_initialize_site`.
- [ ] `deactivate()` limpia el cron; nunca borra datos.
- [ ] La decisión de borrar datos al desinstalar vive en una `option` con
      valor por defecto, nunca en una pregunta que `uninstall.php` no puede hacer.
- [ ] Tests: al menos idempotencia de la migración y las reglas de qué campo
      se actualiza y cuál no.
- [ ] Changelog explícito: qué cambia, que no se pierde nada, y que el
      rollback a la versión anterior es seguro (y por qué).

## Ver también

- `src/Core/Schema.php` de este plugin — implementación real y completa del
  patrón descrito aquí.
- `docs/ANALYTICS-PLAN.md` §1 — el diseño original, con las decisiones y sus
  motivos (por qué UTC, por qué dos tablas, por qué el agregado diario no es
  reconstruible, etc.).
- `docs/CHANGELOG.md`, entrada `1.2.0` — cómo se comunicó el cambio al usuario
  final en el changelog público (`readme.txt`).
