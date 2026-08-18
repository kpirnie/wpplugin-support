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
 * @var \WP_Post                         $kpts_ticket       The ticket.
 * @var int                              $kpts_ticket_id    The ticket id.
 * @var array<int, array<string, mixed>> $kpts_replies      The threaded replies.
 * @var bool                             $kpts_can_reply    Whether they can reply.
 * @var bool                             $kpts_can_internal Whether they can post internal notes.
 * @var bool                             $kpts_can_manage   Whether they can change the ticket.
 * @var array<int, \WP_Term>             $kpts_statuses     The available statuses.
 * @var array<int, \WP_Term>             $kpts_priorities   The available priorities.
 * @var array<int, \WP_Term>             $kpts_departments  The available departments.
 * @var array<int, int>                  $kpts_agents       The assignable agents.
 */

declare(strict_types=1);

use KP\Support\Helpers\Access;
use KP\Support\Helpers\Chat;
use KP\Support\Helpers\Ticket;
use KP\Support\Helpers\Template;
use KP\Support\Modules\Portal;
use KP\Support\Modules\PostTypes;
use KP\Support\Modules\Attachments;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// we need a real ticket
if (! isset($kpts_ticket) || ! $kpts_ticket instanceof \WP_Post) {
    return;
}

// tidy up what we were handed
$kpts_ticket_id = (int) $kpts_ticket->ID;
$kpts_can_manage = ! empty($kpts_can_manage);

// pull the terms hanging off it
$kpts_status = Ticket::term($kpts_ticket_id, PostTypes::TAX_STATUS);
$kpts_priority = Ticket::term($kpts_ticket_id, PostTypes::TAX_PRIORITY);
$kpts_department = Ticket::term($kpts_ticket_id, PostTypes::TAX_DEPARTMENT);
$kpts_category = Ticket::term($kpts_ticket_id, PostTypes::TAX_CATEGORY);

// did this ticket start life as a chat
$kpts_chat_source = Chat::sourceOf($kpts_ticket_id);

// who opened it and who has it
$kpts_requester = get_userdata((int) get_post_meta($kpts_ticket_id, Access::META_REQUESTER, true));
$kpts_assignee = (int) get_post_meta($kpts_ticket_id, Access::META_ASSIGNEE, true);

// and any files that came in on the opening message
$kpts_files = get_post_meta($kpts_ticket_id, Ticket::META_ATTACHMENTS, true);
$kpts_files = is_array($kpts_files) ? $kpts_files : array();

/**
 * Render a coloured term badge.
 *
 * @param  \WP_Term|null $kpts_term The term to render.
 * @return string The badge markup, already escaped.
 */
