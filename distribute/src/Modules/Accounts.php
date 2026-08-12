<?php

/**
 * Accounts - Registration, login and profile management
 *
 * Handles the front-end account forms. These are plain form posts rather than
 * AJAX so they keep working without JavaScript, and so the login can set its
 * cookies the normal way.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

namespace KP\Support\Modules;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Modules\Accounts')) {

    /**
     * Class Accounts
     *
     * Front-end account handling.
     *
     * @since 1.0.0
     */
    class Accounts extends AbstractModule
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

            // handle the account form posts
            add_action('init', array($this, 'handleForms'), 30);

            // keep customers out of the dashboard
            add_action('admin_init', array($this, 'blockAdminAccess'), 1);
            add_filter('show_admin_bar', array($this, 'filterAdminBar'));

            // and send them somewhere useful when they log in
            add_filter('login_redirect', array($this, 'loginRedirect'), 10, 3);
        }

        /**
         * Route whichever account form just got posted.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function handleForms(): void
        {

            // nothing posted, nothing to do
            if (empty($_POST['kpts_action'])) {
                return;
            }

            // what are they trying to do
            $action = sanitize_key(wp_unslash($_POST['kpts_action']));

            // and hand it off
            match ($action) {
                'login'    => $this->handleLogin(),
                'register' => $this->handleRegister(),
                'profile'  => $this->handleProfile(),
                default    => null,
            };
        }

        /**
         * Log somebody in.
         *
         * @since  1.0.0
         * @access private
         * @return void
         */
        private function handleLogin(): void
        {

            // check the nonce first, always
            if (! isset($_POST['kpts_login_nonce']) || ! wp_verify_nonce(sanitize_key(wp_unslash($_POST['kpts_login_nonce'])), 'kpts_login')) {
                $this->redirect('error', 'bad_nonce');
            }

            // already logged in, so there's nothing to do
            if (is_user_logged_in()) {
                $this->redirect('success', 'logged_in');
            }

            // pull what they gave us, we don't sanitize the password, it goes through as typed
            $login = sanitize_text_field(wp_unslash($_POST['kpts_login'] ?? ''));
            $password = (string) ($_POST['kpts_password'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- passwords are verified, never stored or echoed
            $remember = ! empty($_POST['kpts_remember']);

            // they have to give us both
            if ($login === '' || $password === '') {
                $this->redirect('error', 'empty_login');
            }

            // try to sign them in
            $user = wp_signon(array(
                'user_login'    => $login,
                'user_password' => $password,
                'remember'      => $remember,
            ), is_ssl());

            // if that didn't work, send them back with a generic message, we don't
            // want to tell an attacker which half of the credentials was wrong
            if (is_wp_error($user)) {
                $this->redirect('error', 'bad_login');
            }

            // set them as current and send them into the portal
            wp_set_current_user($user->ID);

            // off they go
            wp_safe_redirect(Portal::url());
            exit;
        }

        /**
         * Register a brand new customer.
         *
         * @since  1.0.0
         * @access private
         * @return void
         */
        private function handleRegister(): void
        {

            // check the nonce first, always
            if (! isset($_POST['kpts_register_nonce']) || ! wp_verify_nonce(sanitize_key(wp_unslash($_POST['kpts_register_nonce'])), 'kpts_register')) {
                $this->redirect('error', 'bad_nonce');
            }

            // registration has to actually be open
            if (! $this->opt('allow_registration', true)) {
                $this->redirect('error', 'registration_closed');
            }

            // already logged in, so there's nothing to do
            if (is_user_logged_in()) {
                $this->redirect('error', 'already_registered');
            }

            // if the honeypot got filled in, quietly pretend it worked
            if (! empty($_POST['kpts_website'])) {
                $this->redirect('success', 'registered');
            }

            // pull everything they gave us
            $email = sanitize_email(wp_unslash($_POST['kpts_email'] ?? ''));
            $first = sanitize_text_field(wp_unslash($_POST['kpts_first_name'] ?? ''));
            $last = sanitize_text_field(wp_unslash($_POST['kpts_last_name'] ?? ''));
            $password = (string) ($_POST['kpts_password'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed by WP, never stored or echoed
            $confirm = (string) ($_POST['kpts_password_confirm'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only

            // the email has to be real
            if (! is_email($email)) {
                $this->redirect('error', 'bad_email');
            }

            // and not already taken
            if (email_exists($email)) {
                $this->redirect('error', 'email_taken');
            }

            // the passwords have to match
            if ($password !== $confirm) {
                $this->redirect('error', 'password_mismatch');
            }

            // and be long enough to be worth something
            if (strlen($password) < 8) {
                $this->redirect('error', 'password_short');
            }

            // build a username off the email, making sure it's unique
            $username = $this->uniqueUsername($email);

            // create the account
            $user_id = wp_insert_user(array(
                'user_login' => $username,
                'user_email' => $email,
                'user_pass'  => $password,
                'first_name' => $first,
                'last_name'  => $last,
                'display_name' => trim($first . ' ' . $last) ?: $username,
                'role'       => Roles::ROLE_CUSTOMER,
            ));

            // if that blew up, send them back
            if (is_wp_error($user_id)) {
                $this->redirect('error', 'registration_failed');
            }

            // let WordPress send its usual new user notifications
            if ($this->opt('notify_new_user', true)) {
                wp_new_user_notification((int) $user_id, null, 'both');
            }

            // let people hook in
            do_action('kpts_user_registered', (int) $user_id);

            // log them straight in if we're set up to
            if ($this->opt('auto_login_after_register', true)) {

                // set the auth cookie and mark them current
                wp_set_current_user((int) $user_id);
                wp_set_auth_cookie((int) $user_id, false, is_ssl());

                // and drop them into the portal
                wp_safe_redirect(Portal::url());
                exit;
            }

            // otherwise send them back to log in
            $this->redirect('success', 'registered');
        }

        /**
         * Update somebody's profile.
         *
         * @since  1.0.0
         * @access private
         * @return void
         */
        private function handleProfile(): void
        {

            // check the nonce first, always
            if (! isset($_POST['kpts_profile_nonce']) || ! wp_verify_nonce(sanitize_key(wp_unslash($_POST['kpts_profile_nonce'])), 'kpts_profile')) {
                $this->redirect('error', 'bad_nonce');
            }

            // and they have to actually be logged in to have a profile
            if (! is_user_logged_in()) {
                $this->redirect('error', 'not_logged_in');
            }

            // who we're updating, which is only ever themselves
            $user_id = get_current_user_id();

            // pull what they gave us
            $first = sanitize_text_field(wp_unslash($_POST['kpts_first_name'] ?? ''));
            $last = sanitize_text_field(wp_unslash($_POST['kpts_last_name'] ?? ''));
            $email = sanitize_email(wp_unslash($_POST['kpts_email'] ?? ''));

            // the email still has to be real
            if (! is_email($email)) {
                $this->redirect('error', 'bad_email', 'profile');
            }

            // and if they're changing it, it can't belong to somebody else
            $existing = email_exists($email);
            if ($existing && (int) $existing !== $user_id) {
                $this->redirect('error', 'email_taken', 'profile');
            }

            // build the update
            $update = array(
                'ID'           => $user_id,
                'first_name'   => $first,
                'last_name'    => $last,
                'user_email'   => $email,
                'display_name' => trim($first . ' ' . $last) ?: null,
            );

            // drop the display name if we couldn't build one
            if ($update['display_name'] === null) {
                unset($update['display_name']);
            }

            // are they changing their password too
            $password = (string) ($_POST['kpts_password'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed by WP, never stored or echoed
            $confirm = (string) ($_POST['kpts_password_confirm'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only

            // if they filled the password in, check it over
            if ($password !== '') {

                // they have to match
                if ($password !== $confirm) {
                    $this->redirect('error', 'password_mismatch', 'profile');
                }

                // and be long enough
                if (strlen($password) < 8) {
                    $this->redirect('error', 'password_short', 'profile');
                }

                // good to change
                $update['user_pass'] = $password;
            }

            // push the update through
            $result = wp_update_user($update);

            // if that blew up, send them back
            if (is_wp_error($result)) {
                $this->redirect('error', 'profile_failed', 'profile');
            }

            // changing the password logs them out, so put their cookie back
            if (isset($update['user_pass'])) {
                wp_set_auth_cookie($user_id, false, is_ssl());
            }

            // and let them know it worked
            $this->redirect('success', 'profile_updated', 'profile');
        }

        /**
         * Build a unique username out of an email address.
         *
         * @since  1.0.0
         * @access private
         * @param  string $email The email address.
         * @return string A username nobody is using yet.
         */
        private function uniqueUsername(string $email): string
        {

            // start with the part before the at sign
            $base = sanitize_user((string) strstr($email, '@', true), true);

            // fall back to something generic if that came out empty
            if ($base === '') {
                $base = 'customer';
            }

            // if nobody has it, we're done
            if (! username_exists($base)) {
                return $base;
            }

            // otherwise keep adding a suffix until we find a free one
            $suffix = 1;
            while (username_exists($base . $suffix) && $suffix < 1000) {
                $suffix++;
            }

            // hand back the free one
            return $base . $suffix;
        }

        /**
         * Keep customers out of the WordPress dashboard.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function blockAdminAccess(): void
        {

            // if we're not set up to do this, leave them alone
            if (! $this->opt('block_admin_access', true)) {
                return;
            }

            // AJAX has to keep working
            if (wp_doing_ajax()) {
                return;
            }

            // anybody who can work tickets is allowed in
            if (current_user_can('edit_kpts_tickets')) {
                return;
            }

            // as is anybody who has business in there for other reasons
            if (current_user_can('edit_posts') || current_user_can('manage_options')) {
                return;
            }

            // everybody else gets bounced to the portal
            wp_safe_redirect(Portal::url());
            exit;
        }

        /**
         * Hide the admin bar from customers.
         *
         * @since  1.0.0
         * @access public
         * @param  bool $show Whether the bar is currently showing.
         * @return bool Whether it should show.
         */
        public function filterAdminBar($show): bool
        {

            // if we're not blocking the dashboard, leave the bar alone
            if (! $this->opt('block_admin_access', true)) {
                return (bool) $show;
            }

            // customers don't need it
            if (is_user_logged_in() && ! current_user_can('edit_kpts_tickets') && ! current_user_can('edit_posts')) {
                return false;
            }

            // everybody else keeps it
            return (bool) $show;
        }

        /**
         * Send customers to the portal when they log in.
         *
         * @since  1.0.0
         * @access public
         * @param  string           $redirect The URL they're headed to.
         * @param  string           $request  The URL they asked for.
         * @param  \WP_User|\WP_Error $user   The user, or an error.
         * @return string Where they should actually go.
         */
        public function loginRedirect($redirect, $request, $user): string
        {

            // if the login failed there's nothing to redirect
            if (! $user instanceof \WP_User) {
                return (string) $redirect;
            }

            // if we're not blocking the dashboard, leave it alone
            if (! $this->opt('block_admin_access', true)) {
                return (string) $redirect;
            }

            // anybody who can work tickets goes wherever they were headed
            if (user_can($user, 'edit_kpts_tickets') || user_can($user, 'edit_posts')) {
                return (string) $redirect;
            }

            // everybody else lands in the portal
            return Portal::url();
        }

        /**
         * Send somebody back to the portal with a message code.
         *
         * We only ever pass a code, never a message, so there's nothing to
         * reflect back onto the page.
         *
         * @since  1.0.0
         * @access private
         * @param  string $type The message type, error or success.
         * @param  string $code The message code.
         * @param  string $view The view to land on.
         * @return void
         */
        private function redirect(string $type, string $code, string $view = ''): void
        {

            // start at the portal
            $url = ($view !== '') ? Portal::viewUrl($view) : Portal::url();

            // tack the message code on
            $url = add_query_arg(array('kpts_' . $type => $code), $url);

            // and send them there
            wp_safe_redirect($url);
            exit;
        }

        /**
         * Turn a message code into something we can show somebody.
         *
         * @since  1.0.0
         * @access public
         * @param  string $type The message type, error or success.
         * @param  string $code The message code.
         * @return string The message, or an empty string if we don't know the code.
         */
        public static function message(string $type, string $code): string
        {

            // every message we're willing to show, keyed by code
            $messages = array(
                'error' => array(
                    'bad_nonce'           => __('That request expired. Please try again.', 'kp-support'),
                    'empty_login'         => __('Please enter your email and password.', 'kp-support'),
                    'bad_login'           => __('That email or password was not correct.', 'kp-support'),
                    'registration_closed' => __('Registration is currently closed.', 'kp-support'),
                    'already_registered'  => __('You already have an account and are logged in.', 'kp-support'),
                    'bad_email'           => __('Please enter a valid email address.', 'kp-support'),
                    'email_taken'         => __('There is already an account using that email address.', 'kp-support'),
                    'password_mismatch'   => __('Those passwords did not match.', 'kp-support'),
                    'password_short'      => __('Please use a password of at least 8 characters.', 'kp-support'),
                    'registration_failed' => __('Your account could not be created. Please try again.', 'kp-support'),
                    'profile_failed'      => __('Your profile could not be updated. Please try again.', 'kp-support'),
                    'not_logged_in'       => __('Please log in first.', 'kp-support'),
                ),
                'success' => array(
                    'registered'      => __('Your account has been created. You can log in now.', 'kp-support'),
                    'logged_in'       => __('You are logged in.', 'kp-support'),
                    'profile_updated' => __('Your profile has been updated.', 'kp-support'),
                    'ticket_created'  => __('Your ticket has been opened.', 'kp-support'),
                ),
            );

            // hand back the message if we know the code, otherwise nothing
            return $messages[$type][$code] ?? '';
        }

        /**
         * Render whatever message is sitting on the query string.
         *
         * @since  1.0.0
         * @access public
         * @return string The rendered notice, or an empty string.
         */
        public static function renderMessages(): string
        {

            // what we'll build up
            $output = '';

            // check both message types
            foreach (array('error', 'success') as $_type) {

                // nothing of this type
                if (empty($_GET['kpts_' . $_type])) {
                    continue;
                }

                // pull the code and look it up, this is why we only ever pass codes
                $code = sanitize_key(wp_unslash($_GET['kpts_' . $_type]));
                $message = self::message($_type, $code);

                // if we don't recognize it, show nothing at all
                if ($message === '') {
                    continue;
                }

                // and build the notice
                $output .= sprintf(
                    '<div class="kpts-notice kpts-notice-%1$s">%2$s</div>',
                    esc_attr($_type),
                    esc_html($message)
                );
            }

            // hand it back
            return $output;
        }
    }
}
