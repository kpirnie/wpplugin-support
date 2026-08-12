<?php

/**
 * Template - A single ticket with its conversation
 *
 * Override this by copying it to your-theme/kp-support/ticket.php
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 *
 * @var \WP_Post                         $ticket       The ticket.
 * @var int                              $ticket_id    The ticket id.
 * @var array<int, array<string, mixed>> $replies      The threaded replies.
 * @var bool                             $can_reply    Whether they can reply.
 * @var bool                             $can_internal Whether they can post internal notes.
 * @var bool                             $can_manage   Whether they can change the ticket.
 * @var array<int, \WP_Term>             $statuses     The available statuses.
 * @var array<int, \WP_Term>             $priorities   The available priorities.
 * @var array<int, \WP_Term>             $departments  The available departments.
 * @var array<int, int>                  $agents       The assignable agents.
 */

declare(strict_types=1);

use KP\Support\Helpers\Access;
use KP\Support\Helpers\Ticket;
use KP\Support\Helpers\Template;
use KP\Support\Modules\Portal;
use KP\Support\Modules\PostTypes;
use KP\Support\Modules\Attachments;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// we need a real ticket
if (! isset($ticket) || ! $ticket instanceof \WP_Post) {
    return;
}

// tidy up what we were handed
$ticket_id = (int) $ticket->ID;
$can_manage = ! empty($can_manage);

// pull the terms hanging off it
$status = Ticket::term($ticket_id, PostTypes::TAX_STATUS);
$priority = Ticket::term($ticket_id, PostTypes::TAX_PRIORITY);
$department = Ticket::term($ticket_id, PostTypes::TAX_DEPARTMENT);
$category = Ticket::term($ticket_id, PostTypes::TAX_CATEGORY);

// who opened it and who has it
$requester = get_userdata((int) get_post_meta($ticket_id, Access::META_REQUESTER, true));
$assignee = (int) get_post_meta($ticket_id, Access::META_ASSIGNEE, true);

// and any files that came in on the opening message
$files = get_post_meta($ticket_id, Ticket::META_ATTACHMENTS, true);
$files = is_array($files) ? $files : array();

/**
 * Render a coloured term badge.
 *
 * @param  \WP_Term|null $term The term to render.
 * @return string The badge markup, already escaped.
 */
