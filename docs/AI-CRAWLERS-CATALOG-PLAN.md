# Plan: catálogo completo de rastreadores IA con categorías y código de color

> ✅ **Implementado.** `Core\AiCrawlers` tiene ahora las 63 entradas en 4 categorías,
> `SettingsApi` las expone en `GET /settings`, y el admin muestra el panel plegable en
> Ajustes → AI & privacy y la pelotita de color + leyenda en la tabla «AI bot crawling» de
> Estadísticas. `php Tests/run.php` (77/77) y `./vendor/bin/phpcs` en verde. Pendiente de
> confirmación del usuario: bump de versión (ver AGENTS.md, «Versionado del plugin»).

## Contexto

La captura `docs/assets/Captura de pantalla 2026-09-22 a las 15.18.57.png` muestra un panel «Rastreadores IA monitorizados (63)» con cada bot etiquetado por **fabricante** y por **función**, en cuatro categorías con color propio.

El plugin hoy está muy por debajo de eso:

- `Core\AiCrawlers::SIGNATURES` (`src/Core/AiCrawlers.php:32-42`) contiene **solo 9 firmas**: `GPTBot`, `ClaudeBot`, `Claude-User`, `PerplexityBot`, `CCBot`, `Google-Extended`, `Bytespider`, `Amazonbot`, `Applebot-Extended`.
- **No existe ninguna noción de categoría ni de fabricante**. La tabla `{prefix}bbk_post_ai_crawls` (`src/Core/Schema.php:267-274`) guarda un único campo plano `bot VARCHAR(50)`.
- La tabla «AI bot crawling» del admin (`assets/src/js/admin/components/StatsPanel.js:474-500`) renderiza el nombre crudo, sin `labels` y sin color.
- No existe ningún panel-catálogo que documente qué se monitoriza.

**Resultado buscado:** que el plugin detecte los 63 rastreadores de la captura, clasificados en 4 categorías, y que el admin muestre (a) una leyenda de colores bajo la card de IA, (b) un panel plegable con el catálogo completo, y (c) una pelotita del color de su categoría delante de cada bot en la tabla de crawling real.

**Decisiones ya cerradas con el usuario:**

- El opt-in sigue siendo **todo o nada**: se mantiene el único checkbox `ai_crawler_tracking`. No se añaden opciones por categoría.
- Los colores se aplican **tanto en el catálogo como en la tabla de datos reales**.
- El panel plegable con el catálogo de 63 vive en **Ajustes, card «AI & privacy»**, junto al checkbox `ai_crawler_tracking`.

---

## Categorías y paleta

Cuatro categorías, con los colores de la captura:

| Slug | Etiqueta (es) | Color | Significado |
|---|---|---|---|
| `assistant` | Asistente IA | verde | Recupera la página en vivo para responder a un usuario humano |
| `scraper` | Extractor de datos | naranja | Extrae contenido para terceros / pipelines de datos |
| `search` | Búsqueda IA | azul | Indexa para un buscador con respuestas generativas |
| `training` | Entrenamiento IA | rojo | Recolecta corpus para entrenar modelos |

Los valores exactos se definen como tokens en `assets/src/scss/admin/config/_tokens.scss`, junto al resto de tokens del admin. Elegir tonos accesibles sobre fondo claro y oscuro; el color **nunca** es el único portador de información: cada pelotita lleva `title` + texto accesible, y en el catálogo el badge de texto acompaña al color (como en la captura).

---

## Catálogo de los 63 rastreadores

Cada entrada es `nombre canónico => [ firma UA, fabricante, categoría ]`.

**Asistente IA (16):** Amzn-User (Amazon), ChatGPT Agent (OpenAI), ChatGPT-User (OpenAI), Claude-Code (Anthropic), Claude-User (Anthropic), Claude-Web (Anthropic), Cohere-AI (Cohere), Devin (Cognition AI), DuckAssistBot (DuckDuckGo), Gemini-Deep-Research (Google), Google-Agent (Google), Google-NotebookLM (Google), Google-Read-Aloud (Google), Kimi-User (Moonshot AI), MistralAI-User (Mistral AI), Perplexity-User (Perplexity).

