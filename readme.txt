=== Bubuku post view count ===
Contributors: lruizcode
Tags: page view count, post views, post count, posts, post view count
Requires at least: 5.7
Tested up to: 6.5.3
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Complement to know how many times a Post has been seen

== Description ==
With this plugin, you can easily see how many people have viewed a Post

It only runs on Posts and after some time has passed through an Endpoint, so it doesn't affect CWV, and the page loads quickly.

The post meta that we store the value in is "views".

More information in Spanish about the plugin in the link [Show the most viewed Articles, without affecting the loading speed](https://www.bubuku.com/mostrar-articulos-mas-vistos-sin-que-afecte-velocidad-de-carga/)

== Installation ==
Extract the zip file and just drop the contents in the wp-content/plugins/ directory of your
WordPress installation and then activate the Plugin from Plugins page.


== Changelog ==

= 1.1.0 =
* Fix: production zip was missing the Composer autoloader, causing a fatal error on activation. The plugin now uses its own lightweight autoloader and no longer depends on `vendor/` at runtime.
* Security: the view-count endpoint no longer accepts arbitrary/invalid post IDs, and now deduplicates repeated views per visitor instead of relying on a broken nonce check.
* Fix: race condition that could undercount concurrent views; the counter is now incremented atomically.
* Fix: the tracking script was loaded in the page `<head>` with jQuery as an unused dependency instead of deferred in the footer.
* Fix: translations were never loaded due to an incorrect textdomain path.
* Improvement: added multisite support to the uninstall routine.

= 1.0.4 =
* Compatibility: WordPress 6.2 – WordPress 6.5.3

= 1.0.3 =
* Compatibility: WordPress 6.1 – WordPress 6.2
* Fix some PHP errors

= 1.0.2 =
* Updated for WordPress 6.1
* Fix: Internationalization Issues

= 1.0.1 =
* Fix: It counted in categories.

= 1.0.0 =
* Initial release.