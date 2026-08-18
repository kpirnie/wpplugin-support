<?php

/**
 * ChatConvert - Turning a chat into a ticket
 *
 * Both paths land here, the agent hitting convert and either side closing the
 * chat out. The only difference between them is the state the chat ends in and
 * the status the ticket starts in.
 *
 * The first message becomes the ticket's opening post, everything after it
 * becomes a reply, and the chat post itself is kept as the archive record.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

namespace KP\Support\Helpers;

use KP\Support\Plugin;
use KP\Support\Modules\Attachments;
use KP\Support\Modules\PostTypes;
use KP\Support\Modules\Replies;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Helpers\ChatConvert')) {

    /**
     * Class ChatConvert
     *
     * Converts chats into tickets and builds their transcripts.
     *
     * @since 1.0.0
     */
    final class ChatConvert
    {
        /**
         * Turn a chat into a ticket.
         *
         * @since  1.0.0
         * @access public
         * @param  int                  $chat_id The chat id.
         * @param  array<string, mixed> $args    How we're converting it.
         * @return int|\WP_Error The new ticket id, or an error.
         */
        public static function toTicket(int $chat_id, array $args = array()): int|\WP_Error
        {

            // what we're working with
            $args = wp_parse_args($args, array(
                'state'  => Chat::STATE_CONVERTED,
                'status' => '',
                'agent'  => 0,
            ));

            // it has to be a real chat
            if (! Chat::exists($chat_id)) {
                return new \WP_Error('kpts_bad_chat', __('That chat could not be found.', 'kp-support'));
            }

            // and one we haven't already converted, this is what stops a double
            // click turning a single chat into two tickets
            $existing = Chat::ticketId($chat_id);
            if ($existing > 0) {
                return $existing;
            }

            // we need the visitor to own the ticket
            $visitor = Chat::visitor($chat_id);
            if ($visitor < 1) {
                return new \WP_Error('kpts_no_visitor', __('That chat has no customer attached to it.', 'kp-support'));
            }

            // pull the whole conversation
            $messages = Chat::messages($chat_id);

            // nothing was ever said, there's nothing worth keeping
            if (empty($messages)) {
                Chat::setState($chat_id, (string) $args['state']);
                return new \WP_Error('kpts_empty_chat', __('That chat has no messages to convert.', 'kp-support'));
            }

            // the first message is the opening post, the rest are replies
            $opening = array_shift($messages);

            // build the ticket
            $ticket_id = Ticket::create(array(
                'subject'    => self::buildTitle($opening->comment_content),
                'message'    => $opening->comment_content,
                'requester'  => $visitor,
                'department' => Ticket::termIdBySlug(PostTypes::TAX_DEPARTMENT, (string) Plugin::opt('chat_department', '')),
            ));

            // if that failed, hand the error back up and leave the chat alone
            if (is_wp_error($ticket_id)) {
                return $ticket_id;
            }

            // cast it down
            $ticket_id = (int) $ticket_id;

            // the opening post keeps the time it was actually said
            wp_update_post(array(
                'ID'            => $ticket_id,
                'post_date'     => $opening->comment_date,
                'post_date_gmt' => $opening->comment_date_gmt,
            ));

            // move any files that came in on the opening message
            $opening_files = get_comment_meta((int) $opening->comment_ID, Attachments::META_REPLY_FILES, true);
            if (is_array($opening_files) && ! empty($opening_files)) {
                update_post_meta($ticket_id, Ticket::META_ATTACHMENTS, $opening_files);
            }

            // the opening message itself is now the ticket body, so it goes
            wp_delete_comment((int) $opening->comment_ID, true);

            // and every remaining message becomes a reply on the ticket
            self::moveMessages($messages, $ticket_id, $visitor);

            // point the two records at each other
            update_post_meta($chat_id, Chat::META_TICKET, $ticket_id);
            update_post_meta($ticket_id, Chat::META_SOURCE, $chat_id);

            // hand the ticket to whoever was working the chat
            $agent = absint($args['agent']) > 0 ? absint($args['agent']) : Chat::agent($chat_id);
            if ($agent > 0) {
                Ticket::setAssignee($ticket_id, $agent);
            }

            // drop it into the status this conversion path calls for
            $status = (string) $args['status'];
            if ($status !== '') {
                Ticket::setStatusBySlug($ticket_id, $status);
            }

            // refresh the cached reply count now everything has moved
            update_post_meta($ticket_id, Ticket::META_REPLY_COUNT, Replies::countForTicket($ticket_id));

            // and close the chat out
            Chat::setState($chat_id, (string) $args['state']);
            Chat::touch($chat_id);

            // let everybody know
            do_action('kpts_chat_converted', $chat_id, $ticket_id, (string) $args['state']);

            // hand the new ticket back
            return $ticket_id;
        }

        /**
         * Re-point a set of chat messages onto a ticket as replies.
         *
         * @since  1.0.0
         * @access private
         * @param  array<int, \WP_Comment> $messages  The messages to move.
         * @param  int                     $ticket_id The ticket they're moving to.
         * @param  int                     $visitor   The customer's user id.
         * @return void
         */
        private static function moveMessages(array $messages, int $ticket_id, int $visitor): void
        {

            // walk each one
            foreach ($messages as $_message) {

                // cast the id down
                $_message_id = (int) $_message->comment_ID;

                // move it across and turn it into a reply, timestamps untouched
                wp_update_comment(array(
                    'comment_ID'      => $_message_id,
                    'comment_post_ID' => $ticket_id,
                    'comment_type'    => Replies::COMMENT_TYPE,
                    'comment_parent'  => 0,
                ));

                // nothing said in a chat was ever internal
                update_comment_meta($_message_id, Replies::META_INTERNAL, 0);

                // index any files against the ticket now they live there
                $_files = get_comment_meta($_message_id, Attachments::META_REPLY_FILES, true);
                if (is_array($_files) && ! empty($_files)) {
                    Attachments::indexFiles($ticket_id, $_files, $_message_id);
                }

                // and get whoever said it onto the participant list
                Ticket::addParticipant($ticket_id, (int) $_message->user_id);
            }

            // the customer is always on it, even if they never said anything past the first line
            Ticket::addParticipant($ticket_id, $visitor);
        }

        /**
         * Build the ticket title out of the chat's opening message.
         *
         * @since  1.0.0
         * @access private
         * @param  string $content The opening message.
         * @return string The ticket title.
         */
        private static function buildTitle(string $content): string
        {

            // strip it back to plain text and squash the whitespace
            $title = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($content)) ?? '');

            // if there was nothing usable, fall back to something we can read
            if ($title === '') {
                $title = __('Chat', 'kp-support');
            }

            // keep it to a sensible length for a list table
            $title = wp_trim_words($title, 12, '...');

            // and prefix it so the archive is obvious at a glance
            return (string) Plugin::opt('chat_ticket_prefix', 'CHAT - ') . $title;
        }

        /**
         * Build a plain text transcript of a chat.
         *
         * @since  1.0.0
         * @access public
         * @param  int $chat_id The chat id.
         * @return string The transcript.
         */
        public static function transcript(int $chat_id): string
        {

            // nothing to build
            if (! Chat::exists($chat_id)) {
                return '';
            }

            // grab the chat itself
            $chat = get_post($chat_id);

            // the date format we write timestamps in
            $format = get_option('date_format') . ' ' . get_option('time_format');

            // start with a header so the file stands on its own
            $lines = array(
                get_bloginfo('name'),
                ($chat instanceof \WP_Post) ? $chat->post_title : __('Chat', 'kp-support'),
                str_repeat('-', 60),
                '',
            );

            // pull whatever is still on the chat itself
            $messages = Chat::messages($chat_id);

            // and whatever moved across to the ticket, if it was converted
            $ticket_id = Chat::ticketId($chat_id);
            if ($ticket_id > 0) {

                // the opening post went into the ticket body
                $ticket = get_post($ticket_id);
                if ($ticket instanceof \WP_Post) {
                    $lines[] = sprintf(
                        '[%1$s] %2$s:',
                        mysql2date($format, $ticket->post_date),
                        self::authorName((int) $ticket->post_author)
                    );
                    $lines[] = wp_strip_all_tags($ticket->post_content);
                    $lines[] = '';
                }

                // and the rest became replies
                $messages = array_merge($messages, Replies::forTicket($ticket_id, false));
            }

            // write each message out
            foreach ($messages as $_message) {
                $lines[] = sprintf(
                    '[%1$s] %2$s:',
                    mysql2date($format, $_message->comment_date),
                    $_message->comment_author
                );
                $lines[] = wp_strip_all_tags($_message->comment_content);
                $lines[] = '';
            }

            // and hand the whole thing back
            return implode("\n", $lines);
        }

        /**
         * Get a display name for a user id.
         *
         * @since  1.0.0
         * @access private
         * @param  int $user_id The user id.
         * @return string Their display name.
         */
        private static function authorName(int $user_id): string
        {

            // go get them
            $user = get_userdata($user_id);

            // and hand back something printable either way
            return ($user instanceof \WP_User) ? $user->display_name : __('Unknown', 'kp-support');
        }
    }
}