**Extractor de datos (14):** Crawlspace (—), Diffbot (Diffbot), FirecrawlAgent (Firecrawl), Google-CloudVertexBot (Google), GoogleOther (Google), ICC-Crawler (NICT), ImagesiftBot (Imagesift), Kangaroo Bot (—), Meta-ExternalFetcher (Meta), OAI-AdsBot (OpenAI), Omgili (Webz.io), PanguBot (Huawei), Webzio-Extended (Webz.io), bedrockbot (Amazon).

**Búsqueda IA (12):** Amzn-SearchBot (Amazon), Applebot (Apple), BingBot (Microsoft), Claude-SearchBot (Anthropic), Cloudflare-AutoRAG (Cloudflare), Meta-WebIndexer (Meta), MistralAI-Index (Mistral AI), OAI-SearchBot (OpenAI), PerplexityBot (Perplexity), PetalBot (Huawei), YouBot (You.com), iAskSpider (iAsk.AI).

**Entrenamiento IA (21):** AI2Bot (Allen Institute), AI2Bot-Dolma (Allen Institute), Amazonbot (Amazon), Anthropic-AI (Anthropic), Applebot-Extended (Apple), Bytespider (ByteDance), CCBot (Common Crawl), ClaudeBot (Anthropic), Cohere Training Crawler (Cohere), DeepSeekBot (DeepSeek), FacebookBot (Meta), FriendlyCrawler (—), GPTBot (OpenAI), Google-Extended (Google), GrokBot (xAI), Meta-ExternalAgent (Meta), SBIntuitionsBot (SB Intuitions), TikTokSpider (ByteDance), TimpiBot (Timpi), YandexAdditional (Yandex), img2dataset (LAION).

Los 9 bots actuales están todos en la lista **con el mismo nombre canónico**, así que **no hace falta migración de datos**: las filas históricas de `bbk_post_ai_crawls` siguen casando. El nombre más largo («Cohere Training Crawler», 23 car.) cabe en el `VARCHAR(50)` existente.

---

## Implementación

### 1. `src/Core/AiCrawlers.php` — reestructurar el catálogo

Sustituir la lista plana `SIGNATURES` por un mapa con metadatos, manteniendo la API pública `detect()` intacta (devuelve el nombre canónico) para no tocar a sus consumidores.

- `const CATEGORIES = [ 'assistant', 'scraper', 'search', 'training' ]`.
- `const CRAWLERS = [ '<nombre canónico>' => [ 'signature' => '<substring UA>', 'vendor' => '<fabricante>', 'category' => '<slug>' ], ... ]` con las 63 entradas.
- `detect( string $user_agent ): ?string` — mantiene la firma y el contrato actuales (`src/Core/AiCrawlers.php:50-62`), pero recorre `CRAWLERS`.
- Nuevos helpers: `category_for( string $bot ): ?string`, `vendor_for( string $bot ): ?string`, y `catalog(): array` (payload para el admin, ya ordenado por categoría y nombre como en la captura).

**Orden de coincidencia — crítico.** `detect()` devuelve el primer match por `stripos()`, así que hay colisiones de substring que hoy no existen y que romperían la atribución:

- `Applebot` ⊂ `Applebot-Extended`
- `AI2Bot` ⊂ `AI2Bot-Dolma`
- `Omgili` ⊂ `Omgilibot` (si apareciera)

Solución: iterar **ordenando las firmas por longitud descendente** (una sola vez, o precalculando el orden en la constante). Añadir un test que lo cubra explícitamente.

**Riesgos de falso positivo a validar antes de dar por cerrada la lista** (no bloquean el plan, pero conviene comprobar UA reales en documentación del fabricante):

- `Devin` y `Omgili` son substrings cortos y genéricos; podrían casar con UA no relacionados.
- `ChatGPT Agent` y `ChatGPT-User`: el UA del agente puede contener literalmente `ChatGPT-User`, en cuyo caso ambos nombres colapsan y hay que decidir cuál gana (o usar una firma distintiva).
- `Cohere Training Crawler` y `Kangaroo Bot` llevan espacios; el UA real suele usar guiones (`cohere-training-data-crawler`). Usar la firma real y el nombre bonito solo para mostrar.
- `BingBot` y `PetalBot` son crawlers de buscador clásico. Contarlos como «IA» es la clasificación de la captura, pero conviene que el usuario sepa que inflarán la columna de «Búsqueda IA» con tráfico de indexación convencional de toda la vida.

