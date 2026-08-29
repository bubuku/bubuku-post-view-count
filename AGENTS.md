# AGENTS.md — Bubuku Post View Count

> Instrucciones del proyecto para agentes de IA (Claude Code, Codex, Copilot, Gemini).

## Proyecto

Plugin público de WordPress (WordPress.org) que cuenta las visitas de un Post sin afectar Core Web Vitals: encola un script que llama a un endpoint REST tras la carga, y el contador se guarda en el post meta `views`.

| Concepto | Valor |
|---|---|
| **Versión actual** | `1.1.0` (Header PHP, fuente de verdad). `readme.txt` trae `Stable tag: 1.4.1` desincronizado — corregir a `1.1.0` solo si el usuario lo pide explícitamente |
| **Prefijo PHP** | `PCV_` (clases) / `bbk` (constantes, hooks, funciones globales) |
| **Namespace PHP** | `Bubuku\Plugins\PostViewCount\` (ya correcto — objetivo pendiente: quitar prefijo `PCV_`, ver `docs/MIGRATION-PSR4.md`) |
| **Estructura actual** | `src/PCV_*.php` plano, sin subcarpetas (`Core/`, `Api/`, `Frontend/`) |
| **Text domain** | `bubuku-post-view-count` |
| **Archivo principal** | `bubuku-post-view-count.php` |
| **REST API namespace** | `bbk_postview/v1` (endpoint público, sin autenticación — ver `wp-security`) |
| **Autoload** | Autoloader propio (`bbk_autoload`, `spl_autoload_register`) — sin `vendor/autoload.php` en producción |
| **Dato persistido** | Post meta `views` (entero). Sin tabla propia ni opciones |

Para la arquitectura completa (clases, flujo de una vista, constantes, estructura de directorios) ver `docs/ARCHITECTURE.md`.

## Entorno local de pruebas

- URL: `https://test.wp.local/`
- Usa este entorno para validar cambios del plugin antes de dar por terminada cualquier tarea o hacer commit.
- Tests automatizados y artefactos temporales viven en `Tests/` (sin PHPUnit, ver `docs/ARCHITECTURE.md`); se suben a GitHub pero se excluyen del zip de producción vía `.distignore`.

## Skills enlazados

Skills viven en `/Users/bubuku/dev/bubuku-plugins-wp/skills/` y se enlazan en este plugin mediante symlinks individuales en `skills/`.

```bash
bash scripts/setup-skills.sh         # menú interactivo
bash scripts/setup-skills.sh --list  # ver enlazados
bash scripts/setup-skills.sh --add <skill>
```

### Activos en este plugin

Snapshot actual. Si cambia el enlace de skills, refrescar con `bash scripts/setup-skills.sh --list`.

| Skill | Cuándo cargarlo |
|---|---|
| `git-conventions` | Mensajes de commit y títulos de PR |
| `wordpress-router` | Clasificar la base de código WordPress y enrutar al workflow correcto |
| `wp-abilities-api` | Registro y diseño de abilities/categorías/meta y exposición REST de capacidades |
| `wp-abilities-audit` | Auditoría de superficie REST y propuesta de registro de abilities |
| `wp-abilities-verify` | Verificación de capacidades registradas y coherencia entre anotaciones y callbacks |
| `wp-admin` | Páginas de ajustes, menús del admin, notices y assets del dashboard |
| `wp-build` | Build, versionado, packaging, release y CI/CD del plugin |
| `wp-coding` | Cualquier cambio en `src/` — WPCS, formato, IIFE, i18n |
| `wp-frontend` | Cambios en `assets/js/common.js` u otros assets frontend |
| `wp-mcp-conex` | Integración del plugin como satélite del hub `bubuku-mcp-conex` y registro de tools |
| `wp-performance` | Optimización de rendimiento y diagnóstico de consultas/cron/HTTP |
| `wp-php` | Lógica PHP, hooks, post meta, consultas y clases del plugin |
| `wp-plugin-development` | Arquitectura general de plugin, hooks, seguridad y release packaging |
| `wp-plugin-directory-guidelines` | Revisión de cumplimiento para WordPress.org (licencias, naming, políticas del directorio) |
| `wp-rest-api` | Endpoint REST `bbk_postview/v1`, permisos y validación |
| `wp-scaffold` | Generar estructura inicial de plugin (referencia, no aplica a este ya scaffolded) |
| `wp-security` | Endpoint REST público, deduplicación, sanitize/escape |
| `wp-tools-architect` | Arquitectura consistente para tools/abilities (base abstracta, autoload, settings admin) |

### Delegación rápida por tipo de tarea

| Tarea | Skills a cargar |
|---|---|
| Cambios en clases PHP (`src/`, `PCV_*`) | `wp-php` + `wp-coding` + `wp-plugin-development` |
| Endpoint REST (`PCV_restapi`) / permisos / deduplicación | `wp-php` + `wp-security` + `wp-rest-api` |
| Definir o registrar abilities (API de capacidades) | `wp-abilities-api` + `wp-rest-api` + `wp-security` |
| Auditar/verificar abilities ya implementadas | `wp-abilities-audit` o `wp-abilities-verify` |
| Script frontend (`assets/js/common.js`) | `wp-frontend` + `wp-security` |
| Rendimiento / consultas a `$wpdb` / transients | `wp-performance` + `wp-php` |
| Integrar tools MCP del plugin con el hub | `wp-mcp-conex` + `wp-tools-architect` + `wp-php` |
| Build, empaquetado, release | `wp-build` |
| Revisión de cumplimiento para WordPress.org | `wp-plugin-directory-guidelines` |
| Commits / PRs | `git-conventions` |

