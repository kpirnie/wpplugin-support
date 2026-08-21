<?php

/**
 * ChatAjax - The AJAX endpoints behind live chat
 *
 * Same three step gate as the ticket endpoints, nonce then capability then
 * access to the specific chat. The visitor side and the agent side carry
 * different nonces on purpose, so a customer's nonce can never satisfy an
 * agent endpoint even if a capability check were ever loosened.
 *
 * None of these are registered for logged out users.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

namespace KP\Support\Modules;

use KP\Support\Helpers\Chat;
use KP\Support\Helpers\ChatAccess;
use KP\Support\Helpers\ChatConvert;
use KP\Support\Helpers\ChatGuest;
use KP\Support\Helpers\Ticket;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Modules\ChatAjax')) {

    /**
     * Class ChatAjax
     *
     * Our chat AJAX endpoints.
     *
     * @since 1.0.0
     */
    class ChatAjax extends AbstractModule
    {
        /**
         * The nonce action the visitor panel carries.
         *
         * @since 1.0.0
         * @var string
         */
        public const NONCE_VISITOR = 'kpts_chat_visitor';

        /**
         * The nonce action the agent screen carries.
         *
         * @since 1.0.0
         * @var string
         */
        public const NONCE_AGENT = 'kpts_chat_agent';

        /**
         * Hook this module into WordPress.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function register(): void
        {

            // the endpoints we expose
            $actions = array(
                'kpts_chat_start'  => 'startChat',
                'kpts_chat_send'   => 'sendMessage',
                'kpts_chat_poll'   => 'pollChat',
                'kpts_chat_queue'  => 'fetchQueue',
                'kpts_chat_assign' => 'assignChat',
                'kpts_chat_convert' => 'convertChat',
                'kpts_chat_close'  => 'closeChat',
                'kpts_chat_offline_ticket' => 'offlineTicket',
            );

            // the ones a guest reaches, carrying their signed chat cookie rather
            // than a login, everything else stays logged in only
            $guest = array(
                'kpts_chat_start',
                'kpts_chat_send',
                'kpts_chat_poll',
                'kpts_chat_close',
                'kpts_chat_offline_ticket',
            );

            // wire each one up
            foreach ($actions as $_action => $_method) {
                add_action('wp_ajax_' . $_action, array($this, $_method));

                // and again for the logged out side where it applies
                if (in_array($_action, $guest, true)) {
                    add_action('wp_ajax_nopriv_' . $_action, array($this, $_method));
                }
            }
        }

        /**
         * Check the nonce and work out who we're dealing with.
         *
         * Either nonce is accepted here because both sides hit the shared
         * endpoints. The agent only endpoints call requireAgentNonce() instead.
         *
         * A logged out caller is fine as long as they carry a guest chat cookie,
         * or are starting a chat and about to be issued one.
         *
         * @since  1.0.0
         * @access private
         * @param  bool $allow_new True when a guest without a cookie yet is fine.
         * @return void
         */
        private function verifyRequest(bool $allow_new = false): void
        {

            // one of our two actions has to check out, and we handle the failure
            // ourselves so the browser gets JSON back rather than a bare -1
            $visitor = check_ajax_referer(self::NONCE_VISITOR, 'nonce', false);
            $agent = check_ajax_referer(self::NONCE_AGENT, 'nonce', false);

            // neither one passed
            if (! $visitor && ! $agent) {
                wp_send_json_error(array(
                    'message' => __('Your session expired. Please reload the page.', 'kp-support'),
                    'code'    => 'expired',
                ), 403);
            }

            // a login is one way in
            if (is_user_logged_in()) {
                return;
            }

            // a guest session is the other, and starting a chat comes before one
            if ($allow_new || ChatGuest::userId() > 0) {
                return;
            }

            // and that's everybody
            wp_send_json_error(array(
                'message' => __('Please log in to continue.', 'kp-support'),
                'code'    => 'logged_out',
            ), 401);
        }

        /**
         * Who this request is acting as.
         *
         * A logged in user is themselves. A guest is the account their signed
         * cookie points at, which is never a login of any kind.
         *
         * @since  1.0.40
         * @access private
         * @param  int $chat_id The chat being worked on, 0 for any.
         * @return int The user id, or 0.
         */
        private function actorId(int $chat_id = 0): int
        {

            // a login always wins
            $user_id = get_current_user_id();
            if ($user_id > 0) {
                return $user_id;
            }

            // otherwise it comes off the cookie
            return ChatGuest::userId($chat_id);
        }

        /**
         * Check the agent nonce specifically, for the endpoints only they reach.
         *
         * @since  1.0.0
         * @access private
         * @return void
         */
        private function verifyAgentRequest(): void
        {

            // the agent nonce and nothing else
            if (! check_ajax_referer(self::NONCE_AGENT, 'nonce', false)) {
                wp_send_json_error(array(
                    'message' => __('Your session expired. Please reload the page.', 'kp-support'),
                    'code'    => 'expired',
                ), 403);
            }

            // they have to be logged in
            if (! is_user_logged_in()) {
                wp_send_json_error(array(
                    'message' => __('Please log in to continue.', 'kp-support'),
                    'code'    => 'logged_out',
                ), 401);
            }

            // and they have to actually work chats
            if (! ChatAccess::isChatAgent()) {
                wp_send_json_error(array(
                    'message' => __('You are not allowed to work chats.', 'kp-support'),
                    'code'    => 'forbidden',
                ), 403);
            }
        }

        /**
         * Pull the chat id off the request and check access to it.
         *
         * @since  1.0.0
         * @access private
         * @return int The chat id.
         */
        private function requireChat(): int
        {

            // what chat did they ask about
            $chat_id = isset($_POST['chat_id']) ? absint(wp_unslash($_POST['chat_id'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer before any handler reaches this

            // they have to be allowed on it, and this covers the chat being real
            if (! ChatAccess::canView($chat_id, $this->actorId($chat_id))) {
                wp_send_json_error(array(
                    'message' => __('You are not allowed to access that chat.', 'kp-support'),
                    'code'    => 'forbidden',
                ), 403);
            }

            // hand the id back
            return $chat_id;
        }

        /**
         * Throttle how fast one person can post.
         *
         * @since  1.0.0
         * @access private
         * @return void
         */
        private function enforceRateLimit(): void
        {

            // how many are we allowing a minute
            $limit = absint($this->opt('chat_rate_limit', 20));

            // nothing configured, nothing to enforce
            if ($limit < 1) {
                return;
            }

            // the key is per user and rolls with the transient
            $key = 'kpts_chat_rate_' . $this->actorId();

            // what have they sent so far this window
            $sent = absint(get_transient($key));

            // over the line, tell them to slow down
            if ($sent >= $limit) {
                wp_send_json_error(array(
                    'message' => __('You are sending messages too quickly. Please wait a moment.', 'kp-support'),
                    'code'    => 'rate_limited',
                ), 429);
            }

            // count this one, the window starts on the first message
            set_transient($key, $sent + 1, MINUTE_IN_SECONDS);
        }

        /**
         * Start a chat off the pre-chat form.
         *
         * The form carries who they are, which either matches an account we
         * already have or quietly builds one. Nobody is logged in either way,
         * a guest leaves with a signed cookie good for this chat alone.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function startChat(): void
        {

            // nonce, and a guest with no cookie yet is exactly who this is for
            $this->verifyRequest(true);

            // what they told us about themselves
            $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above
            $first = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above
            $last = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above
            $message = isset($_POST['message']) ? wp_unslash($_POST['message']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing -- sanitized with wp_kses in Chat::addMessage()

            // who is this
            $user_id = get_current_user_id();

            // a logged out visitor comes in off the form
            if ($user_id < 1) {

                // guests have to be allowed in the first place
                if (! ChatAccess::guestsCanStart()) {
                    wp_send_json_error(array(
                        'message' => __('Please log in to start a chat.', 'kp-support'),
                        'code'    => 'forbidden',
                    ), 403);
                }

                // we need a name to go with the address
                if ($first === '' || $last === '') {
                    wp_send_json_error(array(
                        'message' => __('Please enter your first and last name.', 'kp-support'),
                        'code'    => 'empty',
                    ), 400);
                }

                // find them, or stand an account up for them
                $user_id = ChatGuest::resolve($email, $first, $last);

                // if that failed, hand the reason back
                if (is_wp_error($user_id)) {
                    wp_send_json_error(array(
                        'message' => $user_id->get_error_message(),
                        'code'    => $user_id->get_error_code(),
                    ), 400);
                }
            }

            // the opening message is the whole point of the form
            if (trim(wp_strip_all_tags((string) $message)) === '') {
                wp_send_json_error(array(
                    'message' => __('Please enter a message.', 'kp-support'),
                    'code'    => 'empty',
                ), 400);
            }

            // chat has to be on and they have to be allowed to open one
            if (! ChatAccess::canStart($user_id)) {
                wp_send_json_error(array(
                    'message' => __('You are not allowed to start a chat.', 'kp-support'),
                    'code'    => 'forbidden',
                ), 403);
            }

            // and somebody has to actually be around to take it
            if (! ChatAccess::anyAgentOnline()) {
                wp_send_json_error(array(
                    'message' => __('There is nobody available to chat right now.', 'kp-support'),
                    'code'    => 'offline',
                ), 409);
            }

            // and we have to be open with somebody around to take it
            if (! ChatAccess::chatAvailable()) {
                wp_send_json_error(array(
                    'message' => __('There is nobody available to chat right now.', 'kp-support'),
                    'code'    => 'offline',
                ), 409);
            }

            // whatever chat their own cookie already vouches for
            $session = ChatGuest::current();

            // a guest only picks an existing chat back up when their cookie already
            // points at it, otherwise anyone typing a known address would walk
            // straight into somebody else's conversation
            $reuse = is_user_logged_in() || ($session['user_id'] === $user_id && $session['chat_id'] > 0);

            // open it, or pick up the one they already have going
            $chat_id = Chat::create($user_id, $reuse);

            // if that failed, hand the reason back
            if (is_wp_error($chat_id)) {
                wp_send_json_error(array(
                    'message' => $chat_id->get_error_message(),
                    'code'    => $chat_id->get_error_code(),
                ), 400);
            }

            // a guest gets the cookie that lets them carry on
            if (! is_user_logged_in()) {
                ChatGuest::issue($chat_id, $user_id);
            }

            // and their opening message goes straight on
            $message_id = Chat::addMessage($chat_id, $user_id, (string) $message);

            // which is nobody's fault but ours if it didn't land
            if (is_wp_error($message_id)) {
                wp_send_json_error(array(
                    'message' => $message_id->get_error_message(),
                    'code'    => $message_id->get_error_code(),
                ), 400);
            }

            // hand back the chat and everything already on it
            wp_send_json_success($this->chatPayload($chat_id, ''));
        }

        /**
         * Take a leave-a-message submission when nobody is online.
         *
         * @since  1.0.21
         * @access public
         * @return void
         */
        public function offlineTicket(): void
        {

            // nonce, and a guest leaving a message has no cookie yet either
            $this->verifyRequest(true);

            // who they are, off the form when they aren't logged in
            $user_id = get_current_user_id();

            // a guest gets the same find-or-create treatment a chat would give them
            if ($user_id < 1) {

                // guests have to be allowed in the first place
                if (! ChatAccess::guestsCanStart()) {
                    wp_send_json_error(array(
                        'message' => __('You are not allowed to do that.', 'kp-support'),
                        'code'    => 'forbidden',
                    ), 403);
                }

                // what they told us about themselves
                $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above
                $first = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above
                $last = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above

                // we need a name to go with the address
                if ($first === '' || $last === '') {
                    wp_send_json_error(array(
                        'message' => __('Please enter your first and last name.', 'kp-support'),
                        'code'    => 'empty',
                    ), 400);
                }

                // find them, or stand an account up for them
                $user_id = ChatGuest::resolve($email, $first, $last);

                // if that failed, hand the reason back
                if (is_wp_error($user_id)) {
                    wp_send_json_error(array(
                        'message' => $user_id->get_error_message(),
                        'code'    => $user_id->get_error_code(),
                    ), 400);
                }
            }

            // and they still need to be allowed to open a chat to use this
            if (! ChatAccess::canStart($user_id)) {
                wp_send_json_error(array(
                    'message' => __('You are not allowed to do that.', 'kp-support'),
                    'code'    => 'forbidden',
                ), 403);
            }

            // what they typed
            $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above
            $message = isset($_POST['message']) ? wp_kses_post(wp_unslash($_POST['message'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above

            // both are required
            if ($subject === '' || trim(wp_strip_all_tags($message)) === '') {
                wp_send_json_error(array(
                    'message' => __('Please fill in both a subject and a message.', 'kp-support'),
                    'code'    => 'empty',
                ), 400);
            }

            // take whatever came with it
            $attachments = Attachments::processUploads('kpts_files', $user_id);

            // if any file was rejected, stop right here and say why
            if (is_wp_error($attachments)) {
                wp_send_json_error(array(
                    'message' => $attachments->get_error_message(),
                    'code'    => $attachments->get_error_code(),
                ), 400);
            }

            // open it as a normal ticket
            $ticket_id = Ticket::create(array(
                'subject'     => $subject,
                'message'     => $message,
                'requester'   => $user_id,
                'department'  => Ticket::termIdBySlug(PostTypes::TAX_DEPARTMENT, (string) $this->opt('chat_department', '')),
                'attachments' => $attachments,
            ));

            // if that failed, hand the reason back
            if (is_wp_error($ticket_id)) {
                wp_send_json_error(array(
                    'message' => $ticket_id->get_error_message(),
                    'code'    => $ticket_id->get_error_code(),
                ), 400);
            }

            // and tell them where it went
            wp_send_json_success(array(
                'ticket_id' => (int) $ticket_id,
                'url'       => Portal::ticketUrl((int) $ticket_id),
                'message'   => __('Thanks, your message has been logged as a ticket.', 'kp-support'),
                'nonce'     => $this->freshNonce(),
            ));
        }

        /**
         * Post a message onto a chat.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function sendMessage(): void
        {

            // nonce and login
            $this->verifyRequest();

            // and access to the chat itself
            $chat_id = $this->requireChat();

            // who's posting
            $user_id = $this->actorId($chat_id);

            // posting is a different check to viewing, and it covers the chat being closed
            if (! ChatAccess::canPost($chat_id, $user_id)) {
                wp_send_json_error(array(
                    'message' => __('This chat is no longer open.', 'kp-support'),
                    'code'    => 'closed',
                ), 403);
            }

            // slow down anybody hammering it
            $this->enforceRateLimit();

            // pull the message, kses runs inside Chat::addMessage
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- sanitized with wp_kses in Chat::addMessage(), and the nonce is checked above
            $content = wp_unslash($_POST['content'] ?? '');

            // take whatever files came along with it
            $attachments = Attachments::processUploads('kpts_files', $user_id);

            // if any file was rejected, stop right here and say why
            if (is_wp_error($attachments)) {
                wp_send_json_error(array(
                    'message' => $attachments->get_error_message(),
                    'code'    => $attachments->get_error_code(),
                ), 400);
            }

            // drop it in
            $message_id = Chat::addMessage($chat_id, $user_id, (string) $content, $attachments);

            // if that failed, hand the reason back
            if (is_wp_error($message_id)) {
                wp_send_json_error(array(
                    'message' => $message_id->get_error_message(),
                    'code'    => $message_id->get_error_code(),
                ), 400);
            }

            // an agent answering picks the chat up if nobody had it, though
            // never their own chat, an agent asking for help is the customer here
            if (
                ChatAccess::isChatAgent($user_id)
                && Chat::agent($chat_id) < 1
                && Chat::visitor($chat_id) !== $user_id
            ) {
                Chat::setAgent($chat_id, $user_id);
            }

            // grab it back so we can render it
            $message = get_comment($message_id);

            // and hand the rendered message back to the browser
            wp_send_json_success(array(
                'message' => $this->messageArray($message, $chat_id),
                'latest'  => $message->comment_date_gmt,
                'state'   => $this->statePayload($chat_id),
                'nonce'   => $this->freshNonce(),
            ));
        }

        /**
         * Hand back anything said since the browser last checked.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function pollChat(): void
        {

            // nonce and login
            $this->verifyRequest();

            // and access to the chat itself
            $chat_id = $this->requireChat();

            // what's the last thing they've seen
            $since = isset($_POST['since']) ? sanitize_text_field(wp_unslash($_POST['since'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyRequest() runs check_ajax_referer above

            // it has to look like a real GMT datetime, otherwise we ignore it
            if ($since !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
                $since = '';
            }

            // hand back whatever is newer than their cutoff
            wp_send_json_success($this->chatPayload($chat_id, $since));
        }

        /**
         * Hand back the queue of chats an agent can work.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function fetchQueue(): void
        {

            // agents only, on the agent nonce
            $this->verifyAgentRequest();

            // they're clearly here, so stamp their presence
            ChatAccess::markPresence();

            // which slice do they want
            $filter = isset($_POST['filter']) ? sanitize_key(wp_unslash($_POST['filter'])) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyAgentRequest() runs check_ajax_referer above

            // and hand it back
            wp_send_json_success(array(
                'chats' => $this->queuePayload($filter),
                'nonce' => $this->freshNonce(),
            ));
        }

        /**
         * Hand a chat to an agent.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function assignChat(): void
        {

            // agents only, on the agent nonce
            $this->verifyAgentRequest();

            // and access to the chat itself
            $chat_id = $this->requireChat();

            // reassignment has its own capability on top
            if (! ChatAccess::canAssign($chat_id)) {
                wp_send_json_error(array(
                    'message' => __('You are not allowed to assign this chat.', 'kp-support'),
                    'code'    => 'forbidden',
                ), 403);
            }

            // who are we handing it to
            $agent_id = isset($_POST['agent']) ? absint(wp_unslash($_POST['agent'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyAgentRequest() runs check_ajax_referer above

            // do it, setAgent refuses anybody who can't work chats
            if (! Chat::setAgent($chat_id, $agent_id)) {
                wp_send_json_error(array(
                    'message' => __('That chat could not be assigned.', 'kp-support'),
                    'code'    => 'assign_failed',
                ), 400);
            }

            // hand back where things stand now
            wp_send_json_success(array(
                'state'   => $this->statePayload($chat_id),
                'message' => __('Chat assigned.', 'kp-support'),
                'nonce'   => $this->freshNonce(),
            ));
        }

        /**
         * Turn a chat into a live ticket.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function convertChat(): void
        {

            // agents only, on the agent nonce
            $this->verifyAgentRequest();

            // and access to the chat itself
            $chat_id = $this->requireChat();

            // this carries its own single purpose nonce on top of the request one
            $confirm = isset($_POST['confirm']) ? sanitize_text_field(wp_unslash($_POST['confirm'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifyAgentRequest() runs check_ajax_referer above

            // and it's tied to this specific chat
            if (! wp_verify_nonce($confirm, 'kpts_chat_convert_' . $chat_id)) {
                wp_send_json_error(array(
                    'message' => __('Please reload the chat and try again.', 'kp-support'),
                    'code'    => 'expired',
                ), 403);
            }

            // converting needs its own capability and a chat that's still running
            if (! ChatAccess::canConvert($chat_id)) {
                wp_send_json_error(array(
                    'message' => __('You are not allowed to convert this chat.', 'kp-support'),
                    'code'    => 'forbidden',
                ), 403);
            }

            // build the ticket
            $ticket_id = ChatConvert::toTicket($chat_id, array(
                'state'  => Chat::STATE_CONVERTED,
                'status' => (string) $this->opt('status_after_chat_convert', 'open'),
                'agent'  => get_current_user_id(),
            ));

            // if that failed, hand the reason back
            if (is_wp_error($ticket_id)) {
                wp_send_json_error(array(
                    'message' => $ticket_id->get_error_message(),
                    'code'    => $ticket_id->get_error_code(),
                ), 400);
            }

            // and send them off to it
            wp_send_json_success(array(
                'ticketId' => $ticket_id,
                'redirect' => get_edit_post_link($ticket_id, 'raw'),
                'state'    => $this->statePayload($chat_id),
            ));
        }

        /**
         * Close a chat out, which archives it as a ticket.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function closeChat(): void
        {

            // nonce and login
            $this->verifyRequest();

            // and access to the chat itself
            $chat_id = $this->requireChat();

            // who's asking, and whether that makes them the agent side
            $user_id = $this->actorId($chat_id);
            $is_agent = ChatAccess::canManage($chat_id, $user_id);

            // the customer can only close their own
            if (! $is_agent && Chat::visitor($chat_id) !== $user_id) {
                wp_send_json_error(array(
                    'message' => __('You are not allowed to close this chat.', 'kp-support'),
                    'code'    => 'forbidden',
                ), 403);
            }

            // already closed, nothing to do and nothing to complain about
            if (! Chat::isLive($chat_id)) {
                wp_send_json_success(array(
                    'state' => $this->statePayload($chat_id),
                ));
            }

            // archive it as a closed ticket
            $ticket_id = ChatConvert::toTicket($chat_id, array(
                'state'  => $is_agent ? Chat::STATE_AGENT_CLOSED : Chat::STATE_CLIENT_CLOSED,
                'status' => (string) $this->opt('status_after_chat_close', 'closed'),
            ));

            // a chat nobody ever said anything on just closes, that isn't an error
            if (is_wp_error($ticket_id) && $ticket_id->get_error_code() !== 'kpts_empty_chat') {
                wp_send_json_error(array(
                    'message' => $ticket_id->get_error_message(),
                    'code'    => $ticket_id->get_error_code(),
                ), 400);
            }

            // a guest's session dies with the chat
            if (! is_user_logged_in()) {
                ChatGuest::clear();
            }

            // hand back where things stand now
            wp_send_json_success(array(
                'state'   => $this->statePayload($chat_id),
                'message' => __('This chat has been closed.', 'kp-support'),
            ));
        }

        /**
         * Build the payload describing a chat and its messages.
         *
         * @since  1.0.0
         * @access private
         * @param  int    $chat_id The chat id.
         * @param  string $since   Only include messages newer than this.
         * @return array<string, mixed> The payload.
         */
        private function chatPayload(int $chat_id, string $since): array
        {

            // pull whatever is newer than the cutoff
            $comments = Chat::messages($chat_id, $since);

            // build them out, tracking the newest timestamp as we go
            $messages = array();
            $latest = $since;

            // walk each one
            foreach ($comments as $_comment) {

                // render it out
                $messages[] = $this->messageArray($_comment, $chat_id);

                // and keep the newest timestamp we've seen
                if ($_comment->comment_date_gmt > $latest) {
                    $latest = $_comment->comment_date_gmt;
                }
            }

            // and hand the lot back, with a fresh nonce so a panel that
            // sits open all day doesn't quietly die when the old one ages out
            return array(
                'chatId'   => $chat_id,
                'messages' => $messages,
                'latest'   => $latest,
                'state'    => $this->statePayload($chat_id),
                'nonce'    => $this->freshNonce(),
            );
        }

        /**
         * Describe a single message for the browser.
         *
         * @since  1.0.0
         * @access private
         * @param  \WP_Comment $comment The message.
         * @param  int         $chat_id The chat it's on.
         * @return array<string, mixed> The message details.
         */
        private function messageArray(\WP_Comment $comment, int $chat_id): array
        {

            // who said it
            $user_id = (int) $comment->user_id;

            // pull whatever files came in on it
            $files = array();
            foreach (Chat::messageFiles((int) $comment->comment_ID) as $_file) {

                // skip anything malformed
                if (empty($_file['key']) || empty($_file['name'])) {
                    continue;
                }

                // describe it
                $files[] = array(
                    'name' => (string) $_file['name'],
                    'url'  => Attachments::chatUrl($chat_id, (string) $_file['key']),
                    'size' => size_format((int) ($_file['size'] ?? 0)),
                );
            }

            // and describe the whole thing
            return array(
                'id'          => (int) $comment->comment_ID,
                'content'     => wpautop(wp_kses($comment->comment_content, Replies::allowedTags())),
                'author'      => $comment->comment_author,
                'avatar'      => get_avatar_url($user_id, array('size' => 48)),
                'isAgent'     => $user_id !== Chat::visitor($chat_id),
                'isMine'      => $user_id === $this->actorId($chat_id),
                'date'        => mysql2date(get_option('time_format'), $comment->comment_date),
                'gmt'         => $comment->comment_date_gmt,
                'attachments' => $files,
            );
        }

        /**
         * Build the little state payload we hand back with most responses.
         *
         * @since  1.0.0
         * @access private
         * @param  int $chat_id The chat id.
         * @return array<string, mixed> The state details.
         */
        private function statePayload(int $chat_id): array
        {

            // who has it
            $agent_id = Chat::agent($chat_id);
            $agent = ($agent_id > 0) ? get_userdata($agent_id) : false;

            // what it became, if it became anything
            $ticket_id = Chat::ticketId($chat_id);

            // and describe where it stands
            return array(
                'state'     => Chat::state($chat_id),
                'live'      => Chat::isLive($chat_id),
                'agentId'   => $agent_id,
                'agentName' => ($agent instanceof \WP_User) ? $agent->display_name : '',
                'ticketId'  => $ticket_id,
                'ticketUrl' => ($ticket_id > 0) ? Portal::ticketUrl($ticket_id) : '',
            );
        }

        /**
         * Build the queue an agent sees.
         *
         * @since  1.0.0
         * @access private
         * @param  string $filter Which slice they asked for.
         * @return array<int, array<string, mixed>> The chats.
         */
        private function queuePayload(string $filter): array
        {

            // who's asking
            $user_id = get_current_user_id();

            // the base query, live chats first and newest activity at the top
            $args = array(
                'post_type'      => PostTypes::CHAT_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => 50,
                'orderby'        => 'meta_value',
                'meta_key'       => Chat::META_LAST_ACTIVITY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ordering the agent queue by activity, capped at 50 rows
                'order'          => 'DESC',
                'no_found_rows'  => true,
                'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the agent queue has to filter on state, capped at 50 rows
                    array(
                        'key'     => Chat::META_STATE,
                        'value'   => array(Chat::STATE_WAITING, Chat::STATE_ACTIVE),
                        'compare' => 'IN',
                    ),
                ),
            );

            // just the ones they're working
            if ($filter === 'mine') {
                $args['meta_query'][] = array(
                    'key'   => Chat::META_AGENT,
                    'value' => $user_id,
                );
            }

            // or just the ones nobody has picked up
            if ($filter === 'unassigned') {
                $args['meta_query'][] = array(
                    'key'     => Chat::META_AGENT,
                    'compare' => 'NOT EXISTS',
                );
            }

            // run it
            $chats = get_posts($args);

            // build each row out
            $rows = array();
            foreach ($chats as $_chat) {

                // cast the id down
                $_chat_id = (int) $_chat->ID;

                // department coverage still applies, an agent never sees a chat they can't work
                if (! ChatAccess::canView($_chat_id)) {
                    continue;
                }

                // who started it
                $_visitor = get_userdata(Chat::visitor($_chat_id));

                // and what they last said
                $_messages = Chat::messages($_chat_id);
                $_last = ! empty($_messages) ? end($_messages) : null;

                // describe the row
                $rows[] = array(
                    'chatId'   => $_chat_id,
                    'visitor'  => ($_visitor instanceof \WP_User) ? $_visitor->display_name : __('Unknown', 'kp-support'),
                    'avatar'   => get_avatar_url(Chat::visitor($_chat_id), array('size' => 48)),
                    'preview'  => ($_last instanceof \WP_Comment) ? $this->previewFor($_last) : '',
                    'activity' => (string) get_post_meta($_chat_id, Chat::META_LAST_ACTIVITY, true),
                    'state'    => $this->statePayload($_chat_id),
                    'confirm'  => wp_create_nonce('kpts_chat_convert_' . $_chat_id),
                );
            }

            // hand them back
            return $rows;
        }

        /**
         * Build the one line preview a queue row shows.
         *
         * @since  1.0.0
         * @access private
         * @param  \WP_Comment $message The last message on the chat.
         * @return string The preview.
         */
        private function previewFor(\WP_Comment $message): string
        {

            // what they said
            $preview = wp_trim_words(wp_strip_all_tags($message->comment_content), 10, '...');

            // a message that's nothing but a file still needs something to show
            if ($preview === '' && ! empty(Chat::messageFiles((int) $message->comment_ID))) {
                $preview = __('Sent an attachment', 'kp-support');
            }

            // hand it back
            return $preview;
        }

        /**
         * Mint a fresh nonce for whichever side is asking.
         *
         * @since  1.0.0
         * @access private
         * @return string The nonce.
         */
        private function freshNonce(): string
        {

            // agents get the agent action, everybody else the visitor one
            return wp_create_nonce(ChatAccess::isChatAgent() ? self::NONCE_AGENT : self::NONCE_VISITOR);
        }
    }
}
