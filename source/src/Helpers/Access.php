<?php

/**
 * Access - Ticket access control helpers
 *
 * Every "can this person see or touch this ticket" decision in the plugin runs
 * through here, so there's exactly one place to audit.
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
if (! class_exists('\KP\Support\Helpers\Access')) {

    /**
     * Class Access
     *
     * Centralized access checks for tickets and replies.
     *
     * @since 1.0.0
     */
    final class Access
    {
        /**
         * Meta key holding the ticket requester's user id.
         *
         * @since 1.0.0
         * @var string
         */
        public const META_REQUESTER = '_kpts_requester';

        /**
         * Meta key holding the assigned agent's user id.
         *
         * @since 1.0.0
         * @var string
         */
        public const META_ASSIGNEE = '_kpts_assignee';

        /**
         * Meta key holding participant user ids.
         *
         * This is stored as one meta row per user rather than a single array,
         * so we can actually run a meta query against it when we're listing
         * out somebody's tickets.
         *
         * @since 1.0.0
         * @var string
         */
        public const META_PARTICIPANT = '_kpts_participant';

        /**
         * User meta key holding an agent's assigned department term ids.
         *
         * @since 1.0.0
         * @var string
         */
        public const META_AGENT_DEPARTMENTS = 'kpts_departments';

        /**
         * Work out whether a user is an agent of some kind.
         *
         * @since  1.0.0
         * @access public
         * @param  int|null $user_id The user to check, defaults to the current user.
         * @return bool True if they can work tickets.
         */
        public static function isAgent(?int $user_id = null): bool
        {

            // default to whoever is logged in
            $user_id = $user_id ?? get_current_user_id();

            // nobody logged in means definitely not an agent
            if ($user_id < 1) {
                return false;
            }

            // agents are the folks who can edit other people's tickets
            return user_can($user_id, 'edit_others_kpts_tickets');
        }

        /**
         * Work out whether a user can manage the plugin's settings.
         *
         * @since  1.0.0
         * @access public
         * @param  int|null $user_id The user to check, defaults to the current user.
         * @return bool True if they're a manager or admin.
         */
        public static function isManager(?int $user_id = null): bool
        {

            // default to whoever is logged in
            $user_id = $user_id ?? get_current_user_id();

            // nobody logged in means no
            if ($user_id < 1) {
                return false;
            }

            // managers are the ones who can change our settings
            return user_can($user_id, 'kpts_manage_settings');
        }

        /**
         * Grab the department term ids an agent is assigned to.
         *
         * @since  1.0.0
         * @access public
         * @param  int $user_id The agent's user id.
         * @return array<int, int> The department term ids.
         */
        public static function agentDepartments(int $user_id): array
        {

            // pull the raw value off the user
            $departments = get_user_meta($user_id, self::META_AGENT_DEPARTMENTS, true);

            // nothing there means no restriction
            if (! is_array($departments)) {
                return array();
            }

            // clean them up into a list of positive integers
            return array_values(array_filter(array_map('absint', $departments)));
        }

        /**
         * Grab the department term ids attached to a ticket.
         *
         * @since  1.0.0
         * @access public
         * @param  int $ticket_id The ticket id.
         * @return array<int, int> The department term ids.
         */
        public static function ticketDepartments(int $ticket_id): array
        {

            // pull the terms as plain ids
            $terms = wp_get_object_terms($ticket_id, PostTypes::TAX_DEPARTMENT, array('fields' => 'ids'));

            // if that failed, treat it as none
            if (is_wp_error($terms)) {
                return array();
            }

            // clean them up
            return array_map('absint', $terms);
        }

        /**
         * Work out whether an agent is allowed into a ticket's department.
         *
         * If department restriction is off, or the agent has no departments
         * assigned, they get access to everything.
         *
         * @since  1.0.0
         * @access public
         * @param  int $ticket_id The ticket id.
         * @param  int $user_id   The agent's user id.
         * @return bool True if the agent covers this ticket's department.
         */
        public static function agentCoversTicket(int $ticket_id, int $user_id): bool
        {

            // if we're not restricting agents by department, they're all good
            if (! Plugin::opt('restrict_agents_by_department', false)) {
                return true;
            }

            // managers always see everything
            if (self::isManager($user_id)) {
                return true;
            }

            // what departments does this agent cover
            $agent_departments = self::agentDepartments($user_id);

            // no departments assigned means no restriction for them
            if (empty($agent_departments)) {
                return true;
            }

            // what department is the ticket in
            $ticket_departments = self::ticketDepartments($ticket_id);

            // an unassigned ticket is visible to everyone so it can get picked up
            if (empty($ticket_departments)) {
                return true;
            }

            // they're covered if there's any overlap at all
            return ! empty(array_intersect($agent_departments, $ticket_departments));
        }

        /**
         * Get every user id attached to a ticket.
         *
         * That's the requester, the assigned agent, and anyone CC'd on.
         *
         * @since  1.0.0
         * @access public
         * @param  int $ticket_id The ticket id.
         * @return array<int, int> The unique participant user ids.
         */
        public static function participants(int $ticket_id): array
        {

            // start with the requester
            $participants = array((int) get_post_meta($ticket_id, self::META_REQUESTER, true));

            // add the assigned agent
            $participants[] = (int) get_post_meta($ticket_id, self::META_ASSIGNEE, true);

            // pull in every participant row we've got
            $rows = get_post_meta($ticket_id, self::META_PARTICIPANT, false);
            if (is_array($rows)) {
                $participants = array_merge($participants, array_map('absint', $rows));
            }

            // strip out the empties and any duplicates
            $participants = array_values(array_unique(array_filter($participants)));

            // let people hook in and adjust the list
            return (array) apply_filters('kpts_ticket_participants', $participants, $ticket_id);
        }

        /**
         * Work out whether a user can view a ticket at all.
         *
         * @since  1.0.0
         * @access public
         * @param  int      $ticket_id The ticket id.
         * @param  int|null $user_id   The user to check, defaults to the current user.
         * @return bool True if they're allowed to see it.
         */
        public static function canViewTicket(int $ticket_id, ?int $user_id = null): bool
        {

            // default to whoever is logged in
            $user_id = $user_id ?? get_current_user_id();

            // nobody logged in gets to see anything
            if ($user_id < 1 || $ticket_id < 1) {
                return false;
            }

            // make sure this is actually one of our tickets
            $ticket = get_post($ticket_id);
            if (! $ticket instanceof \WP_Post || $ticket->post_type !== PostTypes::POST_TYPE) {
                return false;
            }

            // agents get in as long as they cover the department
            if (self::isAgent($user_id)) {
                return self::agentCoversTicket($ticket_id, $user_id);
            }

            // everyone else has to actually be on the ticket
            $allowed = in_array($user_id, self::participants($ticket_id), true);

            // let people hook in and adjust the decision
            return (bool) apply_filters('kpts_can_view_ticket', $allowed, $ticket_id, $user_id);
        }

        /**
         * Work out whether a user can post a reply to a ticket.
         *
         * @since  1.0.0
         * @access public
         * @param  int      $ticket_id The ticket id.
         * @param  int|null $user_id   The user to check, defaults to the current user.
         * @return bool True if they're allowed to reply.
         */
        public static function canReply(int $ticket_id, ?int $user_id = null): bool
        {

            // default to whoever is logged in
            $user_id = $user_id ?? get_current_user_id();

            // they have to be able to view it before anything else
            if (! self::canViewTicket($ticket_id, $user_id)) {
                return false;
            }

            // agents can always reply, even on a closed ticket
            if (self::isAgent($user_id)) {
                return true;
            }

            // customers can't reply to a closed ticket unless we allow reopening
            if (self::ticketIsClosed($ticket_id) && ! Plugin::opt('allow_reopen', true)) {
                return false;
            }

            // otherwise they're good
            return (bool) apply_filters('kpts_can_reply', true, $ticket_id, $user_id);
        }

        /**
         * Work out whether a user can post an internal-only reply.
         *
         * @since  1.0.0
         * @access public
         * @param  int      $ticket_id The ticket id.
         * @param  int|null $user_id   The user to check, defaults to the current user.
         * @return bool True if they can post internal notes.
         */
        public static function canReplyInternal(int $ticket_id, ?int $user_id = null): bool
        {

            // default to whoever is logged in
            $user_id = $user_id ?? get_current_user_id();

            // nobody logged in, no chance
            if ($user_id < 1) {
                return false;
            }

            // they need the capability and access to the ticket itself
            return user_can($user_id, 'kpts_reply_internal') && self::canViewTicket($ticket_id, $user_id);
        }

        /**
         * Work out whether a user can change a ticket's properties.
         *
         * That's status, priority, department, category and assignment.
         *
         * @since  1.0.0
         * @access public
         * @param  int      $ticket_id The ticket id.
         * @param  int|null $user_id   The user to check, defaults to the current user.
         * @return bool True if they can manage the ticket.
         */
        public static function canManageTicket(int $ticket_id, ?int $user_id = null): bool
        {

            // default to whoever is logged in
            $user_id = $user_id ?? get_current_user_id();

            // has to be an agent, and has to cover the department
            return self::isAgent($user_id) && self::canViewTicket($ticket_id, $user_id);
        }

        /**
         * Work out whether a user can see internal notes on a ticket.
         *
         * @since  1.0.0
         * @access public
         * @param  int      $ticket_id The ticket id.
         * @param  int|null $user_id   The user to check, defaults to the current user.
         * @return bool True if internal notes should be shown.
         */
        public static function canSeeInternal(int $ticket_id, ?int $user_id = null): bool
        {

            // same rule as posting them, if you can write them you can read them
            return self::canReplyInternal($ticket_id, $user_id);
        }

        /**
         * Work out whether a ticket sits in a closed status.
         *
         * @since  1.0.0
         * @access public
         * @param  int $ticket_id The ticket id.
         * @return bool True if the ticket is closed or resolved.
         */
        public static function ticketIsClosed(int $ticket_id): bool
        {

            // pull the status terms off the ticket
            $terms = wp_get_object_terms($ticket_id, PostTypes::TAX_STATUS, array('fields' => 'ids'));

            // no status, or an error, means it's still open
            if (is_wp_error($terms) || empty($terms)) {
                return false;
            }

            // if any of them are flagged closed, the ticket is closed
            foreach ($terms as $_term_id) {
                if (PostTypes::statusIsClosed((int) $_term_id)) {
                    return true;
                }
            }

            // still open
            return false;
        }
    }
}