Para más skills (frontend React, CSV, etc.) ver `skills/_meta/catalog.json` del monorepo y enlazarlos bajo demanda — este plugin no los necesita hoy por su alcance reducido.

## Comandos

```bash
# PHP
composer install
./vendor/bin/phpcs / phpcbf
composer run-script lint / lint:fix / test

# Tests (sin dependencias externas, sin PHPUnit)
php Tests/run.php

# Build & release
bash scripts/build.sh                # genera dist/bubuku-post-view-count-{version}.zip
```

## Reglas globales

### Filosofía

- Itera sobre código existente antes de escribir desde cero — el plugin es intencionalmente pequeño (4 clases); no añadas capas ni abstracciones sin necesidad concreta.
- Nunca duplicar lógica — comprobar si ya existe en `PCV_db`, `PCV_restapi` o `PCV_assets`.
- No hay build step de JS/CSS: `assets/js/common.js` es JS plano, se edita directamente.

### Seguridad (resumen — detalle en `wp-security`)

- Guarda mínima: `defined('ABSPATH') || exit;` en cada PHP.
- `bbk_postview/v1` es un endpoint público sin nonce por diseño (debe funcionar detrás de full-page caching con visitantes deslogueados). La seguridad se apoya en: `permission_callback` de origen same-site (`check_request_origin`), validación estricta de `post_id` (`validate_post_id`) y deduplicación por transient (`DEDUPE_TTL`). No añadir autenticación que rompa este contrato sin discutirlo antes.
- Cualquier cambio en `PCV_restapi` debe mantener o reforzar estas tres capas, no debilitarlas.

### Definition of Done

- Aplicado el/los skills correctos para la tarea.
- `./vendor/bin/phpcs` (o `composer run-script lint`) sin errores.
- `php Tests/run.php` sin fallos; si se tocó `PCV_db` o `PCV_restapi`, añadido un test nuevo siguiendo el patrón de `Tests/run.php`.
- Versión sincronizada si se pidió bump: header PHP, `readme.txt` (`Stable tag`), `docs/CHANGELOG.md`.

### Versionado del plugin

- Solo el usuario decide cuándo subir la versión del plugin. El agente **nunca** sube la versión por iniciativa propia, aunque el cambio lo justifique (nueva feature, fix, etc.).
- Si un cambio parece justificar un bump de versión, el agente debe **sugerirlo** (y proponer el tipo: patch/minor/major) y esperar confirmación explícita del usuario antes de tocar header PHP, `readme.txt` o `docs/CHANGELOG.md`.
- Nota: el header PHP (`1.1.0`, fuente de verdad, confirmada por el usuario) y el `Stable tag` de `readme.txt` (`1.4.1`) están desincronizados — señalarlo al usuario si surge la ocasión, no corregirlo por iniciativa propia.

### Plugin Check (WordPress.org)

- Ejecutar siempre Plugin Check sobre el zip generado por `bash scripts/build.sh` (`dist/bubuku-post-view-count-{version}.zip`), nunca sobre la carpeta de desarrollo enlazada en `wp-content/plugins/`. El checkout de desarrollo contiene `Tests/`, `docs/`, `skills/` y `scripts/` — código y contenido que `.distignore` ya excluye del paquete distribuido pero que Plugin Check sí escanea si el symlink apunta al repo completo.
- Hallazgos de Plugin Check dentro de `Tests/` (WPCS de escaping, `fwrite`, `var_export`, referencias a `test.wp.local`) son ruido esperado de un harness CLI (`php Tests/run.php`) — no requieren fix; confirmar primero que no aparecen al escanear el zip de `dist/`.

### Lo que nunca debes hacer

- Subir la versión del plugin sin que el usuario lo pida explícitamente (ver «Versionado del plugin»).
- Añadir autenticación/nonce al endpoint REST público sin acordarlo antes — rompería el conteo de visitantes deslogueados detrás de caché.
- Almacenar secretos en el repositorio.
- Introducir un build step (webpack, npm) para `assets/js/common.js` sin que el usuario lo pida — hoy es JS plano intencionalmente.
- Reintroducir `vendor/autoload.php` como dependencia runtime — el autoload de producción es el autoloader manual (`bbk_autoload`) en `bubuku-post-view-count.php`.

### Gestión de skills (obligatorio)

- No modificar skills oficiales de WordPress ni skills de terceros enlazados por symlink.
- Si se necesita adaptar un skill oficial para una convención Bubuku, crear un skill nuevo en `/Users/bubuku/dev/bubuku-plugins-wp/skills/bubuku`.
- La documentación operativa de un skill debe vivir dentro de su propia carpeta `references/`; no enlazar desde un skill a `.md` del proyecto en `docs/`.

## Documentación adicional

- `docs/ARCHITECTURE.md` — clases, flujo de una vista, constantes, tests, CI, estructura de directorios.
- `docs/CHANGELOG.md` — historial de versiones.
- `docs/MIGRATION-PSR4.md` — plan futuro para eliminar el prefijo `PCV_*` y mover a `src/{Core,Api,Frontend}/` (si se decide).
- `docs/IMPROVEMENT-PLAN.md` — plan de mejoras pendiente (sus fases 6–7 quedan reemplazadas por `docs/ANALYTICS-PLAN.md`).
- `docs/ANALYTICS-PLAN.md` — hoja de ruta por fases: tabla propia con última visita y agregado diario, página de ajustes con CPT seleccionables, y exposición de los datos como satélite de `bubuku-mcp-conex`.

Sigue estas reglas **salvo que la petición explícita del usuario indique lo contrario**.
