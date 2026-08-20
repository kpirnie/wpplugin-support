<?php

/**
 * Admin - The wp-admin ticket screens
 *
 * Gives agents a proper list table for triage, filters, and a ticket screen that
 * runs the same threaded chat the front-end portal does.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

namespace KP\Support\Modules;

use KP\Support\Helpers\Access;
use KP\Support\Helpers\Ticket;
use KP\Support\Helpers\Template;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Modules\Admin')) {

    /**
     * Class Admin
     *
     * Our wp-admin screens for working tickets.
     *
     * @since 1.0.0
     */
    class Admin extends AbstractModule
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

            // the ticket list table
            add_filter('manage_' . PostTypes::POST_TYPE . '_posts_columns', array($this, 'columns'));
            add_action('manage_' . PostTypes::POST_TYPE . '_posts_custom_column', array($this, 'renderColumn'), 10, 2);
            add_filter('manage_edit-' . PostTypes::POST_TYPE . '_sortable_columns', array($this, 'sortableColumns'));

            // the filter dropdowns above it
            add_action('restrict_manage_posts', array($this, 'renderFilters'));

            // scope and order the list itself
            add_action('pre_get_posts', array($this, 'filterAdminQuery'));

            // the ticket edit screen
            add_action('add_meta_boxes', array($this, 'addMetaBoxes'));
            add_action('save_post_' . PostTypes::POST_TYPE, array($this, 'saveTicketMeta'), 10, 2);

            // the opening message has to go in before the post is written
            add_filter('wp_insert_post_data', array($this, 'filterInsertData'), 10, 2);

            // drop the boxes we don't want on a ticket
            add_action('add_meta_boxes', array($this, 'removeMetaBoxes'), 99);

            // the quick edit row
            add_action('quick_edit_custom_box', array($this, 'quickEditBox'), 10, 2);
            add_action('save_post_' . PostTypes::POST_TYPE, array($this, 'saveQuickEdit'), 10, 2);
            add_filter('quick_edit_show_taxonomy', array($this, 'hideQuickEditTaxonomies'), 10, 3);

            // and our admin assets
            add_action('admin_enqueue_scripts', array($this, 'enqueueAssets'));

            // and hide the editor outright, supports alone doesn't always do it
            add_action('admin_head', array($this, 'hideEditor'));
        }

        /**
         * Set up the ticket list table columns.
         *
         * @since  1.0.0
         * @access public
         * @param  array<string, string> $columns The default columns.
         * @return array<string, string> Our columns.
         */
        public function columns($columns): array
        {

            // build our own set rather than bolting onto the defaults
            return array(
                'cb'             => $columns['cb'] ?? '',
                'kpts_number'    => __('Ticket', 'kp-support'),
                'title'          => __('Subject', 'kp-support'),
                'kpts_requester' => __('Requester', 'kp-support'),
                'kpts_status'    => __('Status', 'kp-support'),
                'kpts_priority'  => __('Priority', 'kp-support'),
                'kpts_dept'      => __('Department', 'kp-support'),
                'kpts_assignee'  => __('Assigned To', 'kp-support'),
                'kpts_replies'   => __('Replies', 'kp-support'),
                'kpts_activity'  => __('Last Activity', 'kp-support'),
            );
        }

        /**
         * Render one of our list table columns.
         *
         * @since  1.0.0
         * @access public
         * @param  string $column    The column being rendered.
         * @param  int    $ticket_id The ticket id.
         * @return void
         */
        public function renderColumn($column, $ticket_id): void
        {

            // cast it down
            $ticket_id = absint($ticket_id);

            // and render whichever column we were asked for
            switch ($column) {
                case 'kpts_number':
                    echo '<strong>' . esc_html(Ticket::number($ticket_id)) . '</strong>';
                    $this->renderInlineData($ticket_id);
                    break;

                case 'kpts_requester':
                    // who opened it
                    $requester = get_userdata((int) get_post_meta($ticket_id, Access::META_REQUESTER, true));
                    echo ($requester instanceof \WP_User)
                        ? esc_html($requester->display_name) . '<br /><small>' . esc_html($requester->user_email) . '</small>'
                        : '&mdash;';
                    break;

                case 'kpts_status':
                    $this->renderTermBadge($ticket_id, PostTypes::TAX_STATUS);
                    break;

                case 'kpts_priority':
                    $this->renderTermBadge($ticket_id, PostTypes::TAX_PRIORITY);
                    break;

                case 'kpts_dept':
                    // the department it's routed to
                    $department = Ticket::term($ticket_id, PostTypes::TAX_DEPARTMENT);
                    echo ($department instanceof \WP_Term) ? esc_html($department->name) : '&mdash;';
                    break;

                case 'kpts_assignee':
                    // who's working it
                    $assignee = get_userdata((int) get_post_meta($ticket_id, Access::META_ASSIGNEE, true));
                    echo ($assignee instanceof \WP_User)
                        ? esc_html($assignee->display_name)
                        : '<em>' . esc_html__('Unassigned', 'kp-support') . '</em>';
                    break;

                case 'kpts_replies':
                    // straight off the cached count, so we're not counting per row
                    echo esc_html((string) absint(get_post_meta($ticket_id, Ticket::META_REPLY_COUNT, true)));
                    break;

                case 'kpts_activity':
                    // when something last happened
                    $activity = (string) get_post_meta($ticket_id, Ticket::META_LAST_ACTIVITY, true);
                    echo ($activity !== '')
                        ? esc_html(human_time_diff(strtotime($activity), current_time('timestamp')) . ' ' . __('ago', 'kp-support'))
                        : '&mdash;';
                    break;
            }
        }

        /**
         * Render a coloured badge for one of a ticket's terms.
         *
         * @since  1.0.0
         * @access private
         * @param  int    $ticket_id The ticket id.
         * @param  string $taxonomy  The taxonomy to render.
         * @return void
         */
        private function renderTermBadge(int $ticket_id, string $taxonomy): void
        {

            // grab the term
            $term = Ticket::term($ticket_id, $taxonomy);

            // nothing set, nothing to show
            if (! $term instanceof \WP_Term) {
                echo '&mdash;';
                return;
            }

            // pull its colour, falling back to a neutral grey
            $color = (string) get_term_meta($term->term_id, 'kpts_color', true);
            $color = ($color !== '') ? $color : '#6c757d';

            // and render the badge
            printf(
                '<span class="kpts-badge" style="background:%1$s;">%2$s</span>',
                esc_attr($color),
                esc_html($term->name)
            );
        }

        /**
         * Mark our activity column as sortable.
         *
         * @since  1.0.0
         * @access public
         * @param  array<string, string> $columns The sortable columns.
         * @return array<string, string> The columns with ours added.
         */
        public function sortableColumns($columns): array
        {

            // let them sort on last activity
            $columns['kpts_activity'] = 'kpts_activity';

            // hand them back
            return (array) $columns;
        }

        /**
         * Render the filter dropdowns above the ticket list.
         *
         * @since  1.0.0
         * @access public
         * @param  string $post_type The post type being listed.
         * @return void
         */
        public function renderFilters($post_type): void
        {

            // only on our own list table
            if ($post_type !== PostTypes::POST_TYPE) {
                return;
            }

            // the taxonomies we offer a filter for
            $filters = array(
                PostTypes::TAX_STATUS     => __('All statuses', 'kp-support'),
                PostTypes::TAX_PRIORITY   => __('All priorities', 'kp-support'),
                PostTypes::TAX_DEPARTMENT => __('All departments', 'kp-support'),
            );

            // render each dropdown
            foreach ($filters as $_taxonomy => $_label) {

                // what's currently selected
                $selected = isset($_GET[$_taxonomy]) ? sanitize_key(wp_unslash($_GET[$_taxonomy])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only list table filter state

                // open the select
                printf('<select name="%1$s"><option value="">%2$s</option>', esc_attr($_taxonomy), esc_html($_label));

                // and add each term
                foreach (PostTypes::terms($_taxonomy) as $_term) {
                    printf(
                        '<option value="%1$s" %2$s>%3$s</option>',
                        esc_attr($_term->slug),
                        selected($selected, $_term->slug, false),
                        esc_html($_term->name)
                    );
                }

                // close it up
                echo '</select>';
            }
        }

        /**
         * Scope and order the admin ticket list.
         *
         * @since  1.0.0
         * @access public
         * @param  \WP_Query $query The query about to run.
         * @return void
         */
        public function filterAdminQuery($query): void
        {

            // only the main query, on our list table, in the admin
            if (! is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query()) {
                return;
            }

            // and only for our post type
            if (($query->get('post_type') ?: '') !== PostTypes::POST_TYPE) {
                return;
            }

            // default the ordering to most recently active
            if (($query->get('orderby') ?: '') === '') {
                $query->set('meta_key', Ticket::META_LAST_ACTIVITY); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ordering the ticket list by last activity requires it
                $query->set('orderby', 'meta_value');
                $query->set('order', 'DESC');
            }

            // if they clicked our activity column, sort on it
            if ($query->get('orderby') === 'kpts_activity') {
                $query->set('meta_key', Ticket::META_LAST_ACTIVITY); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ordering the ticket list by last activity requires it
                $query->set('orderby', 'meta_value');
            }

            // now scope it down, managers and admins see everything
            $user_id = get_current_user_id();
            if (Access::isManager($user_id) || ! $this->opt('restrict_agents_by_department', false)) {
                return;
            }

            // what departments does this agent cover
            $departments = Access::agentDepartments($user_id);

            // no departments assigned means no restriction
            if (empty($departments)) {
                return;
            }

            // otherwise limit them to their own departments
            $tax_query = (array) $query->get('tax_query');
            $tax_query[] = array(
                'taxonomy' => PostTypes::TAX_DEPARTMENT,
                'field'    => 'term_id',
                'terms'    => $departments,
                'operator' => 'IN',
            );
            $query->set('tax_query', $tax_query);
        }

        /**
         * Stash a ticket's current values on its row for the quick editor.
         *
         * @since  1.0.40
         * @access private
         * @param  int $ticket_id The ticket id.
         * @return void
         */
        private function renderInlineData(int $ticket_id): void
        {

            // everything the quick edit row needs to set itself up
            printf(
                '<div class="hidden kpts-inline-data" id="kpts-inline-%1$d" data-status="%2$d" data-priority="%3$d" data-dept="%4$d" data-assignee="%5$d"></div>',
                (int) $ticket_id,
                (int) Ticket::termId($ticket_id, PostTypes::TAX_STATUS),
                (int) Ticket::termId($ticket_id, PostTypes::TAX_PRIORITY),
                (int) Ticket::termId($ticket_id, PostTypes::TAX_DEPARTMENT),
                (int) get_post_meta($ticket_id, Access::META_ASSIGNEE, true)
            );
        }

        /**
         * Keep our taxonomies out of the stock quick edit boxes.
         *
         * @since  1.0.40
         * @access public
         * @param  bool   $show      Whether core would show it.
         * @param  string $taxonomy  The taxonomy.
         * @param  string $post_type The post type being listed.
         * @return bool True if core should render its own box.
         */
        public function hideQuickEditTaxonomies($show, $taxonomy, $post_type): bool
        {

            // anything that isn't ours is none of our business
            if ($post_type !== PostTypes::POST_TYPE) {
                return (bool) $show;
            }

            // ours get dropdowns of our own instead of core's free text
            $ours = array(PostTypes::TAX_STATUS, PostTypes::TAX_PRIORITY, PostTypes::TAX_DEPARTMENT);

            // and out they go
            return in_array($taxonomy, $ours, true) ? false : (bool) $show;
        }

        /**
         * Render our fields into the quick edit row.
         *
         * @since  1.0.40
         * @access public
         * @param  string $column    The column being rendered against.
         * @param  string $post_type The post type being listed.
         * @return void
         */
        public function quickEditBox($column, $post_type): void
        {

            // only our own list, and only once
            if ($post_type !== PostTypes::POST_TYPE || $column !== 'kpts_status') {
                return;
            }

            // and only for somebody who can actually change any of it
            if (! current_user_can('edit_kpts_tickets')) {
                return;
            }

            // our nonce rides along with the row
            echo '<fieldset class="inline-edit-col-right kpts-quick-edit">';
            echo '<div class="inline-edit-col">';
            wp_nonce_field('kpts_quick_edit', 'kpts_quick_nonce');

            // the three taxonomies, one term each
            $this->renderQuickSelect(PostTypes::TAX_STATUS, __('Status', 'kp-support'), 'kpts_status', 'status');
            $this->renderQuickSelect(PostTypes::TAX_PRIORITY, __('Priority', 'kp-support'), 'kpts_priority', 'priority');
            $this->renderQuickSelect(PostTypes::TAX_DEPARTMENT, __('Department', 'kp-support'), 'kpts_dept', 'dept');

            // and the assignment, which needs its own capability
            if (current_user_can('kpts_assign_tickets')) {

                // open the field
                echo '<label class="inline-edit-group">';
                echo '<span class="title">' . esc_html__('Assigned To', 'kp-support') . '</span>';
                echo '<select name="kpts_assignee" class="kpts-quick-assignee">';
                echo '<option value="0">' . esc_html__('Unassigned', 'kp-support') . '</option>';

                // every agent who could take it
                foreach (Ticket::eligibleAgents(0) as $_agent_id) {

                    // grab them
                    $agent = get_userdata($_agent_id);

                    // and list them if they're real
                    if ($agent instanceof \WP_User) {
                        printf(
                            '<option value="%1$d">%2$s</option>',
                            (int) $agent->ID,
                            esc_html($agent->display_name)
                        );
                    }
                }

                // close it up
                echo '</select></label>';
            }

            // close the row out
            echo '</div></fieldset>';
        }

        /**
         * Render one of our taxonomy dropdowns into the quick edit row.
         *
         * @since  1.0.40
         * @access private
         * @param  string $taxonomy The taxonomy to pull terms from.
         * @param  string $label    The label to put on it.
         * @param  string $name     The field name to post under.
         * @param  string $key      The data key the script matches it up with.
         * @return void
         */
        private function renderQuickSelect(string $taxonomy, string $label, string $name, string $key): void
        {

            // everything they could pick
            $terms = get_terms(array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            ));

            // nothing to show
            if (is_wp_error($terms) || empty($terms)) {
                return;
            }

            // open the field up
            printf(
                '<label class="inline-edit-group"><span class="title">%1$s</span><select name="%2$s" class="kpts-quick-%3$s">',
                esc_html($label),
                esc_attr($name),
                esc_attr($key)
            );

            // an empty first option so it can be cleared
            printf(
                '<option value="0">%s</option>',
                esc_html__('None', 'kp-support')
            );

            // and every term we found
            foreach ($terms as $_term) {
                printf(
                    '<option value="%1$d">%2$s</option>',
                    (int) $_term->term_id,
                    esc_html($_term->name)
                );
            }

            // close it up
            echo '</select></label>';
        }

        /**
         * Save what came off the quick edit row.
         *
         * @since  1.0.40
         * @access public
         * @param  int      $post_id The ticket id.
         * @param  \WP_Post $post    The ticket.
         * @return void
         */
        public function saveQuickEdit($post_id, $post): void
        {

            // don't fight with autosave
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }

            // the nonce our row carried has to check out
            if (! isset($_POST['kpts_quick_nonce']) || ! wp_verify_nonce(sanitize_key(wp_unslash($_POST['kpts_quick_nonce'])), 'kpts_quick_edit')) {
                return;
            }

            // cast it down
            $post_id = absint($post_id);

            // they have to be allowed to edit this specific ticket
            if (! current_user_can('edit_kpts_ticket', $post_id)) {
                return;
            }

            // the status, one term only
            if (isset($_POST['kpts_status'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at the top of this method
                $status_id = absint(wp_unslash($_POST['kpts_status'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at the top of this method
                if ($status_id > 0) {
                    Ticket::setStatus($post_id, $status_id);
                }
            }

            // the priority, same again
            if (isset($_POST['kpts_priority'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at the top of this method
                $priority_id = absint(wp_unslash($_POST['kpts_priority'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at the top of this method
                if ($priority_id > 0) {
                    Ticket::setTerm($post_id, PostTypes::TAX_PRIORITY, $priority_id);
                }
            }

            // the department, which can be cleared right back off
            if (isset($_POST['kpts_dept'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at the top of this method
                Ticket::setTerm($post_id, PostTypes::TAX_DEPARTMENT, absint(wp_unslash($_POST['kpts_dept']))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at the top of this method
            }

            // and the assignment, which needs its own capability on top
            if (isset($_POST['kpts_assignee']) && current_user_can('kpts_assign_tickets')) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at the top of this method
                Ticket::setAssignee($post_id, absint(wp_unslash($_POST['kpts_assignee']))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at the top of this method
            }
        }

        /**
         * Add our metaboxes to the ticket edit screen.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function addMetaBoxes(): void
        {

            // the conversation itself, which is the main event
            add_meta_box(
                'kpts_conversation',
                __('Conversation', 'kp-support'),
                array($this, 'renderConversationBox'),
                PostTypes::POST_TYPE,
                'normal',
                'high'
            );

            // and the ticket details off to the side
            add_meta_box(
                'kpts_details',
                __('Ticket Details', 'kp-support'),
                array($this, 'renderDetailsBox'),
                PostTypes::POST_TYPE,
                'side',
                'high'
            );
        }

        /**
         * Strip the core boxes that have no business on a ticket.
         *
         * The editor is the customer's opening message and the Conversation box
         * owns the replies, so neither the editor nor the comment boxes should
         * be sitting there for an agent to type into.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function removeMetaBoxes(): void
        {

            // the comment boxes, both the list and the discussion settings
            remove_meta_box('commentsdiv', PostTypes::POST_TYPE, 'normal');
            remove_meta_box('commentstatusdiv', PostTypes::POST_TYPE, 'normal');

            // and the same again if anything moved them
            remove_meta_box('commentsdiv', PostTypes::POST_TYPE, 'side');
            remove_meta_box('commentstatusdiv', PostTypes::POST_TYPE, 'side');

            // the author box, the requester is tracked in Ticket Details
            remove_meta_box('authordiv', PostTypes::POST_TYPE, 'normal');
            remove_meta_box('authordiv', PostTypes::POST_TYPE, 'side');

            // the publish box is really just the ticket's status, so say so
            remove_meta_box('submitdiv', PostTypes::POST_TYPE, 'side');
            add_meta_box(
                'submitdiv',
                __('Status', 'kp-support'),
                'post_submit_meta_box',
                PostTypes::POST_TYPE,
                'side',
                'core'
            );
        }

        /**
         * Hide the editor on the ticket screen.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function hideEditor(): void
        {

            // what screen are we on
            $screen = get_current_screen();

            // only our own
            if (! $screen instanceof \WP_Screen || $screen->post_type !== PostTypes::POST_TYPE) {
                return;
            }

            // and out it goes, along with the visibility row in the status box
            echo '<style>#postdivrich, #post-body-content { display: none; }'
                . '#submitdiv .misc-pub-visibility { display: none; }'
                . '.inline-edit-row .inline-edit-status,'
                . '.inline-edit-row .inline-edit-password-input { display: none; }</style>';
        }

        /**
         * Render the conversation metabox.
         *
         * @since  1.0.0
         * @access public
         * @param  \WP_Post $post The ticket being edited.
         * @return void
         */
        public function renderConversationBox($post): void
        {

            // cast the id down
            $ticket_id = (int) $post->ID;

            // brand new tickets don't have a conversation yet, so this is where
            // the opening message gets typed instead
            if (get_post_status($ticket_id) === 'auto-draft') {

                // the label
                printf(
                    '<p><label for="kpts_opening_message"><strong>%s</strong></label></p>',
                    esc_html__('Opening Message', 'kp-support')
                );

                // and the field itself
                printf(
                    '<textarea name="kpts_opening_message" id="kpts_opening_message" rows="10" style="width:100%%;"></textarea>'
                );

                // tell them what it becomes
                printf(
                    '<p class="description">%s</p>',
                    esc_html__('This becomes the first post in the conversation.', 'kp-support')
                );

                return;
            }

            // and this is still gated, an agent outside the department sees nothing
            if (! Access::canViewTicket($ticket_id)) {
                echo '<p>' . esc_html__('You are not allowed to view this conversation.', 'kp-support') . '</p>';
                return;
            }

            // work out what they can do
            $can_internal = Access::canSeeInternal($ticket_id);

            // pull the thread
            $replies = Replies::thread(Replies::forTicket($ticket_id, $can_internal));

            // and render the same thread template the portal uses
            Template::render('thread', array(
                'ticket_id'    => $ticket_id,
                'replies'      => $replies,
                'can_reply'    => Access::canReply($ticket_id),
                'can_internal' => $can_internal,
                'context'      => 'admin',
            ));
        }

        /**
         * Put the opening message into the post content as the ticket is written.
         *
         * This has to happen on the way in rather than in save_post, otherwise
         * we'd be updating the post from inside its own save.
         *
         * @since  1.0.21
         * @access public
         * @param  array<string, mixed> $data    The post data heading for the database.
         * @param  array<string, mixed> $postarr The raw posted data.
         * @return array<string, mixed> The data, with the opening message in it.
         */
        public function filterInsertData($data, $postarr): array
        {

            // only ever our tickets
            if (($data['post_type'] ?? '') !== PostTypes::POST_TYPE) {
                return $data;
            }

            // nothing was typed
            if (! isset($_POST['kpts_opening_message'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below
                return $data;
            }

            // the nonce has to check out
            if (! isset($_POST['kpts_ticket_nonce']) || ! wp_verify_nonce(sanitize_key(wp_unslash($_POST['kpts_ticket_nonce'])), 'kpts_save_ticket')) {
                return $data;
            }

            // never overwrite a conversation that already has an opening post
            if (trim((string) ($data['post_content'] ?? '')) !== '') {
                return $data;
            }

            // and in it goes
            $data['post_content'] = wp_kses_post(wp_unslash($_POST['kpts_opening_message'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above

            return $data;
        }

        /**
         * Render the ticket details metabox.
         *
         * @since  1.0.0
         * @access public
         * @param  \WP_Post $post The ticket being edited.
         * @return void
         */
        public function renderDetailsBox($post): void
        {

            // cast the id down
            $ticket_id = (int) $post->ID;

            // our nonce for the save
            wp_nonce_field('kpts_save_ticket', 'kpts_ticket_nonce');

            // who opened it and who has it
            $requester = get_userdata((int) get_post_meta($ticket_id, Access::META_REQUESTER, true));
            $assignee = (int) get_post_meta($ticket_id, Access::META_ASSIGNEE, true);

            // the ticket number
            echo '<p><strong>' . esc_html__('Ticket', 'kp-support') . ':</strong> ' . esc_html(Ticket::number($ticket_id)) . '</p>';

            // status and priority, one term each, never a multi select
            $this->renderTermSelect($ticket_id, PostTypes::TAX_STATUS, __('Status', 'kp-support'), 'kpts_status');
            $this->renderTermSelect($ticket_id, PostTypes::TAX_PRIORITY, __('Priority', 'kp-support'), 'kpts_priority');

            // who it's from, with everybody else on it sat alongside
            echo '<div class="kpts-details-people">';

            // the requester side
            echo '<div class="kpts-details-person">';
            if ($requester instanceof \WP_User) {
                echo '<strong>' . esc_html__('Requester', 'kp-support') . ':</strong><br />'
                    . esc_html($requester->display_name) . '<br />'
                    . '<a href="mailto:' . esc_attr($requester->user_email) . '">' . esc_html($requester->user_email) . '</a>';
            }
            echo '</div>';

            // and the participant list beside it
            echo '<div class="kpts-details-person">';
            echo '<strong>' . esc_html__('Participants', 'kp-support') . ':</strong><br />';
            foreach (Access::participants($ticket_id) as $_user_id) {

                // grab them
                $participant = get_userdata($_user_id);

                // and list them
                if ($participant instanceof \WP_User) {
                    echo esc_html($participant->display_name) . '<br />';
                }
            }
            echo '</div>';

            // close the pair out
            echo '</div>';

            // the assignment dropdown, if they're allowed to change it
            if (current_user_can('kpts_assign_tickets')) {

                // open the field
                echo '<p><label for="kpts_assignee"><strong>' . esc_html__('Assigned To', 'kp-support') . '</strong></label><br />';
                echo '<select name="kpts_assignee" id="kpts_assignee" style="width:100%;">';
                echo '<option value="0">' . esc_html__('Unassigned', 'kp-support') . '</option>';

                // and add every eligible agent
                foreach (Ticket::eligibleAgents($ticket_id) as $_agent_id) {

                    // grab them
                    $agent = get_userdata($_agent_id);

                    // and add them if they're real
                    if ($agent instanceof \WP_User) {
                        printf(
                            '<option value="%1$d" %2$s>%3$s</option>',
                            (int) $agent->ID,
                            selected($assignee, (int) $agent->ID, false),
                            esc_html($agent->display_name)
                        );
                    }
                }

                // close it up
                echo '</select></p>';
            }

            // the portal link, so they can jump straight over
            printf(
                '<p><a href="%1$s" class="button" target="_blank" rel="noopener">%2$s</a></p>',
                esc_url(Portal::ticketUrl($ticket_id)),
                esc_html__('Open in Portal', 'kp-support')
            );
        }

        /**
         * Render a single term dropdown for one of our flat taxonomies.
         *
         * @since  1.0.21
         * @access private
         * @param  int    $ticket_id The ticket id.
         * @param  string $taxonomy  The taxonomy to pull terms from.
         * @param  string $label     The label to put on it.
         * @param  string $name      The field name to post under.
         * @return void
         */
        private function renderTermSelect(int $ticket_id, string $taxonomy, string $label, string $name): void
        {

            // what's on it now
            $current = Ticket::termId($ticket_id, $taxonomy);

            // everything they could pick
            $terms = get_terms(array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            ));

            // nothing to show
            if (is_wp_error($terms) || empty($terms)) {
                return;
            }

            // if they can't set it, it's just a read out
            if (! current_user_can('edit_kpts_tickets')) {

                // find the one that's on it
                $term = ($current > 0) ? get_term($current, $taxonomy) : null;

                // and print it
                printf(
                    '<p><strong>%1$s:</strong> %2$s</p>',
                    esc_html($label),
                    esc_html(($term instanceof \WP_Term) ? $term->name : __('None', 'kp-support'))
                );

                return;
            }

            // open the field up
            printf(
                '<p><label for="%1$s"><strong>%2$s</strong></label><br /><select name="%1$s" id="%1$s" style="width:100%%;">',
                esc_attr($name),
                esc_html($label)
            );

            // an empty first option so it can be cleared
            printf(
                '<option value="0">%s</option>',
                esc_html__('None', 'kp-support')
            );

            // and every term we found
            foreach ($terms as $_term) {
                printf(
                    '<option value="%1$d" %2$s>%3$s</option>',
                    (int) $_term->term_id,
                    selected($current, (int) $_term->term_id, false),
                    esc_html($_term->name)
                );
            }

            // close it up
            echo '</select></p>';
        }

        /**
         * Save what we put on the ticket edit screen.
         *
         * @since  1.0.0
         * @access public
         * @param  int      $post_id The ticket id.
         * @param  \WP_Post $post    The ticket.
         * @return void
         */
        public function saveTicketMeta($post_id, $post): void
        {

            // don't fight with autosave
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }

            // the nonce has to check out
            if (! isset($_POST['kpts_ticket_nonce']) || ! wp_verify_nonce(sanitize_key(wp_unslash($_POST['kpts_ticket_nonce'])), 'kpts_save_ticket')) {
                return;
            }

            // cast it down
            $post_id = absint($post_id);

            // they have to be allowed to edit this specific ticket
            if (! current_user_can('edit_kpts_ticket', $post_id)) {
                return;
            }

            // the assignment needs its own capability on top of that
            if (isset($_POST['kpts_assignee']) && current_user_can('kpts_assign_tickets')) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at the top of this method
                Ticket::setAssignee($post_id, absint(wp_unslash($_POST['kpts_assignee']))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at the top of this method
            }

            // the status, one term only
            if (isset($_POST['kpts_status'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at the top of this method
                $status_id = absint(wp_unslash($_POST['kpts_status'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at the top of this method
                if ($status_id > 0) {
                    Ticket::setStatus($post_id, $status_id);
                }
            }

            // and the priority, same again
            if (isset($_POST['kpts_priority'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at the top of this method
                $priority_id = absint(wp_unslash($_POST['kpts_priority'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at the top of this method
                if ($priority_id > 0) {
                    Ticket::setTerm($post_id, PostTypes::TAX_PRIORITY, $priority_id);
                }
            }

            // a ticket opened straight from wp-admin has no requester yet, so the
            // author becomes the requester and goes onto the participant list
            if ((int) get_post_meta($post_id, Access::META_REQUESTER, true) < 1) {

                // the post author is the closest thing we have to a requester
                $author = (int) ($post instanceof \WP_Post ? $post->post_author : 0);

                // set them up if we got one
                if ($author > 0) {
                    update_post_meta($post_id, Access::META_REQUESTER, $author);
                    Ticket::addParticipant($post_id, $author);
                }
            }

            // a ticket opened from wp-admin with nothing picked still needs both
            if (Ticket::termId($post_id, PostTypes::TAX_STATUS) < 1) {
                Ticket::setStatusBySlug($post_id, 'open');
            }

            // same for the priority
            if (Ticket::termId($post_id, PostTypes::TAX_PRIORITY) < 1) {
                Ticket::setTerm($post_id, PostTypes::TAX_PRIORITY, Ticket::termIdBySlug(PostTypes::TAX_PRIORITY, PostTypes::defaultPrioritySlug()));
            }

            // make sure it has a ticket number and an activity stamp
            if (get_post_meta($post_id, Ticket::META_NUMBER, true) === '') {
                update_post_meta($post_id, Ticket::META_NUMBER, Ticket::number($post_id));
            }

            // and stamp the activity if it's never been set
            if (get_post_meta($post_id, Ticket::META_LAST_ACTIVITY, true) === '') {
                Ticket::touch($post_id);
            }
        }

        /**
         * Load our admin assets.
         *
         * @since  1.0.0
         * @access public
         * @param  string $hook The current admin screen.
         * @return void
         */
        public function enqueueAssets($hook): void
        {

            // our admin styling goes on any of our screens
            $screen = get_current_screen();

            // nothing to work with
            if (! $screen instanceof \WP_Screen) {
                return;
            }

            // only our own screens
            if ($screen->post_type !== PostTypes::POST_TYPE && ! str_contains((string) $screen->id, 'kp-support')) {
                return;
            }

            // the admin styles
            wp_enqueue_style(
                'kpts-admin',
                KP_SUPPORT_URL . 'assets/css/admin.min.css',
                array(),
                KP_SUPPORT_VERSION
            );

            // the portal styles too, since the conversation box reuses them
            wp_enqueue_style(
                'kpts-portal',
                KP_SUPPORT_URL . 'assets/css/portal.min.css',
                array(),
                KP_SUPPORT_VERSION
            );

            // the quick edit row needs a hand filling itself in
            if ($hook === 'edit.php') {
                wp_enqueue_script(
                    'kpts-admin',
                    KP_SUPPORT_URL . 'assets/js/admin.min.js',
                    array('jquery', 'inline-edit-post'),
                    KP_SUPPORT_VERSION,
                    true
                );
            }

            // on the ticket edit screen we run the same chat script the portal does
            if (in_array($hook, array('post.php', 'post-new.php'), true)) {

                // pull the ticket id off the request
                $ticket_id = isset($_GET['post']) ? absint(wp_unslash($_GET['post'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only, the edit screen post id

                // load the script
                wp_enqueue_script(
                    'kpts-portal',
                    KP_SUPPORT_URL . 'assets/js/portal.min.js',
                    array(),
                    KP_SUPPORT_VERSION,
                    true
                );

                // and hand it the same configuration the front end gets
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
        }
    }
}
