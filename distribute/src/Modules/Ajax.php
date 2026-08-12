<?php

/**
 * Ajax - The AJAX endpoints behind the chat
 *
 * Every handler in here does the same three things before it touches anything:
 * verifies the nonce, verifies the capability, then verifies access to the
 * specific ticket being asked about. None of these are registered for logged
 * out users, there's nothing in a support system a stranger should reach.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

namespace KP\Support\Modules;

use KP\Support\Helpers\Access;
use KP\Support\Helpers\Ticket;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Modules\Ajax')) {

    /**
     * Class Ajax
     *
     * Our AJAX endpoints.
     *
     * @since 1.0.0
     */
    class Ajax extends AbstractModule
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

            // the endpoints we expose, all of them logged in only
            $actions = array(
                'kpts_send_reply'    => 'sendReply',
                'kpts_fetch_replies' => 'fetchReplies',
                'kpts_create_ticket' => 'createTicket',
                'kpts_update_ticket' => 'updateTicket',
            );

            // wire each one up
            foreach ($actions as $_action => $_method) {
                add_action('wp_ajax_' . $_action, array($this, $_method));
            }
        }

        /**
         * Check the nonce and make sure somebody is actually logged in.
         *
         * @since  1.0.0
         * @access private
         * @return void
         */
        private function verifyRequest(): void
        {

            // the nonce has to check out, and we handle the failure ourselves
            // so the browser gets JSON back rather than a bare -1
            if (! check_ajax_referer('kpts_portal', 'nonce', false)) {
                wp_send_json_error(array(
                    'message' => __('Your session expired. Please reload the page.', 'kp-support'),
                    'code'    => 'expired',
                ), 403);
            }

            // and they have to be logged in
            if (! is_user_logged_in()) {
                wp_send_json_error(array(
                    'message' => __('Please log in to continue.', 'kp-support'),
                    'code'    => 'logged_out',
                ), 401);
            }
        }

        /**
         * Pull the ticket id off the request and check access to it.
         *
         * @since  1.0.0
         * @access private
         * @return int The ticket id.
         */
        private function requireTicket(): int
        {

            // what ticket did they ask about
            $ticket_id = isset($_POST['ticket_id']) ? absint(wp_unslash($_POST['ticket_id'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer before any handler reaches this

            // they have to be allowed on it, and this covers the ticket being real
            if (! Access::canViewTicket($ticket_id)) {
                wp_send_json_error(array(
                    'message' => __('You are not allowed to access that ticket.', 'kp-support'),
                    'code'    => 'forbidden',
                ), 403);
            }

            // hand the id back
            return $ticket_id;
        }

        /**
         * Post a reply onto a ticket.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function sendReply(): void
        {

            // nonce and login
            $this->verifyRequest();

            // and access to the ticket itself
            $ticket_id = $this->requireTicket();

            // they have to be allowed to reply, which is a different check to viewing
            if (! Access::canReply($ticket_id)) {
                wp_send_json_error(array(
                    'message' => __('You are not allowed to reply to this ticket.', 'kp-support'),
                    'code'    => 'forbidden',
                ), 403);
            }

            // pull the reply itself, kses runs inside Replies::add
            $content = wp_unslash($_POST['content'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized with wp_kses in Replies::add()
            $parent = isset($_POST['parent']) ? absint(wp_unslash($_POST['parent'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above
            $internal = ! empty($_POST['internal']) && Access::canReplyInternal($ticket_id); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above

            // take whatever files came along with it
            $attachments = Attachments::processUploads('kpts_files', get_current_user_id());

            // if any file was rejected, stop right here and say why
            if (is_wp_error($attachments)) {
                wp_send_json_error(array(
                    'message' => $attachments->get_error_message(),
                    'code'    => $attachments->get_error_code(),
                ), 400);
            }

            // drop the reply in
            $comment_id = Replies::add(array(
                'ticket_id'   => $ticket_id,
                'user_id'     => get_current_user_id(),
                'content'     => $content,
                'parent'      => $parent,
                'internal'    => $internal,
                'attachments' => $attachments,
            ));

            // if that failed, hand the reason back
            if (is_wp_error($comment_id)) {
                wp_send_json_error(array(
                    'message' => $comment_id->get_error_message(),
                    'code'    => $comment_id->get_error_code(),
                ), 400);
            }

            // grab it back so we can render it
            $comment = get_comment($comment_id);

            // and hand the rendered reply back to the browser
            wp_send_json_success(array(
                'reply'  => Replies::toArray($comment, $ticket_id),
                'latest' => $comment->comment_date_gmt,
                'status' => $this->statusPayload($ticket_id),
            ));
        }

        /**
         * Hand back any replies posted since the browser last checked.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function fetchReplies(): void
        {

            // nonce and login
            $this->verifyRequest();

            // and access to the ticket itself
            $ticket_id = $this->requireTicket();

            // what's the last thing they've seen
            $since = isset($_POST['since']) ? sanitize_text_field(wp_unslash($_POST['since'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above

            // it has to look like a real GMT datetime, otherwise we ignore it
            if ($since !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
                $since = '';
            }

            // this is the check that keeps internal notes internal
            $can_internal = Access::canSeeInternal($ticket_id);

            // pull anything newer than their cutoff
            $comments = Replies::forTicket($ticket_id, $can_internal, $since);

            // build out the payload, and track the newest timestamp as we go
            $replies = array();
            $latest = $since;

            // walk each one
            foreach ($comments as $_comment) {

                // render it out
                $replies[] = Replies::toArray($_comment, $ticket_id);

                // and keep the newest timestamp we've seen
                if ($_comment->comment_date_gmt > $latest) {
                    $latest = $_comment->comment_date_gmt;
                }
            }

            // hand it all back
            wp_send_json_success(array(
                'replies' => $replies,
                'latest'  => $latest,
                'status'  => $this->statusPayload($ticket_id),
            ));
        }

        /**
         * Open a brand new ticket.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function createTicket(): void
        {

            // nonce and login
            $this->verifyRequest();

            // they have to be allowed to open tickets
            if (! current_user_can('create_kpts_tickets')) {
                wp_send_json_error(array(
                    'message' => __('You are not allowed to open tickets.', 'kp-support'),
                    'code'    => 'forbidden',
                ), 403);
            }

            // pull what they filled in
            $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above
            $message = wp_unslash($_POST['message'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- sanitized with wp_kses just below, nonce checked in verifyRequest()
            $department = isset($_POST['department']) ? absint(wp_unslash($_POST['department'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above
            $category = isset($_POST['category']) ? absint(wp_unslash($_POST['category'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above
            $priority = isset($_POST['priority']) ? absint(wp_unslash($_POST['priority'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above

            // clean the body down to the tags we allow
            $message = wp_kses($message, Replies::allowedTags());

            // enforce the required fields the admin configured
            if ($this->opt('require_department', true) && $department < 1) {
                wp_send_json_error(array(
                    'message' => __('Please choose a department.', 'kp-support'),
                    'code'    => 'no_department',
                ), 400);
            }

            // same for the category
            if ($this->opt('require_category', false) && $category < 1) {
                wp_send_json_error(array(
                    'message' => __('Please choose a category.', 'kp-support'),
                    'code'    => 'no_category',
                ), 400);
            }

            // take whatever files came along with it
            $attachments = Attachments::processUploads('kpts_files', get_current_user_id());

            // if any file was rejected, stop right here and say why
            if (is_wp_error($attachments)) {
                wp_send_json_error(array(
                    'message' => $attachments->get_error_message(),
                    'code'    => $attachments->get_error_code(),
                ), 400);
            }

            // open the ticket
            $ticket_id = Ticket::create(array(
                'subject'     => $subject,
                'message'     => $message,
                'requester'   => get_current_user_id(),
                'department'  => $department,
                'category'    => $category,
                'priority'    => $priority,
                'attachments' => $attachments,
            ));

            // if that failed, hand the reason back
            if (is_wp_error($ticket_id)) {
                wp_send_json_error(array(
                    'message' => $ticket_id->get_error_message(),
                    'code'    => $ticket_id->get_error_code(),
                ), 400);
            }

            // index the opening message's files against the ticket
            if (! empty($attachments)) {
                Attachments::indexFiles($ticket_id, $attachments, 0);
            }

            // and send the browser off to the new ticket
            wp_send_json_success(array(
                'ticketId' => $ticket_id,
                'redirect' => Portal::ticketUrl($ticket_id),
            ));
        }

        /**
         * Change a ticket's properties.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function updateTicket(): void
        {

            // nonce and login
            $this->verifyRequest();

            // and access to the ticket itself
            $ticket_id = $this->requireTicket();

            // this one is agents only, a customer can never retag their own ticket
            if (! Access::canManageTicket($ticket_id)) {
                wp_send_json_error(array(
                    'message' => __('You are not allowed to update this ticket.', 'kp-support'),
                    'code'    => 'forbidden',
                ), 403);
            }

            // the taxonomies they're allowed to set, and where they come from
            $taxonomies = array(
                'status'     => PostTypes::TAX_STATUS,
                'priority'   => PostTypes::TAX_PRIORITY,
                'department' => PostTypes::TAX_DEPARTMENT,
                'category'   => PostTypes::TAX_CATEGORY,
            );

            // apply each one that was sent
            foreach ($taxonomies as $_key => $_taxonomy) {

                // skip anything they didn't send
                if (! isset($_POST[$_key])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above
                    continue;
                }

                // cast it down
                $term_id = absint(wp_unslash($_POST[$_key])); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above

                // the status gets set through its own method so the hook fires
                if ($_key === 'status') {
                    Ticket::setStatus($ticket_id, $term_id);
                    continue;
                }

                // everything else is a straight set
                Ticket::setTerm($ticket_id, $_taxonomy, $term_id);
            }

            // and the assignment, which needs its own capability
            if (isset($_POST['assignee']) && current_user_can('kpts_assign_tickets')) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above
                Ticket::setAssignee($ticket_id, absint(wp_unslash($_POST['assignee']))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above
            }

            // hand back the current state
            wp_send_json_success(array(
                'status'   => $this->statusPayload($ticket_id),
                'message'  => __('Ticket updated.', 'kp-support'),
            ));
        }

        /**
         * Build the little status payload we hand back with most responses.
         *
         * @since  1.0.0
         * @access private
         * @param  int $ticket_id The ticket id.
         * @return array<string, mixed> The status details.
         */
        private function statusPayload(int $ticket_id): array
        {

            // grab the status term
            $status = Ticket::term($ticket_id, PostTypes::TAX_STATUS);

            // and describe it
            return array(
                'id'     => ($status instanceof \WP_Term) ? (int) $status->term_id : 0,
                'name'   => ($status instanceof \WP_Term) ? $status->name : '',
                'slug'   => ($status instanceof \WP_Term) ? $status->slug : '',
                'color'  => ($status instanceof \WP_Term) ? (string) get_term_meta($status->term_id, 'kpts_color', true) : '',
                'closed' => Access::ticketIsClosed($ticket_id),
            );
        }
    }
}
