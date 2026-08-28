# Plan: entorno de pruebas sincronizado con solo los archivos distribuidos

Plan para una sesión futura. No implementado todavía — este documento describe el diseño para que un agente o desarrollador lo ejecute sin tener que redescubrir el contexto.

**Diseñado para ser genérico**: el resultado final no debe vivir solo en este plugin, sino en el skill `wp-build` del monorepo `bubuku-plugins-wp`, igual que `scripts/build.sh`, para que se propague a todos los plugins Bubuku (los existentes vía `setup-skills.sh` / copia manual del asset, y los nuevos automáticamente vía el scaffold `create-plugin-basic`).

## Problema

El entorno local de pruebas de un plugin en desarrollo (p.ej. `https://test.wp.local/`) suele enlazar `wp-content/plugins/{plugin-slug}` directamente al checkout de desarrollo (symlink al repo). Eso significa que herramientas como **Plugin Check** escanean también `Tests/`, `docs/`, `skills/`, `scripts/`, `composer.json`, etc. — archivos que `.distignore` ya excluye del zip real que se sube a WordPress.org, generando falsos positivos (caso real: `Tests/run.php` en `bubuku-post-view-count`, detectado el 2026-08-28, sin fix de código necesario — ver `docs/ARCHITECTURE.md` y `AGENTS.md` de ese plugin).

Este problema es idéntico en **todos** los plugins Bubuku que siguen la convención `.distignore` + `scripts/build.sh` (prácticamente todos los del monorepo), así que la solución no debe implementarse una vez por plugin, sino una única vez como asset reutilizable.

## Objetivo

Poder probar en un WordPress local contra **exactamente** los archivos que `.distignore` deja pasar — los mismos que se suben a WordPress.org — sin pasos manuales de zip/unzip. El script debe ser **verbatim y autodetectable**, sin placeholders, siguiendo exactamente el mismo patrón que `skills/bubuku/wp-build/assets/build.sh` (ver ese archivo como referencia de estilo antes de implementar este).

### Por qué merece la pena más allá de Plugin Check

El motivo original era el ruido de Plugin Check, pero hay una razón más fuerte: **el symlink oculta una clase de bug que la copia limpia detecta al instante** — que el código en runtime dependa de un archivo excluido de la distribución.

Ejemplo concreto y vigente en este plugin: `/vendor/` está en `.distignore` y el plugin arranca gracias al autoloader manual `bbk_autoload()` del archivo principal. Si alguien añadiera una dependencia Composer y un `require vendor/autoload.php`, con el symlink **todo funcionaría en local** y el zip de producción daría fatal. Con la copia limpia, falla en local de inmediato.

### El coste: obsolescencia silenciosa

La contrapartida es seria y hay que tenerla presente al elegir modelo: **si el watcher no está corriendo, se prueba código viejo sin saberlo**. Editas, recargas, no cambia nada — o peor, das por válida una prueba sobre código que ya no existe. El symlink no tiene ese fallo posible.

## Enfoque elegido: `rsync --exclude-from=.distignore` + watcher

De las 3 opciones evaluadas (zip de `scripts/build.sh`, `git archive` vía `.gitattributes`, `rsync` con watcher), esta es la única que no requiere un paso manual de rebuild en cada edición.

### Modelo de uso — decidir antes de implementar

Dos formas de aplicarlo, con implicaciones muy distintas:

**A. Copia limpia efímera (recomendado).** Se mantiene el symlink de desarrollo como única carpeta permanente. El script crea la copia limpia en el sitio de validación bajo demanda (antes de un release o al querer pasar Plugin Check), se valida, y se borra al terminar (`--teardown` o similar). Día a día existe una sola carpeta del plugin; el riesgo de obsolescencia desaparece porque la copia vive minutos, no semanas. El desarrollo diario conserva el edit→recarga instantáneo.

