<?php

/**
 * Ticket - Ticket creation, updates and querying
 *
 * All of the ticket level operations live here so the AJAX handlers, the portal
 * and the admin screens are all going through the same code.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

namespace KP\Support\Helpers;

use KP\Support\Plugin;
use KP\Support\Modules\PostTypes;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Helpers\Ticket')) {

    /**
     * Class Ticket
     *
     * Ticket level operations.
     *
     * @since 1.0.0
     */
    final class Ticket
    {
        /**
         * Meta key holding the human readable ticket number.
         *
         * @since 1.0.0
         * @var string
         */
        public const META_NUMBER = '_kpts_number';

        /**
         * Meta key holding the last activity timestamp.
         *
         * @since 1.0.0
         * @var string
         */
        public const META_LAST_ACTIVITY = '_kpts_last_activity';

        /**
         * Meta key holding the user id of whoever replied last.
         *
         * @since 1.0.0
         * @var string
         */
        public const META_LAST_REPLY_BY = '_kpts_last_reply_by';

        /**
         * Meta key holding the opening message's attachments.
         *
         * @since 1.0.0
         * @var string
         */
        public const META_ATTACHMENTS = '_kpts_attachments';

        /**
         * Meta key holding the cached reply count.
         *
         * We keep this so the list tables can show a reply count without running
         * a count query for every single row.
         *
         * @since 1.0.0
         * @var string
         */
        public const META_REPLY_COUNT = '_kpts_reply_count';

        /**
         * Open up a brand new ticket.
         *
         * @since  1.0.0
         * @access public
         * @param  array<string, mixed> $args The ticket details.
         * @return int|\WP_Error The new ticket id, or an error.
         */
        public static function create(array $args): int|\WP_Error
        {

            // what we're working with
            $args = wp_parse_args($args, array(
                'subject'     => '',
                'message'     => '',
                'requester'   => get_current_user_id(),
                'department'  => 0,
                'category'    => 0,
                'priority'    => 0,
                'attachments' => array(),
            ));

            // we need a subject
            $subject = trim((string) $args['subject']);
            if ($subject === '') {
                return new \WP_Error('kpts_no_subject', __('Please provide a subject for your ticket.', 'kp-support'));
            }

            // and we need a message
            $message = trim((string) $args['message']);
            if ($message === '') {
                return new \WP_Error('kpts_no_message', __('Please describe your issue.', 'kp-support'));
            }

            // and somebody to own it
            $requester = absint($args['requester']);
            if ($requester < 1) {
                return new \WP_Error('kpts_no_requester', __('You must be logged in to open a ticket.', 'kp-support'));
            }

            // drop the ticket in
            $ticket_id = wp_insert_post(array(
                'post_type'    => PostTypes::POST_TYPE,
                'post_title'   => $subject,
                'post_content' => $message,
                'post_status'  => 'publish',
                'post_author'  => $requester,
            ), true);

            // if that failed, hand the error back up
            if (is_wp_error($ticket_id)) {
                return $ticket_id;
            }

            // cast it down now that we know it's good
            $ticket_id = (int) $ticket_id;

            // record who opened it and get them on the participant list
            update_post_meta($ticket_id, Access::META_REQUESTER, $requester);
            self::addParticipant($ticket_id, $requester);

            // stamp it with a ticket number
            update_post_meta($ticket_id, self::META_NUMBER, self::buildNumber($ticket_id));

            // hang on to any attachments that came in with it
            if (! empty($args['attachments']) && is_array($args['attachments'])) {
                update_post_meta($ticket_id, self::META_ATTACHMENTS, $args['attachments']);
            }

            // set the department, category and priority
            self::setTerm($ticket_id, PostTypes::TAX_DEPARTMENT, absint($args['department']));
            self::setTerm($ticket_id, PostTypes::TAX_CATEGORY, absint($args['category']));

            // priority falls back to our default if nothing was picked
            $priority = absint($args['priority']);
            if ($priority < 1) {
                $priority = self::termIdBySlug(PostTypes::TAX_PRIORITY, PostTypes::defaultPrioritySlug());
            }
            self::setTerm($ticket_id, PostTypes::TAX_PRIORITY, $priority);

            // and drop it into the default status
            self::setStatusBySlug($ticket_id, PostTypes::defaultStatusSlug());

            // mark the activity timestamp
            self::touch($ticket_id, $requester);

            // if we're auto assigning, find somebody to hand it to
            if (Plugin::opt('auto_assign', false)) {
                self::autoAssign($ticket_id);
            }

            // let everybody know a ticket just landed
            do_action('kpts_ticket_created', $ticket_id, $requester);

            // and hand back the id
            return $ticket_id;
        }

        /**
         * Add somebody to a ticket's participant list.
         *
         * @since  1.0.0
         * @access public
         * @param  int $ticket_id The ticket id.
         * @param  int $user_id   The user to add.
         * @return void
         */
        public static function addParticipant(int $ticket_id, int $user_id): void
        {

            // nothing to do without a real user
            if ($user_id < 1 || $ticket_id < 1) {
                return;
            }

            // grab what's already on there
            $existing = get_post_meta($ticket_id, Access::META_PARTICIPANT, false);
            $existing = is_array($existing) ? array_map('absint', $existing) : array();

            // if they're already on it, we're done
            if (in_array($user_id, $existing, true)) {
                return;
            }

            // otherwise add a row for them
            add_post_meta($ticket_id, Access::META_PARTICIPANT, $user_id, false);

            // and let people know
            do_action('kpts_participant_added', $ticket_id, $user_id);
        }

        /**
         * Take somebody off a ticket's participant list.
         *
         * @since  1.0.0
         * @access public
         * @param  int $ticket_id The ticket id.
         * @param  int $user_id   The user to remove.
         * @return void
         */
        public static function removeParticipant(int $ticket_id, int $user_id): void
        {

            // nothing to do without a real user
            if ($user_id < 1 || $ticket_id < 1) {
                return;
            }

            // pull their row off the ticket
            delete_post_meta($ticket_id, Access::META_PARTICIPANT, $user_id);
        }

        /**
         * Set a single taxonomy term on a ticket.
         *
         * @since  1.0.0
         * @access public
         * @param  int    $ticket_id The ticket id.
         * @param  string $taxonomy  The taxonomy to set.
         * @param  int    $term_id   The term id, 0 clears it out.
         * @return void
         */
        public static function setTerm(int $ticket_id, string $taxonomy, int $term_id): void
        {

            // zero means clear the taxonomy off entirely
            if ($term_id < 1) {
                wp_set_object_terms($ticket_id, array(), $taxonomy, false);
                return;
            }

            // make sure the term is real and in the right taxonomy
            $term = get_term($term_id, $taxonomy);
            if (! $term instanceof \WP_Term) {
                return;
            }

            // set it, replacing whatever was there
            wp_set_object_terms($ticket_id, array($term_id), $taxonomy, false);
        }

        /**
         * Set a ticket's status by term id.
         *
         * @since  1.0.0
         * @access public
         * @param  int $ticket_id The ticket id.
         * @param  int $term_id   The status term id.
         * @return bool True if the status actually changed.
         */
        public static function setStatus(int $ticket_id, int $term_id): bool
        {

            // what it's set to right now
            $previous = self::termId($ticket_id, PostTypes::TAX_STATUS);

            // if nothing is changing, don't bother
            if ($previous === $term_id) {
                return false;
            }

            // set the new one
            self::setTerm($ticket_id, PostTypes::TAX_STATUS, $term_id);

            // and let everybody know it moved
            do_action('kpts_ticket_status_changed', $ticket_id, $term_id, $previous);

            // it changed
            return true;
        }

        /**
         * Set a ticket's status by term slug.
         *
         * @since  1.0.0
         * @access public
         * @param  int    $ticket_id The ticket id.
         * @param  string $slug      The status slug.
         * @return bool True if the status actually changed.
         */
        public static function setStatusBySlug(int $ticket_id, string $slug): bool
        {

            // look the slug up
            $term_id = self::termIdBySlug(PostTypes::TAX_STATUS, $slug);

            // and set it if we found one
            return ($term_id > 0) ? self::setStatus($ticket_id, $term_id) : false;
        }

        /**
         * Assign a ticket to an agent.
         *
         * @since  1.0.0
         * @access public
         * @param  int $ticket_id The ticket id.
         * @param  int $user_id   The agent's user id, 0 unassigns.
         * @return bool True if the assignment changed.
         */
        public static function setAssignee(int $ticket_id, int $user_id): bool
        {

            // who has it now
            $previous = (int) get_post_meta($ticket_id, Access::META_ASSIGNEE, true);

            // nothing changing, nothing to do
            if ($previous === $user_id) {
                return false;
            }

            // unassigning is just clearing the meta
            if ($user_id < 1) {
                delete_post_meta($ticket_id, Access::META_ASSIGNEE);
                do_action('kpts_ticket_assigned', $ticket_id, 0, $previous);
                return true;
            }

            // make sure they're actually an agent before handing them a ticket
            if (! Access::isAgent($user_id)) {
                return false;
            }

            // set them as the assignee and get them onto the participant list
            update_post_meta($ticket_id, Access::META_ASSIGNEE, $user_id);
            self::addParticipant($ticket_id, $user_id);

            // let everybody know
            do_action('kpts_ticket_assigned', $ticket_id, $user_id, $previous);

            // it changed
            return true;
        }

        /**
         * Find an agent for a ticket and hand it to them.
         *
         * Picks whichever eligible agent currently has the fewest open tickets.
         *
         * @since  1.0.0
         * @access public
         * @param  int $ticket_id The ticket id.
         * @return int The user id we assigned it to, or 0 if we couldn't.
         */
        public static function autoAssign(int $ticket_id): int
        {

            // grab everybody who could take this
            $agents = self::eligibleAgents($ticket_id);

            // nobody to hand it to
            if (empty($agents)) {
                return 0;
            }

            // work out who has the lightest load
            $best_agent = 0;
            $best_count = PHP_INT_MAX;

            // count up each agent's open tickets
            foreach ($agents as $_agent_id) {

                // how many open tickets are they sitting on
                $count = self::openTicketCount($_agent_id);

                // hang on to them if they're the lightest so far
                if ($count < $best_count) {
                    $best_count = $count;
                    $best_agent = $_agent_id;
                }
            }

            // hand it over
            if ($best_agent > 0) {
                self::setAssignee($ticket_id, $best_agent);
            }

            // and report back
            return $best_agent;
        }

        /**
         * Get every agent who could reasonably take a given ticket.
         *
         * @since  1.0.0
         * @access public
         * @param  int $ticket_id The ticket id.
         * @return array<int, int> The eligible agent user ids.
         */
        public static function eligibleAgents(int $ticket_id): array
        {

            // pull everybody who can actually work tickets, by capability rather than
            // role, so administrators and custom roles land in the pool too
            $users = get_users(array(
                'capability__in' => array('edit_others_kpts_tickets'),
                'fields'         => 'ID',
                'number'         => 200,
            ));

            // narrow it to the ones who cover this ticket's department
            $eligible = array();
            foreach ($users as $_user_id) {

                // cast it down
                $_user_id = (int) $_user_id;

                // keep them if they cover the department
                if (Access::agentCoversTicket($ticket_id, $_user_id)) {
                    $eligible[] = $_user_id;
                }
            }

            // let people hook in and adjust the pool
            return (array) apply_filters('kpts_eligible_agents', $eligible, $ticket_id);
        }

        /**
         * Count how many open tickets an agent is holding.
         *
         * @since  1.0.0
         * @access public
         * @param  int $user_id The agent's user id.
         * @return int The open ticket count.
         */
        public static function openTicketCount(int $user_id): int
        {

            // grab the status terms that count as closed
            $closed = self::closedStatusIds();

            // build the query args
            $args = array(
                'post_type'      => PostTypes::POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => false,
                'meta_query'     => array(
                    array(
                        'key'   => Access::META_ASSIGNEE,
                        'value' => $user_id,
                    ),
                ),
            );

            // exclude anything sitting in a closed status
            if (! empty($closed)) {
                $args['tax_query'] = array(
                    array(
                        'taxonomy' => PostTypes::TAX_STATUS,
                        'field'    => 'term_id',
                        'terms'    => $closed,
                        'operator' => 'NOT IN',
                    ),
                );
            }

            // run it and hand back the count
            $query = new \WP_Query($args);
            return (int) $query->found_posts;
        }

        /**
         * Get the status term ids that count as closed.
         *
         * @since  1.0.0
         * @access public
         * @return array<int, int> The closed status term ids.
         */
        public static function closedStatusIds(): array
        {

            // walk every status term looking for the closed flag
            $closed = array();
            foreach (PostTypes::terms(PostTypes::TAX_STATUS) as $_term) {

                // keep it if it's flagged closed
                if (PostTypes::statusIsClosed((int) $_term->term_id)) {
                    $closed[] = (int) $_term->term_id;
                }
            }

            // hand them back
            return $closed;
        }

        /**
         * Stamp a ticket with the current activity time.
         *
         * @since  1.0.0
         * @access public
         * @param  int $ticket_id The ticket id.
         * @param  int $user_id   Who caused the activity.
         * @return void
         */
        public static function touch(int $ticket_id, int $user_id = 0): void
        {

            // record when it happened
            update_post_meta($ticket_id, self::META_LAST_ACTIVITY, current_time('mysql'));

            // and who did it, if we know
            if ($user_id > 0) {
                update_post_meta($ticket_id, self::META_LAST_REPLY_BY, $user_id);
            }
        }

        /**
         * Get a ticket's display number.
         *
         * @since  1.0.0
         * @access public
         * @param  int $ticket_id The ticket id.
         * @return string The ticket number.
         */
        public static function number(int $ticket_id): string
        {

            // see if we've already stamped one on
            $number = get_post_meta($ticket_id, self::META_NUMBER, true);

            // build one on the fly if we haven't
            if (! is_string($number) || $number === '') {
                $number = self::buildNumber($ticket_id);
            }

            // hand it back
            return $number;
        }

        /**
         * Build a ticket number out of an id.
         *
         * @since  1.0.0
         * @access private
         * @param  int $ticket_id The ticket id.
         * @return string The ticket number.
         */
        private static function buildNumber(int $ticket_id): string
        {

            // the prefix people can configure
            $prefix = (string) Plugin::opt('ticket_prefix', '#');

            // pad the id out so they all line up nicely
            $number = $prefix . str_pad((string) $ticket_id, 6, '0', STR_PAD_LEFT);

            // let people build their own format if they want
            return (string) apply_filters('kpts_ticket_number', $number, $ticket_id);
        }

        /**
         * Get the single term id set on a ticket for a taxonomy.
         *
         * @since  1.0.0
         * @access public
         * @param  int    $ticket_id The ticket id.
         * @param  string $taxonomy  The taxonomy to look at.
         * @return int The term id, or 0 if there isn't one.
         */
        public static function termId(int $ticket_id, string $taxonomy): int
        {

            // pull the terms as ids
            $terms = wp_get_object_terms($ticket_id, $taxonomy, array('fields' => 'ids'));

            // nothing there, or an error, means zero
            if (is_wp_error($terms) || empty($terms)) {
                return 0;
            }

            // hand back the first one
            return (int) $terms[0];
        }

        /**
         * Get the single term set on a ticket for a taxonomy.
         *
         * @since  1.0.0
         * @access public
         * @param  int    $ticket_id The ticket id.
         * @param  string $taxonomy  The taxonomy to look at.
         * @return \WP_Term|null The term, or null.
         */
        public static function term(int $ticket_id, string $taxonomy): ?\WP_Term
        {

            // work out the id
            $term_id = self::termId($ticket_id, $taxonomy);

            // nothing set means nothing to give back
            if ($term_id < 1) {
                return null;
            }

            // grab the actual term
            $term = get_term($term_id, $taxonomy);

            // and hand it back if it's real
            return ($term instanceof \WP_Term) ? $term : null;
        }

        /**
         * Look a term id up by its slug.
         *
         * @since  1.0.0
         * @access public
         * @param  string $taxonomy The taxonomy to look in.
         * @param  string $slug     The slug to find.
         * @return int The term id, or 0 if it isn't there.
         */
        public static function termIdBySlug(string $taxonomy, string $slug): int
        {

            // go find it
            $term = get_term_by('slug', $slug, $taxonomy);

            // and hand back the id if we got one
            return ($term instanceof \WP_Term) ? (int) $term->term_id : 0;
        }

        /**
         * Query up a list of tickets, scoped to what the user is allowed to see.
         *
         * @since  1.0.0
         * @access public
         * @param  array<string, mixed> $args The query arguments.
         * @return \WP_Query The resulting query.
         */
        public static function query(array $args): \WP_Query
        {

            // what we're working with
            $args = wp_parse_args($args, array(
                'user_id'    => get_current_user_id(),
                'agent_view' => false,
                'status'     => 0,
                'department' => 0,
                'priority'   => 0,
                'category'   => 0,
                'assignee'   => -1,
                'search'     => '',
                'paged'      => 1,
                'per_page'   => (int) Plugin::opt('tickets_per_page', 20),
                'show_closed' => true,
            ));

            // cast the user down
            $user_id = absint($args['user_id']);

            // the base query
            $query_args = array(
                'post_type'      => PostTypes::POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => absint($args['per_page']),
                'paged'          => max(1, absint($args['paged'])),
                'orderby'        => 'meta_value',
                'meta_key'       => self::META_LAST_ACTIVITY,
                'order'          => 'DESC',
                'meta_query'     => array(),
                'tax_query'      => array(),
            );

            // if this isn't an agent view, lock it down to their own tickets
            if (empty($args['agent_view']) || ! Access::isAgent($user_id)) {
                $query_args['meta_query'][] = array(
                    'key'   => Access::META_PARTICIPANT,
                    'value' => $user_id,
                );
            } elseif (Plugin::opt('restrict_agents_by_department', false) && ! Access::isManager($user_id)) {

                // agents restricted by department only see their own departments
                $departments = Access::agentDepartments($user_id);

                // and only when they've actually got departments assigned
                if (! empty($departments)) {
                    $query_args['tax_query'][] = array(
                        'taxonomy' => PostTypes::TAX_DEPARTMENT,
                        'field'    => 'term_id',
                        'terms'    => $departments,
                        'operator' => 'IN',
                    );
                }
            }

            // filter down by assignee if we were given one
            if ((int) $args['assignee'] >= 0) {
                $query_args['meta_query'][] = array(
                    'key'   => Access::META_ASSIGNEE,
                    'value' => absint($args['assignee']),
                );
            }

            // the taxonomy filters, all built the same way
            $taxonomies = array(
                'status'     => PostTypes::TAX_STATUS,
                'department' => PostTypes::TAX_DEPARTMENT,
                'priority'   => PostTypes::TAX_PRIORITY,
                'category'   => PostTypes::TAX_CATEGORY,
            );

            // add each one that was actually asked for
            foreach ($taxonomies as $_key => $_taxonomy) {

                // skip anything we weren't given
                $term_id = absint($args[$_key]);
                if ($term_id < 1) {
                    continue;
                }

                // and filter on it
                $query_args['tax_query'][] = array(
                    'taxonomy' => $_taxonomy,
                    'field'    => 'term_id',
                    'terms'    => array($term_id),
                );
            }

            // hide closed tickets if we were asked to
            if (empty($args['show_closed'])) {

                // grab the closed statuses
                $closed = self::closedStatusIds();

                // and exclude them
                if (! empty($closed)) {
                    $query_args['tax_query'][] = array(
                        'taxonomy' => PostTypes::TAX_STATUS,
                        'field'    => 'term_id',
                        'terms'    => $closed,
                        'operator' => 'NOT IN',
                    );
                }
            }

            // add a keyword search if we got one
            $search = trim((string) $args['search']);
            if ($search !== '') {
                $query_args['s'] = $search;
            }

            // if we stacked up more than one taxonomy clause, they all have to match
            if (count($query_args['tax_query']) > 1) {
                $query_args['tax_query']['relation'] = 'AND';
            }

            // same deal for the meta clauses
            if (count($query_args['meta_query']) > 1) {
                $query_args['meta_query']['relation'] = 'AND';
            }

            // let people adjust the whole thing
            $query_args = (array) apply_filters('kpts_ticket_query_args', $query_args, $args);

            // and run it
            return new \WP_Query($query_args);
        }
    }
}
