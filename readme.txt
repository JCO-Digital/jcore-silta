=== JCORE Silta ===
Contributors: jcodigital
Tags: jcore, sync, staging, migration, content
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Syncs posts, custom fields, terms and media between WordPress sites, with search-replace and a diff mode for choosing what to sync.

== Description ==

JCORE Silta copies content from one WordPress site to another: prepare it on staging and push it to production, or pull production content into a staging or development site. You choose what to sync: single posts, every post of a post type, menus, media, or any mix of them.

Silta brings along everything a post needs to work on the other site: its custom fields, categories and other terms, featured image and the images and files it uses, its translations, and the pages or posts it links to. Links and IDs are rewritten so they point at the right content on the other site.

= Features =

* **Push and pull** between connected sites, from **Tools → JCORE Silta**, from the post lists ("Push with Silta") or from a panel in the block editor.
* **Choose what to sync**: individual posts, whole post types, menus and media.
* **Everything a post needs**: content, custom fields (including ACF repeaters, flexible content and ACF blocks), terms, featured image and media, Polylang languages and translations, SEO data, page hierarchy.
* **Dependencies**: media, terms and posts the selection refers to are offered along with it when the other site doesn't have them yet.
* **Search and replace**: the site addresses are replaced automatically, and you can add your own rules per connection.
* **Diff mode**: see exactly what would change, section by section (content, fields, custom fields, terms, language…), and sync only the parts you choose.
* **Conflict warnings**: posts edited on the other site since the last sync are flagged and only overwritten when you confirm.
* **Undo**: every sync can be undone from its history.
* **Removed content**: when syncing a whole post type, posts that were deleted on the source can be moved to the trash on the target.
* **Secure pairing**: sites connect with a one-time pairing string. Every request is signed, and you decide per site whether it may read, write or both.
* **WP-CLI**: `wp silta push`, `pull`, `diff`, `undo` and more, for large syncs and deploy scripts.
* **Automatic updates** – through the J&Co Digital update service.

= Getting started =

1. Install and activate the plugin on both sites.
2. On the site that will receive content (for example production), open **Tools → JCORE Silta → Connections** and choose **Allow a site to connect**. Choose what the other site may do, and copy the pairing string.
3. On the other site (for example staging), open **Tools → JCORE Silta → Connections**, choose **Add a connection** and paste the string. It works once and expires after 30 minutes.
4. Open the **Sync** tab, choose **Push to** and the connection, select content, and review the plan before running it.

To also push from production to staging, or pull in the other direction, pair the sites the other way round as well.

= What is synced =

* Title, slug, content, excerpt, status, date, author (matched by username or email), parent, order, template, format, comment settings, password and sticky status.
* All custom fields, except WordPress's internal bookkeeping and keys you exclude in the settings. ACF, Yoast SEO, Rank Math and SEOPress fields that point at other posts, images or terms are rewritten to the matching content on the other site.
* Terms in every taxonomy. Missing terms are created; existing terms are never changed.
* Media files with their sizes, alt text, captions and descriptions. The same image is never imported twice.
* Polylang languages and translation links.
* Menus with their items and locations, and reusable blocks.

Comments, users, widgets, site settings and ACF options pages are not synced.

= Safety =

* Before anything is overwritten, Silta saves its current state, so a sync can be undone from **History** for 30 days (configurable).
* The target site checks that it has the post types, taxonomies, ACF fields and languages the content needs, and refuses the parts it can't take.
* Existing media files are never overwritten.
* When a site's database is copied to another address (a staging refresh, a local copy), Silta notices, gives the copy its own identity and switches off the connections that came with it.

= External services =

The plugin checks for updates against `https://update.jcore.fi`, operated by J&Co Digital Oy. It sends the plugin slug and the installed version, and the site URL as the request's origin. No visitor or content data is involved.

Silta itself only talks to the WordPress sites you connect it to. When you sync, the selected content, its media files and the information needed to match it (such as titles, slugs and file names) are sent between this site and the connected site over the connection you set up. No other service is involved.

= Source code =

The readable source of the built assets is published at https://github.com/JCO-Digital/jcore-silta.

== Installation ==

1. Upload the `jcore-silta` folder to `/wp-content/plugins/`, or install the zip from the Plugins screen.
2. Activate the plugin on every site that should take part in syncing.
3. Pair the sites under **Tools → JCORE Silta → Connections**.

== Frequently Asked Questions ==

= Who can sync? =

Administrators. Other roles, such as editors, can be given the `jcore_silta_sync` capability with a role editor. Managing connections always requires an administrator.

= Does the other site have to be reachable from this one? =

Only the site you start a sync from makes requests, so it must be able to reach the other site. The other site never contacts it. A local or password-protected staging site can therefore push to and pull from production. If the other site is behind a password (basic auth), enter it when adding the connection.

= Is HTTPS required? =

Yes, except between sites marked as local or development environments.

= Where is the key stored? =

Each site that starts syncs has its own key. It is created when the site first connects to another site and stored in the database, encrypted with the site's salts. You can move it into `wp-config.php` (Connections → Move the key to wp-config.php); it then also survives database refreshes. A site that only receives content has no key that could be used to write anywhere.

= What happens after I refresh staging with a copy of production? =

Silta notices the copy, switches off the connections that came with it, and staging needs a new pairing string from production.

== Changelog ==

= 0.3.0 (2026-10-16) =

* Feature: admin - improve sync review and status UX

= v0.2.1 (2026-10-14) =

* Fix: security - harden media transfers and import permissions
* Fix: security - harden sync and remote request handling
* CI: release - pin the shared publish workflow to a reviewed commit

= v0.2.0 (2026-10-13) =

* Feature: i18n - add Finnish and Swedish translations
* Feature: add plugin bootstrap and uninstall cleanup
* Feature: cli - add WP-CLI commands for keys, connections and syncing
* Feature: editor - show sync status in the block editor sidebar
* Feature: admin - add Silta admin screen for syncing, connections and history
* Feature: rest - add REST API for connections, sync jobs, settings and status
* Feature: jobs - run syncs as resumable background jobs with daily cleanup
* Feature: sync - add content, media, menu and ACF sync engine with search-replace
* Feature: remote - add signed site-to-site connections and trusted keys
* Feature: crypto - add signing keys, signatures and encrypted key vault
* Feature: core - add site identity, settings, capabilities and database schema
* Documentation: add readme, developer guide and design notes
* Test: add unit tests and two-site integration tests
* CI: add integration and release workflows
* Maintenance: dev - add two-site Docker dev stack and Playground blueprint
* Maintenance: set up build tooling, linters and project config
