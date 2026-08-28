# Plan: entorno de pruebas sincronizado con solo los archivos distribuidos

Plan para una sesión futura. No implementado todavía — este documento describe el diseño para que un agente o desarrollador lo ejecute sin tener que redescubrir el contexto.

**Diseñado para ser genérico**: el resultado final no debe vivir solo en este plugin, sino en el skill `wp-build` del monorepo `bubuku-plugins-wp`, igual que `scripts/build.sh`, para que se propague a todos los plugins Bubuku (los existentes vía `setup-skills.sh` / copia manual del asset, y los nuevos automáticamente vía el scaffold `create-plugin-basic`).

## Problema

El entorno local de pruebas de un plugin en desarrollo (p.ej. `https://test.wp.local/`) suele enlazar `wp-content/plugins/{plugin-slug}` directamente al checkout de desarrollo (symlink al repo). Eso significa que herramientas como **Plugin Check** escanean también `Tests/`, `docs/`, `skills/`, `scripts/`, `composer.json`, etc. — archivos que `.distignore` ya excluye del zip real que se sube a WordPress.org, generando falsos positivos (caso real: `Tests/run.php` en `bubuku-post-view-count`, detectado el 2026-08-28, sin fix de código necesario — ver `docs/ARCHITECTURE.md` y `AGENTS.md` de ese plugin).

Este problema es idéntico en **todos** los plugins Bubuku que siguen la convención `.distignore` + `scripts/build.sh` (prácticamente todos los del monorepo), así que la solución no debe implementarse una vez por plugin, sino una única vez como asset reutilizable.

## Objetivo

Un segundo symlink en `wp-content/plugins/` que apunte a una carpeta "limpia" que contenga **solo** lo que `.distignore` deja pasar, y que se mantenga sincronizada automáticamente mientras se edita código en el repo — sin build manual ni pasos de zip/unzip. El script debe ser **verbatim y autodetectable**, sin placeholders, siguiendo exactamente el mismo patrón que `skills/bubuku/wp-build/assets/build.sh` (ver ese archivo como referencia de estilo antes de implementar este).

## Enfoque elegido: `rsync --exclude-from=.distignore` + watcher

De las 3 opciones evaluadas (zip de `scripts/build.sh`, `git archive` vía `.gitattributes`, `rsync` con watcher), esta es la única que no requiere un paso manual de rebuild en cada edición.

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
- Modo `--watch`:
  - macOS: `fswatch -o "$PLUGIN_DIR" | while read -r; do rsync ...; done` (verificar que `fswatch` esté instalado — `brew install fswatch`; si no está, avisar e indicar instalación, no asumirla).
  - Fallback sin dependencias nuevas: `while true; do rsync ...; sleep 2; done` (polling, más tosco pero cero dependencias).
- Guarda de seguridad: si `$DEST` está vacío, apunta a `/`, o coincide con `$PLUGIN_DIR`, abortar con error antes de ejecutar `rsync --delete` (evitar un borrado catastrófico por variable mal puesta).
- Al terminar el primer sync, si el symlink `$DEST` no existe todavía como entrada en `wp-content/plugins/`, el script solo avisa con el comando `ln -s` sugerido — no crea symlinks fuera del propio repo sin que el usuario lo pida explícitamente.

### 4. Nombre de carpeta / symlink en WordPress

Sufijo `-clean` para no chocar con el symlink de desarrollo existente (p.ej. `bubuku-post-view-count-clean`). Ambos plugins conviven en `wp-content/plugins/`; se activa uno u otro según qué se quiera probar (con o sin `Tests/`, `docs/`, etc.).

### 5. Uso con Plugin Check

Con el symlink limpio activo, Plugin Check se ejecuta contra `{plugin-slug}-clean` en vez de contra el plugin de desarrollo — mismo resultado que probar el zip de `dist/`, pero sin el paso manual de build/unzip y con cambios reflejados en segundos gracias al watcher.

## Propagación a plugins existentes

Una vez validado en un plugin (candidato natural: `bubuku-post-view-count`, por ser el que originó la necesidad):

1. Mover el script definitivo a `skills/bubuku/wp-build/assets/sync-clean-env.sh` y documentarlo en `SKILL.md`.
2. Añadirlo también a `create-plugin-basic/scripts/` para que el scaffold lo incluya de serie.
3. Actualizar `scripts/setup-skills.sh` (o el flujo que corresponda) si hace falta un paso explícito de "actualizar assets de un skill ya enlazado", para que otros plugins puedan tirar del script sin rehacer el trabajo manualmente.
4. Documentar el comando de uso en la sección "Entorno local de pruebas" del `AGENTS.md` de cada plugin que lo adopte (no es automático — cada plugin decide si lo quiere).

## Preguntas abiertas para la sesión de implementación

- ¿Instalar `fswatch` (macOS) es aceptable como dependencia opcional, o se prefiere que el script solo ofrezca el fallback de polling por defecto?
- ¿El plugin activo en WordPress para desarrollo diario debe seguir siendo el de desarrollo (con `Tests/`, `docs/`, etc.) y el `-clean` se activa puntualmente antes de un release, o al revés?
- ¿Se extrae el parseo de `.distignore` a un helper compartido entre `build.sh` y `sync-clean-env.sh`, o se duplica por simplicidad (ambos son scripts cortos y autocontenidos por diseño)?

## Referencias

- `.distignore` — lista de exclusiones, fuente de verdad de "qué se distribuye" (misma convención en todos los plugins Bubuku).
- `skills/bubuku/wp-build/assets/build.sh` (monorepo) — patrón de referencia: autodetección de slug/versión, parseo de `.distignore`, estilo de mensajes.
- `skills/bubuku/wp-build/SKILL.md` (monorepo) — dónde documentar el nuevo script.
- `create-plugin-basic/scripts/` (monorepo) — scaffold que debe incluir el script de serie para plugins nuevos.
- `docs/ARCHITECTURE.md` de este plugin — contexto del caso concreto que originó este plan (falsos positivos de Plugin Check en `Tests/`).