$kpts_badge = static function (?\WP_Term $kpts_term): string {

    // nothing to render
    if (! $kpts_term instanceof \WP_Term) {
        return '';
    }

    // pull its colour, falling back to a neutral grey
    $kpts_color = (string) get_term_meta($kpts_term->term_id, 'kpts_color', true);
    $kpts_color = ($kpts_color !== '') ? $kpts_color : '#6c757d';

    // and build the badge
    return sprintf(
        '<span class="kpts-badge" style="background:%1$s;">%2$s</span>',
        esc_attr($kpts_color),
        esc_html($kpts_term->name)
    );
};
?>
<div class="kpts-portal kpts-portal-ticket">

    <?php echo \KP\Support\Modules\Accounts::renderMessages(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in renderMessages() 
    ?>

    <p class="kpts-back">
        <a href="<?php echo esc_url(Portal::url()); ?>">&larr; <?php esc_html_e('Back to all tickets', 'kp-support'); ?></a>
    </p>

    <div class="kpts-ticket-header">
        <div class="kpts-ticket-heading">
            <span class="kpts-ticket-number"><?php echo esc_html(Ticket::number($kpts_ticket_id)); ?></span>
            <h2 class="kpts-ticket-subject"><?php echo esc_html($kpts_ticket->post_title); ?></h2>
        </div>
        <div class="kpts-ticket-badges">
            <?php echo $kpts_badge($kpts_status); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the closure 
            ?>
            <?php echo $kpts_badge($kpts_priority); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the closure 
            ?>
            <?php if ($kpts_chat_source > 0) : ?>
                <a class="kpts-link kpts-transcript-link" href="<?php echo esc_url(Portal::transcriptUrl($kpts_chat_source)); ?>">
                    <?php esc_html_e('Download chat transcript', 'kp-support'); ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="kpts-ticket-layout">

        <div class="kpts-ticket-main">

            <div class="kpts-original-message">
                <div class="kpts-reply-inner">
                    <div class="kpts-reply-avatar">
                        <?php echo get_avatar((int) $kpts_ticket->post_author, 40); ?>
                    </div>
                    <div class="kpts-reply-main">
                        <div class="kpts-reply-meta">
                            <span class="kpts-reply-author">
                                <?php echo esc_html(($kpts_requester instanceof \WP_User) ? $kpts_requester->display_name : __('Unknown', 'kp-support')); ?>
                            </span>
                            <span class="kpts-tag kpts-tag-original"><?php esc_html_e('Opened the ticket', 'kp-support'); ?></span>
                            <time class="kpts-reply-date" datetime="<?php echo esc_attr($kpts_ticket->post_date_gmt); ?>">
                                <?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $kpts_ticket->post_date)); ?>
                            </time>
                        </div>
                        <div class="kpts-reply-content">
                            <?php echo wp_kses_post(wpautop($kpts_ticket->post_content)); ?>
                        </div>

                        <?php if (! empty($kpts_files)) : ?>
                            <ul class="kpts-attachments">
                                <?php foreach ($kpts_files as $_kpts_file) : ?>
                                    <?php
                                    // skip anything malformed
                                    if (empty($_kpts_file['key']) || empty($_kpts_file['name'])) {
                                        continue;
                                    }
                                    ?>
                                    <li class="kpts-attachment">
                                        <a href="<?php echo esc_url(Attachments::url($kpts_ticket_id, (string) $_kpts_file['key'])); ?>" rel="nofollow">
                                            <span class="kpts-attachment-name"><?php echo esc_html((string) $_kpts_file['name']); ?></span>
                                            <span class="kpts-attachment-size"><?php echo esc_html(size_format((int) ($_kpts_file['size'] ?? 0))); ?></span>
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
                'ticket_id'    => $kpts_ticket_id,
                'replies'      => $kpts_replies ?? array(),
                'can_reply'    => ! empty($kpts_can_reply),
                'can_internal' => ! empty($kpts_can_internal),
                'context'      => 'portal',
            ));
            ?>
        </div>

        <aside class="kpts-ticket-sidebar">

            <div class="kpts-panel">
                <h3><?php esc_html_e('Details', 'kp-support'); ?></h3>
                <dl class="kpts-detail-list">
                    <dt><?php esc_html_e('Requester', 'kp-support'); ?></dt>
                    <dd><?php echo esc_html(($kpts_requester instanceof \WP_User) ? $kpts_requester->display_name : __('Unknown', 'kp-support')); ?></dd>

                    <dt><?php esc_html_e('Department', 'kp-support'); ?></dt>
                    <dd><?php echo esc_html(($kpts_department instanceof \WP_Term) ? $kpts_department->name : __('None', 'kp-support')); ?></dd>

                    <dt><?php esc_html_e('Category', 'kp-support'); ?></dt>
                    <dd><?php echo esc_html(($kpts_category instanceof \WP_Term) ? $kpts_category->name : __('None', 'kp-support')); ?></dd>

                    <dt><?php esc_html_e('Opened', 'kp-support'); ?></dt>
                    <dd><?php echo esc_html(mysql2date(get_option('date_format'), $kpts_ticket->post_date)); ?></dd>
                </dl>
            </div>

            <?php if ($kpts_can_manage) : ?>
                <div class="kpts-panel kpts-agent-panel" data-ticket-id="<?php echo esc_attr((string) $kpts_ticket_id); ?>">
                    <h3><?php esc_html_e('Agent Controls', 'kp-support'); ?></h3>

                    <p>
                        <label for="kpts-set-status"><?php esc_html_e('Status', 'kp-support'); ?></label>
                        <select id="kpts-set-status" class="kpts-ticket-field" data-field="status">
                            <?php foreach (($kpts_statuses ?? array()) as $_kpts_term) : ?>
                                <option value="<?php echo esc_attr((string) $_kpts_term->term_id); ?>"
                                    <?php selected($kpts_status instanceof \WP_Term ? $kpts_status->term_id : 0, $_kpts_term->term_id); ?>>
                                    <?php echo esc_html($_kpts_term->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <p>
                        <label for="kpts-set-priority"><?php esc_html_e('Priority', 'kp-support'); ?></label>
                        <select id="kpts-set-priority" class="kpts-ticket-field" data-field="priority">
                            <?php foreach (($kpts_priorities ?? array()) as $_kpts_term) : ?>
                                <option value="<?php echo esc_attr((string) $_kpts_term->term_id); ?>"
                                    <?php selected($kpts_priority instanceof \WP_Term ? $kpts_priority->term_id : 0, $_kpts_term->term_id); ?>>
                                    <?php echo esc_html($_kpts_term->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <p>
                        <label for="kpts-set-department"><?php esc_html_e('Department', 'kp-support'); ?></label>
                        <select id="kpts-set-department" class="kpts-ticket-field" data-field="department">
                            <option value="0"><?php esc_html_e('None', 'kp-support'); ?></option>
                            <?php foreach (($kpts_departments ?? array()) as $_kpts_term) : ?>
                                <option value="<?php echo esc_attr((string) $_kpts_term->term_id); ?>"
                                    <?php selected($kpts_department instanceof \WP_Term ? $kpts_department->term_id : 0, $_kpts_term->term_id); ?>>
                                    <?php echo esc_html($_kpts_term->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <?php if (current_user_can('kpts_assign_tickets')) : ?>
                        <p>
                            <label for="kpts-set-assignee"><?php esc_html_e('Assigned To', 'kp-support'); ?></label>
                            <select id="kpts-set-assignee" class="kpts-ticket-field" data-field="assignee">
                                <option value="0"><?php esc_html_e('Unassigned', 'kp-support'); ?></option>
                                <?php foreach (($kpts_agents ?? array()) as $_kpts_agent_id) : ?>
                                    <?php
                                    // grab the agent
                                    $_kpts_agent = get_userdata((int) $_kpts_agent_id);

                                    // and skip anybody who isn't real
                                    if (! $_kpts_agent instanceof \WP_User) {
                                        continue;
                                    }
                                    ?>
                                    <option value="<?php echo esc_attr((string) $_kpts_agent->ID); ?>" <?php selected($kpts_assignee, (int) $_kpts_agent->ID); ?>>
                                        <?php echo esc_html($_kpts_agent->display_name); ?>
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