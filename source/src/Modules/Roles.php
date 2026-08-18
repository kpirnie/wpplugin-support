<?php

/**
 * Roles - Our roles, capabilities and meta capability mapping
 *
 * Builds out the customer, agent and manager roles, and maps the per-ticket
 * meta capabilities so people only ever get at the tickets they should.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

namespace KP\Support\Modules;

use KP\Support\Helpers\Access;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Modules\Roles')) {

    /**
     * Class Roles
     *
     * Handles roles, capabilities and meta capability mapping.
     *
     * @since 1.0.0
     */
    class Roles extends AbstractModule
    {
        /**
         * The customer role name.
         *
         * @since 1.0.0
         * @var string
         */
        public const ROLE_CUSTOMER = 'kpts_customer';

        /**
         * The agent role name.
         *
         * @since 1.0.0
         * @var string
         */
        public const ROLE_AGENT = 'kpts_agent';

        /**
         * The manager role name.
         *
         * @since 1.0.0
         * @var string
         */
        public const ROLE_MANAGER = 'kpts_manager';

        /**
         * Hook this module into WordPress.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function register(): void
        {

            // map our per-ticket meta capabilities
            add_filter('map_meta_cap', array($this, 'mapMetaCap'), 10, 4);

            // add the agent department picker to user profiles
            add_action('init', array($this, 'registerAgentFields'), 20);
        }

        /**
         * Build out the capability list for each of our roles.
         *
         * @since  1.0.0
         * @access public
         * @return array<string, array<string, bool>> The roles and their caps.
         */
        public static function capabilities(): array
        {

            // what a customer can do, which is really just open tickets and chats
            $customer = array(
                'read'                 => true,
                'create_kpts_tickets'  => true,
                'kpts_start_chat'      => true,
            );

            // what an agent can do on top of that
            $agent = array_merge($customer, array(
                'edit_kpts_tickets'           => true,
                'edit_others_kpts_tickets'    => true,
                'edit_published_kpts_tickets' => true,
                'publish_kpts_tickets'        => true,
                'read_private_kpts_tickets'   => true,
                'kpts_reply_internal'         => true,
                'kpts_assign_tickets'         => true,
                'edit_kpts_chats'             => true,
                'edit_others_kpts_chats'      => true,
                'edit_published_kpts_chats'   => true,
                'publish_kpts_chats'          => true,
                'read_private_kpts_chats'     => true,
                'kpts_handle_chats'           => true,
                'kpts_assign_chats'           => true,
                'kpts_convert_chats'          => true,
            ));

            // and what a manager can do on top of that
            $manager = array_merge($agent, array(
                'edit_private_kpts_tickets'     => true,
                'delete_kpts_tickets'           => true,
                'delete_others_kpts_tickets'    => true,
                'delete_published_kpts_tickets' => true,
                'delete_private_kpts_tickets'   => true,
                'delete_kpts_chats'             => true,
                'delete_others_kpts_chats'      => true,
                'delete_published_kpts_chats'   => true,
                'delete_private_kpts_chats'     => true,
                'kpts_manage_settings'          => true,
            ));

            // hand them all back keyed by role
            return array(
                self::ROLE_CUSTOMER => $customer,
                self::ROLE_AGENT    => $agent,
                self::ROLE_MANAGER  => $manager,
            );
        }

        /**
         * Create our roles and grant our caps to administrators.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public static function addRoles(): void
        {

            // the display names for each role
            $names = array(
                self::ROLE_CUSTOMER => __('Support Customer', 'kp-support'),
                self::ROLE_AGENT    => __('Support Agent', 'kp-support'),
                self::ROLE_MANAGER  => __('Support Manager', 'kp-support'),
            );

            // grab our capability map
            $capabilities = self::capabilities();

            // build out each role
            foreach ($capabilities as $_role => $_caps) {

                // drop the old one first so a reactivation picks up any new caps
                remove_role($_role);

                // and add it back with the current capability set
                add_role($_role, $names[$_role], $_caps);
            }

            // administrators get everything we've got
            $admin = get_role('administrator');
            if ($admin instanceof \WP_Role) {

                // the manager set is our full set
                foreach (array_keys($capabilities[self::ROLE_MANAGER]) as $_cap) {
                    $admin->add_cap($_cap);
                }
            }
        }

        /**
         * Tear our roles back down, used on uninstall.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public static function removeRoles(): void
        {

            // grab our capability map
            $capabilities = self::capabilities();

            // drop each of our roles
            foreach (array_keys($capabilities) as $_role) {
                remove_role($_role);
            }

            // and pull our caps back off of the administrator
            $admin = get_role('administrator');
            if ($admin instanceof \WP_Role) {

                // strip every cap we handed out
                foreach (array_keys($capabilities[self::ROLE_MANAGER]) as $_cap) {
                    $admin->remove_cap($_cap);
                }
            }
        }

        /**
         * Map our per-ticket meta capabilities down to real ones.
         *
         * This is what lets a customer read their own ticket without being able
         * to read anybody else's.
         *
         * @since  1.0.0
         * @access public
         * @param  array<int, string> $caps    The mapped capabilities so far.
         * @param  string             $cap     The capability being checked.
         * @param  int                $user_id The user being checked.
         * @param  array<int, mixed>  $args    Any extra arguments, args[0] is the object id.
         * @return array<int, string> The capabilities required.
         */
        public function mapMetaCap(array $caps, string $cap, int $user_id, array $args): array
        {

            // the meta caps we actually care about
            $ours = array('read_kpts_ticket', 'edit_kpts_ticket', 'delete_kpts_ticket');

            // if it's not one of ours, leave it alone
            if (! in_array($cap, $ours, true)) {
                return $caps;
            }

            // we need an object id to work with
            $ticket_id = isset($args[0]) ? absint($args[0]) : 0;
            if ($ticket_id < 1) {
                return $caps;
            }

            // and it has to actually be one of our tickets
            $ticket = get_post($ticket_id);
            if (! $ticket instanceof \WP_Post || $ticket->post_type !== PostTypes::POST_TYPE) {
                return $caps;
            }

            // reading is open to anyone attached to the ticket
            if ($cap === 'read_kpts_ticket') {
                return Access::canViewTicket($ticket_id, $user_id)
                    ? array('read')
                    : array('do_not_allow');
            }

            // editing and deleting are agent territory only
            if ($cap === 'edit_kpts_ticket') {
                return Access::canManageTicket($ticket_id, $user_id)
                    ? array('edit_others_kpts_tickets')
                    : array('do_not_allow');
            }

            // which just leaves deleting
            return Access::canManageTicket($ticket_id, $user_id)
                ? array('delete_others_kpts_tickets')
                : array('do_not_allow');
        }

        /**
         * Add the department picker to agent user profiles.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function registerAgentFields(): void
        {

            // we need the framework for this
            $framework = \KP\Support\Plugin::instance()->framework();
            if ($framework === null) {
                return;
            }

            // build the department options out of the taxonomy terms
            $options = array();
            foreach (PostTypes::terms(PostTypes::TAX_DEPARTMENT) as $_term) {
                $options[(string) $_term->term_id] = $_term->name;
            }

            // no departments means there's nothing worth showing
            if (empty($options)) {
                return;
            }

            // drop a support section onto the user profile screen
            $framework->addMetaBox(array(
                'id'        => 'kpts_agent_settings',
                'title'     => __('Support Agent Settings', 'kp-support'),
                'user_meta' => true,
                'fields'    => array(
                    array(
                        'id'          => Access::META_AGENT_DEPARTMENTS,
                        'type'        => 'multiselect',
                        'label'       => __('Departments', 'kp-support'),
                        'description' => __('Which departments this agent handles. Leave everything unselected to give them access to all departments.', 'kp-support'),
                        'options'     => $options,
                    ),
                    array(
                        'id'          => 'kpts_signature',
                        'type'        => 'textarea',
                        'label'       => __('Reply Signature', 'kp-support'),
                        'description' => __('Appended to the bottom of this agent\'s public replies.', 'kp-support'),
                        'rows'        => 4,
                    ),
                ),
            ));
        }
    }
}
