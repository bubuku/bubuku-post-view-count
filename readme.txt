=== Bubuku post view count ===
Contributors: lruizcode, bubuku
Tags: page view count, post views, post count, posts, post view count
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
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
The view count is stored as post meta with the key "views". You can access it programmatically using `get_post_meta( $post_id, 'views', true )`.

= Does this plugin affect page load speed? =
No, the plugin only updates the view count after some time has passed through an endpoint, ensuring it doesn't impact Core Web Vitals (CWV) or page load performance.

= Is this plugin compatible with multisite installations? =
Yes, the plugin now includes multisite support, including in the uninstall routine. 


== Changelog ==

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