**B. Copia limpia permanente, sin symlink.** El sitio local de pruebas contiene únicamente la carpeta rsync con archivos de producción; se elimina el symlink al repo. Es lo más fiel a producción de forma continua, y evita tener dos entradas en `wp-content/plugins/`. Pero **exige que el watcher esté siempre vivo**: arrancarlo a mano no es suficiente, hay que dejarlo como agente `launchd` que se inicie con la sesión del usuario, o la obsolescencia silenciosa aparece tarde o temprano.

Recomendación: **A**. Cambiar una configuración que siempre es correcta (symlink) por otra que solo lo es mientras un proceso en segundo plano siga vivo es un mal intercambio para el trabajo del día a día, y el beneficio real (probar contra la distribución exacta) se obtiene igual haciéndolo antes de cada release. Si se prefiere **B** por querer una única carpeta siempre real, implementar primero el agente `launchd` — sin él, B es peor que el estado actual.

### 1. Dónde vive el asset (monorepo, no el plugin)

```
bubuku-plugins-wp/skills/bubuku/wp-build/assets/sync-clean-env.sh   ← nuevo template, copiado verbatim
bubuku-plugins-wp/skills/bubuku/wp-build/SKILL.md                    ← documentar el nuevo script (sección "Script de sincronización — sync-clean-env.sh", análoga a la de build.sh)
bubuku-plugins-wp/create-plugin-basic/scripts/sync-clean-env.sh      ← scaffold, para que los plugins nuevos lo tengan de serie
```

Cada plugin existente lo incorpora copiando el asset a su propio `scripts/sync-clean-env.sh` (mismo mecanismo que ya usan para `build.sh` / `setup-skills.sh`), no por symlink — así cada plugin puede tener una copia congelada y el monorepo mantiene la versión canónica.

### 2. Autodetección (igual que `build.sh`)

El script **no** debe llevar el slug del plugin ni rutas hardcodeadas:

- Detecta `PLUGIN_DIR` igual que `build.sh` (resolviendo su propia ruta con `dirname "${BASH_SOURCE[0]}"` + `cd ..`).
- Detecta `PLUGIN_SLUG` con `basename "${PLUGIN_DIR}"`.
- Lee `.distignore` desde la raíz del plugin — mismo parseo (ignora líneas vacías y comentarios `#`) que ya usa `build.sh`; si `build.sh` no tiene esa lógica extraída a función reutilizable, valorar extraerla a un helper compartido (`scripts/lib/distignore.sh`) para no duplicar el parseo entre los dos scripts.
- El **destino** del symlink limpio no se autodetecta (no hay forma fiable de adivinar dónde está el WordPress local) — se pasa como argumento o variable de entorno, ver punto 3.

### 3. Uso

```bash
# Sync único (equivalente a build.sh pero sin generar zip, directo a una carpeta):
bash scripts/sync-clean-env.sh /ruta/al/sitio/wp-content/plugins/{plugin-slug}-clean

# Modo watch (mantiene sincronizado mientras se edita):
bash scripts/sync-clean-env.sh /ruta/al/sitio/wp-content/plugins/{plugin-slug}-clean --watch
```

Internamente:

- Sync base: `rsync -a --delete --exclude-from="$PLUGIN_DIR/.distignore" "$PLUGIN_DIR/" "$DEST/"` (`--delete` es necesario para reflejar archivos borrados en el repo).

  **Compatibilidad `.distignore` ↔ `rsync` ya verificada** (2026-08-28, sobre este plugin): `rsync --exclude-from=.distignore` produce exactamente el mismo conjunto de archivos que el zip de `build.sh` — `assets/`, `src/`, `index.php`, `license.txt`, `readme.txt`, `uninstall.php` y el PHP principal, nada más. `rsync` soporta de serie comentarios `#` y líneas vacías, y las rutas ancladas con `/` inicial se resuelven contra la raíz de la transferencia, igual que en `.distignore`. **No hace falta preprocesar el archivo** — se puede pasar tal cual, a diferencia de `build.sh`, que sí tiene que reescribir cada patrón porque `zip` necesita el prefijo `{slug}/` (ver bucle en `scripts/build.sh`).
