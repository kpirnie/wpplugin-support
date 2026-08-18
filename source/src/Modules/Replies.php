<?php

/**
 * Replies - Threaded ticket replies
 *
 * Replies are stored as a dedicated comment type on the ticket, which gets us
 * native threading for free. Each one carries a flag saying whether it's public
 * or an internal note that customers must never see.
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
if (! class_exists('\KP\Support\Modules\Replies')) {

    /**
     * Class Replies
     *
     * Handles creating, querying and rendering ticket replies.
     *
     * @since 1.0.0
     */
    class Replies extends AbstractModule
    {
        /**
         * The comment type we store replies under.
         *
         * @since 1.0.0
         * @var string
         */
        public const COMMENT_TYPE = 'kpts_reply';

        /**
         * Comment meta key flagging a reply as internal only.
         *
         * @since 1.0.0
         * @var string
         */
        public const META_INTERNAL = '_kpts_internal';

        /**
         * Hook this module into WordPress.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function register(): void
        {

            // keep our replies out of every normal comment query
            add_filter('comments_clauses', array($this, 'excludeFromQueries'), 10, 2);

            // and out of the post comment counts
            add_filter('get_comments_number', array($this, 'filterCommentsNumber'), 10, 2);
        }

        /**
         * Keep our replies out of comment queries that didn't ask for them.
         *
         * Without this they'd turn up in the admin comments list, the recent
         * comments widget, and the site's comment feed.
         *
         * @since  1.0.0
         * @access public
         * @param  array<string, string> $clauses The query clauses.
         * @param  \WP_Comment_Query     $query   The comment query.
         * @return array<string, string> The adjusted clauses.
         */
        public function excludeFromQueries(array $clauses, $query): array
        {

            // we need the query object to work out what they asked for
            if (! $query instanceof \WP_Comment_Query) {
                return $clauses;
            }

            // what type did they ask for
            $type = $query->query_vars['type'] ?? '';
            $type_in = $query->query_vars['type__in'] ?? array();

            // both of the comment types we own
            $ours = array(self::COMMENT_TYPE, PostTypes::CHAT_COMMENT_TYPE);

            // if they explicitly asked for one of ours, hand it straight back
            if (in_array($type, $ours, true)) {
                return $clauses;
            }

            // same deal if one of ours is in the list they asked for
            if (is_array($type_in) && ! empty(array_intersect($ours, $type_in))) {
                return $clauses;
            }

            // otherwise filter both of our types right out of it
            global $wpdb;
            foreach ($ours as $_type) {
                $clauses['where'] .= $wpdb->prepare(" AND {$wpdb->comments}.comment_type != %s", $_type);
            }

            // and hand the clauses back
            return $clauses;
        }

        /**
         * Keep our replies out of the public comment count.
         *
         * @since  1.0.0
         * @access public
         * @param  int|string $count   The comment count.
         * @param  int        $post_id The post id.
         * @return int The adjusted count.
         */
        public function filterCommentsNumber($count, $post_id): int
        {

            // tickets and chats don't have a public comment count at all
            if (in_array(get_post_type($post_id), array(PostTypes::POST_TYPE, PostTypes::CHAT_POST_TYPE), true)) {
                return 0;
            }

            // everything else passes straight through
            return (int) $count;
        }

        /**
         * The HTML tags we let people use in a reply.
         *
         * @since  1.0.0
         * @access public
         * @return array<string, array<string, bool>> The allowed tag set.
         */
        public static function allowedTags(): array
        {

            // a deliberately small set, this is chat not a page builder
            $tags = array(
                'a'          => array('href' => true, 'title' => true, 'target' => true, 'rel' => true),
                'br'         => array(),
                'p'          => array(),
                'strong'     => array(),
                'b'          => array(),
                'em'         => array(),
                'i'          => array(),
                'u'          => array(),
                'ul'         => array(),
                'ol'         => array(),
                'li'         => array(),
                'blockquote' => array(),
                'code'       => array(),
                'pre'        => array(),
            );

            // let people adjust it if they need to
            return (array) apply_filters('kpts_reply_allowed_tags', $tags);
        }

        /**
         * Post a reply onto a ticket.
         *
         * @since  1.0.0
         * @access public
         * @param  array<string, mixed> $args The reply details.
         * @return int|\WP_Error The new reply id, or an error.
         */
        public static function add(array $args): int|\WP_Error
        {

            // what we're working with
            $args = wp_parse_args($args, array(
                'ticket_id'   => 0,
                'user_id'     => get_current_user_id(),
                'content'     => '',
                'parent'      => 0,
                'internal'    => false,
                'attachments' => array(),
            ));

            // cast everything down
            $ticket_id = absint($args['ticket_id']);
            $user_id = absint($args['user_id']);
            $parent = absint($args['parent']);
            $internal = (bool) $args['internal'];

            // the ticket has to be real
            $ticket = get_post($ticket_id);
            if (! $ticket instanceof \WP_Post || $ticket->post_type !== PostTypes::POST_TYPE) {
                return new \WP_Error('kpts_bad_ticket', __('That ticket could not be found.', 'kp-support'));
            }

            // and we need somebody posting it
            $user = get_userdata($user_id);
            if (! $user instanceof \WP_User) {
                return new \WP_Error('kpts_bad_user', __('You must be logged in to reply.', 'kp-support'));
            }

            // clean the content down to our allowed tags
            $content = trim(wp_kses((string) $args['content'], self::allowedTags()));

            // an empty reply with no files attached isn't a reply
            if ($content === '' && empty($args['attachments'])) {
                return new \WP_Error('kpts_empty_reply', __('Please enter a reply.', 'kp-support'));
            }

            // only let it be internal if they're actually allowed to do that
            if ($internal && ! Access::canReplyInternal($ticket_id, $user_id)) {
                $internal = false;
            }

            // make sure the parent is a real reply on this same ticket
            if ($parent > 0) {

                // go get it
                $parent_comment = get_comment($parent);

                // if it isn't ours, or it's on another ticket, drop it to top level
                if (
                    ! $parent_comment instanceof \WP_Comment
                    || (int) $parent_comment->comment_post_ID !== $ticket_id
                    || $parent_comment->comment_type !== self::COMMENT_TYPE
                ) {
                    $parent = 0;
                }
            }

            // drop the reply in, we go through wp_insert_comment directly so this
            // never touches the moderation or flood control pipeline
            $comment_id = wp_insert_comment(array(
                'comment_post_ID'      => $ticket_id,
                'comment_parent'       => $parent,
                'comment_content'      => $content,
                'comment_type'         => self::COMMENT_TYPE,
                'comment_approved'     => 1,
                'user_id'              => $user_id,
                'comment_author'       => $user->display_name,
                'comment_author_email' => $user->user_email,
                'comment_author_IP'    => '',
                'comment_agent'        => '',
                'comment_date'         => current_time('mysql'),
                'comment_date_gmt'     => current_time('mysql', 1),
            ));

            // if that failed, say so
            if (! $comment_id) {
                return new \WP_Error('kpts_insert_failed', __('Your reply could not be saved. Please try again.', 'kp-support'));
            }

            // cast it down
            $comment_id = (int) $comment_id;

            // flag it internal or not
            update_comment_meta($comment_id, self::META_INTERNAL, $internal ? 1 : 0);

            // store any attachments against the reply and index them on the ticket
            if (! empty($args['attachments']) && is_array($args['attachments'])) {
                update_comment_meta($comment_id, Attachments::META_REPLY_FILES, $args['attachments']);
                Attachments::indexFiles($ticket_id, $args['attachments'], $comment_id);
            }

            // get whoever replied onto the participant list
            Ticket::addParticipant($ticket_id, $user_id);

            // and stamp the ticket as active
            Ticket::touch($ticket_id, $user_id);

            // bump the cached reply count so the list tables don't have to count
            update_post_meta($ticket_id, Ticket::META_REPLY_COUNT, self::countForTicket($ticket_id));

            // move the status along, but never on an internal note
            if (! $internal) {
                self::advanceStatus($ticket_id, $user_id);
            }

            // let everybody know, this is what fires the notifications
            do_action('kpts_reply_added', $comment_id, $ticket_id, $user_id, $internal);

            // hand back the new reply id
            return $comment_id;
        }

        /**
         * Nudge the ticket status along after a public reply.
         *
         * @since  1.0.0
         * @access private
         * @param  int $ticket_id The ticket id.
         * @param  int $user_id   Who replied.
         * @return void
         */
        private static function advanceStatus(int $ticket_id, int $user_id): void
        {

            // if the admin turned this off, leave the status alone
            if (! Plugin::opt('auto_status', true)) {
                return;
            }

            // an agent replying puts the ball back in the customer's court
            if (Access::isAgent($user_id)) {
                Ticket::setStatusBySlug($ticket_id, (string) Plugin::opt('status_after_agent_reply', 'pending'));
                return;
            }

            // and a customer replying reopens it for the agents
            Ticket::setStatusBySlug($ticket_id, (string) Plugin::opt('status_after_customer_reply', 'open'));
        }

        /**
         * Work out whether a reply is an internal note.
         *
         * @since  1.0.0
         * @access public
         * @param  int $comment_id The reply id.
         * @return bool True if it's internal only.
         */
        public static function isInternal(int $comment_id): bool
        {

            // pull the flag off the comment meta
            return (bool) get_comment_meta($comment_id, self::META_INTERNAL, true);
        }

        /**
         * Count the replies on a ticket.
         *
         * @since  1.0.0
         * @access public
         * @param  int $ticket_id The ticket id.
         * @return int How many replies it has.
         */
        public static function countForTicket(int $ticket_id): int
        {

            // let the database do the counting rather than pulling every row back
            $count = get_comments(array(
                'post_id' => $ticket_id,
                'type'    => self::COMMENT_TYPE,
                'status'  => 'approve',
                'count'   => true,
            ));

            // hand it back
            return (int) $count;
        }

        /**
         * Get the replies on a ticket.
         *
         * @since  1.0.0
         * @access public
         * @param  int    $ticket_id        The ticket id.
         * @param  bool   $include_internal Whether internal notes should come back.
         * @param  string $since            Only return replies newer than this GMT datetime.
         * @return array<int, \WP_Comment> The replies, oldest first.
         */
        public static function forTicket(int $ticket_id, bool $include_internal, string $since = ''): array
        {

            // the base query
            $args = array(
                'post_id' => $ticket_id,
                'type'    => self::COMMENT_TYPE,
                'status'  => 'approve',
                'orderby' => 'comment_date_gmt',
                'order'   => 'ASC',
            );

            // if we were given a cutoff, only pull what came after it
            if ($since !== '') {
                $args['date_query'] = array(
                    array(
                        'after'     => $since,
                        'column'    => 'comment_date_gmt',
                        'inclusive' => false,
                    ),
                );
            }

            // if they can't see internal notes, filter them out in the query itself
            if (! $include_internal) {
                $args['meta_query'] = array(
                    'relation' => 'OR',
                    array(
                        'key'     => self::META_INTERNAL,
                        'value'   => '1',
                        'compare' => '!=',
                    ),
                    array(
                        'key'     => self::META_INTERNAL,
                        'compare' => 'NOT EXISTS',
                    ),
                );
            }

            // run it
            $comments = get_comments($args);

            // and hand back what we got
            return is_array($comments) ? $comments : array();
        }

        /**
         * Arrange a flat list of replies into a threaded tree.
         *
         * @since  1.0.0
         * @access public
         * @param  array<int, \WP_Comment> $comments The flat reply list.
         * @return array<int, array<string, mixed>> The tree, each entry having a comment and children.
         */
        public static function thread(array $comments): array
        {

            // group every reply under its parent, and note which ids we actually have
            $by_parent = array();
            $present = array();

            // walk them once to build both
            foreach ($comments as $_comment) {

                // note that we have this one
                $present[(int) $_comment->comment_ID] = true;

                // and file it under its parent
                $by_parent[(int) $_comment->comment_parent][] = $_comment;
            }

            // anything with no parent, or whose parent isn't in our set, is top level.
            // that second case matters because an internal note can be filtered out
            // from under a customer while its public children are still visible
            $roots = array();
            foreach ($comments as $_comment) {

                // what's its parent
                $parent = (int) $_comment->comment_parent;

                // and keep it if that parent isn't here
                if ($parent === 0 || ! isset($present[$parent])) {
                    $roots[] = $_comment;
                }
            }

            // now build the tree out from the roots
            return self::buildNodes($roots, $by_parent, 0);
        }

        /**
         * Recursively build tree nodes out of a set of replies.
         *
         * @since  1.0.0
         * @access private
         * @param  array<int, \WP_Comment>              $comments  The replies at this level.
         * @param  array<int, array<int, \WP_Comment>>  $by_parent Every reply grouped by parent id.
         * @param  int                                  $depth     How deep we currently are.
         * @return array<int, array<string, mixed>> The nodes at this level.
         */
        private static function buildNodes(array $comments, array $by_parent, int $depth): array
        {

            // stop runaway nesting dead, just in case the data ever gets strange
            if ($depth > 20) {
                return array();
            }

            // build a node for each reply at this level
            $nodes = array();
            foreach ($comments as $_comment) {

                // whatever hangs off this one
                $children = $by_parent[(int) $_comment->comment_ID] ?? array();

                // and build it out
                $nodes[] = array(
                    'comment'  => $_comment,
                    'children' => self::buildNodes($children, $by_parent, $depth + 1),
                );
            }

            // hand back this level
            return $nodes;
        }

        /**
         * Render a single reply, plus everything nested underneath it.
         *
         * @since  1.0.0
         * @access public
         * @param  array<string, mixed> $node      A node from the reply tree.
         * @param  int                  $ticket_id The ticket id.
         * @param  int                  $depth     How deep we currently are.
         * @return string The rendered markup.
         */
        public static function renderNode(array $node, int $ticket_id, int $depth = 0): string
        {

            // we need a real comment to render
            if (! isset($node['comment']) || ! $node['comment'] instanceof \WP_Comment) {
                return '';
            }

            // render this one
            $output = Template::get('reply', array(
                'comment'   => $node['comment'],
                'ticket_id' => $ticket_id,
                'depth'     => $depth,
                'children'  => $node['children'] ?? array(),
            ));

            // and hand it back
            return $output;
        }

        /**
         * Build up the data we hand back to the browser for a reply.
         *
         * @since  1.0.0
         * @access public
         * @param  \WP_Comment $comment   The reply.
         * @param  int         $ticket_id The ticket id.
         * @return array<string, mixed> The reply data.
         */
        public static function toArray(\WP_Comment $comment, int $ticket_id): array
        {

            // who wrote it
            $author_id = (int) $comment->user_id;

            // put it all together
            return array(
                'id'       => (int) $comment->comment_ID,
                'parent'   => (int) $comment->comment_parent,
                'author'   => $comment->comment_author,
                'authorId' => $author_id,
                'isAgent'  => Access::isAgent($author_id),
                'internal' => self::isInternal((int) $comment->comment_ID),
                'date'     => mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $comment->comment_date),
                'dateGmt'  => $comment->comment_date_gmt,
                'html'     => Template::get('reply', array(
                    'comment'   => $comment,
                    'ticket_id' => $ticket_id,
                    'depth'     => 0,
                    'children'  => array(),
                )),
            );
        }
    }
}
