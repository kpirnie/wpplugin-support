<?php

/**
 * MailLog - A short history of what we tried to email
 *
 * wp_mail() returning false is indistinguishable from success from the outside,
 * and a notification with nobody left to send to is indistinguishable from a
 * broken mailer. This keeps a rolling record of both so neither is a mystery.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.21
 */

declare(strict_types=1);

namespace KP\Support\Helpers;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Helpers\MailLog')) {

    /**
     * Class MailLog
     *
     * Records the outcome of every email we attempt.
     *
     * @since 1.0.21
     */
    class MailLog
    {
        /**
         * Where the log lives. Deliberately its own option so it stays out of
         * the autoloaded settings blob.
         *
         * @since 1.0.21
         * @var string
         */
        public const OPTION_KEY = 'kpts_mail_log';

        /**
         * How many entries we hold on to.
         *
         * @since 1.0.21
         * @var int
         */
        public const KEEP = 25;

        /**
         * The last error WordPress handed us, if any.
         *
         * @since 1.0.21
         * @var string
         */
        private static string $last_error = '';

        /**
         * Whether we've already hooked ourselves in.
         *
         * @since 1.0.21
         * @var bool
         */
        private static bool $watching = false;

        /**
         * Start listening for mail failures.
         *
         * @since  1.0.21
         * @access public
         * @return void
         */
        public static function watch(): void
        {

            // only ever once
            if (self::$watching) {
                return;
            }

            // WordPress hands us a WP_Error when the mailer throws
            add_action('wp_mail_failed', array(__CLASS__, 'captureError'));

            // and remember that we're on it
            self::$watching = true;
        }

        /**
         * Hold on to whatever WordPress told us went wrong.
         *
         * @since  1.0.21
         * @access public
         * @param  \WP_Error $error The failure.
         * @return void
         */
        public static function captureError(\WP_Error $error): void
        {
            self::$last_error = $error->get_error_message();
        }

        /**
         * Pull and clear the last captured error.
         *
         * @since  1.0.21
         * @access public
         * @return string The error message, or an empty string.
         */
        public static function takeError(): string
        {

            // grab it and reset, so it can't leak onto the next send
            $error = self::$last_error;
            self::$last_error = '';

            return $error;
        }

        /**
         * Write an entry to the log.
         *
         * @since  1.0.21
         * @access public
         * @param  string $outcome   One of sent, failed or skipped.
         * @param  string $recipient Who it was going to, if anybody.
         * @param  string $subject   The subject line.
         * @param  string $error     Whatever went wrong, if anything.
         * @return void
         */
        public static function record(string $outcome, string $recipient, string $subject, string $error = ''): void
        {

            // what we've already got
            $log = get_option(self::OPTION_KEY, array());
            if (! is_array($log)) {
                $log = array();
            }

            // newest first
            array_unshift($log, array(
                'time'      => time(),
                'outcome'   => $outcome,
                'recipient' => $recipient,
                'subject'   => $subject,
                'error'     => $error,
            ));

            // trim it back and save it, never autoloaded
            update_option(self::OPTION_KEY, array_slice($log, 0, self::KEEP), false);
        }

        /**
         * Pull the whole log back.
         *
         * @since  1.0.21
         * @access public
         * @return array<int, array<string, mixed>> The entries, newest first.
         */
        public static function entries(): array
        {

            // grab it
            $log = get_option(self::OPTION_KEY, array());

            // and hand back something we can actually loop
            return is_array($log) ? $log : array();
        }

        /**
         * Empty the log out.
         *
         * @since  1.0.21
         * @access public
         * @return void
         */
        public static function clear(): void
        {
            delete_option(self::OPTION_KEY);
        }
    }
}
