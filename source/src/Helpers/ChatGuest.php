<?php

/**
 * ChatGuest - Guest chat sessions
 *
 * A visitor who starts a chat without an account still gets one, because the
 * chat records point at a user id. They are never logged in though, so there
 * is no session to authorise their later requests with. Instead they carry a
 * signed cookie that is good for one chat and nothing else.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie\.com>
 * @since       1.0.40
 */

declare(strict_types=1);

namespace KP\Support\Helpers;

use KP\Support\Modules\Roles;
use KP\Support\Plugin;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Helpers\ChatGuest')) {

    /**
     * Class ChatGuest
     *
     * Mints and checks the guest chat cookie, and finds or builds the account
     * behind it.
     *
     * @since 1.0.40
     */
    class ChatGuest
    {
        /**
         * The cookie the guest session rides in.
         *
         * @since 1.0.40
         * @var string
         */
        public const COOKIE = 'kpts_chat_guest';

        /**
         * Find the account behind an email address, creating one if we have to.
         *
         * Nothing is emailed and nobody is logged in, the account exists purely
         * so the chat has somewhere to hang.
         *
         * @since  1.0.40
         * @access public
         * @param  string $email The email they gave us.
         * @param  string $first Their first name.
         * @param  string $last  Their last name.
         * @return int|\WP_Error The user id, or an error.
         */
        public static function resolve(string $email, string $first, string $last): int|\WP_Error
        {

            // it has to be a real address
            if (! is_email($email)) {
                return new \WP_Error('kpts_bad_email', __('Please enter a valid email address.', 'kp-support'));
            }

            // if we already know them, that's who this is
            $existing = email_exists($email);
            if ($existing) {
                return (int) $existing;
            }

            // build a username off the address, making sure it's unique
            $username = self::uniqueUsername($email);

            // and stand the account up, with a password nobody will ever use
            $user_id = wp_insert_user(array(
                'user_login'   => $username,
                'user_email'   => $email,
                'user_pass'    => wp_generate_password(24, true, true),
                'first_name'   => $first,
                'last_name'    => $last,
                'display_name' => trim($first . ' ' . $last) ?: $username,
                'role'         => Roles::ROLE_CUSTOMER,
            ));

            // if that blew up, hand the reason back
            if (is_wp_error($user_id)) {
                return $user_id;
            }

            // mark where they came from, and let people hook in
            update_user_meta((int) $user_id, 'kpts_created_via', 'chat');
            do_action('kpts_chat_guest_created', (int) $user_id);

            // hand the new id back
            return (int) $user_id;
        }

        /**
         * Issue the cookie that gets a guest back into their own chat.
         *
         * @since  1.0.40
         * @access public
         * @param  int $chat_id The chat it's good for.
         * @param  int $user_id The account behind it.
         * @return void
         */
        public static function issue(int $chat_id, int $user_id): void
        {

            // when it stops being any good
            $expires = time() + self::lifetime();

            // sign the three of them together
            $token = implode('|', array($chat_id, $user_id, $expires, self::sign($chat_id, $user_id, $expires)));

            // and set it, http only so no script on the page can read it
            setcookie(self::COOKIE, $token, array(
                'expires'  => $expires,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ));

            // and make it readable on this request too
            $_COOKIE[self::COOKIE] = $token;
        }

        /**
         * Throw the cookie away.
         *
         * @since  1.0.40
         * @access public
         * @return void
         */
        public static function clear(): void
        {

            // expire it in the past
            setcookie(self::COOKIE, '', array(
                'expires'  => time() - HOUR_IN_SECONDS,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ));

            // and drop it off this request
            unset($_COOKIE[self::COOKIE]);
        }

        /**
         * Read the guest session off the cookie, if there is a good one.
         *
         * @since  1.0.40
         * @access public
         * @return array{chat_id: int, user_id: int} The session, zeros if there isn't one.
         */
        public static function current(): array
        {

            // nothing to work with
            $empty = array('chat_id' => 0, 'user_id' => 0);
            if (empty($_COOKIE[self::COOKIE])) {
                return $empty;
            }

            // it has to be the shape we wrote
            $parts = explode('|', sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])));
            if (count($parts) !== 4) {
                return $empty;
            }

            // pull it apart
            $chat_id = absint($parts[0]);
            $user_id = absint($parts[1]);
            $expires = absint($parts[2]);
            $signature = (string) $parts[3];

            // nothing usable in it
            if ($chat_id < 1 || $user_id < 1 || $expires < 1) {
                return $empty;
            }

            // it has to still be good
            if ($expires < time()) {
                return $empty;
            }

            // and the signature has to check out, compared in constant time
            if (! hash_equals(self::sign($chat_id, $user_id, $expires), $signature)) {
                return $empty;
            }

            // finally, the chat has to actually be theirs
            if (Chat::visitor($chat_id) !== $user_id) {
                return $empty;
            }

            // and that's a session
            return array('chat_id' => $chat_id, 'user_id' => $user_id);
        }

        /**
         * The user id behind the guest session, if there is one.
         *
         * @since  1.0.40
         * @access public
         * @param  int $chat_id Only accept the session if it's for this chat, 0 for any.
         * @return int The user id, or 0.
         */
        public static function userId(int $chat_id = 0): int
        {

            // whatever they're carrying
            $session = self::current();

            // nothing at all
            if ($session['user_id'] < 1) {
                return 0;
            }

            // a session for one chat is no good for another
            if ($chat_id > 0 && $session['chat_id'] !== $chat_id) {
                return 0;
            }

            // hand the account back
            return $session['user_id'];
        }

        /**
         * How long a guest session is good for, in seconds.
         *
         * @since  1.0.40
         * @access public
         * @return int The lifetime.
         */
        public static function lifetime(): int
        {

            // it outlives the chat by nothing, the reaper closes those out daily
            $hours = max(1, (int) Plugin::opt('chat_abandon_hours', 24));

            // and back in seconds
            return $hours * HOUR_IN_SECONDS;
        }

        /**
         * Sign a session.
         *
         * @since  1.0.40
         * @access private
         * @param  int $chat_id The chat id.
         * @param  int $user_id The user id.
         * @param  int $expires When it stops being good.
         * @return string The signature.
         */
        private static function sign(int $chat_id, int $user_id, int $expires): string
        {

            // keyed on the site's own auth salt, so it never travels
            return hash_hmac('sha256', $chat_id . '|' . $user_id . '|' . $expires, wp_salt('auth'));
        }

        /**
         * Build a username off an email address that nothing else is using.
         *
         * @since  1.0.40
         * @access private
         * @param  string $email The email address.
         * @return string The username.
         */
        private static function uniqueUsername(string $email): string
        {

            // start with the local part, cleaned down to something WordPress likes
            $base = sanitize_user((string) strstr($email, '@', true), true);

            // if there was nothing usable in it, fall back to something generic
            $base = ($base !== '') ? $base : 'customer';

            // if it's free, we're done
            if (! username_exists($base)) {
                return $base;
            }

            // otherwise count up until we find one that isn't taken
            $suffix = 2;
            while (username_exists($base . $suffix)) {
                $suffix++;
            }

            // and that's the one
            return $base . $suffix;
        }
    }
}
