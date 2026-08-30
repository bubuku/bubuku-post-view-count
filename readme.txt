=== Bubuku post view count ===
Contributors: lruizcode, bubuku
Tags: page view count, post views, post count, posts, post view count
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Complement to know how many times a Post has been seen

== Description ==
With this plugin, you can easily see how many people have viewed a Post

It only runs on Posts and after some time has passed through an Endpoint, so it doesn't affect CWV, and the page loads quickly.

The post meta that we store the value in is "views".

More information in Spanish about the plugin in the link [Show the most viewed Articles, without affecting the loading speed](https://www.bubuku.com/blog/mostrar-articulos-mas-vistos-sin-que-afecte-velocidad-de-carga/)

== Installation ==
1. Unzip the plugin ZIP file on your computer.
2. Copy or move the resulting folder to the "wp-content/plugins/" directory of your WordPress installation.
3. Log in to the WordPress admin area and navigate to the "Plugins" screen.
4. Locate "Bubuku Post View Count" in the plugins list and click "Activate" to enable the plugin.
5. Ensure your installation meets the requirements listed in the plugin header (WordPress and PHP versions) before using it in production.

== SUPPORT ==

**Need help or have a suggestion?**
Please use the [official WordPress.org Support Forum](https://wordpress.org/support/plugin/bubuku-post-view-count/) for any issues related to the plugin.
    
**Official Website**
For additional information or to get in touch with the development team, please visit our [official website](https://www.bubuku.com/).

**Like the plugin?**
Please [leave a 5-star review](https://wordpress.org/support/plugin/bubuku-post-view-count/reviews/?filter=5) and help others discover Bubuku Post View Count.

== ABOUT BUBUKU_CODE ==

We develop custom solutions for WordPress focused on performance, accessibility, and maintainable code. Our work includes plugins, themes, and integrations designed to improve the daily workflow of marketing and content teams.

== Frequently Asked Questions ==

= How do I see the view count for a post? =
Since 1.2.0 the source of truth is the plugin's own database table, but the total is still mirrored to post meta for backwards compatibility. You can keep using `get_post_meta( $post_id, 'views', true )`; the date of the last view is available as `get_post_meta( $post_id, 'views_last', true )`.

= Does the 1.2.0 update lose my existing view counts? =
No. On activation the plugin copies every existing `views` post meta into its new table in the background, in batches. The date of the last view isn't available for posts that were only counted before 1.2.0 — the count itself carries over intact.

= Does uninstalling the plugin delete my view data? =
Yes, by default — deleting the plugin removes its tables, options and deduplication data, in line with WordPress.org's guidelines against leaving data behind. You can turn this off from Settings → Post View Count if you'd rather keep your data for a future reinstall.

= Can I choose which content types count views, or exclude certain user roles and bots? =
Yes, since 1.2.0. Go to Settings → Post View Count to choose which content types are counted (only Posts by default, same as before), exclude specific user roles (roles that can already edit content are excluded by default), and optionally exclude known bots and crawlers. Unchecking a content type stops counting new views for it but never deletes the views already recorded.

= Can I reset the view counts without uninstalling the plugin? =
Yes. Settings → Post View Count has a "Delete all data now" button that clears every recorded view, with a confirmation prompt before it runs.

= Does this plugin affect page load speed? =
No, the plugin only updates the view count after some time has passed through an endpoint, ensuring it doesn't impact Core Web Vitals (CWV) or page load performance.

= Is this plugin compatible with multisite installations? =
Yes, the plugin now includes multisite support, including in the uninstall routine.

= Can I show the view count inside a post or page? =
Yes. Use the `[bbk_post_views]` shortcode, or add the "Bubuku · Vistas del post" block in the block editor. Both show the current post's view count; add `show_last_viewed="1"` to the shortcode (or toggle "Mostrar la fecha de la última visita" on the block) to also show the date of the last view. A content type that isn't counting views, per your settings, shows nothing.

= Can I see how views have evolved over time? =
Yes. Settings → Post View Count includes a chart of views over time (day/week/month) and a comparison of the current 30-day period against the previous one, both drawn from the same data used by the REST/MCP trend endpoints.


== Changelog ==

= 1.2.1 =
* New: read-only query layer (`Core\Query`) powering most-viewed content, stale content, per-post stats, trends and summary — used by the new admin columns, WP-CLI commands and MCP tools below.
* New: sortable "Views" and "Last view" columns in the admin list table for every enabled content type.
* New: WP-CLI commands `wp bbk-views top`, `wp bbk-views stale` and `wp bbk-views post <id>` (only registered when WP-CLI is present).
* New: optional satellite integration with the `bubuku-mcp-conex` hub, exposing five MCP tools (most-viewed, stale content, single-post views, summary, content trends). Inactive and invisible if the hub plugin isn't active.
* New: `GET /bbk_postview/v1/trends` REST endpoint (capability `edit_posts`, cacheable) for period-over-period view trends.
* Fix: resolved Plugin Check warnings in the production zip (nonce verification and direct-DB-call/no-caching/unescaped-parameter notices on the plugin's own tables) — documentation only, no behavior change.

= 1.2.0 =
* The view counter now has its own database tables (`{prefix}bbk_post_views`, `{prefix}bbk_post_views_daily`), tracking the date and time of the first and last view per post in addition to the total — the first step towards analytics features (most-viewed content, stale content) planned for upcoming versions.
* New: a settings page under Settings → Post View Count. Choose which content types count views (defaults to Posts, same as before), exclude specific user roles (defaults to roles that can already edit content), optionally exclude known bots and crawlers (Googlebot, Bingbot, GPTBot, ClaudeBot, etc.), and set how long the daily view history is kept.
* New: a "delete all data now" button on the settings page to reset all recorded views without uninstalling the plugin.
* Improvement: the counter increment is now a single atomic upsert per table (no read-then-write), removing the last possible race condition under concurrent traffic.
* Improvement: existing `views` post meta is migrated automatically and incrementally (500 rows per batch via WP-Cron) into the new tables on upgrade — no data is lost, and the migration is safe to run more than once.
* Kept: the `views` post meta is still written on every view as a compatibility mirror for themes and queries that already read it (e.g. `orderby=meta_value_num`); a new `views_last` post meta exposes the last-view date the same way.
* Improvement: `uninstall.php` now removes the plugin's own tables, options and deduplication transients (in addition to post meta), including on multisite. Whether to delete this data on uninstall is now configurable from the settings page (enabled by default, per WordPress.org guidelines).
* Note: this is a database schema change. Rolling back to 1.1.x after upgrading is safe — the `views` post meta is never removed, so the counter keeps working from where it was.

= 1.1.1 =
* Security: the view-count endpoint now also checks the request's `Origin`/`Referer` against the site's own URL, rejecting cross-origin browser requests in addition to the existing post_id validation and per-visitor deduplication.
* Improvement: internal refactor to a PSR-4 class structure (`src/Core`, `src/Api`, `src/Frontend`) — no functional change for site visitors.

= 1.1.0 =
* Fix: production zip was missing the Composer autoloader, causing a fatal error on activation. The plugin now uses its own lightweight autoloader and no longer depends on `vendor/` at runtime.
* Security: the view-count endpoint no longer accepts arbitrary/invalid post IDs, and now deduplicates repeated views per visitor instead of relying on a broken nonce check.
* Fix: race condition that could undercount concurrent views; the counter is now incremented atomically.
* Fix: the tracking script was loaded in the page `<head>` with jQuery as an unused dependency instead of deferred in the footer.
* Removed: translation loading — WordPress.org already serves translations automatically for public plugins since 4.6, and the previous implementation never worked (wrong textdomain path).
* Improvement: added multisite support to the uninstall routine.

= 1.0.4 =
* Compatibility: WordPress 6.2 - WordPress 6.5.3

= 1.0.3 =
* Compatibility: WordPress 6.1 - WordPress 6.2
* Fix some PHP errors

= 1.0.2 =
* Updated for WordPress 6.1
* Fix: Internationalization Issues

= 1.0.1 =
* Fix: It counted in categories.

= 1.0.0 =
* Initial release.