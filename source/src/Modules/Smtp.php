<?php

/**
 * Smtp - Our own SMTP delivery
 *
 * Configures the PHPMailer instance WordPress hands us, but only for the mail
 * this plugin sends. Anything else on the site goes out however it already
 * went out, so a site running its own mailer plugin is left completely alone.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.21
 */

declare(strict_types=1);

namespace KP\Support\Modules;

use KP\Support\Helpers\MailLog;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Modules\Smtp')) {

    /**
     * Class Smtp
     *
     * Applies our SMTP settings to our own sends.
     *
     * @since 1.0.21
     */
    class Smtp extends AbstractModule
    {
        /**
         * Whether we're inside one of our own sends right now.
         *
         * Nothing gets reconfigured unless this is true, which is what keeps us
         * out of the way of the rest of the site's mail.
         *
         * @since 1.0.21
         * @var bool
         */
        public static bool $sending = false;

        /**
         * The mailer plugins we know about, keyed by their plugin file.
         *
         * @since 1.0.21
         * @var array<string, string>
         */
        private const KNOWN_MAILERS = array(
            'wp-mail-smtp/wp_mail_smtp.php'              => 'WP Mail SMTP',
            'easy-wp-smtp/easy-wp-smtp.php'              => 'Easy WP SMTP',
            'post-smtp/postman-smtp.php'                 => 'Post SMTP',
            'fluent-smtp/fluent-smtp.php'                => 'FluentSMTP',
            'wp-ses/wp-ses.php'                          => 'WP Offload SES Lite',
            'mailin/sendinblue.php'                       => 'Brevo',
            'gmail-smtp/main.php'                         => 'Gmail SMTP',
            'smtp-mailer/main.php'                        => 'SMTP Mailer',
        );

        /**
         * Hook this module into WordPress.
         *
         * @since  1.0.21
         * @access public
         * @return void
         */
        public function register(): void
        {

            // we want to be the last thing to touch the mailer
            add_action('phpmailer_init', array($this, 'configure'), 99);

            // and the test button needs somewhere to land
            add_action('admin_post_kpts_smtp_test', array($this, 'sendTest'));
        }

        /**
         * Point PHPMailer at our SMTP server, for our own sends only.
         *
         * @since  1.0.21
         * @access public
         * @param  \PHPMailer\PHPMailer\PHPMailer $mailer The mailer instance.
         * @return void
         */
        public function configure(\PHPMailer\PHPMailer\PHPMailer $mailer): void
        {

            // if this isn't one of ours, it's none of our business
            if (! self::$sending) {
                return;
            }

            // nor if we've been switched off
            if (! $this->opt('smtp_enable', false)) {
                return;
            }

            // we can't do anything without somewhere to send it
            $host = trim((string) $this->opt('smtp_host', ''));
            if ($host === '') {
                return;
            }

            // hand it over to SMTP
            $mailer->isSMTP();
            $mailer->Host = $host;
            $mailer->Port = (int) $this->opt('smtp_port', 587);

            // sort the encryption out
            $encryption = (string) $this->opt('smtp_encryption', 'tls');
            if ($encryption === 'none') {
                $mailer->SMTPSecure = '';
                $mailer->SMTPAutoTLS = false;
            } else {
                $mailer->SMTPSecure = $encryption;
            }

            // and the credentials, if we're authenticating
            if ($this->opt('smtp_auth', true)) {
                $mailer->SMTPAuth = true;
                $mailer->Username = (string) $this->opt('smtp_username', '');
                $mailer->Password = (string) $this->opt('smtp_password', '');
                return;
            }

            // otherwise make sure auth is properly off
            $mailer->SMTPAuth = false;
        }

        /**
         * Send a test email to whoever clicked the button.
         *
         * @since  1.0.21
         * @access public
         * @return void
         */
        public function sendTest(): void
        {

            // they have to be allowed to be in here
            if (! current_user_can('kpts_manage_settings')) {
                wp_die(esc_html__('You are not allowed to do that.', 'kp-support'));
            }

            // and it has to have come from our button
            check_admin_referer('kpts_smtp_test');

            // where it's going
            $user = wp_get_current_user();
            $address = ($user instanceof \WP_User) ? $user->user_email : '';

            // we need somewhere to send it
            if (! is_email($address)) {
                wp_safe_redirect($this->settingsUrl('no-address'));
                exit;
            }

            // make sure we're listening for a failure
            MailLog::watch();

            // build it out the same way a notification would
            $subject = __('KP Support test email', 'kp-support');
            $body = __('If you are reading this, mail is getting out.', 'kp-support');
            $headers = array('Content-Type: text/html; charset=UTF-8');

            // reuse the from settings so this tests what a notification tests
            $from_name = (string) $this->opt('email_from_name', get_bloginfo('name'));
            $from_address = (string) $this->opt('email_from_address', get_bloginfo('admin_email'));

            // only add a from header if the address is real
            if (is_email($from_address)) {
                $headers[] = sprintf('From: %s <%s>', $from_name, $from_address);
            }

            // flag it as ours, send it, and make sure the flag comes back down
            self::$sending = true;

            try {
                $sent = wp_mail($address, $subject, wpautop($body), $headers);
            } finally {
                self::$sending = false;
            }

            // record it like anything else
            MailLog::record(
                $sent ? 'sent' : 'failed',
                $address,
                $subject,
                $sent ? '' : MailLog::takeError()
            );

            // and back to the settings
            wp_safe_redirect($this->settingsUrl($sent ? 'sent' : 'failed'));
            exit;
        }

        /**
         * Work out which known mailer plugin is running, if any.
         *
         * @since  1.0.21
         * @access public
         * @return string The plugin's name, or an empty string.
         */
        public static function activeMailer(): string
        {

            // we need the plugin functions for this
            if (! function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            // walk the ones we know about
            foreach (self::KNOWN_MAILERS as $_file => $_name) {
                if (is_plugin_active($_file)) {
                    return $_name;
                }
            }

            // nothing we recognise
            return '';
        }

        /**
         * Build the url back to our SMTP tab.
         *
         * @since  1.0.21
         * @access private
         * @param  string $result What happened, for the notice.
         * @return string The admin url.
         */
        private function settingsUrl(string $result): string
        {
            return admin_url(
                'edit.php?post_type=' . PostTypes::POST_TYPE
                    . '&page=kp-support-settings&tab=smtp&kpts_test=' . rawurlencode($result)
            );
        }
    }
}
