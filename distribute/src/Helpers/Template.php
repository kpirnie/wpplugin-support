<?php

/**
 * Template - Template loading with theme overrides
 *
 * Finds our front-end templates, letting a theme override any of them by
 * dropping a matching file into a kp-support directory.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

namespace KP\Support\Helpers;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Helpers\Template')) {

    /**
     * Class Template
     *
     * Locates and renders our front-end templates.
     *
     * @since 1.0.0
     */
    final class Template
    {
        /**
         * Find a template file, preferring the theme's copy.
         *
         * @since  1.0.0
         * @access public
         * @param  string $name The template name, with no extension.
         * @return string The full path, or an empty string if we couldn't find it.
         */
        public static function locate(string $name): string
        {

            // only ever allow simple names, no walking out of our directory
            $name = preg_replace('/[^a-z0-9_-]/', '', strtolower($name));

            // nothing usable left
            if ($name === '') {
                return '';
            }

            // give the theme first crack at it
            $theme_file = locate_template(array('kp-support/' . $name . '.php'));

            // if the theme has one, use it
            if ($theme_file !== '') {
                return $theme_file;
            }

            // otherwise fall back to ours
            $ours = KP_SUPPORT_DIR . 'templates/' . $name . '.php';

            // hand it back if it's actually there
            return is_readable($ours) ? $ours : '';
        }

        /**
         * Render a template out to the page.
         *
         * @since  1.0.0
         * @access public
         * @param  string              $name The template name.
         * @param  array<string, mixed> $args Variables to hand the template.
         * @return void
         */
        public static function render(string $name, array $args = array()): void
        {

            // go find it
            $file = self::locate($name);

            // nothing to render
            if ($file === '') {
                return;
            }

            // pull the args into scope for the template, then load it
            extract($args, EXTR_SKIP); // phpcs:ignore WordPress.PHP.DontExtract -- template arguments, keys are ours
            include $file;
        }

        /**
         * Render a template and hand back the markup instead of printing it.
         *
         * @since  1.0.0
         * @access public
         * @param  string              $name The template name.
         * @param  array<string, mixed> $args Variables to hand the template.
         * @return string The rendered markup.
         */
        public static function get(string $name, array $args = array()): string
        {

            // catch everything the template prints
            ob_start();
            self::render($name, $args);

            // and hand it back
            return (string) ob_get_clean();
        }
    }
}
