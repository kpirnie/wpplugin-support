# KP Support

[![GitHub Issues](https://img.shields.io/github/issues/kpirnie/wpplugin-support?style=for-the-badge&logo=github&color=006400&logoColor=white&labelColor=000)](https://github.com/kpirnie/wpplugin-support/issues)
[![Last Commit](https://img.shields.io/github/last-commit/kpirnie/wpplugin-support?style=for-the-badge&labelColor=000)](https://github.com/kpirnie/wpplugin-support/commits/main)
[![License: MIT](https://img.shields.io/badge/License-MIT-orange.svg?style=for-the-badge&logo=opensourceinitiative&logoColor=white&labelColor=000)](LICENSE)


[![PHP](https://img.shields.io/badge/Up%20To-php8.5-777BB4?logo=php&logoColor=white&style=for-the-badge&labelColor=000)](https://php.net)
[![WordPress](https://img.shields.io/badge/Min.%20WP-6.8-3858e9?logo=wordpress&logoColor=white&style=for-the-badge&labelColor=000)](https://php.net)
[![Kevin Pirnie](https://img.shields.io/badge/-KevinPirnie.com-000d2d?style=for-the-badge&labelColor=000&logoColor=white&logo=data:image/svg%2Bxml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSJ3aGl0ZSIgc3Ryb2tlLXdpZHRoPSIxLjgiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCI+CiAgPGNpcmNsZSBjeD0iMTIiIGN5PSIxMiIgcj0iMTAiLz4KICA8ZWxsaXBzZSBjeD0iMTIiIGN5PSIxMiIgcng9IjQuNSIgcnk9IjEwIi8+CiAgPGxpbmUgeDE9IjIiIHkxPSIxMiIgeDI9IjIyIiB5Mj0iMTIiLz4KICA8bGluZSB4MT0iNC41IiB5MT0iNi41IiB4Mj0iMTkuNSIgeTI9IjYuNSIvPgogIDxsaW5lIHgxPSI0LjUiIHkxPSIxNy41IiB4Mj0iMTkuNSIgeTI9IjE3LjUiLz4KPC9zdmc+Cg==)](https://kevinpirnie.com/)

A WordPress support ticket system with an AJAX chat thread, live chat, threaded replies, internal notes, departments, priorities, protected attachments, and a front-end customer portal.

Agents can work tickets entirely from the front end. wp-admin is there for list-table triage when you want it, not because anybody needs it to do their job.

## Requirements

* PHP 8.2 or higher
* WordPress 6.8 or higher
* Composer, for the [KPT WP Field Framework](https://github.com/kpirnie/kp-wpfieldframework) the settings screens are built on

## Repository layout

Work happens in `source`. Nothing in `distribute` is edited by hand - it is regenerated in full on every build.

```
composer.json        the package definition, autoloading source/src
package.json         name, version and the build paths
build.sh             the build
source/              everything that is worked on
    kp-support.php   the plugin bootstrap
    src/             the namespaced classes
    templates/       the front-end templates
    assets/          the unminified css and js
    uninstall.php
    readme.txt
    LICENSE
distribute/          the built plugin, committed, installable as-is
vendor/              composer dependencies, not committed
```

## Building

```bash
composer install
npm install
./build.sh
```

`build.sh` wipes `distribute` and rebuilds it every time. It copies the PHP, the templates and the supporting files, minifies the stylesheets and scripts into `.min.css` and `.min.js`, generates `languages/kp-support.pot` with WP-CLI, and copies the production vendor tree in. `composer.json` ships alongside it.

There are no translation files in `source`. The `.pot` is generated on each build and lives only in `distribute/languages`.

Set `production.shouldcopy` to `true` in `package.json` and give it a `production.path` to have the build copy the result somewhere else when it finishes.

## Installing

Install `distribute` as the plugin directory, or install from the WordPress plugin repository. Activation creates the three roles, registers the post type and its taxonomies, seeds the default terms, creates a "Support" page holding the portal shortcode, and schedules the daily maintenance sweep. Once installed, updates come through the normal WordPress update flow, pulled from this repository's releases.

## Architecture

`Plugin` is a singleton that defines the constants, registers a PSR-4 autoloader so there is no hard Composer dependency at runtime, boots the field framework, and registers every module.

Everything under `src/Modules` extends `AbstractModule` and does its work through a single `register()` method that hooks into WordPress. Nothing outside a module hooks anything.

* `PostTypes` - the ticket post type and the department, category, priority and status taxonomies
* `Roles` - the three roles, the capability map, and the meta capability mapping
* `Portal` - the shortcodes, the front-end routing and the portal asset loading
* `Accounts` - front-end registration, login and profile handling
* `Replies` - the threaded reply tree, internal notes and the reply markup
* `Ajax` - every ticket thread endpoint, all behind one nonce check
* `ChatAjax` - every live chat endpoint, split across a visitor nonce and an agent nonce
* `ChatWidget` - the corner docked launcher and panel on the front end
* `ChatAdmin` - the agent Live Chat screen and its queue
* `Attachments` - upload validation, protected storage and gated delivery
* `Notifications` - the participant emails and their templates
* `Admin` - the list table, the metaboxes and the admin asset loading
* `TermFields` - the colour and sort order fields on the taxonomy terms
* `Settings` - the tabbed options page, built from the field framework
* `Smtp` - our own SMTP delivery, scoped to this plugin's mail only
* `Updater` - the GitHub releases update checker

`src/Helpers` holds the stateless pieces the modules lean on: `Access` for every ticket capability and visibility decision, `ChatAccess` for the same on chats, `Ticket` for ticket creation, querying and state changes, `Chat` for chat creation, messages and state, `ChatConvert` for turning a chat into a ticket and building its transcript, and `Template` for locating and rendering the front-end template sand `MailLog` for the rolling record of what this plugin tried to email and what came of it. 

## Data model

Tickets are a custom post type. Replies are comments on that post, threaded through the standard comment parent, with internal notes flagged in comment meta. Departments, categories, priorities and statuses are taxonomies, so they are managed from the normal term screens and can be reordered and colour coded there.

Chats are their own post type, with messages as a separate comment type on the chat post. A chat is never a ticket while it is running. When an agent converts it, or either side closes it out, the first message becomes a ticket's opening post and everything said after it is re-pointed onto that ticket as a reply with its original timestamp. The chat post is kept afterwards as the archive record and the two point at each other, which is what the transcript download reads.

Attachments never enter the media library. They are written into `wp-content/uploads/kpts-attachments`, which is hardened with an `.htaccess`, a `web.config` and an index file, and they come back out only through a delivery endpoint that re-checks ticket access on every request. Files on an internal note stay restricted to the people who can see internal notes. Executable and script types are refused regardless of the allow list.

All plugin settings live in one option key.

## Template overrides

Copy any file from `source/templates` into a `kp-support` directory in your theme and edit it there. A child theme's copy wins over the parent's, and either wins over the plugin's.

Every variable a template is handed is prefixed with `kpts_`, and each file documents its own set in the docblock at the top.

## Hooks

Actions:

| Hook | Arguments |
| --- | --- |
| `kpts_ticket_created` | `$ticket_id`, `$requester_id` |
| `kpts_chat_started` | `$chat_id`, `$visitor_id` |
| `kpts_chat_message_added` | `$message_id`, `$chat_id`, `$user_id` |
| `kpts_chat_state_changed` | `$chat_id`, `$state`, `$previous` |
| `kpts_chat_assigned` | `$chat_id`, `$agent_id` |
| `kpts_chat_converted` | `$chat_id`, `$ticket_id`, `$state` |
| `kpts_reply_added` | `$comment_id`, `$ticket_id`, `$user_id`, `$internal` |
| `kpts_ticket_status_changed` | `$ticket_id`, `$term_id`, `$previous` |
| `kpts_ticket_assigned` | `$ticket_id`, `$user_id`, `$previous` |
| `kpts_participant_added` | `$ticket_id`, `$user_id` |
| `kpts_user_registered` | `$user_id` |

Filters:

| Hook | Filters |
| --- | --- |
| `kpts_can_view_ticket` | whether a user can see a ticket |
| `kpts_can_reply` | whether a user can reply to a ticket |
| `kpts_can_view_chat` | whether a user can see a chat |
| `kpts_can_post_chat` | whether a user can post to a chat |
| `kpts_can_start_chat` | whether a user can open a chat |
| `kpts_eligible_agents` | the assignable agent pool for a ticket |
| `kpts_ticket_participants` | everybody attached to a ticket |
| `kpts_ticket_query_args` | the query args behind the ticket lists |
| `kpts_ticket_number` | the displayed ticket number |
| `kpts_reply_allowed_tags` | the tags a reply may contain |
| `kpts_notification_email` | a notification before it is sent |
| `kpts_email_html` | the rendered email markup |
| `kpts_default_status_slug` | the status a new ticket opens in |
| `kpts_default_priority_slug` | the priority a new ticket opens at |
| `kpts_any_agent_online` | whether anybody is around to take a chat |

## Coding standards

PSR-12, strict types everywhere, and the WordPress coding standards on top. Class files are namespaced under `KP\Support` and guarded with a `class_exists` check. Every superglobal read is unslashed and sanitized, every output is escaped, every write path checks a nonce and a capability, and anything the standards flag as a false positive carries a `phpcs:ignore` with the reason.

Run Plugin Check against `distribute`, not `source` - the built tree is what ships.

## License

MIT. See `source/LICENSE`.