- Modo `--watch`:
  - macOS: `fswatch -o "$PLUGIN_DIR" | while read -r; do rsync ...; done` (verificar que `fswatch` esté instalado — `brew install fswatch`; si no está, avisar e indicar instalación, no asumirla).
  - Fallback sin dependencias nuevas: `while true; do rsync ...; sleep 2; done` (polling, más tosco pero cero dependencias).
- Guarda de seguridad: si `$DEST` está vacío, apunta a `/`, o coincide con `$PLUGIN_DIR`, abortar con error antes de ejecutar `rsync --delete` (evitar un borrado catastrófico por variable mal puesta). Añadir también una guarda de que `$DEST` **no sea un symlink** — si alguien apunta el script al symlink de desarrollo, `rsync --delete` escribiría sobre el propio repo.
- Verificar que `.distignore` excluye `/.git` antes de sincronizar. En este plugin sí lo hace, pero si un plugin lo omitiera, `rsync` copiaría todo el historial de git dentro de `wp-content/plugins/`. Merece un aviso explícito del script si `/.git` no aparece en `.distignore`.
- **Symlinks**: `rsync -a` preserva symlinks tal cual. Los plugins Bubuku tienen `skills/`, `.claude/skills`, `.codex/skills` y `.gemini/skills` como symlinks al monorepo — todos excluidos por `.distignore`, así que en la práctica no llegan al destino. Si algún plugin tuviera un symlink **no excluido** que deba viajar como archivo real, usar `-aL` (resolver symlinks) en vez de `-a`. Documentar la diferencia, no cambiar el default.
- Al terminar el primer sync, si el symlink `$DEST` no existe todavía como entrada en `wp-content/plugins/`, el script solo avisa con el comando `ln -s` sugerido — no crea symlinks fuera del propio repo sin que el usuario lo pida explícitamente.

### 4. Nombre de carpeta y dónde colocarla — ⚠️ no usar sufijo

**La carpeta destino debe llamarse exactamente igual que el slug del plugin** (`bubuku-post-view-count`, no `bubuku-post-view-count-clean`). Un sufijo rompe dos cosas, ambas verificadas en el código de Plugin Check instalado en `~/Studio/test/wp-content/plugins/plugin-check/`:

1. **Falso positivo nuevo de Text Domain.** `Plugin_Context.php:95` calcula el slug como `basename( dirname( $main_file ) )` — el nombre de la carpeta. `Plugin_Header_Fields_Check.php:460` compara ese slug contra la cabecera `Text Domain` y emite error si no coinciden: *"The 'Text Domain' header in the plugin file does not match the slug. Found 'bubuku-post-view-count', expected 'bubuku-post-view-count-clean'."* Es decir, el sufijo introduce exactamente el tipo de ruido que este plan quiere eliminar.
2. **Fatal si se activan ambas copias a la vez.** El archivo principal hace `define( 'BBK_PLUGIN_FILE', ... )` y declara `function bbk_autoload()` sin guardas `defined()` / `function_exists()`, y ambas copias registran las mismas clases del namespace. Dos copias activas simultáneamente = *Cannot redeclare bbk_autoload()*. Este patrón viene del scaffold, así que afecta a todos los plugins Bubuku, no solo a este.

**Solución: la copia limpia va en un WordPress distinto, con el slug intacto.**

```
~/Studio/test/wp-content/plugins/bubuku-post-view-count    ← symlink al repo (desarrollo diario)
~/Studio/claude/wp-content/plugins/bubuku-post-view-count  ← destino del rsync (validación Plugin Check)
```

Ya existen varios sitios locales en `~/Studio/` (`test`, `claude`, `bk26`, …) — designar uno como "sitio de validación" y usarlo para todos los plugins. Así nunca conviven dos copias del mismo plugin en la misma instalación, el slug es correcto y Plugin Check ve exactamente lo que verá WordPress.org.

