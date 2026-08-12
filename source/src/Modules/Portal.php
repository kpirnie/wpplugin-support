<?php

/**
 * Portal - The front-end support portal
 *
 * Registers the shortcodes, works out which view somebody is asking for, and
 * loads the assets the AJAX chat needs.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

namespace KP\Support\Modules;

use KP\Support\Plugin;
use KP\Support\Helpers\Access;
use KP\Support\Helpers\Ticket;
use KP\Support\Helpers\Template;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Modules\Portal')) {

    /**
     * Class Portal
     *
     * The front-end portal for customers and agents alike.
     *
     * @since 1.0.0
     */
    class Portal extends AbstractModule
    {
        /**
         * The shortcode that renders the whole portal.
         *
         * @since 1.0.0
         * @var string
         */
        public const SHORTCODE = 'kp_support';

        /**
         * Hook this module into WordPress.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function register(): void
        {

            // the portal itself, plus the standalone account shortcodes
            add_shortcode(self::SHORTCODE, array($this, 'renderPortal'));
            add_shortcode('kp_support_login', array($this, 'renderLogin'));
            add_shortcode('kp_support_register', array($this, 'renderRegister'));
            add_shortcode('kp_support_profile', array($this, 'renderProfile'));

            // load our assets when we're actually on a page that needs them
            add_action('wp_enqueue_scripts', array($this, 'enqueueAssets'));
        }

        /**
         * Get the id of the page holding the portal shortcode.
         *
         * @since  1.0.0
         * @access public
         * @return int The page id, or 0 if there isn't one set.
         */
        public static function pageId(): int
        {

            // straight off the settings
            return absint(Plugin::opt('portal_page_id', 0));
        }

        /**
         * Get the URL of the portal page.
         *
         * @since  1.0.0
         * @access public
         * @return string The portal URL.
         */
        public static function url(): string
        {

            // what page is it on
            $page_id = self::pageId();

            // fall back to the home page if nothing is configured
            if ($page_id < 1) {
                return home_url('/');
            }

            // hand back its permalink
            return (string) get_permalink($page_id);
        }

        /**
         * Get the URL for a single ticket in the portal.
         *
         * @since  1.0.0
         * @access public
         * @param  int $ticket_id The ticket id.
         * @return string The ticket URL.
         */
        public static function ticketUrl(int $ticket_id): string
        {

            // hang the view args off the portal URL
            return add_query_arg(
                array(
                    'kpts_view'   => 'ticket',
                    'kpts_ticket' => $ticket_id,
                ),
                self::url()
            );
        }

        /**
         * Get the URL for one of the portal's views.
         *
         * @since  1.0.0
         * @access public
         * @param  string $view The view name.
         * @return string The view URL.
         */
        public static function viewUrl(string $view): string
        {

            // the list view is just the bare portal URL
            if ($view === 'list') {
                return self::url();
            }

            // everything else gets the view arg
            return add_query_arg(array('kpts_view' => $view), self::url());
        }

        /**
         * Make sure a portal page actually exists, creating one if it doesn't.
         *
         * @since  1.0.0
         * @access public
         * @return int The portal page id.
         */
        public static function ensurePage(): int
        {

            // if one is already configured and still there, leave it alone
            $existing = self::pageId();
            if ($existing > 0 && get_post_status($existing) !== false) {
                return $existing;
            }

            // build a fresh one with our shortcode in it
            $page_id = wp_insert_post(array(
                'post_type'    => 'page',
                'post_title'   => __('Support', 'kp-support'),
                'post_name'    => 'support',
                'post_content' => '[' . self::SHORTCODE . ']',
                'post_status'  => 'publish',
            ));

            // if that failed we've got nothing
            if (is_wp_error($page_id) || ! $page_id) {
                return 0;
            }

            // save it into our settings
            $options = get_option(Plugin::OPTION_KEY, array());
            $options = is_array($options) ? $options : array();
            $options['portal_page_id'] = (int) $page_id;
            update_option(Plugin::OPTION_KEY, $options);

            // and hand the id back
            return (int) $page_id;
        }

        /**
         * Load our front-end styles and scripts.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function enqueueAssets(): void
        {

            // only bother on a page that's actually using us
            if (! $this->isPortalPage()) {
                return;
            }

            // our styles
            wp_enqueue_style(
                'kpts-portal',
                KP_SUPPORT_URL . 'assets/css/portal.min.css',
                array(),
                KP_SUPPORT_VERSION
            );

            // and our script
            wp_enqueue_script(
                'kpts-portal',
                KP_SUPPORT_URL . 'assets/js/portal.min.js',
                array(),
                KP_SUPPORT_VERSION,
                true
            );

            // work out what ticket we're looking at, if any
            $ticket_id = isset($_GET['kpts_ticket']) ? absint(wp_unslash($_GET['kpts_ticket'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only, public portal routing

            // hand the script everything it needs
            wp_localize_script('kpts-portal', 'kptsPortal', array(
                'ajaxUrl'      => admin_url('admin-ajax.php'),
                'nonce'        => wp_create_nonce('kpts_portal'),
                'ticketId'     => $ticket_id,
                'pollInterval' => max(5, (int) $this->opt('poll_interval', 10)) * 1000,
                'maxFiles'     => (int) $this->opt('max_attachments', 5),
                'maxFileSize'  => Attachments::maxSize(),
                'strings'      => array(
                    'sending'      => __('Sending...', 'kp-support'),
                    'sendFailed'   => __('Your reply could not be sent. Please try again.', 'kp-support'),
                    'emptyReply'   => __('Please enter a reply.', 'kp-support'),
                    'tooManyFiles' => __('Too many files attached.', 'kp-support'),
                    'fileTooBig'   => __('One of those files is too large.', 'kp-support'),
                    'confirmClose' => __('Are you sure you want to close this ticket?', 'kp-support'),
                    'loading'      => __('Loading...', 'kp-support'),
                    /* translators: %s: the name of the person being replied to */
                    'replyTo'      => __('Replying to %s', 'kp-support'),
                    'cancel'       => __('Cancel', 'kp-support'),
                ),
            ));
        }

        /**
         * Work out whether the current page is using one of our shortcodes.
         *
         * @since  1.0.0
         * @access private
         * @return bool True if we should be loading our assets.
         */
        private function isPortalPage(): bool
        {

            // if it's the configured portal page, that's an easy yes
            if (self::pageId() > 0 && is_page(self::pageId())) {
                return true;
            }

            // otherwise go look at the content for our shortcodes
            $post = get_post();

            // nothing to look at
            if (! $post instanceof \WP_Post) {
                return false;
            }

            // the shortcodes worth loading assets for
            $shortcodes = array(self::SHORTCODE, 'kp_support_profile', 'kp_support_login', 'kp_support_register');

            // if any of them are in there, we're on a portal page
            foreach ($shortcodes as $_shortcode) {
                if (has_shortcode($post->post_content, $_shortcode)) {
                    return true;
                }
            }

            // nope
            return false;
        }

        /**
         * Render the portal.
         *
         * @since  1.0.0
         * @access public
         * @param  array<string, mixed>|string $atts The shortcode attributes.
         * @return string The rendered markup.
         */
        public function renderPortal($atts = array()): string
        {

            // what they asked for
            $atts = shortcode_atts(array('view' => ''), (array) $atts, self::SHORTCODE);

            // anybody not logged in gets the login and registration screen
            if (! is_user_logged_in()) {
                return Template::get('auth', array(
                    'allow_registration' => (bool) $this->opt('allow_registration', true),
                    'redirect'           => $this->currentUrl(),
                ));
            }

            // work out which view we're on, the query string wins over the attribute
            $view = isset($_GET['kpts_view']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only, public portal routing
                ? sanitize_key(wp_unslash($_GET['kpts_view']))
                : '';

            // and route it
            return match ($view) {
                'new'     => $this->viewNewTicket(),
                'ticket'  => $this->viewTicket(),
                'profile' => $this->viewProfile(),
                default   => $this->viewList(),
            };
        }

        /**
         * Render the ticket list view.
         *
         * @since  1.0.0
         * @access private
         * @return string The rendered markup.
         */
        private function viewList(): string
        {

            // who's looking
            $user_id = get_current_user_id();
            $is_agent = Access::isAgent($user_id);

            // agents get the whole queue, customers get their own tickets
            $agent_view = $is_agent && (! isset($_GET['kpts_mine']) || (string) $_GET['kpts_mine'] !== '1'); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- read only, compared as a literal string

            // pull the filters off the query string
            $query = Ticket::query(array(
                'user_id'     => $user_id,
                'agent_view'  => $agent_view,
                'status'      => isset($_GET['kpts_status']) ? absint(wp_unslash($_GET['kpts_status'])) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only list filter
                'department'  => isset($_GET['kpts_department']) ? absint(wp_unslash($_GET['kpts_department'])) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only list filter
                'priority'    => isset($_GET['kpts_priority']) ? absint(wp_unslash($_GET['kpts_priority'])) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only list filter
                'search'      => isset($_GET['kpts_search']) ? sanitize_text_field(wp_unslash($_GET['kpts_search'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only list filter
                'paged'       => isset($_GET['kpts_paged']) ? absint(wp_unslash($_GET['kpts_paged'])) : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only list filter
                'show_closed' => ! isset($_GET['kpts_open']) || (string) $_GET['kpts_open'] !== '1', // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- read only, compared as a literal string
            ));

            // and render it
            return Template::get('list', array(
                'query'      => $query,
                'is_agent'   => $is_agent,
                'agent_view' => $agent_view,
                'user_id'    => $user_id,
            ));
        }

        /**
         * Render the new ticket form.
         *
         * @since  1.0.0
         * @access private
         * @return string The rendered markup.
         */
        private function viewNewTicket(): string
        {

            // they have to be allowed to open tickets
            if (! current_user_can('create_kpts_tickets')) {
                return $this->notice(__('You are not allowed to open tickets.', 'kp-support'), 'error');
            }

            // render the form
            return Template::get('new-ticket', array(
                'departments' => PostTypes::terms(PostTypes::TAX_DEPARTMENT),
                'categories'  => PostTypes::terms(PostTypes::TAX_CATEGORY),
                'priorities'  => PostTypes::terms(PostTypes::TAX_PRIORITY),
            ));
        }

        /**
         * Render a single ticket and its chat thread.
         *
         * @since  1.0.0
         * @access private
         * @return string The rendered markup.
         */
        private function viewTicket(): string
        {

            // what ticket did they ask for
            $ticket_id = isset($_GET['kpts_ticket']) ? absint(wp_unslash($_GET['kpts_ticket'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only, public portal routing

            // and this is the gate, they have to actually be allowed on it
            if (! Access::canViewTicket($ticket_id)) {
                return $this->notice(__('That ticket could not be found, or you are not allowed to view it.', 'kp-support'), 'error');
            }

            // grab it
            $ticket = get_post($ticket_id);

            // work out what this person is allowed to do with it
            $can_internal = Access::canSeeInternal($ticket_id);
            $can_manage = Access::canManageTicket($ticket_id);

            // pull the replies, threaded
            $replies = Replies::thread(Replies::forTicket($ticket_id, $can_internal));

            // and render the whole thing
            return Template::get('ticket', array(
                'ticket'       => $ticket,
                'ticket_id'    => $ticket_id,
                'replies'      => $replies,
                'can_reply'    => Access::canReply($ticket_id),
                'can_internal' => $can_internal,
                'can_manage'   => $can_manage,
                'statuses'     => PostTypes::terms(PostTypes::TAX_STATUS),
                'priorities'   => PostTypes::terms(PostTypes::TAX_PRIORITY),
                'departments'  => PostTypes::terms(PostTypes::TAX_DEPARTMENT),
                'agents'       => $can_manage ? Ticket::eligibleAgents($ticket_id) : array(),
            ));
        }

        /**
         * Render the profile management view.
         *
         * @since  1.0.0
         * @access private
         * @return string The rendered markup.
         */
        private function viewProfile(): string
        {

            // grab the current user
            $user = wp_get_current_user();

            // render their profile form
            return Template::get('profile', array('user' => $user));
        }

        /**
         * Render the standalone login shortcode.
         *
         * @since  1.0.0
         * @access public
         * @return string The rendered markup.
         */
        public function renderLogin(): string
        {

            // already logged in, nothing to show
            if (is_user_logged_in()) {
                return $this->notice(__('You are already logged in.', 'kp-support'), 'info');
            }

            // render just the login side of it
            return Template::get('auth', array(
                'allow_registration' => false,
                'redirect'           => $this->currentUrl(),
            ));
        }

        /**
         * Render the standalone registration shortcode.
         *
         * @since  1.0.0
         * @access public
         * @return string The rendered markup.
         */
        public function renderRegister(): string
        {

            // already logged in, nothing to show
            if (is_user_logged_in()) {
                return $this->notice(__('You are already logged in.', 'kp-support'), 'info');
            }

            // registration has to actually be open
            if (! $this->opt('allow_registration', true)) {
                return $this->notice(__('Registration is currently closed.', 'kp-support'), 'info');
            }

            // render the auth screen with registration showing first
            return Template::get('auth', array(
                'allow_registration' => true,
                'default_tab'        => 'register',
                'redirect'           => $this->currentUrl(),
            ));
        }

        /**
         * Render the standalone profile shortcode.
         *
         * @since  1.0.0
         * @access public
         * @return string The rendered markup.
         */
        public function renderProfile(): string
        {

            // they have to be logged in for this one
            if (! is_user_logged_in()) {
                return Template::get('auth', array(
                    'allow_registration' => (bool) $this->opt('allow_registration', true),
                    'redirect'           => $this->currentUrl(),
                ));
            }

            // render their profile form
            return Template::get('profile', array('user' => wp_get_current_user()));
        }

        /**
         * Work out the URL we're currently sitting on.
         *
         * @since  1.0.0
         * @access private
         * @return string The current URL.
         */
        private function currentUrl(): string
        {

            // the portal page is the safe answer here, we never echo back a raw request URI
            return self::url();
        }

        /**
         * Build a simple notice block.
         *
         * @since  1.0.0
         * @access private
         * @param  string $message The message to show.
         * @param  string $type    The notice type.
         * @return string The rendered markup.
         */
        private function notice(string $message, string $type = 'info'): string
        {

            // a plain, escaped notice
            return sprintf(
                '<div class="kpts-portal"><div class="kpts-notice kpts-notice-%1$s">%2$s</div></div>',
                esc_attr($type),
                esc_html($message)
            );
        }
    }
}
