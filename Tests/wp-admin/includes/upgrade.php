<?php
/**
 * Stand-in for WordPress core's wp-admin/includes/upgrade.php, required by
 * Schema::create_tables(). dbDelta() itself is stubbed in Tests/bootstrap.php
 * (loaded first), so this file only needs to exist for the require_once to
 * succeed — see Tests/wp-admin/includes/upgrade.php's role in bootstrap.php.
 *
 * @package Bubuku Post View Count
 */
