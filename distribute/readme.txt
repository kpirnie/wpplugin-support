=== KP Support ===
Contributors: kevp75
Tags: support, helpdesk, ticket system, live chat, customer service
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.13
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A full support ticket system with AJAX chat, threaded replies, internal notes, departments, attachments and a front-end customer portal.

== Description ==

KP Support turns WordPress into a proper help desk. Customers open tickets from a front-end portal, agents work them from either the portal or wp-admin, and the whole conversation runs as an AJAX chat that picks up new replies without a page refresh.

= Tickets =

* Tickets are a custom post type, so everything you already know about WordPress applies
* Categories, departments, priorities and statuses are all taxonomies, which means you add, rename, reorder and colour code them from the normal term screens
* Flag any status as a "closed" state and the plugin treats tickets in it accordingly
* Optional auto assignment hands each new ticket to whichever eligible agent has the lightest load
* Optional department restriction keeps agents scoped to just the departments on their profile

= Threaded chat =

* Replies are threaded, so a conversation can branch instead of running as one flat list
* Agents can mark any reply as an internal note, which customers never see and never get emailed
* New replies arrive by polling on a configurable interval, with backoff when the tab is hidden
* Agents get the same thread inside wp-admin on the ticket edit screen

= Attachments =

Attachments never touch the media library. They are written into a hardened uploads subdirectory that denies direct web access, and they are only ever served back through a PHP endpoint that re-checks ticket access on every request. Files attached to an internal note stay restricted to the people who can see internal notes. Executable and script file types are refused regardless of what the allow list says.

= Notifications =

Every public reply emails everybody attached to the ticket, which is the requester, the assigned agent and anybody else CC'd on. Internal notes only go to people who can see internal notes. Every email subject and body is editable with tokens for the ticket, the customer and the reply.

= Accounts =

Registration, login and profile management all happen on the front end. Customers get their own role and are kept out of wp-admin entirely.

= Shortcodes =

* `[kp_support]` - the whole portal, including the ticket list, the ticket view, the new ticket form and the account screens
* `[kp_support_login]` - just the login form
* `[kp_support_register]` - just the registration form
* `[kp_support_profile]` - just the profile form

== Installation ==

1. Upload the plugin to `/wp-content/plugins/kp-support` or install it through the plugins screen.
2. Run `composer install` in the plugin directory to pull in the KPT WP Field Framework, which the settings screens are built on.
3. Activate the plugin. A "Support" page holding the portal shortcode is created for you.
4. Visit Support &rarr; Settings to configure departments, notifications and attachment handling.
5. Give your agents the Support Agent or Support Manager role, and set their departments on their user profile.

== Frequently Asked Questions ==

= Where do attachments get stored? =

In `wp-content/uploads/kpts-attachments`, which is locked down with an .htaccess and a web.config that deny direct access. Files come back out only through a delivery endpoint that verifies the requester is on the ticket first.

= Can agents work tickets without a wp-admin login? =

Yes. The front-end portal gives agents the full thread, internal notes and the status, priority, department and assignment controls. wp-admin is there when you want list-table triage, not because agents need it.

= What is the difference between the three roles? =

Support Customer can open tickets and reply to their own. Support Agent can see and work every ticket they have access to, and post internal notes. Support Manager adds deleting tickets and managing the plugin settings.

= Does the chat use polling or websockets? =

Polling, on an interval you configure, defaulting to ten seconds. It backs off when the browser tab is hidden and again when requests fail, so it stays friendly on shared hosting where a long-lived connection would tie up a PHP worker.

= How do I change the look of the portal? =

Copy any file out of the plugin's `templates` directory into a `kp-support` directory in your theme and edit it there. The plugin picks up the theme's copy automatically.

== Changelog ==

= 1.0.13 =
* Attachment uploads, downloads and deletion now go through the WordPress filesystem API
* Attachment directory removal on uninstall now goes through the WordPress filesystem API
* Dropped the explicit text domain load, translations now load just in time from the languages directory
* Escaped the page id fallback field on the portal ticket list
* Added translator comments to the reply-to strings in the admin and portal scripts
* Sanitized the term sort order and colour values before saving
* Prefixed the variables used in the uninstall routine and the front-end templates
* Stylesheets and scripts are now minified, and the translation template is generated at build time
* Tested up to WordPress 7.1
* Tested up to PHP 8.5

= 1.0.0 =
* Initial release
* Ticket post type with department, category, priority and status taxonomies
* Threaded AJAX chat with public replies and internal notes
* Protected attachment storage with access-gated delivery
* Email notifications to every ticket participant, with editable templates
* Front-end registration, login and profile management
* Customer, agent and manager roles with per-ticket capability mapping
* Tabbed settings screen built on the KPT WP Field Framework