Todo esto refuerza la separación entre **nombre canónico** (para mostrar y para la BD) y **firma UA** (para casar), que es justo lo que introduce la nueva estructura.

### 2. `src/Frontend/AiCrawlerTracker.php` — sin cambios

Los guards de `src/Frontend/AiCrawlerTracker.php:40-58` siguen igual: opt-in `ai_crawler_tracking`, `is_singular()`, `AiCrawlers::detect()`, `Db::record_ai_crawl()`. Al ser todo-o-nada, no hay filtrado por categoría.

### 3. Exponer el catálogo al admin

El catálogo es estático, así que viaja como contexto, no como endpoint nuevo:

- Añadir `ai_crawler_catalog` (salida de `AiCrawlers::catalog()`) al payload que ya alimenta `SettingsPanel` / `StatsPanel`, junto a `bot_signature_examples` (`src/Admin/Settings.php:201-219` y su punto de localización de script). Las etiquetas de categoría se traducen en PHP con el text domain `bubuku-post-view-count`.
- La respuesta de `Query::ai_traffic()` no cambia: el front resuelve la categoría de cada bot contra el catálogo que ya tiene.

### 4. Componente de pelotita + leyenda

Nuevo componente en `assets/src/js/admin/components/`, p. ej. `CrawlerCategoryDot.js` (pelotita `<span>` con `aria-label`/`title` = etiqueta de categoría) y `CrawlerCategoryLegend.js` (las 4 entradas color + etiqueta).

- `ExpandableViewsTable.js` recibe una **prop opcional nueva** (p. ej. `renderIndicator( key )`) que se pinta antes del texto del botón, en `assets/src/js/admin/components/ExpandableViewsTable.js:68`. Al ser opcional, la tabla de «AI referrals» no cambia.
- En `StatsPanel.js:474-500`, la tabla de «AI bot crawling» pasa ese `renderIndicator` y también un mapa `labels` (hoy no se pasa), para que el nombre mostrado sea el canónico del catálogo.

### 5. Catálogo plegable en Ajustes + leyenda en ambos sitios

**Catálogo — Ajustes, card «AI & privacy»** (`assets/src/js/admin/components/SettingsPanel.js:244-280`), debajo del checkbox `ai_crawler_tracking`:

Un `<details>`/acordeón «Rastreadores IA monitorizados (63)» que reproduce la captura: texto explicativo («Este plugin monitoriza los siguientes rastreadores de IAs. La lista se actualizará en cada versión del plugin.»), la leyenda de 4 colores, y la rejilla de tarjetas con nombre, fabricante y badge de categoría coloreado. El contador `(63)` se deriva de la longitud del catálogo, nunca se escribe a mano. Es el mismo patrón que ya usa esa pantalla para `bot_signature_examples` (`SettingsPanel.js:218-241`), así que queda coherente: Ajustes documenta *qué* se monitoriza.

**Leyenda — también en Estadísticas**, dentro de la card «AI traffic» (`StatsPanel.js:449-501`), debajo de la tabla «AI bot crawling»: versión compacta de las 4 entradas color + etiqueta, sin el catálogo. Sin ella las pelotitas de la tabla de datos reales no serían interpretables desde esa pantalla. Es el mismo componente `CrawlerCategoryLegend` reutilizado.

### 6. Estilos

En `assets/src/scss/admin/components/`: tokens de las 4 categorías en `config/_tokens.scss`, estilos de pelotita y leyenda, y la rejilla de tarjetas del catálogo (responsive, como en la captura). Reutilizar los patrones de `_data-table.scss:108-175` para el acordeón en vez de inventar uno nuevo.

### 7. Tests (`Tests/run.php`)

Se toca `Core\AiCrawlers` y se añade superficie, así que la Definition of Done exige tests nuevos siguiendo el patrón existente:

- `detect()` acierta con un UA de cada categoría.
- **Precedencia de substrings**: un UA de `Applebot-Extended` no devuelve `Applebot`; uno de `AI2Bot-Dolma` no devuelve `AI2Bot`.
- Un UA de navegador normal devuelve `null`.
- Integridad del catálogo: las 63 entradas tienen `signature`/`vendor`/`category`, toda categoría está en `CATEGORIES`, no hay nombres canónicos duplicados, y ningún nombre excede los 50 caracteres de la columna `bot`.
- Regresión: los 9 nombres previos siguen detectándose con el mismo nombre canónico (garantía de continuidad de los datos históricos).

