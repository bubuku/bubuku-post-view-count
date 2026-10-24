=== Bubuku Post View Count ===
Contributors: lruizcode, bubuku
Tags: page view count, post views, post count, posts, post view count
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.3
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Count post views without slowing your site down, and see which content is rising, falling or being crawled by AI bots.

== Description ==
Most view counters add a database write to every page load, break behind a page cache, or store more visitor data than you ever wanted. Meanwhile your editors still need an honest answer to a simple question: which articles are actually being read?

Bubuku Post View Count counts a visit with a tiny script that fires after the page has loaded, through a public REST endpoint. It stays out of the rendering path, so Core Web Vitals are not affected, and it works behind full-page caching. Totals and daily history live in the plugin's own indexed tables.

= Know what is being read =
* Summary cards: total views, last 30 days (and the change vs. the previous 30), AI referrals and AI bot crawling.
* Views over time by day, week or month, plus "Rising & falling" content.
* Most viewed and stale content, and sortable "Views" and "Last view" columns in the list tables.
* A dashboard widget with a 7-day trend, rendered from a background snapshot.

= See where traffic comes from =
* Optional device and referrer breakdown (direct, search, social, AI assistants).
* Optional tracking of 63 known AI crawlers, kept apart from human views.

= Show views to your readers =
Use the `[bbk_post_views]` shortcode or the "Bubuku · Vistas del post" block. The total is also mirrored to the `views` post meta.

= Built for privacy and performance =
No raw IP address or User-Agent is stored, Do Not Track and Global Privacy Control are honored, and you choose which content types count and which roles or bots are excluded. WP-CLI and multisite are supported.

More information in Spanish: [Show the most viewed Articles, without affecting the loading speed](https://www.bubuku.com/blog/mostrar-articulos-mas-vistos-sin-que-afecte-velocidad-de-carga/)

== Installation ==
1. Unzip the plugin ZIP file on your computer.
2. Copy or move the resulting folder to the "wp-content/plugins/" directory of your WordPress installation.
3. Log in to the WordPress admin area and navigate to the "Plugins" screen.
4. Locate "Bubuku Post View Count" in the plugins list and click "Activate" to enable the plugin.
5. Go to Settings → Post View Count to choose which content types count views and to explore your statistics.

== SUPPORT ==

**Need help or have a suggestion?**
Please use the [official WordPress.org Support Forum](https://wordpress.org/support/plugin/bubuku-post-view-count/) for any issues related to the plugin.
    
**Want to know more?**
There is more to it than a view counter: the [plugin page](https://plugins.bubuku.com/plugins/bubuku-post-view-count/) explains how it works, what it records and what it deliberately leaves out. For anything else, or to get in touch with the development team, visit our [official website](https://www.bubuku.com/).

**Like the plugin?**
Please [leave a 5-star review](https://wordpress.org/support/plugin/bubuku-post-view-count/reviews/?filter=5) and help others discover Bubuku Post View Count.

== ABOUT BUBUKU_CODE ==

We develop custom solutions for WordPress focused on performance, accessibility, and maintainable code. Our work includes plugins, themes, and integrations designed to improve the daily workflow of marketing and content teams.

== Privacy ==
This plugin never stores the visitor's raw IP address or User-Agent. To avoid duplicate views it keeps keyed, non-reversible HMAC tokens (derived from the post, network address and User-Agent) for up to 30 minutes, then deletes them. Reporting data is aggregated: total views and last-viewed date per post, daily views per post and, if you enable the optional session dimensions, a daily breakdown by device-width bucket and referrer category (never the raw width or referrer URL).

If the browser sends Do Not Track or Global Privacy Control, the optional device and referrer breakdown is skipped for that visit (default; can be turned off in Settings → Post View Count). Optional AI crawler tracking (off by default) is aggregated by day and bot name, in its own table.

== Screenshots ==

1. Statistics tab: summary cards (total views, last 30 days, AI referrals, AI bot crawling) and the "Views over time" chart.
2. "AI referrals" filter on the "Views over time" chart, with the tooltip of a single day listing the views from each AI assistant.
3. "Post views summary" widget on the WordPress dashboard, with a 7-day trend.
4. "Rising & falling" lists comparing the current period with the previous one.
5. AI traffic: referrals from AI assistants and crawling by known AI bots, each with its category.
6. Device and referrer breakdown, without storing the raw screen width or referrer.
7. Settings tab: content types, excluded roles, bot exclusion and privacy options.

== Frequently Asked Questions ==
= How do I read the view count in code? =
Use `get_post_meta( $post_id, 'views', true )`; the last view date is in `views_last`. The plugin's own tables are the source of truth, and the total is mirrored to post meta for compatibility.

= Will updating lose my existing view counts? =
No. Existing `views` post meta is copied into the plugin's tables in the background, in batches. Only the last-view date is missing for posts counted before 1.2.0.

= Does uninstalling delete my data? =
Yes by default (tables, options and deduplication data). You can turn this off in Settings → Post View Count.

= Can I choose which content types count, or exclude roles and bots? =
Yes. In Settings → Post View Count choose the content types (Posts by default), exclude user roles and optionally exclude known bots. Unchecking a type never deletes recorded views.

= Can I reset the counts without uninstalling? =
Yes, with the "Delete all data now" button in Settings → Post View Count.

= Does it affect page load speed? =
The counter is deferred and sends its request after the page has been visible for a few seconds, so it stays outside the initial rendering path.

= Does it work on multisite? =
Yes, including the uninstall routine.

= Can I show the view count on a post or page? =
Yes: the `[bbk_post_views]` shortcode (add `show_last_viewed="1"` for the last view date) or the "Bubuku · Vistas del post" block.

= Does it respect Do Not Track and Global Privacy Control? =
Yes, by default: the view is still counted, but the device and referrer breakdown is skipped.

== Changelog ==

= 1.3.3 =
* Stats: "Loading data..." while each section loads, full-width chart and a new AI referrals view with one trend line per assistant.

= 1.3.2 =
* Improved WordPress.org listing: description and screenshots.

= 1.3.1 =
* Fixed number formatting in the chart and the "Rising & falling" comparison.
* The dashboard widget no longer depends only on WP-Cron to refresh.

= 1.3.0 =
* New summary cards in Stats and a dashboard widget.

= 1.2.3 =
* 63 AI crawlers recognized, Do Not Track / GPC setting and optional write buffer.

= 1.2.2 =
* Charts, rising/falling lists, device and referrer breakdown, AI traffic report, shortcode and block.

= 1.2.1 =
* Sortable Views columns, WP-CLI commands and trends REST endpoint.

= 1.2.0 =
* Own database tables, settings page and automatic data migration.

= 1.1.0 =
* Security, stability and multisite fixes.

= 1.0.0 =
* Initial release.
