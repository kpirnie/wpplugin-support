<?php

/**
 * Notifications - Ticket email notifications
 *
 * Every public reply emails everybody attached to the ticket. Internal notes
 * only ever go to the people allowed to see internal notes, which is the whole
 * point of the flag, so that check lives right at the top of the recipient
 * builder rather than somewhere it could get missed.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

namespace KP\Support\Modules;

use KP\Support\Helpers\ChatAccess;
use KP\Support\Plugin;
use KP\Support\Helpers\Access;
use KP\Support\Helpers\MailLog;
use KP\Support\Helpers\Ticket;
use KP\Support\Modules\Smtp;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Modules\Notifications')) {

    /**
     * Class Notifications
     *
     * Builds and sends our ticket emails.
     *
     * @since 1.0.0
     */
    class Notifications extends AbstractModule
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

            // start watching for mail failures so send() has something to log
            MailLog::watch();

            // a brand new ticket landed
            add_action('kpts_ticket_created', array($this, 'onTicketCreated'), 10, 2);

            // a chat opening up
            add_action('kpts_chat_started', array($this, 'onChatStarted'), 10, 2);

            // somebody replied
            add_action('kpts_reply_added', array($this, 'onReplyAdded'), 10, 4);

            // the status moved
            add_action('kpts_ticket_status_changed', array($this, 'onStatusChanged'), 10, 3);

            // a ticket got handed to an agent
            add_action('kpts_ticket_assigned', array($this, 'onTicketAssigned'), 10, 3);
        }

        /**
         * Let everybody know a new ticket came in.
         *
         * @since  1.0.0
         * @access public
         * @param  int $ticket_id The ticket id.
         * @param  int $user_id   Who opened it.
         * @return void
         */
        public function onTicketCreated(int $ticket_id, int $user_id): void
        {

            // only if we're set up to do this
            if (! $this->opt('notify_new_ticket', true)) {
                return;
            }

            // the tokens we'll swap into the templates
            $tokens = $this->tokens($ticket_id);

            // confirm it back to whoever opened it
            $requester = get_userdata($user_id);
            if ($requester instanceof \WP_User) {

                // build their copy out
                $this->send(
                    $requester->user_email,
                    $this->template(
                        'email_new_ticket_customer_subject',
                        __('[{ticket_number}] We received your ticket: {ticket_subject}', 'kp-support')
                    ),
                    $this->template(
                        'email_new_ticket_customer_body',
                        __("Hi {customer_name},\n\nThanks for getting in touch. We've received your ticket and someone will be with you shortly.\n\n<strong>{ticket_subject}</strong>\n\n{ticket_content}\n\nYou can view and reply to your ticket here:\n{ticket_url}\n\n- {site_name}", 'kp-support')
                    ),
                    $tokens
                );
            }

            // and let the agents who cover this department know about it
            $agents = Ticket::eligibleAgents($ticket_id);

            // pull their addresses, skipping whoever opened it
            $addresses = $this->addressesFor($agents, $user_id);

            // nothing to send
            if (empty($addresses)) {
                return;
            }

            // fire it off
            $this->send(
                $addresses,
                $this->template(
                    'email_new_ticket_agent_subject',
                    __('[{ticket_number}] New ticket: {ticket_subject}', 'kp-support')
                ),
                $this->template(
                    'email_new_ticket_agent_body',
                    __("A new ticket has been opened by {customer_name}.\n\n<strong>{ticket_subject}</strong>\nDepartment: {department}\nPriority: {priority}\n\n{ticket_content}\n\nView the ticket:\n{ticket_url}", 'kp-support')
                ),
                $tokens
            );
        }

        /**
         * Let the agents know somebody has opened a chat.
         *
         * Only the agents get this one, the customer is sitting in the panel
         * watching it happen so there's nothing to tell them.
         *
         * @since  1.0.0
         * @access public
         * @param  int $chat_id The chat id.
         * @param  int $user_id Who started it.
         * @return void
         */
        public function onChatStarted(int $chat_id, int $user_id): void
        {

            // only if we're set up to do this
            if (! $this->opt('notify_new_chat', true)) {
                return;
            }

            // who started it
            $visitor = get_userdata($user_id);

            // pull everybody who works chats
            $agents = get_users(array(
                'capability' => 'kpts_handle_chats',
                'fields'     => 'ID',
            ));

            // narrow them to whoever actually covers chats
            $eligible = array();
            foreach ($agents as $_agent_id) {
                if (ChatAccess::agentCoversChats((int) $_agent_id)) {
                    $eligible[] = (int) $_agent_id;
                }
            }

            // pull their addresses, skipping whoever started it
            $addresses = $this->addressesFor($eligible, $user_id);

            // nothing to send
            if (empty($addresses)) {
                return;
            }

            // the tokens we'll swap into the templates
            $tokens = array(
                '{customer_name}'  => ($visitor instanceof \WP_User) ? $visitor->display_name : __('A customer', 'kp-support'),
                '{customer_email}' => ($visitor instanceof \WP_User) ? $visitor->user_email : '',
                '{chat_url}'       => admin_url('edit.php?post_type=' . PostTypes::POST_TYPE . '&page=' . ChatAdmin::MENU_SLUG),
                '{site_name}'      => get_bloginfo('name'),
                '{site_url}'       => home_url('/'),
            );

            // fire it off
            $this->send(
                $addresses,
                $this->template(
                    'email_new_chat_subject',
                    __('New chat waiting from {customer_name}', 'kp-support')
                ),
                $this->template(
                    'email_new_chat_body',
                    __("{customer_name} has started a live chat and is waiting for somebody to pick it up.\n\nOpen the chat queue:\n{chat_url}\n\n- {site_name}", 'kp-support')
                ),
                $tokens
            );
        }

        /**
         * Let everybody on a ticket know about a new reply.
         *
         * @since  1.0.0
         * @access public
         * @param  int  $comment_id The reply id.
         * @param  int  $ticket_id  The ticket id.
         * @param  int  $user_id    Who replied.
         * @param  bool $internal   Whether it's an internal note.
         * @return void
         */
        public function onReplyAdded(int $comment_id, int $ticket_id, int $user_id, bool $internal): void
        {

            // only if we're set up to do this
            if (! $this->opt('notify_new_reply', true)) {
                return;
            }

            // internal notes are opt-in, since some teams don't want the noise
            if ($internal && ! $this->opt('notify_internal_notes', true)) {
                return;
            }

            // grab the reply itself
            $comment = get_comment($comment_id);
            if (! $comment instanceof \WP_Comment) {
                return;
            }

            // work out who should be getting this
            $recipients = $this->replyRecipients($ticket_id, $user_id, $internal);

            // nobody to tell
            if (empty($recipients)) {
                return;
            }

            // build the tokens, including the reply itself
            $tokens = $this->tokens($ticket_id);
            $tokens['{reply_content}'] = wpautop(wp_kses_post($comment->comment_content));
            $tokens['{reply_author}'] = $comment->comment_author;

            // internal notes get a clearly different subject line so nobody misreads them
            if ($internal) {
                $this->send(
                    $recipients,
                    $this->template(
                        'email_internal_note_subject',
                        __('[{ticket_number}] Internal note from {reply_author}', 'kp-support')
                    ),
                    $this->template(
                        'email_internal_note_body',
                        __("<strong>This is an internal note. The customer cannot see it.</strong>\n\n{reply_author} wrote on {ticket_subject}:\n\n{reply_content}\n\nView the ticket:\n{ticket_url}", 'kp-support')
                    ),
                    $tokens
                );
                return;
            }

            // and the normal public reply
            $this->send(
                $recipients,
                $this->template(
                    'email_new_reply_subject',
                    __('[{ticket_number}] New reply: {ticket_subject}', 'kp-support')
                ),
                $this->template(
                    'email_new_reply_body',
                    __("{reply_author} replied to {ticket_subject}:\n\n{reply_content}\n\nView and reply to the ticket:\n{ticket_url}\n\n- {site_name}", 'kp-support')
                ),
                $tokens
            );
        }

        /**
         * Work out who gets an email about a reply.
         *
         * @since  1.0.0
         * @access private
         * @param  int  $ticket_id The ticket id.
         * @param  int  $author_id Who wrote the reply.
         * @param  bool $internal  Whether it's an internal note.
         * @return array<int, string> The email addresses.
         */
        private function replyRecipients(int $ticket_id, int $author_id, bool $internal): array
        {

            // everybody attached to the ticket
            $participants = Access::participants($ticket_id);

            // an internal note goes nowhere near anybody who can't see internal notes
            if ($internal) {

                // keep only the people allowed to see them
                $participants = array_filter($participants, static function (int $user_id) use ($ticket_id): bool {
                    return Access::canSeeInternal($ticket_id, $user_id);
                });

                // and loop in the rest of the department's agents if we're set up to
                if ($this->opt('notify_all_agents', false)) {
                    $participants = array_merge($participants, Ticket::eligibleAgents($ticket_id));
                }
            }

            // turn them into addresses, skipping whoever wrote it
            return $this->addressesFor($participants, $author_id);
        }

        /**
         * Let the requester know their ticket's status moved.
         *
         * @since  1.0.0
         * @access public
         * @param  int $ticket_id The ticket id.
         * @param  int $term_id   The new status term id.
         * @param  int $previous  The old status term id.
         * @return void
         */
        public function onStatusChanged(int $ticket_id, int $term_id, int $previous): void
        {

            // only if we're set up to do this, and only when it's a real change
            if (! $this->opt('notify_status_change', true) || $previous < 1) {
                return;
            }

            // who opened it
            $requester = (int) get_post_meta($ticket_id, Access::META_REQUESTER, true);

            // pull their address
            $addresses = $this->addressesFor(array($requester), get_current_user_id());

            // nothing to send
            if (empty($addresses)) {
                return;
            }

            // and let them know
            $this->send(
                $addresses,
                $this->template(
                    'email_status_change_subject',
                    __('[{ticket_number}] Status updated: {status}', 'kp-support')
                ),
                $this->template(
                    'email_status_change_body',
                    __("Hi {customer_name},\n\nThe status of your ticket <strong>{ticket_subject}</strong> is now <strong>{status}</strong>.\n\nView your ticket:\n{ticket_url}\n\n- {site_name}", 'kp-support')
                ),
                $this->tokens($ticket_id)
            );
        }

        /**
         * Let an agent know a ticket landed on their plate.
         *
         * @since  1.0.0
         * @access public
         * @param  int $ticket_id The ticket id.
         * @param  int $user_id   The agent it went to.
         * @param  int $previous  Who had it before.
         * @return void
         */
        public function onTicketAssigned(int $ticket_id, int $user_id, int $previous): void
        {

            // only if we're set up to do this, and only when somebody actually got it
            if (! $this->opt('notify_assignment', true) || $user_id < 1) {
                return;
            }

            // pull their address, skipping it if they assigned it to themselves
            $addresses = $this->addressesFor(array($user_id), get_current_user_id());

            // nothing to send
            if (empty($addresses)) {
                return;
            }

            // and let them know
            $this->send(
                $addresses,
                $this->template(
                    'email_assignment_subject',
                    __('[{ticket_number}] Assigned to you: {ticket_subject}', 'kp-support')
                ),
                $this->template(
                    'email_assignment_body',
                    __("A ticket has been assigned to you.\n\n<strong>{ticket_subject}</strong>\nFrom: {customer_name}\nPriority: {priority}\nDepartment: {department}\n\nView the ticket:\n{ticket_url}", 'kp-support')
                ),
                $this->tokens($ticket_id)
            );
        }

        /**
         * Turn a list of user ids into email addresses.
         *
         * @since  1.0.0
         * @access private
         * @param  array<int, int> $user_ids The users to look up.
         * @param  int             $exclude  A user id to leave out, normally the author.
         * @return array<int, string> The unique email addresses.
         */
        private function addressesFor(array $user_ids, int $exclude = 0): array
        {

            // walk them and pull addresses
            $addresses = array();
            foreach (array_unique(array_map('absint', $user_ids)) as $_user_id) {

                // skip the empties and whoever we're excluding
                if ($_user_id < 1 || $_user_id === $exclude) {
                    continue;
                }

                // go get them
                $user = get_userdata($_user_id);

                // and keep their address if it looks real
                if ($user instanceof \WP_User && is_email($user->user_email)) {
                    $addresses[] = $user->user_email;
                }
            }

            // hand back a unique list
            return array_values(array_unique($addresses));
        }

        /**
         * Build the token replacements for a ticket.
         *
         * @since  1.0.0
         * @access private
         * @param  int $ticket_id The ticket id.
         * @return array<string, string> The tokens and their values.
         */
        private function tokens(int $ticket_id): array
        {

            // grab the ticket
            $ticket = get_post($ticket_id);

            // nothing to build from
            if (! $ticket instanceof \WP_Post) {
                return array();
            }

            // who opened it
            $requester_id = (int) get_post_meta($ticket_id, Access::META_REQUESTER, true);
            $requester = get_userdata($requester_id);

            // the terms hanging off it
            $status = Ticket::term($ticket_id, PostTypes::TAX_STATUS);
            $priority = Ticket::term($ticket_id, PostTypes::TAX_PRIORITY);
            $department = Ticket::term($ticket_id, PostTypes::TAX_DEPARTMENT);
            $category = Ticket::term($ticket_id, PostTypes::TAX_CATEGORY);

            // and put the whole set together
            return array(
                '{ticket_number}'  => Ticket::number($ticket_id),
                '{ticket_subject}' => $ticket->post_title,
                '{ticket_content}' => wpautop(wp_kses_post($ticket->post_content)),
                '{ticket_url}'     => Portal::ticketUrl($ticket_id),
                '{customer_name}'  => ($requester instanceof \WP_User) ? $requester->display_name : __('there', 'kp-support'),
                '{customer_email}' => ($requester instanceof \WP_User) ? $requester->user_email : '',
                '{status}'         => ($status instanceof \WP_Term) ? $status->name : '',
                '{priority}'       => ($priority instanceof \WP_Term) ? $priority->name : '',
                '{department}'     => ($department instanceof \WP_Term) ? $department->name : '',
                '{category}'       => ($category instanceof \WP_Term) ? $category->name : '',
                '{site_name}'      => get_bloginfo('name'),
                '{site_url}'       => home_url('/'),
                '{reply_content}'  => '',
                '{reply_author}'   => '',
            );
        }

        /**
         * Pull an email template out of the settings, or fall back to our default.
         *
         * @since  1.0.0
         * @access private
         * @param  string $key      The settings key.
         * @param  string $fallback The default template.
         * @return string The template body.
         */
        private function template(string $key, string $fallback): string
        {

            // what the admin has configured, if anything
            $configured = (string) $this->opt($key, '');

            // theirs wins, otherwise ours
            return ($configured !== '') ? $configured : $fallback;
        }

        /**
         * Swap the tokens into a template.
         *
         * @since  1.0.0
         * @access private
         * @param  string                $template The template body.
         * @param  array<string, string> $tokens   The tokens and their values.
         * @return string The parsed template.
         */
        private function parse(string $template, array $tokens): string
        {

            // straight swap of every token we know about
            return str_replace(array_keys($tokens), array_values($tokens), $template);
        }

        /**
         * Actually send one of our emails.
         *
         * @since  1.0.0
         * @access private
         * @param  string|array<int, string> $to      Who it's going to.
         * @param  string                    $subject The subject template.
         * @param  string                    $body    The body template.
         * @param  array<string, string>     $tokens  The tokens to swap in.
         * @return bool True if it handed off to wp_mail cleanly.
         */
        private function send(string|array $to, string $subject, string $body, array $tokens): bool
        {

            // nobody to send to
            if (empty($to)) {
                MailLog::record(
                    'skipped',
                    '',
                    wp_specialchars_decode($this->parse($subject, $tokens), ENT_QUOTES),
                    __('No recipients left after exclusions.', 'kp-support')
                );
                return false;
            }

            // swap the tokens into both parts
            $subject = wp_specialchars_decode($this->parse($subject, $tokens), ENT_QUOTES);
            $body = $this->parse($body, $tokens);

            // wrap the body up in our little HTML shell
            $html = $this->wrapBody($body, $tokens);

            // build the from header out of the settings
            $from_name = (string) $this->opt('email_from_name', get_bloginfo('name'));
            $from_address = (string) $this->opt('email_from_address', get_bloginfo('admin_email'));

            // put the headers together
            $headers = array('Content-Type: text/html; charset=UTF-8');

            // only add a from header if the address is real
            if (is_email($from_address)) {
                $headers[] = sprintf('From: %s <%s>', $from_name, $from_address);
            }

            // let people adjust any of it before it goes
            $email = (array) apply_filters('kpts_notification_email', array(
                'to'      => $to,
                'subject' => $subject,
                'body'    => $html,
                'headers' => $headers,
            ), $tokens);

            // and off it goes, one message per recipient so nobody sees the others
            $sent = true;

            // flag the whole run as ours so our SMTP settings apply to it
            Smtp::$sending = true;

            try {
                foreach ((array) $email['to'] as $_address) {

                    // fire it and see what came back
                    $ok = wp_mail($_address, $email['subject'], $email['body'], $email['headers']);

                    // log it either way, pulling whatever the mailer complained about
                    MailLog::record(
                        $ok ? 'sent' : 'failed',
                        (string) $_address,
                        (string) $email['subject'],
                        $ok ? '' : MailLog::takeError()
                    );

                    // and keep track of the overall result
                    $sent = $ok && $sent;
                }
            } finally {

                // whatever happened, we're done being us
                Smtp::$sending = false;
            }

            // report back
            return $sent;
        }

        /**
         * Wrap an email body in a simple HTML shell.
         *
         * @since  1.0.0
         * @access private
         * @param  string                $body   The parsed body.
         * @param  array<string, string> $tokens The tokens, so we can title it.
         * @return string The full HTML email.
         */
        private function wrapBody(string $body, array $tokens): string
        {

            // turn the plain line breaks into paragraphs, then keep it to safe markup
            $content = wpautop(wp_kses_post($body));

            // a deliberately plain shell, email clients are miserable about CSS
            $html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>' . esc_html($tokens['{ticket_subject}'] ?? '') . '</title></head>'
                . '<body style="margin:0;padding:0;background:#f4f5f7;">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">'
                . '<tr><td align="center">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px;padding:28px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1d2327;">'
                . '<tr><td>' . $content . '</td></tr>'
                . '<tr><td style="padding-top:20px;border-top:1px solid #e2e4e7;margin-top:20px;color:#787c82;font-size:12px;">'
                . esc_html($tokens['{site_name}'] ?? '') . '</td></tr>'
                . '</table></td></tr></table></body></html>';

            // let people swap the whole shell out
            return (string) apply_filters('kpts_email_html', $html, $body, $tokens);
        }
    }
}
