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
use KP\Support\Helpers\ChatAccess;
use KP\Support\Helpers\MailLog;
use KP\Support\Modules\AbstractModule;
use KP\Support\Modules\PostTypes;
use KP\Support\Modules\Smtp;

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

            // and handle the mail log being cleared out
            add_action('admin_post_kpts_clear_mail_log', array($this, 'clearMailLog'));
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
                    'smtp'          => array(
                        'title'    => __('SMTP', 'kp-support'),
                        'sections' => array('smtp' => array(
                            'title'       => __('Outgoing Mail', 'kp-support'),
                            'description' => __('These settings only apply to the mail this plugin sends. Everything else on the site goes out however it already does. The From name and address are set on the Notifications tab and are reused here. Note that the password is included in a settings export.', 'kp-support'),
                            'fields'      => $this->smtpFields(),
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
            $fields = array(
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
                    'id'          => 'chat_active_label',
                    'type'        => 'text',
                    'title'       => __('Active Chat Label', 'kp-support'),
                    'description' => __('The text shown on the launcher while a chat is already going.', 'kp-support'),
                    'default'     => __('Chat in progress...', 'kp-support'),
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
                    'id'          => 'chat_presence_window',
                    'type'        => 'number',
                    'label'       => __('Presence Window', 'kp-support'),
                    'description' => __('How many minutes an agent counts as online for after their last queue poll.', 'kp-support'),
                    'default'     => 5,
                    'min'         => 1,
                    'max'         => 60,
                ),
                array(
                    'id'          => 'chat_offline_message',
                    'type'        => 'textarea',
                    'label'       => __('Offline Message', 'kp-support'),
                    'description' => __('Shown above the leave-a-message form when nobody is online.', 'kp-support'),
                ),
                array(
                    'id'          => 'chat_closed_message',
                    'type'        => 'textarea',
                    'label'       => __('Closed Message', 'kp-support'),
                    'description' => __('Shown above the leave-a-message form outside business hours.', 'kp-support'),
                ),
                array(
                    'id'          => 'chat_hours_enable',
                    'type'        => 'switch',
                    'label'       => __('Enforce Business Hours', 'kp-support'),
                    'description' => __('Hours take precedence over presence, so chat stays closed outside them even with an agent in the queue.', 'kp-support'),
                    'default'     => false,
                ),
                array(
                    'id'          => 'chat_abandon_hours',
                    'type'        => 'number',
                    'label'       => __('Abandon After', 'kp-support'),
                    'description' => __('Hours of silence before a chat is closed out and archived as a ticket.', 'kp-support'),
                    'default'     => 24,
                    'min'         => 1,
                    'max'         => 168,
                ),
                array(
                    'id'          => 'chat_waiting_timeout',
                    'type'        => 'number',
                    'label'       => __('Waiting Timeout', 'kp-support'),
                    'description' => __('Minutes a customer waits before being offered a ticket instead. Zero switches the offer off.', 'kp-support'),
                    'default'     => 5,
                    'min'         => 0,
                    'max'         => 60,
                ),
                array(
                    'id'          => 'status_after_chat_convert',
                    'type'        => 'select',
                    'title'       => __('Status After Convert', 'kp-support'),
                    'description' => __('The status a ticket gets when an agent converts a live chat.', 'kp-support'),
                    'options'     => $this->termOptions(PostTypes::TAX_STATUS),
                    'default'     => 'open',
                ),
                array(
                    'id'          => 'status_after_chat_close',
                    'type'        => 'select',
                    'title'       => __('Status After Close', 'kp-support'),
                    'description' => __('The status a ticket gets when either side closes the chat out.', 'kp-support'),
                    'options'     => $this->termOptions(PostTypes::TAX_STATUS),
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

            // and a row of fields per day
            foreach ($this->dayLabels() as $_day => $_label) {

                // is the day open at all
                $fields[] = array(
                    'id'          => 'chat_hours_' . $_day . '_open',
                    'type'        => 'switch',
                    /* translators: %s: the day of the week. */
                    'label'       => sprintf(__('%s Open', 'kp-support'), $_label),
                    'conditional' => array(
                        'field'     => 'chat_hours_enable',
                        'value'     => true,
                        'condition' => '==',
                    ),
                );

                // when it opens
                $fields[] = array(
                    'id'          => 'chat_hours_' . $_day . '_from',
                    'type'        => 'time',
                    /* translators: %s: the day of the week. */
                    'label'       => sprintf(__('%s From', 'kp-support'), $_label),
                    'default'     => '09:00',
                    'conditional' => array(
                        'field'     => 'chat_hours_' . $_day . '_open',
                        'value'     => true,
                        'condition' => '==',
                    ),
                );

                // and when it shuts
                $fields[] = array(
                    'id'          => 'chat_hours_' . $_day . '_to',
                    'type'        => 'time',
                    /* translators: %s: the day of the week. */
                    'label'       => sprintf(__('%s To', 'kp-support'), $_label),
                    'default'     => '17:00',
                    'conditional' => array(
                        'field'     => 'chat_hours_' . $_day . '_open',
                        'value'     => true,
                        'condition' => '==',
                    ),
                );
            }

            return $fields;
        }

        /**
         * The days of the week, keyed the way the settings are.
         *
         * @since  1.0.21
         * @access private
         * @return array<string, string> The labels, keyed by day.
         */
        private function dayLabels(): array
        {

            // pull them from WordPress so they're already translated
            $days = array();

            // walk our own order
            foreach (ChatAccess::DAYS as $_index => $_day) {
                $days[$_day] = $GLOBALS['wp_locale']->get_weekday($_index);
            }

            return $days;
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
                array(
                    'id'      => 'mail_log_heading',
                    'type'    => 'heading',
                    'label'   => __('Recent Email Activity', 'kp-support'),
                    'tag'     => 'h3',
                ),
                array(
                    'id'      => 'mail_log',
                    'type'    => 'html',
                    'content' => $this->mailLogTable(),
                ),
            );
        }

        /**
         * The SMTP tab's fields.
         *
         * @since  1.0.21
         * @access private
         * @return array<int, array<string, mixed>> The field definitions.
         */
        private function smtpFields(): array
        {

            // start with whatever we need to warn them about
            $fields = array();

            // if there's already a mailer running, say so
            $mailer = Smtp::activeMailer();
            if ($mailer !== '') {
                $fields[] = array(
                    'id'           => 'smtp_mailer_notice',
                    'type'         => 'message',
                    'message_type' => 'warning',
                    'content'      => sprintf(
                        /* translators: %s: the name of the detected mailer plugin. */
                        esc_html__('%s is active on this site. Our SMTP settings only ever apply to this plugin\'s own mail, so leaving this off will let that plugin keep handling everything.', 'kp-support'),
                        esc_html($mailer)
                    ),
                );
            }

            // the connection itself
            $fields[] = array(
                'id'          => 'smtp_enable',
                'type'        => 'switch',
                'label'       => __('Enable SMTP', 'kp-support'),
                'description' => __('Off means our mail falls straight through to whatever WordPress already uses.', 'kp-support'),
                'default'     => false,
            );

            $fields[] = array(
                'id'          => 'smtp_host',
                'type'        => 'text',
                'label'       => __('Host', 'kp-support'),
                'conditional' => array(
                    'field'     => 'smtp_enable',
                    'value'     => true,
                    'condition' => '==',
                ),
            );

            $fields[] = array(
                'id'          => 'smtp_port',
                'type'        => 'number',
                'label'       => __('Port', 'kp-support'),
                'default'     => 587,
                'min'         => 1,
                'max'         => 65535,
                'conditional' => array(
                    'field'     => 'smtp_enable',
                    'value'     => true,
                    'condition' => '==',
                ),
            );

            $fields[] = array(
                'id'          => 'smtp_encryption',
                'type'        => 'select',
                'label'       => __('Encryption', 'kp-support'),
                'options'     => array(
                    'none' => __('None', 'kp-support'),
                    'ssl'  => __('SSL', 'kp-support'),
                    'tls'  => __('TLS', 'kp-support'),
                ),
                'default'     => 'tls',
                'conditional' => array(
                    'field'     => 'smtp_enable',
                    'value'     => true,
                    'condition' => '==',
                ),
            );

            $fields[] = array(
                'id'          => 'smtp_auth',
                'type'        => 'switch',
                'label'       => __('Authenticate', 'kp-support'),
                'default'     => true,
                'conditional' => array(
                    'field'     => 'smtp_enable',
                    'value'     => true,
                    'condition' => '==',
                ),
            );

            $fields[] = array(
                'id'          => 'smtp_username',
                'type'        => 'text',
                'label'       => __('Username', 'kp-support'),
                'conditional' => array(
                    'field'     => 'smtp_auth',
                    'value'     => true,
                    'condition' => '==',
                ),
            );

            $fields[] = array(
                'id'          => 'smtp_password',
                'type'        => 'password',
                'label'       => __('Password', 'kp-support'),
                'conditional' => array(
                    'field'     => 'smtp_auth',
                    'value'     => true,
                    'condition' => '==',
                ),
            );

            // and the test button
            $fields[] = array(
                'id'      => 'smtp_test',
                'type'    => 'html',
                'content' => $this->smtpTestButton(),
            );

            return $fields;
        }

        /**
         * Build the test email button, plus the result of the last one.
         *
         * @since  1.0.21
         * @access private
         * @return string The markup.
         */
        private function smtpTestButton(): string
        {

            // where it's going, so they know before they click
            $user = wp_get_current_user();
            $address = ($user instanceof \WP_User) ? $user->user_email : '';

            // the button itself
            $html = sprintf(
                '<p><a href="%1$s" class="button">%2$s</a></p>',
                esc_url(wp_nonce_url(
                    admin_url('admin-post.php?action=kpts_smtp_test'),
                    'kpts_smtp_test'
                )),
                esc_html__('Send A Test Email', 'kp-support')
            );

            // tell them where it lands
            $html .= sprintf(
                '<p class="description">%s</p>',
                sprintf(
                    /* translators: %s: the current user's email address. */
                    esc_html__('Sends to %s. The outcome is recorded in the log on the Notifications tab.', 'kp-support'),
                    esc_html($address)
                )
            );

            // whatever the last one did, if we've just come back from it
            $result = isset($_GET['kpts_test']) ? sanitize_key(wp_unslash($_GET['kpts_test'])) : '';

            // the messages we're willing to show
            $messages = array(
                'sent'       => array('success', __('The test email was handed off without an error.', 'kp-support')),
                'failed'     => array('error', __('The test email failed. Check the log on the Notifications tab for the reason.', 'kp-support')),
                'no-address' => array('error', __('Your account has no valid email address to send to.', 'kp-support')),
            );

            // show it if we recognise it
            if (isset($messages[$result])) {
                $html .= sprintf(
                    '<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
                    esc_attr($messages[$result][0]),
                    esc_html($messages[$result][1])
                );
            }

            return $html;
        }

        /**
         * Build the mail log table out for the notifications tab.
         *
         * @since  1.0.21
         * @access private
         * @return string The table markup.
         */
        private function mailLogTable(): string
        {

            // what we've got recorded
            $entries = MailLog::entries();

            // nothing yet
            if (empty($entries)) {
                return sprintf('<p>%s</p>', esc_html__('Nothing sent yet.', 'kp-support'));
            }

            // how we label each outcome
            $labels = array(
                'sent'    => __('Sent', 'kp-support'),
                'failed'  => __('Failed', 'kp-support'),
                'skipped' => __('Skipped', 'kp-support'),
            );

            // head the table up
            $html = '<table class="widefat striped"><thead><tr>'
                . '<th>' . esc_html__('When', 'kp-support') . '</th>'
                . '<th>' . esc_html__('Outcome', 'kp-support') . '</th>'
                . '<th>' . esc_html__('To', 'kp-support') . '</th>'
                . '<th>' . esc_html__('Subject', 'kp-support') . '</th>'
                . '<th>' . esc_html__('Detail', 'kp-support') . '</th>'
                . '</tr></thead><tbody>';

            // and walk the entries in
            foreach ($entries as $_entry) {

                // pull the outcome out so we can label it
                $outcome = (string) ($_entry['outcome'] ?? '');

                // build the row
                $html .= '<tr>'
                    . '<td>' . esc_html(wp_date('Y-m-d H:i:s', (int) ($_entry['time'] ?? 0))) . '</td>'
                    . '<td>' . esc_html($labels[$outcome] ?? $outcome) . '</td>'
                    . '<td>' . esc_html((string) ($_entry['recipient'] ?? '')) . '</td>'
                    . '<td>' . esc_html((string) ($_entry['subject'] ?? '')) . '</td>'
                    . '<td>' . esc_html((string) ($_entry['error'] ?? '')) . '</td>'
                    . '</tr>';
            }

            // close it out and hang the clear button off the bottom
            $html .= '</tbody></table>';
            $html .= sprintf(
                '<p><a href="%1$s" class="button">%2$s</a></p>',
                esc_url(wp_nonce_url(
                    admin_url('admin-post.php?action=kpts_clear_mail_log'),
                    'kpts_clear_mail_log'
                )),
                esc_html__('Clear Log', 'kp-support')
            );

            return $html;
        }

        /**
         * Empty the mail log out and drop them back on the settings screen.
         *
         * @since  1.0.21
         * @access public
         * @return void
         */
        public function clearMailLog(): void
        {

            // they have to be allowed to be in here
            if (! current_user_can('kpts_manage_settings')) {
                wp_die(esc_html__('You are not allowed to do that.', 'kp-support'));
            }

            // and it has to have come from our button
            check_admin_referer('kpts_clear_mail_log');

            // wipe it
            MailLog::clear();

            // back to where they were
            wp_safe_redirect(admin_url(
                'edit.php?post_type=' . PostTypes::POST_TYPE
                    . '&page=kp-support-settings&tab=notifications'
            ));
            exit;
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
