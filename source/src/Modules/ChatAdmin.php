<?php

/**
 * ChatAdmin - The agent side chat screen
 *
 * A submenu under Support holding the live queue on the left and whichever
 * chat the agent picked on the right. Assignment and conversion both live in
 * the toolbar above the conversation.
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
if (! class_exists('\KP\Support\Modules\ChatAdmin')) {

    /**
     * Class ChatAdmin
     *
     * The agent facing chat screen.
     *
     * @since 1.0.0
     */
    class ChatAdmin extends AbstractModule
    {
        /**
         * The menu slug our screen lives on.
         *
         * @since 1.0.0
         * @var string
         */
        public const MENU_SLUG = 'kp-support-chat';

        /**
         * Hook this module into WordPress.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function register(): void
        {

            // our screen, before the settings page so it sits above it
            add_action('admin_menu', array($this, 'addMenu'), 5);

            // and its assets
            add_action('admin_enqueue_scripts', array($this, 'enqueueAssets'));
        }

        /**
         * Add the chat screen under the Support menu.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function addMenu(): void
        {

            // no point showing it if chat is switched off
            if (! $this->opt('enable_chat', false)) {
                return;
            }

            // hang it off the ticket post type's menu
            add_submenu_page(
                'edit.php?post_type=' . PostTypes::POST_TYPE,
                __('Live Chat', 'kp-support'),
                __('Live Chat', 'kp-support'),
                'kpts_handle_chats',
                self::MENU_SLUG,
                array($this, 'renderScreen')
            );
        }

        /**
         * Work out whether we're on our screen.
         *
         * @since  1.0.0
         * @access private
         * @param  string $hook The current admin page hook.
         * @return bool True if it's ours.
         */
        private function isChatScreen(string $hook): bool
        {

            // the hook the submenu page ends up under
            return $hook === PostTypes::POST_TYPE . '_page_' . self::MENU_SLUG;
        }

        /**
         * Load the chat assets on our screen.
         *
         * @since  1.0.0
         * @access public
         * @param  string $hook The current admin page hook.
         * @return void
         */
        public function enqueueAssets($hook): void
        {

            // only on our screen, and only for somebody who works chats
            if (! $this->isChatScreen((string) $hook) || ! ChatAccess::isChatAgent()) {
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

            // hand the script everything it needs, on the agent nonce
            wp_localize_script('kpts-chat', 'kptsChat', array(
                'ajaxUrl'       => admin_url('admin-ajax.php'),
                'nonce'         => wp_create_nonce(ChatAjax::NONCE_AGENT),
                'chatId'        => 0,
                'isAgent'       => true,
                'canAssign'     => current_user_can('kpts_assign_chats'),
                'canConvert'    => current_user_can('kpts_convert_chats'),
                'pollInterval'  => max(5, (int) $this->opt('poll_interval', 10)) * 1000,
                'queueInterval' => max(5, (int) $this->opt('poll_interval', 10)) * 1000,
                'allowFiles'    => (bool) $this->opt('allow_attachments', true),
                'maxFiles'      => (int) $this->opt('max_attachments', 5),
                'maxFileSize'   => Attachments::maxSize(),
                'strings'       => array(
                    'sending'        => __('Sending...', 'kp-support'),
                    'sendFailed'     => __('Your message could not be sent. Please try again.', 'kp-support'),
                    'emptyMessage'   => __('Please enter a message.', 'kp-support'),
                    'closed'         => __('This chat has been closed.', 'kp-support'),
                    'expired'        => __('Your session expired. Please reload the page.', 'kp-support'),
                    'waiting'        => __('Nobody has picked this chat up yet.', 'kp-support'),
                    /* translators: %s: the agent's display name */
                    'agentJoined'    => __('Handled by %s', 'kp-support'),
                    'noChats'        => __('No chats waiting.', 'kp-support'),
                    'pickChat'       => __('Pick a chat from the queue to get started.', 'kp-support'),
                    'confirmClose'   => __('Close this chat out? It will be archived as a closed ticket.', 'kp-support'),
                    'confirmConvert' => __('Convert this chat into an open ticket? The chat will end.', 'kp-support'),
                    'assigned'       => __('Chat assigned.', 'kp-support'),
                    'assignFailed'   => __('That chat could not be assigned.', 'kp-support'),
                    'converting'     => __('Converting...', 'kp-support'),
                    'tooManyFiles'   => __('Too many files attached.', 'kp-support'),
                    'fileTooBig'     => __('One of those files is too large.', 'kp-support'),
                ),
            ));
        }

        /**
         * Render the chat screen.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function renderScreen(): void
        {

            // belt and braces, the menu capability already covers this
            if (! ChatAccess::isChatAgent()) {
                wp_die(esc_html__('You are not allowed to work chats.', 'kp-support'));
            }

            // and out it goes
            Template::render('chat-admin', array(
                'agents'      => $this->agentOptions(),
                'can_assign'  => current_user_can('kpts_assign_chats'),
                'can_convert' => current_user_can('kpts_convert_chats'),
                'allow_files' => (bool) $this->opt('allow_attachments', true),
            ));
        }

        /**
         * Build the list of agents a chat can be handed to.
         *
         * @since  1.0.0
         * @access private
         * @return array<int, string> The agents, keyed by user id.
         */
        private function agentOptions(): array
        {

            // anybody who can work chats is a candidate
            $users = get_users(array(
                'capability' => 'kpts_handle_chats',
                'orderby'    => 'display_name',
                'order'      => 'ASC',
                'fields'     => array('ID', 'display_name'),
            ));

            // key them by id
            $agents = array();
            foreach ($users as $_user) {
                $agents[(int) $_user->ID] = $_user->display_name;
            }

            // hand them back
            return $agents;
        }
    }
}
