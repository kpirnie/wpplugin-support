<?php

/**
 * Dashboard - The at a glance widgets
 *
 * Two widgets on the wp-admin dashboard: the tickets an agent should be
 * looking at, and the chats sitting in the queue waiting to be picked up.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.1.1
 */

declare(strict_types=1);

namespace KP\Support\Modules;

use KP\Support\Helpers\Access;
use KP\Support\Helpers\Chat;
use KP\Support\Helpers\ChatAccess;
use KP\Support\Helpers\Ticket;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\\KP\\Support\\Modules\\Dashboard')) {

    /** 
     * Class Dashboard
     *
     * The two support widgets on the wp-admin dashboard.
     *
     * @since 1.1.1
     */
    class Dashboard extends AbstractModule
    {
        /**
         * How many rows either widget shows.
         *
         * @since 1.1.1
         * @var int
         */
        private const LIMIT = 10;

        /**
         * Hook this module into WordPress.
         *
         * @since  1.1.1
         * @access public
         * @return void
         */
        public function register(): void
        {

            // both widgets go up together
            add_action('wp_dashboard_setup', array($this, 'addWidgets'));
        }

        /**
         * Add whichever widgets this user is allowed to see.
         *
         * @since  1.1.1
         * @access public
         * @return void
         */
        public function addWidgets(): void
        {

            // the ticket list, for anybody who works tickets
            if (Access::isAgent()) {
                wp_add_dashboard_widget(
                    'kpts_dashboard_tickets',
                    __('Support Tickets', 'kp-support'),
                    array($this, 'renderTickets')
                );
            }

            // and the queue, so long as chat is actually switched on
            if ($this->opt('enable_chat', false) && ChatAccess::isChatAgent()) {
                wp_add_dashboard_widget(
                    'kpts_dashboard_chats',
                    __('Chats Waiting', 'kp-support'),
                    array($this, 'renderChats')
                );
            }
        }

        /**
         * Pull the status terms that count as closed.
         *
         * @since  1.1.1
         * @access private
         * @return array<int, int> The closed term ids.
         */
        private function closedStatusIds(): array
        {

            // every status we've got
            $terms = get_terms(array(
                'taxonomy'   => PostTypes::TAX_STATUS,
                'hide_empty' => false,
                'fields'     => 'ids',
            ));

            // nothing there, or it went sideways
            if (is_wp_error($terms) || empty($terms)) {
                return array();
            }

            // keep the ones flagged closed
            $closed = array();
            foreach ($terms as $_term_id) {
                if (PostTypes::statusIsClosed((int) $_term_id)) {
                    $closed[] = (int) $_term_id;
                }
            }

            return $closed;
        }

        /**
         * Render the open tickets that are this agent's problem.
         *
         * @since  1.1.1
         * @access public
         * @return void
         */
        public function renderTickets(): void
        {

            // who's looking
            $user_id = get_current_user_id();

            // theirs, or nobody's
            $query_args = array(
                'post_type'      => PostTypes::POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => self::LIMIT,
                'orderby'        => 'meta_value',
                'meta_key'       => Ticket::META_LAST_ACTIVITY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ordering the widget by last activity requires it
                'order'          => 'DESC',
                'no_found_rows'  => true,
                'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- scoping the widget to this agent requires it
                    'relation' => 'OR',
                    array(
                        'key'   => Access::META_ASSIGNEE,
                        'value' => $user_id,
                    ),
                    array(
                        'key'     => Access::META_ASSIGNEE,
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key'   => Access::META_ASSIGNEE,
                        'value' => 0,
                    ),
                ),
            );

            // and nothing that's already been put to bed
            $closed = $this->closedStatusIds();
            if (! empty($closed)) {
                $query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- excluding closed statuses requires it
                    array(
                        'taxonomy' => PostTypes::TAX_STATUS,
                        'field'    => 'term_id',
                        'terms'    => $closed,
                        'operator' => 'NOT IN',
                    ),
                );
            }

            // go get them
            $query = new \WP_Query($query_args);

            // nothing waiting on them
            if (! $query->have_posts()) {
                printf('<p>%s</p>', esc_html__('Nothing open right now.', 'kp-support'));
                return;
            }

            // open the list
            echo '<ul class="kpts-dashboard-list">';

            // and walk them
            foreach ($query->posts as $_ticket) {

                // who has it, if anybody
                $_assignee = (int) get_post_meta($_ticket->ID, Access::META_ASSIGNEE, true);
                $_who = ($_assignee > 0)
                    ? get_the_author_meta('display_name', $_assignee)
                    : __('Unassigned', 'kp-support');

                // when it last moved
                $_activity = (string) get_post_meta($_ticket->ID, Ticket::META_LAST_ACTIVITY, true);

                // and out it goes
                printf(
                    '<li><a href="%1$s">%2$s</a><span class="kpts-dashboard-meta">%3$s</span></li>',
                    esc_url((string) get_edit_post_link($_ticket->ID)),
                    esc_html(($_ticket->post_title !== '') ? $_ticket->post_title : __('(no subject)', 'kp-support')),
                    esc_html(sprintf(
                        /* translators: 1: who the ticket is assigned to, 2: how long ago it last moved. */
                        __('%1$s, %2$s ago', 'kp-support'),
                        $_who,
                        ($_activity !== '') ? human_time_diff((int) strtotime($_activity), (int) current_time('timestamp')) : __('never', 'kp-support')
                    ))
                );
            }

            // close it up
            echo '</ul>';

            // and a way through to the rest
            printf(
                '<p class="kpts-dashboard-more"><a href="%1$s">%2$s</a></p>',
                esc_url(admin_url('edit.php?post_type=' . PostTypes::POST_TYPE)),
                esc_html__('All tickets', 'kp-support')
            );
        }

        /**
         * Render the chats sitting in the queue.
         *
         * @since  1.1.1
         * @access public
         * @return void
         */
        public function renderChats(): void
        {

            // anything nobody has picked up yet, oldest first
            $query = new \WP_Query(array(
                'post_type'      => PostTypes::CHAT_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => self::LIMIT,
                'orderby'        => 'date',
                'order'          => 'ASC',
                'no_found_rows'  => true,
                'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the queue is defined by the chat state
                    array(
                        'key'   => Chat::META_STATE,
                        'value' => Chat::STATE_WAITING,
                    ),
                ),
            ));

            // an empty queue is a good thing
            if (! $query->have_posts()) {
                printf('<p>%s</p>', esc_html__('Nobody is waiting.', 'kp-support'));
                return;
            }

            // open the list
            echo '<ul class="kpts-dashboard-list">';

            // and walk them
            foreach ($query->posts as $_chat) {

                // who's on the other end
                $_visitor = Chat::visitor($_chat->ID);
                $_who = ($_visitor > 0) ? get_the_author_meta('display_name', $_visitor) : __('A visitor', 'kp-support');

                // and out it goes
                printf(
                    '<li><a href="%1$s">%2$s</a><span class="kpts-dashboard-meta">%3$s</span></li>',
                    esc_url(admin_url('admin.php?page=' . ChatAdmin::MENU_SLUG . '&kpts_chat=' . $_chat->ID)),
                    esc_html($_who),
                    esc_html(sprintf(
                        /* translators: %s: how long the visitor has been waiting. */
                        __('waiting %s', 'kp-support'),
                        human_time_diff((int) get_post_time('U', true, $_chat), (int) current_time('timestamp', 1))
                    ))
                );
            }

            // close it up
            echo '</ul>';

            // and a way through to the queue itself
            printf(
                '<p class="kpts-dashboard-more"><a href="%1$s">%2$s</a></p>',
                esc_url(admin_url('admin.php?page=' . ChatAdmin::MENU_SLUG)),
                esc_html__('Open the chat queue', 'kp-support')
            );
        }
    }
}
