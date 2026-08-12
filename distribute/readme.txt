=== KP Support ===
Contributors: kevp75
Tags: support, helpdesk, ticket system, live chat, customer service
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.13
License: MIT
License URI: https://opensource.org/licenses/MIT

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

Every front-end template can be overridden by your theme. Copy any file out of the plugin's `templates` directory into a `kp-support` directory in your theme or child theme, and the plugin picks the theme's copy up automatically. See the Template Overrides section below for the full list and what each one is handed.

= Can I turn the portal styles off? =

The portal stylesheet loads on any page running one of the shortcodes. Dequeue the `kpts-portal` handle on `wp_enqueue_scripts` at a late priority if your theme styles the portal itself.

== Template Overrides ==

Drop a copy of any of these into `wp-content/themes/your-theme/kp-support/` and edit it there. A child theme's copy wins over the parent's, and either wins over the plugin's.

* `auth.php` - the login and registration tabs
* `list.php` - the ticket list, its filters and its pagination
* `new-ticket.php` - the new ticket form
* `profile.php` - the account profile form
* `reply.php` - a single reply in the thread, rendered recursively for nested replies
* `thread.php` - the reply list and the reply form, used by both the portal and wp-admin
* `ticket.php` - the single ticket view, its meta panel and its management controls

Every variable a template is handed is prefixed with `kpts_`, and each file documents its own in the docblock at the top. The full set:

* `auth.php` - `$kpts_allow_registration`, `$kpts_default_tab`, `$kpts_redirect`
* `list.php` - `$kpts_query`, `$kpts_is_agent`, `$kpts_agent_view`, `$kpts_user_id`
* `new-ticket.php` - `$kpts_departments`, `$kpts_categories`, `$kpts_priorities`
* `profile.php` - `$kpts_user`
* `reply.php` - `$kpts_comment`, `$kpts_ticket_id`, `$kpts_depth`, `$kpts_children`
* `thread.php` - `$kpts_ticket_id`, `$kpts_replies`, `$kpts_can_reply`, `$kpts_can_internal`, `$kpts_context`
* `ticket.php` - `$kpts_ticket`, `$kpts_ticket_id`, `$kpts_replies`, `$kpts_can_reply`, `$kpts_can_internal`, `$kpts_can_manage`, `$kpts_statuses`, `$kpts_priorities`, `$kpts_departments`, `$kpts_agents`

The class names in the markup and the CSS custom properties on the `.kpts-portal` wrapper are the supported styling surface. Keep the `kpts-` classes on the elements the scripts bind to - the reply form, the file input, the internal note toggle and the management selects - or the chat stops working.

== Changelog ==

= 1.0.13 =
* Restructured the repository into a source and distribute layout, built by build.sh
* Stylesheets and scripts are now minified into the built plugin, and the translation template is generated at build time
* Attachment uploads, downloads, deletion and directory hardening now go through the WordPress filesystem API
* Attachment directory removal on uninstall now goes through the WordPress filesystem API
* Dropped the explicit text domain load, translations now load just in time from the languages directory
* Escaped the page id fallback field on the portal ticket list
* Added translator comments to the reply-to strings in the admin and portal scripts
* Sanitized the term sort order and colour values before saving
* Prefixed the variables used in the uninstall routine and the front-end templates, template arguments now arrive prefixed with kpts_
* Documented the theme template overrides and the variables each template receives
* Fixed the assignable agent list, which was built from roles and so left out administrators and any custom role holding the ticket capabilities
* Constrained the settings screen field widths, which were running the full width of the screen
* Annotated the read-only request reads and the meta ordering queries that the coding standards flag
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