Alternativa si se prefiere un solo sitio: hacer el sync **sobre la misma ruta**, sustituyendo el symlink de desarrollo por la carpeta real antes de validar y restaurándolo después. Más frágil y con riesgo de borrar el symlink por error — preferir el sitio separado.

### 5. Uso con Plugin Check

Con la copia limpia en el sitio de validación, Plugin Check se ejecuta contra ella en vez de contra el checkout de desarrollo — mismo resultado que probar el zip de `dist/`, pero sin el paso manual de build/unzip y con cambios reflejados en segundos gracias al watcher.

Qué desaparece al validar en limpio (comprobado con el caso de `bubuku-post-view-count`): todos los hallazgos de `Tests/run.php` (`WordPress.Security.EscapeOutput.*`, `WordPress.PHP.DevelopmentFunctions.error_log_var_export`, `WordPress.WP.AlternativeFunctions.file_system_operations_fwrite` y `PluginCheck.CodeAnalysis.Localhost.Found` por `test.wp.local`), ya que `.distignore` excluye `/Tests/`.

Qué **no** desaparece, porque es código real distribuido: cualquier hallazgo en `src/`, el PHP principal, `uninstall.php` o `readme.txt`. Esos sí hay que arreglarlos.

## Detección del symlink al crear o migrar un plugin

Los flujos `new-plugin` / `migrate-plugin` (skills del monorepo) son el momento natural para dejar esto configurado, en vez de que cada plugin lo resuelva a mano después.

Comportamiento sugerido al scaffoldear o migrar:

1. Buscar si ya existe una entrada para el plugin en los sitios locales conocidos (`~/Studio/*/wp-content/plugins/{slug}`, y valorar `~/Local Sites/*/app/public/wp-content/plugins/{slug}`).
2. Informar de lo encontrado, distinguiendo los tres casos: symlink al repo, carpeta real, o nada.
3. Ofrecer configurar el flujo de validación limpia (modelo A por defecto, ver arriba) — **preguntando**, nunca creando ni borrando symlinks en los sitios locales por iniciativa propia. Tocar `wp-content/plugins/` de una instalación es una acción fuera del repo y con potencial de romper el entorno de trabajo del usuario.
4. Si el usuario acepta, dejar anotado en el `AGENTS.md` del plugin (sección «Entorno local de pruebas») qué sitio es el de desarrollo y cuál el de validación, para que futuras sesiones no lo tengan que redescubrir.

Comprobación útil que el script puede hacer sin tocar nada: avisar si la entrada del sitio local es un symlink al repo **y** el usuario está a punto de ejecutar Plugin Check — es justo el caso que genera los falsos positivos que originaron este plan.

## Entregable: guía rápida en `README.md`

Parte de la implementación es dejar el flujo escrito donde se consulte sin pensar. Va en `README.md` (documentación para desarrollo), **no** en `readme.txt` (ese es el listing de WordPress.org, de cara a usuarios finales). `.distignore` ya excluye `README.md` del zip, así que es el sitio correcto.

Este plugin no tiene `README.md` todavía — se crea al implementar. Texto listo para pegar, ajustando rutas y slug:

````markdown
## Flujo de trabajo con el entorno local

**Día a día** — sin cambios: el sitio de desarrollo enlaza el repo por symlink, se edita y se recarga.

```
~/Studio/test/wp-content/plugins/bubuku-post-view-count → symlink a este repo
```

**Antes de un release, o para pasar Plugin Check** — validar contra los archivos exactos que se distribuyen:

```bash
# 1. Crear la copia limpia en el sitio de validación
bash scripts/sync-clean-env.sh ~/Studio/claude/wp-content/plugins/bubuku-post-view-count

# 2. Activar el plugin en ese sitio y pasar Plugin Check ahí

# 3. Arreglar los hallazgos EN EL REPO (nunca en la copia — el siguiente sync la sobrescribe)

# 4. Re-sincronizar y re-validar hasta que salga limpio

# 5. Borrar la copia y generar el zip
bash scripts/sync-clean-env.sh ~/Studio/claude/wp-content/plugins/bubuku-post-view-count --teardown
bash scripts/build.sh
```

