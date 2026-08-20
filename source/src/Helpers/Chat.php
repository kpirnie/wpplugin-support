<?php

/**
 * Chat - Chat creation, messages and state
 *
 * A chat is its own thing until an agent converts it, so everything a chat
 * needs lives here rather than borrowing from the ticket helper. Conversion is
 * the only place the two meet.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

namespace KP\Support\Helpers;

use KP\Support\Modules\Attachments;
use KP\Support\Modules\PostTypes;
use KP\Support\Modules\Replies;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Helpers\Chat')) {

    /**
     * Class Chat
     *
     * Chat level operations.
     *
     * @since 1.0.0
     */
    final class Chat
    {
        /**
         * Meta key holding the visitor's user id.
         *
         * @since 1.0.0
         * @var string
         */
        public const META_VISITOR = '_kpts_chat_visitor';

        /**
         * Meta key holding the handling agent's user id.
         *
         * @since 1.0.0
         * @var string
         */
        public const META_AGENT = '_kpts_chat_agent';

        /**
         * Meta key holding the chat's state.
         *
         * @since 1.0.0
         * @var string
         */
        public const META_STATE = '_kpts_chat_state';

        /**
         * Meta key holding the last activity timestamp.
         *
         * @since 1.0.0
         * @var string
         */
        public const META_LAST_ACTIVITY = '_kpts_chat_last_activity';

        /**
         * Meta key holding the ticket a chat was converted into.
         *
         * @since 1.0.0
         * @var string
         */
        public const META_TICKET = '_kpts_chat_ticket';

        /**
         * Meta key on a ticket pointing back at the chat it came from.
         *
         * @since 1.0.0
         * @var string
         */
        public const META_SOURCE = '_kpts_chat_source';

        /**
         * A chat nobody has picked up yet.
         *
         * @since 1.0.0
         * @var string
         */
        public const STATE_WAITING = 'waiting';

        /**
         * A chat an agent is working.
         *
         * @since 1.0.0
         * @var string
         */
        public const STATE_ACTIVE = 'active';

        /**
         * A chat the customer walked away from.
         *
         * @since 1.0.0
         * @var string
         */
        public const STATE_CLIENT_CLOSED = 'client_closed';

        /**
         * A chat the agent wrapped up.
         *
         * @since 1.0.0
         * @var string
         */
        public const STATE_AGENT_CLOSED = 'agent_closed';

        /**
         * A chat an agent turned into a live ticket.
         *
         * @since 1.0.0
         * @var string
         */
        public const STATE_CONVERTED = 'converted';

        /**
         * Start a chat for somebody.
         *
         * @since  1.0.0
         * @access public
         * @param  int  $user_id   The visitor.
         * @param  bool $reuse_open True to hand back a chat they already have going.
         * @return int|\WP_Error The chat id, or an error.
         */
        public static function create(int $user_id, bool $reuse_open = true): int|\WP_Error
        {

            // if they already have one going, that's the one they get
            $open = self::openForUser($user_id);
            if ($reuse_open && $open > 0) {
                return $open;
            }

            // we need somebody to own it
            if ($user_id < 1) {
                return new \WP_Error('kpts_no_visitor', __('You must be logged in to start a chat.', 'kp-support'));
            }

            // if they already have one going, hand that back instead of stacking them up
            $open = self::openForUser($user_id);
            if ($open > 0) {
                return $open;
            }

            // grab them so we can title the chat
            $user = get_userdata($user_id);
            if (! $user instanceof \WP_User) {
                return new \WP_Error('kpts_bad_user', __('We could not work out who this chat belongs to.', 'kp-support'));
            }

            // drop the chat in
            $chat_id = wp_insert_post(array(
                'post_type'   => PostTypes::CHAT_POST_TYPE,
                'post_status' => 'publish',
                'post_author' => $user_id,
                'post_title'  => sprintf(
                    /* translators: 1: the visitor's display name, 2: the date and time the chat started */
                    __('Chat with %1$s, %2$s', 'kp-support'),
                    $user->display_name,
                    current_time('mysql')
                ),
            ), true);

            // if that failed, say so
            if (is_wp_error($chat_id)) {
                return $chat_id;
            }

            // cast it down
            $chat_id = (int) $chat_id;

            // stamp who it belongs to and where it stands
            update_post_meta($chat_id, self::META_VISITOR, $user_id);
            update_post_meta($chat_id, self::META_STATE, self::STATE_WAITING);
            self::touch($chat_id);

            // let everybody know
            do_action('kpts_chat_started', $chat_id, $user_id);

            // hand the new id back
            return $chat_id;
        }

        /**
         * Find a user's chat that's still going, if they have one.
         *
         * @since  1.0.0
         * @access public
         * @param  int $user_id The visitor's user id.
         * @return int The chat id, or 0 if they haven't got one open.
         */
        public static function openForUser(int $user_id): int
        {

            // nobody, nothing
            if ($user_id < 1) {
                return 0;
            }

            // go looking for one that's still live
            $chats = get_posts(array(
                'post_type'      => PostTypes::CHAT_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'DESC',
                'no_found_rows'  => true,
                'meta_query'     => array(
                    array(
                        'key'   => self::META_VISITOR,
                        'value' => $user_id,
                    ),
                    array(
                        'key'     => self::META_STATE,
                        'value'   => array(self::STATE_WAITING, self::STATE_ACTIVE),
                        'compare' => 'IN',
                    ),
                ),
            ));

            // hand back what we found
            return ! empty($chats) ? (int) $chats[0] : 0;
        }

        /**
         * Make sure an id is actually one of our chats.
         *
         * @since  1.0.0
         * @access public
         * @param  int $chat_id The chat id.
         * @return bool True if it's a real chat.
         */
        public static function exists(int $chat_id): bool
        {

            // it has to be there, and it has to be ours
            return $chat_id > 0 && get_post_type($chat_id) === PostTypes::CHAT_POST_TYPE;
        }

        /**
         * Get a chat's current state.
         *
         * @since  1.0.0
         * @access public
         * @param  int $chat_id The chat id.
         * @return string The state, or an empty string if it isn't a chat.
         */
        public static function state(int $chat_id): string
        {

            // nothing to report on
            if (! self::exists($chat_id)) {
                return '';
            }

            // straight off the meta
            return (string) get_post_meta($chat_id, self::META_STATE, true);
        }

        /**
         * Work out whether a chat is still running.
         *
         * @since  1.0.0
         * @access public
         * @param  int $chat_id The chat id.
         * @return bool True if messages can still be posted to it.
         */
        public static function isLive(int $chat_id): bool
        {

            // only these two states take new messages
            return in_array(self::state($chat_id), array(self::STATE_WAITING, self::STATE_ACTIVE), true);
        }

        /**
         * Move a chat into a new state.
         *
         * @since  1.0.0
         * @access public
         * @param  int    $chat_id The chat id.
         * @param  string $state   The state to move it to.
         * @return bool True if it moved.
         */
        public static function setState(int $chat_id, string $state): bool
        {

            // the states we actually recognise
            $allowed = array(
                self::STATE_WAITING,
                self::STATE_ACTIVE,
                self::STATE_CLIENT_CLOSED,
                self::STATE_AGENT_CLOSED,
                self::STATE_CONVERTED,
            );

            // it has to be a real chat and a state we know
            if (! self::exists($chat_id) || ! in_array($state, $allowed, true)) {
                return false;
            }

            // what it was, so the hook can say
            $previous = self::state($chat_id);

            // nothing changed
            if ($previous === $state) {
                return true;
            }

            // move it
            update_post_meta($chat_id, self::META_STATE, $state);

            // and let everybody know
            do_action('kpts_chat_state_changed', $chat_id, $state, $previous);

            // that worked
            return true;
        }

        /**
         * Get the visitor a chat belongs to.
         *
         * @since  1.0.0
         * @access public
         * @param  int $chat_id The chat id.
         * @return int The visitor's user id.
         */
        public static function visitor(int $chat_id): int
        {
            return (int) get_post_meta($chat_id, self::META_VISITOR, true);
        }

        /**
         * Get the agent handling a chat.
         *
         * @since  1.0.0
         * @access public
         * @param  int $chat_id The chat id.
         * @return int The agent's user id, or 0 if nobody has it.
         */
        public static function agent(int $chat_id): int
        {
            return (int) get_post_meta($chat_id, self::META_AGENT, true);
        }

        /**
         * Hand a chat to an agent.
         *
         * @since  1.0.0
         * @access public
         * @param  int $chat_id  The chat id.
         * @param  int $user_id  The agent's user id, or 0 to unassign.
         * @return bool True if the assignment took.
         */
        public static function setAgent(int $chat_id, int $user_id): bool
        {

            // it has to be a real chat
            if (! self::exists($chat_id)) {
                return false;
            }

            // unassigning drops it back into the queue
            if ($user_id < 1) {
                delete_post_meta($chat_id, self::META_AGENT);
                self::setState($chat_id, self::STATE_WAITING);
                do_action('kpts_chat_assigned', $chat_id, 0);
                return true;
            }

            // whoever we're handing it to has to actually be able to work chats
            if (! user_can($user_id, 'kpts_handle_chats')) {
                return false;
            }

            // hand it over and mark it as being worked
            update_post_meta($chat_id, self::META_AGENT, $user_id);
            self::setState($chat_id, self::STATE_ACTIVE);

            // let everybody know
            do_action('kpts_chat_assigned', $chat_id, $user_id);

            // that worked
            return true;
        }

        /**
         * Get the ticket a chat became, if it became one.
         *
         * @since  1.0.0
         * @access public
         * @param  int $chat_id The chat id.
         * @return int The ticket id, or 0.
         */
        public static function ticketId(int $chat_id): int
        {
            return (int) get_post_meta($chat_id, self::META_TICKET, true);
        }

        /**
         * Get the chat a ticket came from, if it came from one.
         *
         * @since  1.0.0
         * @access public
         * @param  int $ticket_id The ticket id.
         * @return int The chat id, or 0.
         */
        public static function sourceOf(int $ticket_id): int
        {
            return (int) get_post_meta($ticket_id, self::META_SOURCE, true);
        }

        /**
         * Stamp a chat as having just had something happen on it.
         *
         * @since  1.0.0
         * @access public
         * @param  int $chat_id The chat id.
         * @return void
         */
        public static function touch(int $chat_id): void
        {

            // straight onto the meta in GMT so it sorts properly
            update_post_meta($chat_id, self::META_LAST_ACTIVITY, current_time('mysql', 1));
        }

        /**
         * Get the messages on a chat.
         *
         * @since  1.0.0
         * @access public
         * @param  int    $chat_id The chat id.
         * @param  string $since   Only return messages from this GMT datetime on, inclusive.
         * @return array<int, \WP_Comment> The messages, oldest first.
         */
        public static function messages(int $chat_id, string $since = ''): array
        {

            // the base query
            $args = array(
                'post_id' => $chat_id,
                'type'    => PostTypes::CHAT_COMMENT_TYPE,
                'status'  => 'approve',
                'orderby' => 'comment_date_gmt',
                'order'   => 'ASC',
            );

            // if we were given a cutoff, only pull what came after it, and take
            // that second back in again so a message posted in the same second
            // as the one we last saw isn't skipped over for good
            if ($since !== '') {
                $args['date_query'] = array(
                    array(
                        'after'     => $since,
                        'column'    => 'comment_date_gmt',
                        'inclusive' => true,
                    ),
                );
            }

            // run it
            $messages = get_comments($args);

            // and hand back what we got
            return is_array($messages) ? $messages : array();
        }

        /**
         * Get the files hanging off a chat message.
         *
         * @since  1.0.0
         * @access public
         * @param  int $message_id The message id.
         * @return array<int, array<string, mixed>> The file records.
         */
        public static function messageFiles(int $message_id): array
        {

            // straight off the comment meta
            $files = get_comment_meta($message_id, Attachments::META_REPLY_FILES, true);

            // and hand back something loopable either way
            return is_array($files) ? $files : array();
        }

        /**
         * Post a message onto a chat.
         *
         * @since  1.0.0
         * @access public
         * @param  int                        $chat_id     The chat id.
         * @param  int                        $user_id     Who's saying it.
         * @param  string                     $content     What they said.
         * @param  array<int, array<string, mixed>> $attachments Any files that came with it.
         * @return int|\WP_Error The new message id, or an error.
         */
        public static function addMessage(int $chat_id, int $user_id, string $content, array $attachments = array()): int|\WP_Error
        {

            // it has to be a real chat that's still running
            if (! self::isLive($chat_id)) {
                return new \WP_Error('kpts_chat_closed', __('This chat is no longer open.', 'kp-support'));
            }

            // and we need somebody posting it
            $user = get_userdata($user_id);
            if (! $user instanceof \WP_User) {
                return new \WP_Error('kpts_bad_user', __('You must be logged in to send a message.', 'kp-support'));
            }

            // clean the content down to our allowed tags
            $content = trim(wp_kses($content, Replies::allowedTags()));

            // an empty message isn't a message, unless they attached something
            if ($content === '' && empty($attachments)) {
                return new \WP_Error('kpts_empty_message', __('Please enter a message.', 'kp-support'));
            }

            // drop it in, same as replies we skip the moderation pipeline entirely
            $message_id = wp_insert_comment(array(
                'comment_post_ID'      => $chat_id,
                'comment_parent'       => 0,
                'comment_content'      => $content,
                'comment_type'         => PostTypes::CHAT_COMMENT_TYPE,
                'comment_approved'     => 1,
                'user_id'              => $user_id,
                'comment_author'       => $user->display_name,
                'comment_author_email' => $user->user_email,
                'comment_author_IP'    => '',
                'comment_agent'        => '',
                'comment_date'         => current_time('mysql'),
                'comment_date_gmt'     => current_time('mysql', 1),
            ));

            // if that failed, say so
            if (! $message_id) {
                return new \WP_Error('kpts_insert_failed', __('Your message could not be sent. Please try again.', 'kp-support'));
            }

            // cast it down
            $message_id = (int) $message_id;

            // hang any files off the message and index them against the chat
            if (! empty($attachments)) {
                update_comment_meta($message_id, Attachments::META_REPLY_FILES, $attachments);
                Attachments::indexFiles($chat_id, $attachments, $message_id);
            }

            // stamp the chat as active
            self::touch($chat_id);

            // let everybody know
            do_action('kpts_chat_message_added', $message_id, $chat_id, $user_id);

            // hand the new id back
            return $message_id;
        }
    }
}
