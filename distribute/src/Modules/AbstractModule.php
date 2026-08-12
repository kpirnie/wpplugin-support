<?php

/**
 * AbstractModule - The base every module extends
 *
 * Gives each module a common register() contract plus a couple of shortcuts
 * we end up wanting just about everywhere.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

namespace KP\Support\Modules;

use KP\Support\Plugin;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Modules\AbstractModule')) {

    /**
     * Class AbstractModule
     *
     * Base class for every module in the plugin.
     *
     * @since 1.0.0
     */
    abstract class AbstractModule
    {
        /**
         * Hook this module into WordPress.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        abstract public function register(): void;

        /**
         * Shortcut to pull a plugin setting.
         *
         * @since  1.0.0
         * @access protected
         * @param  string $key     The setting key.
         * @param  mixed  $default The fallback if it isn't set.
         * @return mixed The setting value.
         */
        protected function opt(string $key, mixed $default = null): mixed
        {
            return Plugin::opt($key, $default);
        }

        /**
         * Shortcut to the field framework's storage object.
         *
         * @since  1.0.0
         * @access protected
         * @return mixed The storage instance, or null if the framework isn't up.
         */
        protected function storage(): mixed
        {

            // grab the framework
            $framework = Plugin::instance()->framework();

            // and its storage, assuming we actually have one
            return ($framework !== null) ? $framework->getStorage() : null;
        }
    }
}