$kpts_badge = static function (?\WP_Term $term): string {

    // nothing to render
    if (! $term instanceof \WP_Term) {
        return '';
    }

    // pull its colour, falling back to a neutral grey
    $color = (string) get_term_meta($term->term_id, 'kpts_color', true);
    $color = ($color !== '') ? $color : '#6c757d';

    // and build the badge
    return sprintf(
        '<span class="kpts-badge" style="background:%1$s;">%2$s</span>',
        esc_attr($color),
        esc_html($term->name)
    );
};
?>
<div class="kpts-portal kpts-portal-ticket">

    <?php echo \KP\Support\Modules\Accounts::renderMessages(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in renderMessages() ?>

    <p class="kpts-back">
        <a href="<?php echo esc_url(Portal::url()); ?>">&larr; <?php esc_html_e('Back to all tickets', 'kp-support'); ?></a>
    </p>

    <div class="kpts-ticket-header">
        <div class="kpts-ticket-heading">
            <span class="kpts-ticket-number"><?php echo esc_html(Ticket::number($ticket_id)); ?></span>
            <h2 class="kpts-ticket-subject"><?php echo esc_html($ticket->post_title); ?></h2>
        </div>
        <div class="kpts-ticket-badges">
            <?php echo $kpts_badge($status); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the closure ?>
            <?php echo $kpts_badge($priority); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the closure ?>
        </div>
    </div>

    <div class="kpts-ticket-layout">

        <div class="kpts-ticket-main">

            <div class="kpts-original-message">
                <div class="kpts-reply-inner">
                    <div class="kpts-reply-avatar">
                        <?php echo get_avatar((int) $ticket->post_author, 40); ?>
                    </div>
                    <div class="kpts-reply-main">
                        <div class="kpts-reply-meta">
                            <span class="kpts-reply-author">
                                <?php echo esc_html(($requester instanceof \WP_User) ? $requester->display_name : __('Unknown', 'kp-support')); ?>
                            </span>
                            <span class="kpts-tag kpts-tag-original"><?php esc_html_e('Opened the ticket', 'kp-support'); ?></span>
                            <time class="kpts-reply-date" datetime="<?php echo esc_attr($ticket->post_date_gmt); ?>">
                                <?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $ticket->post_date)); ?>
                            </time>
                        </div>
                        <div class="kpts-reply-content">
                            <?php echo wp_kses_post(wpautop($ticket->post_content)); ?>
                        </div>

                        <?php if (! empty($files)) : ?>
                            <ul class="kpts-attachments">
                                <?php foreach ($files as $_file) : ?>
                                    <?php
                                    // skip anything malformed
                                    if (empty($_file['key']) || empty($_file['name'])) {
                                        continue;
                                    }
                                    ?>
                                    <li class="kpts-attachment">
                                        <a href="<?php echo esc_url(Attachments::url($ticket_id, (string) $_file['key'])); ?>" rel="nofollow">
                                            <span class="kpts-attachment-name"><?php echo esc_html((string) $_file['name']); ?></span>
                                            <span class="kpts-attachment-size"><?php echo esc_html(size_format((int) ($_file['size'] ?? 0))); ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php
            // and the conversation itself
            Template::render('thread', array(
                'ticket_id'    => $ticket_id,
                'replies'      => $replies ?? array(),
                'can_reply'    => ! empty($can_reply),
                'can_internal' => ! empty($can_internal),
                'context'      => 'portal',
            ));
            ?>
        </div>

        <aside class="kpts-ticket-sidebar">

            <div class="kpts-panel">
                <h3><?php esc_html_e('Details', 'kp-support'); ?></h3>
                <dl class="kpts-detail-list">
                    <dt><?php esc_html_e('Requester', 'kp-support'); ?></dt>
                    <dd><?php echo esc_html(($requester instanceof \WP_User) ? $requester->display_name : __('Unknown', 'kp-support')); ?></dd>

                    <dt><?php esc_html_e('Department', 'kp-support'); ?></dt>
                    <dd><?php echo esc_html(($department instanceof \WP_Term) ? $department->name : __('None', 'kp-support')); ?></dd>

                    <dt><?php esc_html_e('Category', 'kp-support'); ?></dt>
                    <dd><?php echo esc_html(($category instanceof \WP_Term) ? $category->name : __('None', 'kp-support')); ?></dd>

                    <dt><?php esc_html_e('Opened', 'kp-support'); ?></dt>
                    <dd><?php echo esc_html(mysql2date(get_option('date_format'), $ticket->post_date)); ?></dd>
                </dl>
            </div>

            <?php if ($can_manage) : ?>
                <div class="kpts-panel kpts-agent-panel" data-ticket-id="<?php echo esc_attr((string) $ticket_id); ?>">
                    <h3><?php esc_html_e('Agent Controls', 'kp-support'); ?></h3>

                    <p>
                        <label for="kpts-set-status"><?php esc_html_e('Status', 'kp-support'); ?></label>
                        <select id="kpts-set-status" class="kpts-ticket-field" data-field="status">
                            <?php foreach (($statuses ?? array()) as $_term) : ?>
                                <option value="<?php echo esc_attr((string) $_term->term_id); ?>"
                                    <?php selected($status instanceof \WP_Term ? $status->term_id : 0, $_term->term_id); ?>>
                                    <?php echo esc_html($_term->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <p>
                        <label for="kpts-set-priority"><?php esc_html_e('Priority', 'kp-support'); ?></label>
                        <select id="kpts-set-priority" class="kpts-ticket-field" data-field="priority">
                            <?php foreach (($priorities ?? array()) as $_term) : ?>
                                <option value="<?php echo esc_attr((string) $_term->term_id); ?>"
                                    <?php selected($priority instanceof \WP_Term ? $priority->term_id : 0, $_term->term_id); ?>>
                                    <?php echo esc_html($_term->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <p>
                        <label for="kpts-set-department"><?php esc_html_e('Department', 'kp-support'); ?></label>
                        <select id="kpts-set-department" class="kpts-ticket-field" data-field="department">
                            <option value="0"><?php esc_html_e('None', 'kp-support'); ?></option>
                            <?php foreach (($departments ?? array()) as $_term) : ?>
                                <option value="<?php echo esc_attr((string) $_term->term_id); ?>"
                                    <?php selected($department instanceof \WP_Term ? $department->term_id : 0, $_term->term_id); ?>>
                                    <?php echo esc_html($_term->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <?php if (current_user_can('kpts_assign_tickets')) : ?>
                        <p>
                            <label for="kpts-set-assignee"><?php esc_html_e('Assigned To', 'kp-support'); ?></label>
                            <select id="kpts-set-assignee" class="kpts-ticket-field" data-field="assignee">
                                <option value="0"><?php esc_html_e('Unassigned', 'kp-support'); ?></option>
                                <?php foreach (($agents ?? array()) as $_agent_id) : ?>
                                    <?php
                                    // grab the agent
                                    $_agent = get_userdata((int) $_agent_id);

                                    // and skip anybody who isn't real
                                    if (! $_agent instanceof \WP_User) {
                                        continue;
                                    }
                                    ?>
                                    <option value="<?php echo esc_attr((string) $_agent->ID); ?>" <?php selected($assignee, (int) $_agent->ID); ?>>
                                        <?php echo esc_html($_agent->display_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                    <?php endif; ?>

                    <div class="kpts-agent-feedback" role="status" hidden></div>
                </div>
            <?php endif; ?>

        </aside>
    </div>
</div>
