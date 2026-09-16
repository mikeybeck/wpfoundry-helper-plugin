=== WP Foundry Helper (full) ===
Contributors: mikeywazowski, mikeybeck
Tags: administration, remote, management, backup
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 4.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Full companion plugin for the WP Foundry desktop app: remote management, backups, and WP-CLI over HTTPS.

== Description ==

This is the **full** WP Foundry Helper, distributed from GitHub / wpfoundry.app. It is not the WordPress.org pairing plugin.

It lets you pair a WordPress site with the WP Foundry desktop app using a shared secret, then run authenticated management commands from your own computer.

= What it does =

* HMAC-authenticated REST endpoints used by WP Foundry
* Allowlisted WP-CLI commands (plugin/theme/core/user/database tasks, backups)
* Plugin/theme zip upload and install
* GitHub self-update for this helper

= Requirements =

* WP-CLI must be installed and available as `wp` on the server.
* Pretty permalinks should be enabled so `/wp-json/` REST routes work.

If the WordPress.org Foundry Helper is also installed, this plugin deactivates it on activation. Pairing credentials are shared.

== Changelog ==

= 4.2.0 =
* Split from the WordPress.org pairing stub. This package is the full remote-management helper.