La carpeta de validación se llama **igual que el plugin**, no `-clean`: Plugin Check deduce el slug del nombre de la carpeta y un sufijo provoca un error falso de Text Domain. Lo que la separa del symlink de desarrollo es estar en otro WordPress local — nunca las dos en la misma instalación, y nunca ambas activas a la vez (fatal por `bbk_autoload()` redeclarada).
````

Al propagar el script al monorepo, incluir esta misma sección en el `README.md` del scaffold `create-plugin-basic`, para que los plugins nuevos nazcan con la guía.

## Propagación a plugins existentes

Una vez validado en un plugin (candidato natural: `bubuku-post-view-count`, por ser el que originó la necesidad):

1. Mover el script definitivo a `skills/bubuku/wp-build/assets/sync-clean-env.sh` y documentarlo en `SKILL.md`.
2. Añadirlo también a `create-plugin-basic/scripts/` para que el scaffold lo incluya de serie.
3. Actualizar `scripts/setup-skills.sh` (o el flujo que corresponda) si hace falta un paso explícito de "actualizar assets de un skill ya enlazado", para que otros plugins puedan tirar del script sin rehacer el trabajo manualmente.
4. Documentar el comando de uso en la sección "Entorno local de pruebas" del `AGENTS.md` de cada plugin que lo adopte (no es automático — cada plugin decide si lo quiere).

## Hallazgo aparte, no relacionado con el sync

Al revisar el código de Plugin Check apareció un error real que este plan **no** resuelve, porque está en `readme.txt` (archivo sí distribuido): `Plugin_Readme_Check.php:490-506` compara `Stable tag` contra la versión del PHP principal y emite `stable_tag_mismatch` si difieren. En este plugin son `1.4.1` vs `1.1.0`, así que Plugin Check lo marcará incluso validando en limpio.

Pendiente de decisión del usuario (ver «Versionado del plugin» en `AGENTS.md`: el agente no toca versiones por iniciativa propia). La corrección sería `Stable tag: 1.1.0`.

## Preguntas abiertas para la sesión de implementación

- **¿Modelo A (copia efímera, recomendado) o B (copia permanente sin symlink)?** Es la decisión que condiciona todo lo demás — ver «Modelo de uso». Si es B, implementar antes el agente `launchd` del watcher.
- ¿Qué sitio de `~/Studio/` se designa como sitio de validación (`claude`, uno nuevo…)? Ver punto 4 — la copia limpia no puede convivir con el symlink de desarrollo en la misma instalación.
- ¿Instalar `fswatch` (macOS) es aceptable como dependencia opcional, o se prefiere que el script solo ofrezca el fallback de polling por defecto?
- ¿Se extrae el parseo de `.distignore` a un helper compartido entre `build.sh` y `sync-clean-env.sh`? Nota: tras verificar la compatibilidad de `rsync` con `--exclude-from`, **el nuevo script no necesita parsear nada** — pasa el archivo tal cual. La duplicación que se temía no existe, así que probablemente la respuesta sea «no hace falta helper compartido».
- ¿Merece la pena que el script añada guardas `defined()` / `function_exists()` al scaffold para que dos copias del mismo plugin no provoquen un fatal? Es un endurecimiento independiente de este plan, pero el punto 4 lo destapó.

## Referencias

- `.distignore` — lista de exclusiones, fuente de verdad de "qué se distribuye" (misma convención en todos los plugins Bubuku).
- `skills/bubuku/wp-build/assets/build.sh` (monorepo) — patrón de referencia: autodetección de slug/versión, parseo de `.distignore`, estilo de mensajes.
- `skills/bubuku/wp-build/SKILL.md` (monorepo) — dónde documentar el nuevo script.
- `create-plugin-basic/scripts/` (monorepo) — scaffold que debe incluir el script de serie para plugins nuevos.
- `docs/ARCHITECTURE.md` de este plugin — contexto del caso concreto que originó este plan (falsos positivos de Plugin Check en `Tests/`).
