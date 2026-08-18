<?php

/**
 * Settings - The plugin settings screen
 *
 * Builds our tabbed options page out with the field framework, writing into the
 * single option key everything else in the plugin reads from.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

namespace KP\Support\Settings;

use KP\Support\Plugin;
use KP\Support\Modules\AbstractModule;
use KP\Support\Modules\PostTypes;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Settings\Settings')) {

    /**
     * Class Settings
     *
     * Our settings page.
     *
     * @since 1.0.0
     */
    class Settings extends AbstractModule
    {
        /**
         * Hook this module into WordPress.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function register(): void
        {

            // build the page after the taxonomies are registered, we need their terms
            add_action('init', array($this, 'registerPage'), 20);
        }

        /**
         * Build and register the options page.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function registerPage(): void
        {

            // we need the framework for this
            $framework = Plugin::instance()->framework();
            if ($framework === null) {
                return;
            }

            // and hand the whole thing over
            $framework->addOptionsPage(array(
                'page_title'         => __('Support Settings', 'kp-support'),
                'menu_title'         => __('Settings', 'kp-support'),
                'menu_slug'          => 'kp-support-settings',
                'parent_slug'        => 'edit.php?post_type=' . PostTypes::POST_TYPE,
                'capability'         => 'kpts_manage_settings',
                'option_name'        => Plugin::OPTION_KEY,
                'show_export_import' => true,
                'tabs'               => array(
                    'general'       => array(
                        'title'    => __('General', 'kp-support'),
                        'sections' => array('general' => array(
                            'title'  => __('Portal', 'kp-support'),
                            'fields' => $this->generalFields(),
                        )),
                    ),
                    'tickets'       => array(
                        'title'    => __('Tickets', 'kp-support'),
                        'sections' => array('tickets' => array(
                            'title'  => __('Ticket Handling', 'kp-support'),
                            'fields' => $this->ticketFields(),
                        )),
                    ),
                    'chat'          => array(
                        'title'    => __('Chat &amp; Files', 'kp-support'),
                        'sections' => array('chat' => array(
                            'title'  => __('Chat and Attachments', 'kp-support'),
                            'fields' => $this->chatFields(),
                        )),
                    ),
                    'notifications' => array(
                        'title'    => __('Notifications', 'kp-support'),
                        'sections' => array('notifications' => array(
                            'title'  => __('Email Notifications', 'kp-support'),
                            'fields' => $this->notificationFields(),
                        )),
                    ),
                    'templates'     => array(
                        'title'    => __('Email Templates', 'kp-support'),
                        'sections' => array('templates' => array(
                            'title'       => __('Email Templates', 'kp-support'),
                            'description' => __('Leave any of these empty to use the built in default.', 'kp-support'),
                            'fields'      => $this->templateFields(),
                        )),
                    ),
                    'accounts'      => array(
                        'title'    => __('Accounts', 'kp-support'),
                        'sections' => array('accounts' => array(
                            'title'  => __('Registration and Login', 'kp-support'),
                            'fields' => $this->accountFields(),
                        )),
                    ),
                ),
            ));
        }

        /**
         * The general tab's fields.
         *
         * @since  1.0.0
         * @access private
         * @return array<int, array<string, mixed>> The field definitions.
         */
        private function generalFields(): array
        {

            // the portal basics
            return array(
                array(
                    'id'          => 'portal_page_id',
                    'type'        => 'page_select',
                    'label'       => __('Portal Page', 'kp-support'),
                    'description' => __('The page containing the [kp_support] shortcode. Ticket links in emails point here.', 'kp-support'),
                ),
                array(
                    'id'          => 'ticket_prefix',
                    'type'        => 'text',
                    'label'       => __('Ticket Number Prefix', 'kp-support'),
                    'description' => __('Shown in front of every ticket number.', 'kp-support'),
                    'default'     => '#',
                ),
                array(
                    'id'          => 'tickets_per_page',
                    'type'        => 'number',
                    'label'       => __('Tickets Per Page', 'kp-support'),
                    'description' => __('How many tickets to show in the portal list at a time.', 'kp-support'),
                    'default'     => 20,
                    'min'         => 5,
                    'max'         => 100,
                ),
                array(
                    'id'          => 'block_admin_access',
                    'type'        => 'switch',
                    'label'       => __('Keep Customers Out Of The Dashboard', 'kp-support'),
                    'description' => __('Redirects anybody who cannot work tickets away from wp-admin and hides the admin bar for them.', 'kp-support'),
                    'default'     => true,
                ),
                array(
                    'id'          => 'keep_data_on_uninstall',
                    'type'        => 'switch',
                    'label'       => __('Keep Data When The Plugin Is Deleted', 'kp-support'),
                    'description' => __('Leave this on and deleting the plugin will not remove your tickets, replies, attachments or settings.', 'kp-support'),
                    'default'     => false,
                ),
            );
        }

        /**
         * The tickets tab's fields.
         *
         * @since  1.0.0
         * @access private
         * @return array<int, array<string, mixed>> The field definitions.
         */
        private function ticketFields(): array
        {

            // the status options, built from the taxonomy so renaming works
            $statuses = $this->termOptions(PostTypes::TAX_STATUS);

            // everything about how tickets get handled
            return array(
                array(
                    'id'          => 'require_department',
                    'type'        => 'switch',
                    'label'       => __('Require A Department', 'kp-support'),
                    'description' => __('Customers must pick a department when opening a ticket.', 'kp-support'),
                    'default'     => true,
                ),
                array(
                    'id'          => 'require_category',
                    'type'        => 'switch',
                    'label'       => __('Require A Category', 'kp-support'),
                    'description' => __('Customers must pick a category when opening a ticket.', 'kp-support'),
                    'default'     => false,
                ),
                array(
                    'id'          => 'allow_reopen',
                    'type'        => 'switch',
                    'label'       => __('Allow Reopening Closed Tickets', 'kp-support'),
                    'description' => __('Lets customers reply to a closed ticket, which reopens it.', 'kp-support'),
                    'default'     => true,
                ),
                array(
                    'id'          => 'auto_assign',
                    'type'        => 'switch',
                    'label'       => __('Auto Assign New Tickets', 'kp-support'),
                    'description' => __('Hands each new ticket to whichever eligible agent has the fewest open tickets.', 'kp-support'),
                    'default'     => false,
                ),
                array(
                    'id'          => 'restrict_agents_by_department',
                    'type'        => 'switch',
                    'label'       => __('Restrict Agents By Department', 'kp-support'),
                    'description' => __('Agents only see tickets in the departments set on their profile. Agents with no departments set still see everything.', 'kp-support'),
                    'default'     => false,
                ),
                array(
                    'id'          => 'auto_status',
                    'type'        => 'switch',
                    'label'       => __('Move Status Automatically', 'kp-support'),
                    'description' => __('Changes the ticket status when somebody replies.', 'kp-support'),
                    'default'     => true,
                ),
                array(
                    'id'          => 'status_after_agent_reply',
                    'type'        => 'select',
                    'label'       => __('Status After An Agent Replies', 'kp-support'),
                    'options'     => $statuses,
                    'default'     => 'pending',
                    'conditional' => array(
                        'field'     => 'auto_status',
                        'value'     => true,
                        'condition' => '==',
                    ),
                ),
                array(
                    'id'          => 'status_after_customer_reply',
                    'type'        => 'select',
                    'label'       => __('Status After A Customer Replies', 'kp-support'),
                    'options'     => $statuses,
                    'default'     => 'open',
                    'conditional' => array(
                        'field'     => 'auto_status',
                        'value'     => true,
                        'condition' => '==',
                    ),
                ),
            );
        }

        /**
         * The chat and attachments tab's fields.
         *
         * @since  1.0.0
         * @access private
         * @return array<int, array<string, mixed>> The field definitions.
         */
        private function chatFields(): array
        {

            // how the chat polls and what files we take
            return array(
                array(
                    'id'          => 'enable_chat',
                    'type'        => 'switch',
                    'title'       => __('Enable Live Chat', 'kp-support'),
                    'description' => __('Show the chat launcher to logged in customers.', 'kp-support'),
                    'default'     => false,
                ),
                array(
                    'id'          => 'chat_position',
                    'type'        => 'select',
                    'title'       => __('Launcher Position', 'kp-support'),
                    'description' => __('Which corner the chat launcher sits in.', 'kp-support'),
                    'options'     => array(
                        'bottom-right' => __('Bottom Right', 'kp-support'),
                        'bottom-left'  => __('Bottom Left', 'kp-support'),
                        'top-right'    => __('Top Right', 'kp-support'),
                        'top-left'     => __('Top Left', 'kp-support'),
                    ),
                    'default'     => 'bottom-right',
                ),
                array(
                    'id'          => 'chat_label',
                    'type'        => 'text',
                    'title'       => __('Launcher Label', 'kp-support'),
                    'description' => __('The text shown on the chat launcher.', 'kp-support'),
                    'default'     => __('Need help?', 'kp-support'),
                ),
                array(
                    'id'          => 'chat_department',
                    'type'        => 'select',
                    'title'       => __('Chat Department', 'kp-support'),
                    'description' => __('The department chats are routed to, and the one converted tickets land in.', 'kp-support'),
                    'options'     => $this->termOptions(PostTypes::TAX_DEPARTMENT),
                    'default'     => 0,
                ),
                array(
                    'id'          => 'chat_ticket_prefix',
                    'type'        => 'text',
                    'title'       => __('Converted Ticket Prefix', 'kp-support'),
                    'description' => __('Prefixed to the subject of every ticket built from a chat.', 'kp-support'),
                    'default'     => 'CHAT - ',
                ),
                array(
                    'id'          => 'status_after_chat_convert',
                    'type'        => 'select',
                    'title'       => __('Status After Convert', 'kp-support'),
                    'description' => __('The status a ticket gets when an agent converts a live chat.', 'kp-support'),
                    'options'     => $this->termOptions(PostTypes::TAX_STATUS, 'slug'),
                    'default'     => 'open',
                ),
                array(
                    'id'          => 'status_after_chat_close',
                    'type'        => 'select',
                    'title'       => __('Status After Close', 'kp-support'),
                    'description' => __('The status a ticket gets when either side closes the chat out.', 'kp-support'),
                    'options'     => $this->termOptions(PostTypes::TAX_STATUS, 'slug'),
                    'default'     => 'closed',
                ),
                array(
                    'id'          => 'chat_rate_limit',
                    'type'        => 'number',
                    'title'       => __('Messages Per Minute', 'kp-support'),
                    'description' => __('How many messages one person can send in a minute before being throttled.', 'kp-support'),
                    'default'     => 20,
                    'min'         => 1,
                    'max'         => 120,
                ),
                array(
                    'id'          => 'poll_interval',
                    'type'        => 'number',
                    'label'       => __('Check For New Replies Every', 'kp-support'),
                    'description' => __('Seconds between checks for new replies. Lower feels more live but costs more requests.', 'kp-support'),
                    'default'     => 10,
                    'min'         => 5,
                    'max'         => 120,
                ),
                array(
                    'id'          => 'allow_attachments',
                    'type'        => 'switch',
                    'label'       => __('Allow Attachments', 'kp-support'),
                    'description' => __('Attachments are stored outside the media library and only served to people on the ticket.', 'kp-support'),
                    'default'     => true,
                ),
                array(
                    'id'          => 'max_attachments',
                    'type'        => 'number',
                    'label'       => __('Maximum Files Per Message', 'kp-support'),
                    'default'     => 5,
                    'min'         => 1,
                    'max'         => 20,
                    'conditional' => array(
                        'field'     => 'allow_attachments',
                        'value'     => true,
                        'condition' => '==',
                    ),
                ),
                array(
                    'id'          => 'max_attachment_size',
                    'type'        => 'number',
                    'label'       => __('Maximum File Size (MB)', 'kp-support'),
                    'description' => __('Your server upload limit still applies on top of this.', 'kp-support'),
                    'default'     => 5,
                    'min'         => 1,
                    'max'         => 100,
                    'conditional' => array(
                        'field'     => 'allow_attachments',
                        'value'     => true,
                        'condition' => '==',
                    ),
                ),
                array(
                    'id'          => 'allowed_file_types',
                    'type'        => 'text',
                    'label'       => __('Allowed File Types', 'kp-support'),
                    'description' => __('Comma separated extensions. Executable and script types are always refused regardless of what is listed here.', 'kp-support'),
                    'default'     => 'jpg,jpeg,png,gif,webp,pdf,txt,log,csv,zip,doc,docx,xls,xlsx',
                    'conditional' => array(
                        'field'     => 'allow_attachments',
                        'value'     => true,
                        'condition' => '==',
                    ),
                ),
            );
        }

        /**
         * The notifications tab's fields.
         *
         * @since  1.0.0
         * @access private
         * @return array<int, array<string, mixed>> The field definitions.
         */
        private function notificationFields(): array
        {

            // who gets told about what
            return array(
                array(
                    'id'      => 'email_from_name',
                    'type'    => 'text',
                    'label'   => __('From Name', 'kp-support'),
                    'default' => get_bloginfo('name'),
                ),
                array(
                    'id'      => 'email_from_address',
                    'type'    => 'email',
                    'label'   => __('From Address', 'kp-support'),
                    'default' => get_bloginfo('admin_email'),
                ),
                array(
                    'id'          => 'notify_new_ticket',
                    'type'        => 'switch',
                    'label'       => __('New Ticket', 'kp-support'),
                    'description' => __('Confirms to the customer and alerts the department\'s agents.', 'kp-support'),
                    'default'     => true,
                ),
                array(
                    'id'          => 'notify_new_chat',
                    'type'        => 'switch',
                    'label'       => __('New Chat', 'kp-support'),
                    'description' => __('Email the agents when somebody opens a live chat.', 'kp-support'),
                    'default'     => true,
                ),
                array(
                    'id'          => 'notify_new_reply',
                    'type'        => 'switch',
                    'label'       => __('New Reply', 'kp-support'),
                    'description' => __('Emails everybody attached to the ticket whenever a public reply is posted.', 'kp-support'),
                    'default'     => true,
                ),
                array(
                    'id'          => 'notify_internal_notes',
                    'type'        => 'switch',
                    'label'       => __('Internal Notes', 'kp-support'),
                    'description' => __('Emails internal notes to the agents on the ticket. Customers are never included.', 'kp-support'),
                    'default'     => true,
                ),
                array(
                    'id'          => 'notify_all_agents',
                    'type'        => 'switch',
                    'label'       => __('Copy All Department Agents On Internal Notes', 'kp-support'),
                    'description' => __('Widens internal note emails to every agent covering the department, not just the ones on the ticket.', 'kp-support'),
                    'default'     => false,
                ),
                array(
                    'id'          => 'notify_status_change',
                    'type'        => 'switch',
                    'label'       => __('Status Changes', 'kp-support'),
                    'description' => __('Tells the customer when their ticket status moves.', 'kp-support'),
                    'default'     => true,
                ),
                array(
                    'id'          => 'notify_assignment',
                    'type'        => 'switch',
                    'label'       => __('Ticket Assignment', 'kp-support'),
                    'description' => __('Tells an agent when a ticket lands on their plate.', 'kp-support'),
                    'default'     => true,
                ),
            );
        }

        /**
         * The email templates tab's fields.
         *
         * @since  1.0.0
         * @access private
         * @return array<int, array<string, mixed>> The field definitions.
         */
        private function templateFields(): array
        {

            // the tokens people can use, shown once at the top
            $fields = array(
                array(
                    'id'           => 'template_tokens',
                    'type'         => 'message',
                    'message_type' => 'info',
                    'content'      => __('Available tokens: {ticket_number}, {ticket_subject}, {ticket_content}, {ticket_url}, {customer_name}, {customer_email}, {reply_author}, {reply_content}, {status}, {priority}, {department}, {category}, {site_name}, {site_url}. The chat template only supports {customer_name}, {customer_email}, {chat_url}, {site_name} and {site_url}.', 'kp-support'),
                ),
            );

            // every template we support, keyed by its settings prefix
            $templates = array(
                'email_new_ticket_customer' => __('New Ticket (to the customer)', 'kp-support'),
                'email_new_ticket_agent'    => __('New Ticket (to agents)', 'kp-support'),
                'email_new_reply'           => __('New Reply', 'kp-support'),
                'email_internal_note'       => __('Internal Note', 'kp-support'),
                'email_status_change'       => __('Status Change', 'kp-support'),
                'email_assignment'          => __('Ticket Assigned', 'kp-support'),
                'email_new_chat'            => __('New Chat (to agents)', 'kp-support'),
            );

            // build a subject and body field for each one
            foreach ($templates as $_key => $_label) {

                // the heading so they can tell them apart
                $fields[] = array(
                    'id'    => $_key . '_heading',
                    'type'  => 'heading',
                    'label' => $_label,
                    'tag'   => 'h3',
                );

                // the subject line
                $fields[] = array(
                    'id'    => $_key . '_subject',
                    'type'  => 'text',
                    'label' => __('Subject', 'kp-support'),
                );

                // and the body
                $fields[] = array(
                    'id'    => $_key . '_body',
                    'type'  => 'textarea',
                    'label' => __('Body', 'kp-support'),
                    'rows'  => 6,
                );
            }

            // hand the whole set back
            return $fields;
        }

        /**
         * The accounts tab's fields.
         *
         * @since  1.0.0
         * @access private
         * @return array<int, array<string, mixed>> The field definitions.
         */
        private function accountFields(): array
        {

            // how registration behaves
            return array(
                array(
                    'id'          => 'allow_registration',
                    'type'        => 'switch',
                    'label'       => __('Allow Registration', 'kp-support'),
                    'description' => __('Lets people create a support account from the portal.', 'kp-support'),
                    'default'     => true,
                ),
                array(
                    'id'          => 'auto_login_after_register',
                    'type'        => 'switch',
                    'label'       => __('Log People In After Registering', 'kp-support'),
                    'default'     => true,
                    'conditional' => array(
                        'field'     => 'allow_registration',
                        'value'     => true,
                        'condition' => '==',
                    ),
                ),
                array(
                    'id'          => 'notify_new_user',
                    'type'        => 'switch',
                    'label'       => __('Send The Standard New User Emails', 'kp-support'),
                    'description' => __('WordPress\'s own new account notifications, to both the admin and the new user.', 'kp-support'),
                    'default'     => true,
                    'conditional' => array(
                        'field'     => 'allow_registration',
                        'value'     => true,
                        'condition' => '==',
                    ),
                ),
            );
        }

        /**
         * Build a slug keyed options list out of a taxonomy's terms.
         *
         * @since  1.0.0
         * @access private
         * @param  string $taxonomy The taxonomy to pull.
         * @return array<string, string> The options, keyed by slug.
         */
        private function termOptions(string $taxonomy): array
        {

            // walk the terms and key them by slug
            $options = array();
            foreach (PostTypes::terms($taxonomy) as $_term) {
                $options[$_term->slug] = $_term->name;
            }

            // hand them back
            return $options;
        }
    }
}
