<?php

/**
 * PostTypes - Our ticket post type and its taxonomies
 *
 * Registers the ticket post type along with the department, category, priority
 * and status taxonomies, and seeds sensible defaults on activation.
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
if (! class_exists('\KP\Support\Modules\PostTypes')) {

    /**
     * Class PostTypes
     *
     * Handles the ticket post type and every taxonomy hanging off of it.
     *
     * @since 1.0.0
     */
    class PostTypes extends AbstractModule
    {
        /**
         * The ticket post type name.
         *
         * @since 1.0.0
         * @var string
         */
        public const POST_TYPE = 'kpts_ticket';

        /**
         * The department taxonomy name.
         *
         * @since 1.0.0
         * @var string
         */
        public const TAX_DEPARTMENT = 'kpts_department';

        /**
         * The category taxonomy name.
         *
         * @since 1.0.0
         * @var string
         */
        public const TAX_CATEGORY = 'kpts_category';

        /**
         * The priority taxonomy name.
         *
         * @since 1.0.0
         * @var string
         */
        public const TAX_PRIORITY = 'kpts_priority';

        /**
         * The status taxonomy name.
         *
         * @since 1.0.0
         * @var string
         */
        public const TAX_STATUS = 'kpts_status';

        /**
         * Hook this module into WordPress.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function register(): void
        {

            // register the post type and taxonomies nice and early
            add_action('init', array($this, 'registerPostTypes'), 5);
            add_action('init', array($this, 'registerTaxonomies'), 6);
        }

        /**
         * Register the ticket post type.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function registerPostTypes(): void
        {

            // all the labels for the ticket post type
            $labels = array(
                'name'                  => _x('Tickets', 'post type general name', 'kp-support'),
                'singular_name'         => _x('Ticket', 'post type singular name', 'kp-support'),
                'menu_name'             => _x('Support', 'admin menu', 'kp-support'),
                'name_admin_bar'        => _x('Ticket', 'add new on admin bar', 'kp-support'),
                'add_new'               => __('Add New', 'kp-support'),
                'add_new_item'          => __('Add New Ticket', 'kp-support'),
                'new_item'              => __('New Ticket', 'kp-support'),
                'edit_item'             => __('Edit Ticket', 'kp-support'),
                'view_item'             => __('View Ticket', 'kp-support'),
                'all_items'             => __('All Tickets', 'kp-support'),
                'search_items'          => __('Search Tickets', 'kp-support'),
                'not_found'             => __('No tickets found.', 'kp-support'),
                'not_found_in_trash'    => __('No tickets found in Trash.', 'kp-support'),
                'items_list'            => __('Tickets list', 'kp-support'),
                'items_list_navigation' => __('Tickets list navigation', 'kp-support'),
            );

            // and register it, note it's not publicly queryable, the portal handles display
            register_post_type(self::POST_TYPE, array(
                'labels'              => $labels,
                'public'              => false,
                'publicly_queryable'  => false,
                'show_ui'             => true,
                'show_in_menu'        => true,
                'show_in_nav_menus'   => false,
                'show_in_rest'        => false,
                'query_var'           => false,
                'rewrite'             => false,
                'has_archive'         => false,
                'hierarchical'        => false,
                'menu_position'       => 26,
                'menu_icon'           => 'dashicons-sos',
                'supports'            => array('title', 'editor', 'author'),
                'capability_type'     => array('kpts_ticket', 'kpts_tickets'),
                'map_meta_cap'        => true,
                'delete_with_user'    => false,
            ));
        }

        /**
         * Register every taxonomy we attach to tickets.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function registerTaxonomies(): void
        {

            // the capabilities we use for managing our taxonomy terms
            $caps = array(
                'manage_terms' => 'kpts_manage_settings',
                'edit_terms'   => 'kpts_manage_settings',
                'delete_terms' => 'kpts_manage_settings',
                'assign_terms' => 'edit_kpts_tickets',
            );

            // the departments a ticket can be routed to
            register_taxonomy(self::TAX_DEPARTMENT, self::POST_TYPE, array(
                'labels'            => $this->taxonomyLabels(
                    __('Departments', 'kp-support'),
                    __('Department', 'kp-support')
                ),
                'hierarchical'      => true,
                'public'            => false,
                'show_ui'           => true,
                'show_admin_column' => true,
                'show_in_rest'      => false,
                'query_var'         => false,
                'rewrite'           => false,
                'capabilities'      => $caps,
            ));

            // what the ticket is about
            register_taxonomy(self::TAX_CATEGORY, self::POST_TYPE, array(
                'labels'            => $this->taxonomyLabels(
                    __('Ticket Categories', 'kp-support'),
                    __('Category', 'kp-support')
                ),
                'hierarchical'      => true,
                'public'            => false,
                'show_ui'           => true,
                'show_admin_column' => true,
                'show_in_rest'      => false,
                'query_var'         => false,
                'rewrite'           => false,
                'capabilities'      => $caps,
            ));

            // how important the ticket is
            register_taxonomy(self::TAX_PRIORITY, self::POST_TYPE, array(
                'labels'            => $this->taxonomyLabels(
                    __('Priorities', 'kp-support'),
                    __('Priority', 'kp-support')
                ),
                'hierarchical'      => false,
                'public'            => false,
                'show_ui'           => true,
                'show_admin_column' => true,
                'show_in_rest'      => false,
                'query_var'         => false,
                'rewrite'           => false,
                'capabilities'      => $caps,
            ));

            // where the ticket sits in its lifecycle
            register_taxonomy(self::TAX_STATUS, self::POST_TYPE, array(
                'labels'            => $this->taxonomyLabels(
                    __('Statuses', 'kp-support'),
                    __('Status', 'kp-support')
                ),
                'hierarchical'      => false,
                'public'            => false,
                'show_ui'           => true,
                'show_admin_column' => true,
                'show_in_rest'      => false,
                'query_var'         => false,
                'rewrite'           => false,
                'capabilities'      => $caps,
            ));
        }

        /**
         * Build out a standard set of taxonomy labels.
         *
         * @since  1.0.0
         * @access private
         * @param  string $plural   The plural label.
         * @param  string $singular The singular label.
         * @return array<string, string> The full label set.
         */
        private function taxonomyLabels(string $plural, string $singular): array
        {

            // put together everything WordPress wants
            return array(
                'name'              => $plural,
                'singular_name'     => $singular,
                'search_items'      => sprintf(
                    /* translators: %s: plural taxonomy label */
                    __('Search %s', 'kp-support'),
                    $plural
                ),
                'all_items'         => sprintf(
                    /* translators: %s: plural taxonomy label */
                    __('All %s', 'kp-support'),
                    $plural
                ),
                'edit_item'         => sprintf(
                    /* translators: %s: singular taxonomy label */
                    __('Edit %s', 'kp-support'),
                    $singular
                ),
                'update_item'       => sprintf(
                    /* translators: %s: singular taxonomy label */
                    __('Update %s', 'kp-support'),
                    $singular
                ),
                'add_new_item'      => sprintf(
                    /* translators: %s: singular taxonomy label */
                    __('Add New %s', 'kp-support'),
                    $singular
                ),
                'new_item_name'     => sprintf(
                    /* translators: %s: singular taxonomy label */
                    __('New %s Name', 'kp-support'),
                    $singular
                ),
                'menu_name'         => $plural,
                'not_found'         => sprintf(
                    /* translators: %s: plural taxonomy label */
                    __('No %s found.', 'kp-support'),
                    strtolower($plural)
                ),
                'back_to_items'     => sprintf(
                    /* translators: %s: plural taxonomy label */
                    __('Back to %s', 'kp-support'),
                    $plural
                ),
            );
        }

        /**
         * Seed the default terms for our taxonomies.
         *
         * We only ever insert terms that aren't already there, so this is safe
         * to run on every activation.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public static function seedDefaultTerms(): void
        {

            // the departments we start people off with
            $departments = array(
                'general-support' => __('General Support', 'kp-support'),
                'billing'         => __('Billing', 'kp-support'),
                'technical'       => __('Technical', 'kp-support'),
                'sales'           => __('Sales', 'kp-support'),
            );

            // insert each one
            foreach ($departments as $_slug => $_name) {
                self::maybeInsertTerm(self::TAX_DEPARTMENT, $_slug, $_name);
            }

            // the categories we start people off with
            $categories = array(
                'question'        => __('Question', 'kp-support'),
                'bug-report'      => __('Bug Report', 'kp-support'),
                'feature-request' => __('Feature Request', 'kp-support'),
                'other'           => __('Other', 'kp-support'),
            );

            // insert each one
            foreach ($categories as $_slug => $_name) {
                self::maybeInsertTerm(self::TAX_CATEGORY, $_slug, $_name);
            }

            // the priorities, with their colors and sort weights
            $priorities = array(
                'low'    => array(__('Low', 'kp-support'), '#6c757d', 10),
                'normal' => array(__('Normal', 'kp-support'), '#0073aa', 20),
                'high'   => array(__('High', 'kp-support'), '#f0ad4e', 30),
                'urgent' => array(__('Urgent', 'kp-support'), '#d63638', 40),
            );

            // insert each one, then tag on the color and weight
            foreach ($priorities as $_slug => $_data) {

                // create the term
                $term_id = self::maybeInsertTerm(self::TAX_PRIORITY, $_slug, $_data[0]);

                // and set its meta if we got an id back
                if ($term_id > 0) {
                    update_term_meta($term_id, 'kpts_color', $_data[1]);
                    update_term_meta($term_id, 'kpts_weight', $_data[2]);
                }
            }

            // the statuses, with their colors and whether they count as closed
            $statuses = array(
                'new'      => array(__('New', 'kp-support'), '#0073aa', 0),
                'open'     => array(__('Open', 'kp-support'), '#00a32a', 0),
                'pending'  => array(__('Pending Customer', 'kp-support'), '#f0ad4e', 0),
                'on-hold'  => array(__('On Hold', 'kp-support'), '#8c8f94', 0),
                'resolved' => array(__('Resolved', 'kp-support'), '#2271b1', 1),
                'closed'   => array(__('Closed', 'kp-support'), '#50575e', 1),
            );

            // insert each one, then tag on the color and closed flag
            foreach ($statuses as $_slug => $_data) {

                // create the term
                $term_id = self::maybeInsertTerm(self::TAX_STATUS, $_slug, $_data[0]);

                // and set its meta if we got an id back
                if ($term_id > 0) {
                    update_term_meta($term_id, 'kpts_color', $_data[1]);
                    update_term_meta($term_id, 'kpts_is_closed', $_data[2]);
                }
            }
        }

        /**
         * Insert a term only if it isn't already there.
         *
         * @since  1.0.0
         * @access private
         * @param  string $taxonomy The taxonomy to insert into.
         * @param  string $slug     The term slug.
         * @param  string $name     The term name.
         * @return int The term id, or 0 if something went sideways.
         */
        private static function maybeInsertTerm(string $taxonomy, string $slug, string $name): int
        {

            // see if it's already there
            $existing = get_term_by('slug', $slug, $taxonomy);

            // if it is, hand back its id
            if ($existing instanceof \WP_Term) {
                return (int) $existing->term_id;
            }

            // otherwise create it
            $result = wp_insert_term($name, $taxonomy, array('slug' => $slug));

            // if that blew up, we've got nothing
            if (is_wp_error($result)) {
                return 0;
            }

            // hand back the new term id
            return (int) $result['term_id'];
        }

        /**
         * Get the slug of the status we drop new tickets into.
         *
         * @since  1.0.0
         * @access public
         * @return string The default status slug.
         */
        public static function defaultStatusSlug(): string
        {

            // let people filter this if they've renamed things
            return (string) apply_filters('kpts_default_status_slug', 'new');
        }

        /**
         * Get the slug of the priority we drop new tickets into.
         *
         * @since  1.0.0
         * @access public
         * @return string The default priority slug.
         */
        public static function defaultPrioritySlug(): string
        {

            // let people filter this if they've renamed things
            return (string) apply_filters('kpts_default_priority_slug', 'normal');
        }

        /**
         * Work out if a status term should be treated as closed.
         *
         * @since  1.0.0
         * @access public
         * @param  int $term_id The status term id.
         * @return bool True if the status means the ticket is closed.
         */
        public static function statusIsClosed(int $term_id): bool
        {

            // pull the flag off the term meta
            return (bool) get_term_meta($term_id, 'kpts_is_closed', true);
        }

        /**
         * Grab the terms for one of our taxonomies, ready for a dropdown.
         *
         * @since  1.0.0
         * @access public
         * @param  string $taxonomy The taxonomy to pull.
         * @return array<int, \WP_Term> The terms we found.
         */
        public static function terms(string $taxonomy): array
        {

            // pull them all, including empty ones, ordered by our weight then name
            $terms = get_terms(array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'meta_key'   => 'kpts_weight',
                'orderby'    => 'meta_value_num name',
                'order'      => 'ASC',
            ));

            // if that failed, or came back empty, try again without the meta ordering
            if (is_wp_error($terms) || empty($terms)) {
                $terms = get_terms(array(
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => false,
                    'orderby'    => 'name',
                    'order'      => 'ASC',
                ));
            }

            // hand back what we found
            return is_wp_error($terms) ? array() : $terms;
        }
    }
}
