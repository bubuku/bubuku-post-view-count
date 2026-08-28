# AGENTS.md

Guía de orquestación para agentes de IA trabajando en este plugin WordPress.

## Convenciones del proyecto

| Concepto | Formato | Ejemplo |
|---|---|---|
| Plugin slug | kebab-case | `bubuku-post-view-count` |
| Prefijo constantes | SCREAMING_SNAKE_CASE | `BBK_VERSION` |
| Prefijo funciones globales | snake_case | `bbk_run()` |
| Namespace PHP | `Bubuku\Plugins\PostViewCount` | `Bubuku\Plugins\PostViewCount\PCV_plugin` |
| Versión (fuente de verdad) | Header PHP del plugin | `Version: 1.0.4` |
| Text domain | = plugin slug | `bubuku-post-view-count` |

## Skills disponibles

Los skills viven en el monorepo `/Users/bubuku/dev/bubuku-plugins-wp/skills/` y se enlazan en este plugin mediante symlinks individuales en `skills/`. Para añadir o quitar skills:

```bash
bash scripts/setup-skills.sh         # menú interactivo
bash scripts/setup-skills.sh --list  # ver enlazados
bash scripts/setup-skills.sh --add wp-admin
```

Skills enlazados actualmente en este repositorio:

| Skill | Cuándo cargarlo |
|---|---|
| `git-conventions` | Mensajes de commit y títulos de PR |
| `wordpress-router` | Clasificar la base de código WordPress y enrutar al workflow correcto |
| `wp-admin` | Páginas de ajustes, menús del admin, notices y assets del dashboard |
| `wp-build` | Build, versionado, packaging, release y CI/CD del plugin |
| `wp-coding` | Cualquier cambio en `src/` — WPCS, formato, IIFE, i18n |
| `wp-frontend` | Assets frontend/admin en `assets/src/`, React, SCSS y build de frontend |
| `wp-performance` | Optimización de rendimiento y diagnóstico de consultas/cron/HTTP |
| `wp-php` | Lógica PHP, hooks, opciones, consultas y clases del plugin |
| `wp-plugin-development` | Arquitectura general de plugin, hooks, seguridad y release packaging |
| `wp-rest-api` | Endpoints REST, permisos, schema y respuesta de API |
| `wp-scaffold` | Generar estructura inicial del plugin |
| `wp-security` | Formularios, AJAX, REST, sanitize/escape, nonces |

Estos enlaces viven en `skills/` y se mantienen con `bash scripts/setup-skills.sh`. Si se añade o elimina un skill del monorepo, repite el comando para sincronizar la documentación y los symlinks del proyecto.

Para más skills (admin, REST API, frontend React, CSV, build, etc.) ver `skills/_meta/catalog.json` del monorepo y enlazarlos bajo demanda.

## Flujo habitual

```
scaffold → wp-coding + wp-security (siempre activos)
        → wp-admin / wp-frontend / wp-php / wp-csv (según necesidad, /add-feature)
        → wp-build (al preparar release)
```

## Archivos clave

| Archivo | Para qué |
|---|---|
| `bubuku-post-view-count.php` | Punto de entrada — header, bootstrap, hooks de activación/desactivación |
| `src/PCV_plugin.php` | Clase principal — registra hooks |
| `src/PCV_db.php` | Acceso a datos (tabla de vistas) |
| `src/PCV_restapi.php` | Endpoints REST API |
| `src/PCV_assets.php` | Registro de assets (CSS/JS) |
| `vendor/autoload.php` | Autoloader PSR-4 (Composer) para `Bubuku\Plugins\PostViewCount\*` |
| `uninstall.php` | Limpieza al desinstalar (opciones, tablas, transients) |
| `.distignore` | Qué excluir del zip de producción |
| `scripts/build.sh` | Generar el zip de distribución (lee versión del header) |
| `docs/CHANGELOG.md` | Historial de cambios (actualizar en cada release) |

## Nota sobre estructura src/

Este plugin ya usa namespace PSR-4 (`Bubuku\Plugins\PostViewCount`) vía Composer, pero las clases mantienen el prefijo plano `PCV_*` y viven directamente en `src/` sin subcarpetas (`Admin/`, `Api/`, `Core/`, `Frontend/`). Ver `docs/MIGRATION-PSR4.md` para el plan de migración a la estructura objetivo — no aplicado automáticamente por tratarse de un cambio de riesgo medio (renombrado de clases usadas en hooks).

## Reglas globales

- Carga siempre `wp-coding` y `wp-security` antes de actuar.
- No edites `assets/build/` manualmente — solo `assets/src/`.
- Versión: la fuente de verdad es la cabecera `Version:` del PHP principal.
- Antes de commit: ejecuta `./vendor/bin/phpcs` si hay `composer.json`.
