<?php

/**
 * Plugin - The main plugin controller
 *
 * Singleton controller that boots the field framework, wires up every module,
 * and handles our activation and deactivation routines.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

namespace KP\Support;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Plugin')) {

    /**
     * Class Plugin
     *
     * The central controller for the whole plugin.
     *
     * @since 1.0.0
     */
    final class Plugin
    {
        /**
         * The option key all of our settings live under.
         *
         * @since 1.0.0
         * @var string
         */
        public const OPTION_KEY = 'kpts_settings';

        /**
         * The identifier we hand the field framework.
         *
         * @since 1.0.0
         * @var string
         */
        public const FRAMEWORK_ID = 'kp_support';

        /**
         * The option holding the version our roles were last built against.
         *
         * @since 1.0.0
         * @var string
         */
        public const CAPS_VERSION_KEY = 'kpts_caps_version';

        /**
         * The singleton instance.
         *
         * @since 1.0.0
         * @var Plugin|null
         */
        private static ?Plugin $instance = null;

        /**
         * The field framework instance, if we managed to boot it.
         *
         * @since 1.0.0
         * @var mixed
         */
        private mixed $framework = null;

        /**
         * The modules we've registered.
         *
         * @since 1.0.0
         * @var array<string, Modules\AbstractModule>
         */
        private array $modules = array();

        /**
         * Grab the singleton instance.
         *
         * @since  1.0.0
         * @access public
         * @return Plugin The one and only instance.
         */
        public static function instance(): Plugin
        {

            // spin one up if we don't have one yet
            if (self::$instance === null) {
                self::$instance = new self();
            }

            // hand it back
            return self::$instance;
        }

        /**
         * Keep the constructor to ourselves.
         *
         * @since  1.0.0
         * @access private
         */
        private function __construct() {}

        /**
         * Nobody gets to clone this.
         *
         * @since  1.0.0
         * @access private
         * @return void
         */
        private function __clone() {}

        /**
         * Nobody gets to unserialize this either.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function __wakeup(): void
        {
            throw new \RuntimeException('Cannot unserialize a singleton.');
        }

        /**
         * Fire everything up.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function run(): void
        {

            // we don't support network activation, so guard against it
            add_action('admin_init', array($this, 'blockNetworkActivation'));

            // rebuild the roles if they predate the current version
            add_action('admin_init', array($this, 'maybeUpgradeCaps'));

            // boot the field framework, bail out with a notice if it isn't there
            if (! $this->bootFramework()) {
                add_action('admin_notices', array($this, 'missingFrameworkNotice'));
                return;
            }

            // and register every one of our modules
            $this->registerModules();
        }

        /**
         * Make sure we're not running as a network activated plugin.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function blockNetworkActivation(): void
        {

            // we need the plugin functions available for this check
            if (! function_exists('is_plugin_active_for_network')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            // if we're not network activated, there's nothing to do here
            if (! is_multisite() || ! is_plugin_active_for_network(KP_SUPPORT_BASENAME)) {
                return;
            }

            // shut it down and tell them why
            deactivate_plugins(KP_SUPPORT_BASENAME, false, true);
            wp_die(
                esc_html__('KP Support cannot be network activated. Please activate it on each individual site instead.', 'kp-support'),
                esc_html__('Network Activation Not Supported', 'kp-support'),
                array('back_link' => true)
            );
        }

        /**
         * Rebuild our roles when the plugin has been updated in place.
         *
         * Activation only fires on activate, so an update over the top leaves
         * the old capability set behind. This catches that.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function maybeUpgradeCaps(): void
        {

            // what were the roles last built against
            $built = (string) get_option(self::CAPS_VERSION_KEY, '');

            // nothing to do if they're current
            if ($built === KP_SUPPORT_VERSION) {
                return;
            }

            // an update over the top never fires activate(), so make sure it's scheduled
            if (! wp_next_scheduled('kpts_daily_maintenance')) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'kpts_daily_maintenance');
            }

            // rebuild them and stamp the version
            Modules\Roles::addRoles();
            update_option(self::CAPS_VERSION_KEY, KP_SUPPORT_VERSION, false);
        }

        /**
         * Boot up the field framework.
         *
         * @since  1.0.0
         * @access private
         * @return bool True if the framework is good to go.
         */
        private function bootFramework(): bool
        {

            // if the loader isn't around, we can't do anything
            if (! class_exists('\KP\WPFieldFramework\Loader')) {
                return false;
            }

            // fire it up with our own identifier
            $this->framework = \KP\WPFieldFramework\Loader::init(self::FRAMEWORK_ID);

            // let the caller know how we did
            return $this->framework !== null;
        }

        /**
         * Get the field framework instance.
         *
         * @since  1.0.0
         * @access public
         * @return mixed The framework instance, or null.
         */
        public function framework(): mixed
        {
            return $this->framework;
        }

        /**
         * Register all of our modules.
         *
         * @since  1.0.0
         * @access private
         * @return void
         */
        private function registerModules(): void
        {

            // the modules we run, in the order they need to load
            $modules = array(
                'post_types'    => Modules\PostTypes::class,
                'roles'         => Modules\Roles::class,
                'term_fields'   => Modules\TermFields::class,
                'attachments'   => Modules\Attachments::class,
                'replies'       => Modules\Replies::class,
                'notifications' => Modules\Notifications::class,
                'smtp'          => Modules\Smtp::class,
                'accounts'      => Modules\Accounts::class,
                'portal'        => Modules\Portal::class,
                'ajax'          => Modules\Ajax::class,
                'chat_ajax'     => Modules\ChatAjax::class,
                'chat_widget'   => Modules\ChatWidget::class,
                'chat_admin'    => Modules\ChatAdmin::class,
                'updater'       => Modules\Updater::class,
                'admin'         => Modules\Admin::class,
                'settings'      => Settings\Settings::class,
            );

            // spin each one up and let it hook itself in
            foreach ($modules as $_key => $_class) {

                // make sure it actually exists first
                if (! class_exists($_class)) {
                    continue;
                }

                // instantiate, register, and hold on to it
                $module = new $_class();
                $module->register();
                $this->modules[$_key] = $module;
            }
        }

        /**
         * Grab a registered module.
         *
         * @since  1.0.0
         * @access public
         * @param  string $key The module key.
         * @return Modules\AbstractModule|null The module, or null if it isn't registered.
         */
        public function module(string $key): ?Modules\AbstractModule
        {
            return $this->modules[$key] ?? null;
        }

        /**
         * Pull a single setting value.
         *
         * @since  1.0.0
         * @access public
         * @param  string $key     The setting key.
         * @param  mixed  $default The fallback if it isn't set.
         * @return mixed The setting value.
         */
        public static function opt(string $key, mixed $default = null): mixed
        {

            // grab the whole settings array, WP caches this for us
            $options = get_option(self::OPTION_KEY, array());

            // if it's somehow not an array, just hand back the default
            if (! is_array($options)) {
                return $default;
            }

            // hand back what we found, or the default
            return (isset($options[$key]) && $options[$key] !== '') ? $options[$key] : $default;
        }

        /**
         * Show a notice when the field framework is missing.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function missingFrameworkNotice(): void
        {

            // only bother admins with this
            if (! current_user_can('activate_plugins')) {
                return;
            }

            // let them know what they need to do
            printf(
                '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <code>composer require kevinpirnie/kpt-wpfieldframework</code></p></div>',
                esc_html__('KP Support:', 'kp-support'),
                esc_html__('the KPT WP Field Framework could not be found. Install the plugin dependencies with:', 'kp-support')
            );
        }

        /**
         * Everything we need to do when the plugin is activated.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public static function activate(): void
        {

            // register the post types and taxonomies so the rewrite rules come out right
            $post_types = new Modules\PostTypes();
            $post_types->registerPostTypes();
            $post_types->registerTaxonomies();

            // seed the default terms for our taxonomies
            Modules\PostTypes::seedDefaultTerms();

            // build out our roles and capabilities
            Modules\Roles::addRoles();

            // stamp what the roles were built against
            update_option(self::CAPS_VERSION_KEY, KP_SUPPORT_VERSION, false);

            // lock down the attachment upload directory
            Modules\Attachments::protectUploadDir();

            // drop in our default settings
            self::seedDefaultSettings();

            // make sure there's a page for the portal to live on
            Modules\Portal::ensurePage();

            // and get the daily sweep going
            if (! wp_next_scheduled('kpts_daily_maintenance')) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'kpts_daily_maintenance');
            }

            // and flush the rewrite rules
            flush_rewrite_rules();
        }

        /**
         * Everything we need to do when the plugin is deactivated.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public static function deactivate(): void
        {

            // clear out anything we have scheduled
            wp_clear_scheduled_hook('kpts_daily_maintenance');

            // and flush the rewrite rules
            flush_rewrite_rules();
        }

        /**
         * Write out our default settings, without clobbering existing ones.
         *
         * @since  1.0.0
         * @access private
         * @return void
         */
        private static function seedDefaultSettings(): void
        {

            // what we've already got saved
            $existing = get_option(self::OPTION_KEY, array());
            if (! is_array($existing)) {
                $existing = array();
            }

            // the defaults we want in place
            $defaults = array(
                'tickets_per_page'      => 20,
                'poll_interval'         => 10,
                'allow_registration'    => true,
                'auto_assign'           => false,
                'require_department'    => true,
                'require_category'      => false,
                'allow_attachments'     => true,
                'max_attachment_size'   => 5,
                'max_attachments'       => 5,
                'allowed_file_types'    => 'jpg,jpeg,png,gif,webp,pdf,txt,log,csv,zip,doc,docx,xls,xlsx',
                'notify_new_ticket'     => true,
                'notify_new_reply'      => true,
                'notify_status_change'  => true,
                'email_from_name'       => get_bloginfo('name'),
                'email_from_address'    => get_bloginfo('admin_email'),
                'enable_chat'               => false,
                'chat_position'             => 'bottom-right',
                'chat_label'                => 'Need help?',
                'chat_active_label'         => 'Chat in progress...',
                'chat_department'           => '',
                'chat_ticket_prefix'        => 'CHAT - ',
                'chat_presence_window'      => 5,
                'chat_offline_message'      => __('Nobody is available right now. Leave us a message and we will get back to you.', 'kp-support'),
                'status_after_chat_convert' => 'open',
                'status_after_chat_close'   => 'closed',
                'chat_rate_limit'           => 20,
                'notify_new_chat'           => true,
                'chat_closed_message'       => __('We are closed right now. Leave us a message and we will get back to you as soon as we are available.', 'kp-support'),
                'chat_hours_enable'         => false,
                'chat_hours_sun_open'       => false,
                'chat_hours_sun_from'       => '09:00',
                'chat_hours_sun_to'         => '17:00',
                'chat_hours_mon_open'       => true,
                'chat_hours_mon_from'       => '09:00',
                'chat_hours_mon_to'         => '17:00',
                'chat_hours_tue_open'       => true,
                'chat_hours_tue_from'       => '09:00',
                'chat_hours_tue_to'         => '17:00',
                'chat_hours_wed_open'       => true,
                'chat_hours_wed_from'       => '09:00',
                'chat_hours_wed_to'         => '17:00',
                'chat_hours_thu_open'       => true,
                'chat_hours_thu_from'       => '09:00',
                'chat_hours_thu_to'         => '17:00',
                'chat_hours_fri_open'       => true,
                'chat_hours_fri_from'       => '09:00',
                'chat_hours_fri_to'         => '17:00',
                'chat_hours_sat_open'       => false,
                'chat_hours_sat_from'       => '09:00',
                'chat_hours_sat_to'         => '17:00',
                'chat_abandon_hours'        => 24,
                'chat_waiting_timeout'      => 5,
                'smtp_enable'               => false,
                'smtp_host'                 => '',
                'smtp_port'                 => 587,
                'smtp_encryption'           => 'tls',
                'smtp_auth'                 => true,
                'smtp_username'             => '',
                'smtp_password'             => '',
            );

            // merge ours in underneath whatever is already there and save it back
            update_option(self::OPTION_KEY, array_merge($defaults, $existing));
        }
    }
}
