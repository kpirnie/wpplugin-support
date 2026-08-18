<?php

/**
 * ChatAccess - Chat access control helpers
 *
 * Every "can this person see or touch this chat" decision runs through here,
 * so there's exactly one place to audit. Same shape as the ticket side, and
 * deliberately separate from it, a chat is not a ticket until it's converted.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

namespace KP\Support\Helpers;

use KP\Support\Plugin;
use KP\Support\Modules\PostTypes;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Helpers\ChatAccess')) {

    /**
     * Class ChatAccess
     *
     * Centralized access checks for chats.
     *
     * @since 1.0.0
     */
    final class ChatAccess
    {
        /**
         * Work out whether a user can work chats at all.
         *
         * @since  1.0.0
         * @access public
         * @param  int|null $user_id The user to check, defaults to the current user.
         * @return bool True if they're a chat agent.
         */
        public static function isChatAgent(?int $user_id = null): bool
        {

            // default to whoever is logged in
            $user_id = $user_id ?? get_current_user_id();

            // nobody logged in means definitely not
            if ($user_id < 1) {
                return false;
            }

            // this is the capability that puts somebody in the queue
            return user_can($user_id, 'kpts_handle_chats');
        }

        /**
         * Work out whether an agent is allowed into a chat's department.
         *
         * A chat has no department of its own, so we test the agent against the
         * department chats are configured to land in. An agent with no
         * departments assigned covers everything, same as tickets.
         *
         * @since  1.0.0
         * @access public
         * @param  int $user_id The agent's user id.
         * @return bool True if the agent covers chats.
         */
        public static function agentCoversChats(int $user_id): bool
        {

            // if we're not restricting agents by department, they're all good
            if (! Plugin::opt('restrict_agents_by_department', false)) {
                return true;
            }

            // managers always see everything
            if (Access::isManager($user_id)) {
                return true;
            }

            // what departments does this agent cover
            $agent_departments = Access::agentDepartments($user_id);

            // no departments assigned means no restriction for them
            if (empty($agent_departments)) {
                return true;
            }

            // where are chats configured to land
            $chat_department = Ticket::termIdBySlug(PostTypes::TAX_DEPARTMENT, (string) Plugin::opt('chat_department', ''));

            // an unconfigured chat department is visible to everyone so nothing gets stranded
            if ($chat_department < 1) {
                return true;
            }

            // they're covered if it's one of theirs
            return in_array($chat_department, $agent_departments, true);
        }

        /**
         * Work out whether a user can see a chat at all.
         *
         * @since  1.0.0
         * @access public
         * @param  int      $chat_id The chat id.
         * @param  int|null $user_id The user to check, defaults to the current user.
         * @return bool True if they're allowed to see it.
         */
        public static function canView(int $chat_id, ?int $user_id = null): bool
        {

            // default to whoever is logged in
            $user_id = $user_id ?? get_current_user_id();

            // nobody logged in gets to see anything
            if ($user_id < 1 || ! Chat::exists($chat_id)) {
                return false;
            }

            // the visitor always gets their own chat, running or archived
            if (Chat::visitor($chat_id) === $user_id) {
                return true;
            }

            // the assigned agent always gets it
            if (Chat::agent($chat_id) === $user_id) {
                return true;
            }

            // and any other chat agent, as long as they cover it
            $allowed = self::isChatAgent($user_id) && self::agentCoversChats($user_id);

            // let people hook in and adjust the decision
            return (bool) apply_filters('kpts_can_view_chat', $allowed, $chat_id, $user_id);
        }

        /**
         * Work out whether a user can post a message to a chat.
         *
         * @since  1.0.0
         * @access public
         * @param  int      $chat_id The chat id.
         * @param  int|null $user_id The user to check, defaults to the current user.
         * @return bool True if they're allowed to say something.
         */
        public static function canPost(int $chat_id, ?int $user_id = null): bool
        {

            // default to whoever is logged in
            $user_id = $user_id ?? get_current_user_id();

            // they have to be able to see it before anything else
            if (! self::canView($chat_id, $user_id)) {
                return false;
            }

            // and it has to still be running, nobody posts into an archive
            if (! Chat::isLive($chat_id)) {
                return false;
            }

            // the visitor needs the capability to have started one in the first place
            if (Chat::visitor($chat_id) === $user_id && ! user_can($user_id, 'kpts_start_chat')) {
                return false;
            }

            // let people hook in and adjust the decision
            return (bool) apply_filters('kpts_can_post_chat', true, $chat_id, $user_id);
        }

        /**
         * Work out whether a user can manage a chat.
         *
         * That's assigning it, converting it, and closing it as the agent.
         *
         * @since  1.0.0
         * @access public
         * @param  int      $chat_id The chat id.
         * @param  int|null $user_id The user to check, defaults to the current user.
         * @return bool True if they can manage the chat.
         */
        public static function canManage(int $chat_id, ?int $user_id = null): bool
        {

            // default to whoever is logged in
            $user_id = $user_id ?? get_current_user_id();

            // has to be a chat agent, and has to be able to see this one
            return self::isChatAgent($user_id) && self::canView($chat_id, $user_id);
        }

        /**
         * Work out whether a user can hand a chat to somebody else.
         *
         * @since  1.0.0
         * @access public
         * @param  int      $chat_id The chat id.
         * @param  int|null $user_id The user to check, defaults to the current user.
         * @return bool True if they can reassign it.
         */
        public static function canAssign(int $chat_id, ?int $user_id = null): bool
        {

            // default to whoever is logged in
            $user_id = $user_id ?? get_current_user_id();

            // the manage check plus the assignment capability on top
            return self::canManage($chat_id, $user_id) && user_can($user_id, 'kpts_assign_chats');
        }

        /**
         * Work out whether a user can turn a chat into a ticket.
         *
         * @since  1.0.0
         * @access public
         * @param  int      $chat_id The chat id.
         * @param  int|null $user_id The user to check, defaults to the current user.
         * @return bool True if they can convert it.
         */
        public static function canConvert(int $chat_id, ?int $user_id = null): bool
        {

            // default to whoever is logged in
            $user_id = $user_id ?? get_current_user_id();

            // they need the capability and the chat has to still be running
            if (! user_can($user_id, 'kpts_convert_chats') || ! Chat::isLive($chat_id)) {
                return false;
            }

            // and they have to be able to manage it
            return self::canManage($chat_id, $user_id);
        }

        /**
         * Work out whether a user can pull the transcript of a chat.
         *
         * @since  1.0.0
         * @access public
         * @param  int      $chat_id The chat id.
         * @param  int|null $user_id The user to check, defaults to the current user.
         * @return bool True if they can have the transcript.
         */
        public static function canTranscript(int $chat_id, ?int $user_id = null): bool
        {

            // default to whoever is logged in
            $user_id = $user_id ?? get_current_user_id();

            // if they can see the chat itself, they can have its transcript
            if (self::canView($chat_id, $user_id)) {
                return true;
            }

            // otherwise fall through to the ticket it became, which brings in
            // participants and covering agents who were never on the chat
            $ticket_id = Chat::ticketId($chat_id);

            // nothing to fall through to
            if ($ticket_id < 1 || get_post_type($ticket_id) !== PostTypes::POST_TYPE) {
                return false;
            }

            // and the ticket's own rules decide it
            return Access::canViewTicket($ticket_id, $user_id);
        }

        /**
         * Work out whether somebody is allowed to start a chat right now.
         *
         * @since  1.0.0
         * @access public
         * @param  int|null $user_id The user to check, defaults to the current user.
         * @return bool True if they can open one.
         */
        public static function canStart(?int $user_id = null): bool
        {

            // default to whoever is logged in
            $user_id = $user_id ?? get_current_user_id();

            // nobody logged in, no chance
            if ($user_id < 1) {
                return false;
            }

            // chat has to be switched on, and they need the capability
            if (! Plugin::opt('enable_chat', false) || ! user_can($user_id, 'kpts_start_chat')) {
                return false;
            }

            // let people hook in and adjust the decision
            return (bool) apply_filters('kpts_can_start_chat', true, $user_id);
        }
    }
}