### 8. Documentación

- `docs/ARCHITECTURE.md`: actualizar la fila de `Core\AiCrawlers` (`:30`), que hoy enumera los 9 nombres literales — sustituir por «63 rastreadores en 4 categorías» y remitir al catálogo.
- `docs/ANALYTICS-PLAN.md` §F6 (`:712-761`, sobre todo la enumeración en `:749-750`).
- `docs/CHANGELOG.md`: **no tocar todavía.** Según AGENTS.md, el bump de versión lo decide el usuario. Este cambio justifica un **minor** (`1.2.0` → `1.3.0`, o el siguiente minor sobre la rama `feature/v.1.2.3`): se sugiere al usuario al terminar y se espera confirmación explícita antes de tocar el header PHP, `readme.txt` (`Stable tag`) y el changelog.

---

## Archivos a modificar

| Archivo | Cambio |
|---|---|
| `src/Core/AiCrawlers.php` | Catálogo de 63 con categoría/fabricante; `detect()` por longitud descendente; helpers nuevos |
| `src/Api/SettingsApi.php` | Exponer `ai_crawler_catalog` al contexto del admin (`GET /settings`) |
| `assets/src/js/admin/components/SettingsPanel.js` | Panel plegable con el catálogo de 63 + leyenda, en la card «AI & privacy» |
| `assets/src/js/admin/components/StatsPanel.js` | Leyenda compacta bajo «AI bot crawling»; `labels` y `renderIndicator` para la tabla de bots |
| `assets/src/js/admin/components/ExpandableViewsTable.js` | Prop opcional `renderIndicator` |
| `assets/src/js/admin/components/CrawlerCategoryDot.js`, `CrawlerCategoryLegend.js`, catálogo | Componentes nuevos |
| `assets/src/scss/admin/config/_tokens.scss` + componente SCSS nuevo | Paleta de categorías, pelotita, leyenda, rejilla |
| `Tests/run.php` | Tests de detección, precedencia, integridad y regresión |
| `docs/ARCHITECTURE.md`, `docs/ANALYTICS-PLAN.md` | Actualizar referencias a los 9 crawlers |

**Sin cambios:** `src/Core/Schema.php` y `src/Core/Db.php` (la columna `bot VARCHAR(50)` sirve tal cual, sin migración), `src/Frontend/AiCrawlerTracker.php`, `src/Api/RestApi.php`, `assets/src/js/public/common.js`.

---

## Verificación

1. `./vendor/bin/phpcs` sin errores.
2. `php Tests/run.php` sin fallos.
3. `npm run build` y comprobar en `https://test.wp.local/`:
   - Activar `ai_crawler_tracking` en Ajustes.
   - Simular varios crawlers contra un post singular y confirmar que se registran con el nombre canónico correcto:
     ```bash
     curl -A "Mozilla/5.0 (compatible; Applebot-Extended/0.1; +http://www.apple.com/go/applebot)" https://test.wp.local/<post>/
     curl -A "Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)" https://test.wp.local/<post>/
     curl -A "Mozilla/5.0 (compatible; AI2Bot-Dolma/1.0)" https://test.wp.local/<post>/
     ```
     Verificar en `{prefix}bbk_post_ai_crawls` que `bot` vale `Applebot-Extended` (no `Applebot`) y `AI2Bot-Dolma` (no `AI2Bot`).
   - Confirmar que un UA de navegador normal **no** crea fila.
4. En el admin:
   - Ajustes → card «AI & privacy»: el panel plegable lista los 63 agrupados por categoría como en la captura, con leyenda y contador correcto.
   - Estadísticas → card «AI traffic»: cada bot de la tabla lleva su pelotita del color correcto y la leyenda compacta aparece debajo.
5. Accesibilidad: comprobar con el zoom/lupa o en escala de grises que la categoría sigue siendo identificable sin color (title/aria-label en la pelotita, badge de texto en el catálogo).
6. `bash scripts/build.sh` y pasar Plugin Check **sobre el zip de `dist/`**, no sobre el checkout.
