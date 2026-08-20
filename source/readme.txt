=== KP Support ===
Contributors: kevp75
Tags: support, helpdesk, ticket system, live chat, customer service
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.46
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

= Live chat =

* A corner docked chat panel for logged in customers, fixed positioned and non modal, the page underneath stays scrollable and clickable
* Agents work the queue from a Live Chat screen under the Support menu, with Mine, Waiting and All filters
* Any chat can be handed to another agent from the queue, and agents can start a chat themselves when they need a second pair of eyes
* A chat stays a chat until it ends. Hitting Convert To Ticket turns it into an open ticket, and either side closing it archives it as a closed one
* The opening message becomes the ticket's opening post and everything said after it becomes a reply, timestamps intact
* Every chat is kept, so the customer can pull a plain text transcript from the ticket it became, whenever they want
* Chat messages take attachments on the same terms ticket replies do, stored outside the media library and served only to the people on the chat

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
* `chat-panel.php` - the corner docked launcher and chat panel
* `chat-message.php` - a single chat message, rendered on its own when one arrives over AJAX
* `chat-admin.php` - `$kpts_agents`, `$kpts_can_assign`, `$kpts_can_convert`, `$kpts_allow_files`

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

= 1.0.46 =
* Added: a pre-chat form, so a chat starts with a name, email address and opening message rather than opening straight away
* Added: visitors without an account can now chat, an account is created quietly behind the scenes and they are never logged in
* Added: an Active Chat Label setting, so the launcher says a chat is already going while one is running
* Changed: Quick Edit now offers status, priority, department and assignment as dropdowns, and no longer shows the post status field
* Changed: participants sit alongside the requester in the Ticket Details metabox
* Fixed: a message sent in the same second as the other party's no longer stays hidden until the page is reloaded
* Fixed: the chat panel no longer clips its own forms, and the business hours time fields are wide enough to read

= 1.0.39 =
* Added: updates now come through the normal WordPress update flow, pulled from GitHub releases
* Added: agent presence tracking, so chat only offers itself when somebody actually has the queue open
* Added: business hours per day, which take precedence over agent presence
* Added: a leave-a-message form when nobody is available or the desk is closed, opening a ticket directly
* Added: a daily sweep that closes out abandoned chats and archives them as tickets
* Added: an offer to turn a chat into a ticket when nobody picks it up in time
* Added: SMTP settings, scoped to this plugin's own mail so an existing mailer plugin is left alone
* Added: a test email button, and detection of a known mailer plugin already running
* Added: a log of the last 25 emails and their outcome, on the Notifications tab
* Added: an opening message field on the ticket create screen
* Changed: Live Chat is now its own top level menu, sitting above Support
* Changed: priority and status moved into the Ticket Details metabox as single selects
* Changed: the Publish metabox is now Status, with Visibility removed
* Changed: taxonomy terms can only be added, edited or deleted by an administrator
* Changed: the chat launcher swaps to a close icon while the panel is open
* Fixed: failed and skipped emails are now recorded instead of failing silently
* Fixed: the Author metabox no longer shows on the ticket screen
* Fixed: a duplicate Ticket Assignment setting on the Notifications tab
* Fixed: an extra argument passed to the term options helper on the Chat settings tab

= 1.0.20 =
* Added live chat: a corner docked panel on the front end and a Live Chat queue under the Support menu for agents
* Chats are their own record until they end, then they are archived as a ticket with the opening message as the opening post
* Agents can convert a live chat into an open ticket, hand it to another agent, or close it out
* Chat messages accept attachments, which move onto the ticket when the chat is converted or closed
* Customers can download a plain text transcript from any ticket that came from a chat
* Added chat capabilities, and roles are now rebuilt automatically when the plugin is updated in place rather than only on activation
* Added chat settings for the launcher position and label, the chat department, the converted ticket prefix, the resulting statuses and a per user message rate limit
* Added an agent notification for a waiting chat, with its own editable subject and body
* Fixed replies posting from the wp-admin ticket screen, where the reply form was nested inside the post edit form and never submitted
* The ticket's opening message now renders at the top of the wp-admin conversation, along with any files that came with it
* Removed the editor, comment and author boxes from the ticket screen, so replies only ever go through the conversation
* Switched asset minification to esbuild, dropping the deprecated dependencies the old toolchain pulled in

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
