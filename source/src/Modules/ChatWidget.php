<?php

/**
 * ChatWidget - The front end chat launcher and panel
 *
 * Drops a corner docked launcher into the footer for logged in customers. The
 * panel is fixed positioned and non modal, nothing is overlaid and the page
 * underneath stays scrollable and clickable the whole time.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

namespace KP\Support\Modules;

use KP\Support\Helpers\Chat;
use KP\Support\Helpers\ChatAccess;
use KP\Support\Helpers\Template;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Modules\ChatWidget')) {

    /**
     * Class ChatWidget
     *
     * The customer facing chat widget.
     *
     * @since 1.0.0
     */
    class ChatWidget extends AbstractModule
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

            // our assets, late so we know what the page ended up being
            add_action('wp_enqueue_scripts', array($this, 'enqueueAssets'), 20);

            // and the widget itself, right at the end of the body
            add_action('wp_footer', array($this, 'renderWidget'), 100);
        }

        /**
         * Work out whether the widget belongs on this request at all.
         *
         * @since  1.0.0
         * @access private
         * @return bool True if we should be rendering.
         */
        private function shouldRender(): bool
        {

            // never in the admin, and never on a feed or a REST request
            if (is_admin() || is_feed() || wp_doing_ajax()) {
                return false;
            }

            // the login and registration screens are not the place for it
            if (function_exists('is_login') && is_login()) {
                return false;
            }

            // and then it comes down to the setting and their capability
            return ChatAccess::canStart();
        }

        /**
         * Load the chat assets.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function enqueueAssets(): void
        {

            // nothing to load if the widget isn't going out
            if (! $this->shouldRender()) {
                return;
            }

            // our styles
            wp_enqueue_style(
                'kpts-chat',
                KP_SUPPORT_URL . 'assets/css/chat.min.css',
                array(),
                KP_SUPPORT_VERSION
            );

            // and our script
            wp_enqueue_script(
                'kpts-chat',
                KP_SUPPORT_URL . 'assets/js/chat.min.js',
                array(),
                KP_SUPPORT_VERSION,
                true
            );

            // whatever chat they already have going, if any
            $chat_id = Chat::openForUser(get_current_user_id());

            // hand the script everything it needs
            wp_localize_script('kpts-chat', 'kptsChat', array(
                'ajaxUrl'      => admin_url('admin-ajax.php'),
                'nonce'        => wp_create_nonce(ChatAjax::NONCE_VISITOR),
                'chatId'       => $chat_id,
                'isAgent'      => false,
                'pollInterval' => max(5, (int) $this->opt('poll_interval', 10)) * 1000,
                'allowFiles'   => (bool) $this->opt('allow_attachments', true),
                'maxFiles'     => (int) $this->opt('max_attachments', 5),
                'maxFileSize'  => Attachments::maxSize(),
                'waitingTimeout' => (int) $this->opt('chat_waiting_timeout', 5),
                'strings'      => array(
                    'sending'      => __('Sending...', 'kp-support'),
                    'sendFailed'   => __('Your message could not be sent. Please try again.', 'kp-support'),
                    'emptyMessage' => __('Please enter a message.', 'kp-support'),
                    'starting'     => __('Starting chat...', 'kp-support'),
                    'startFailed'  => __('The chat could not be started. Please try again.', 'kp-support'),
                    'confirmClose' => __('End this chat? A copy will be saved to your tickets.', 'kp-support'),
                    'closed'       => __('This chat has been closed.', 'kp-support'),
                    'expired'      => __('Your session expired. Please reload the page.', 'kp-support'),
                    'waiting'      => __('Waiting for an agent...', 'kp-support'),
                    /* translators: %s: the agent's display name */
                    'agentJoined'  => __('%s has joined the chat.', 'kp-support'),
                    'viewTicket'   => __('View the saved ticket', 'kp-support'),
                    'tooManyFiles' => __('Too many files attached.', 'kp-support'),
                    'fileTooBig'   => __('One of those files is too large.', 'kp-support'),
                    'waitingTooLong' => __('Nobody has picked this up yet. Would you like to turn it into a ticket instead?', 'kp-support'),
                    'makeTicket'     => __('Turn into a ticket', 'kp-support'),
                ),
            ));
        }

        /**
         * Render the launcher and the panel.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function renderWidget(): void
        {

            // nothing to render
            if (! $this->shouldRender()) {
                return;
            }

            // whatever chat they already have going, if any
            $chat_id = Chat::openForUser(get_current_user_id());

            // the corner it sits in, validated against what we actually support
            $position = (string) $this->opt('chat_position', 'bottom-right');
            if (! in_array($position, array('bottom-right', 'bottom-left', 'top-right', 'top-left'), true)) {
                $position = 'bottom-right';
            }

            // is anybody around to take it
            $online = ChatAccess::chatAvailable();

            // and what we say when they aren't
            $offline_message = ChatAccess::withinBusinessHours()
                ? (string) $this->opt('chat_offline_message', __('Nobody is available right now. Leave us a message and we will get back to you.', 'kp-support'))
                : (string) $this->opt('chat_closed_message', __('We are closed right now. Leave us a message and we will get back to you.', 'kp-support'));

            // and out it goes
            Template::render('chat-panel', array(
                'chat_id'  => $chat_id,
                'position' => $position,
                'label'    => (string) $this->opt('chat_label', __('Need help?', 'kp-support')),
                'messages' => ($chat_id > 0) ? Chat::messages($chat_id) : array(),
                'visitor'  => get_current_user_id(),
                'state'    => ($chat_id > 0) ? Chat::state($chat_id) : '',
                'online'   => $online,
                'offline_message' => $offline_message,
            ));
        }
    }
}
