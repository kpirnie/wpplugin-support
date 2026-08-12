<?php

/**
 * Template - The ticket list
 *
 * Override this by copying it to your-theme/kp-support/list.php
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 *
 * @var \WP_Query $kpts_query      The ticket query.
 * @var bool      $kpts_is_agent   Whether the viewer is an agent.
 * @var bool      $kpts_agent_view Whether we're showing the whole queue.
 * @var int       $kpts_user_id    The viewer's user id.
 */

declare(strict_types=1);

use KP\Support\Helpers\Access;
use KP\Support\Helpers\Ticket;
use KP\Support\Modules\Portal;
use KP\Support\Modules\Accounts;
use KP\Support\Modules\PostTypes;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// we need a real query
if (! isset($kpts_query) || ! $kpts_query instanceof \WP_Query) {
    return;
}

// tidy up what we were handed
$kpts_is_agent = ! empty($kpts_is_agent);
$kpts_agent_view = ! empty($kpts_agent_view);

// what's currently filtered
$kpts_current_status = isset($_GET['kpts_status']) ? absint(wp_unslash($_GET['kpts_status'])) : 0;
$kpts_current_department = isset($_GET['kpts_department']) ? absint(wp_unslash($_GET['kpts_department'])) : 0;
$kpts_current_search = isset($_GET['kpts_search']) ? sanitize_text_field(wp_unslash($_GET['kpts_search'])) : '';
?>
<div class="kpts-portal kpts-portal-list">

    <?php echo Accounts::renderMessages(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in renderMessages() 
    ?>

    <div class="kpts-list-header">
        <h2>
            <?php echo $kpts_agent_view ? esc_html__('All Tickets', 'kp-support') : esc_html__('My Tickets', 'kp-support'); ?>
        </h2>
        <div class="kpts-list-actions">
            <?php if ($kpts_is_agent) : ?>
                <a class="kpts-button kpts-button-secondary"
                    href="<?php echo esc_url($kpts_agent_view ? add_query_arg('kpts_mine', '1', Portal::url()) : Portal::url()); ?>">
                    <?php echo $kpts_agent_view ? esc_html__('Only Mine', 'kp-support') : esc_html__('All Tickets', 'kp-support'); ?>
                </a>
            <?php endif; ?>
            <a class="kpts-button" href="<?php echo esc_url(Portal::viewUrl('new')); ?>">
                <?php esc_html_e('Open A Ticket', 'kp-support'); ?>
            </a>
            <a class="kpts-button kpts-button-secondary" href="<?php echo esc_url(Portal::viewUrl('profile')); ?>">
                <?php esc_html_e('My Profile', 'kp-support'); ?>
            </a>
        </div>
    </div>

    <form class="kpts-filters" method="get" action="<?php echo esc_url(Portal::url()); ?>">

        <?php
        // keep the page id on the query string for sites without pretty permalinks
        if (Portal::pageId() > 0 && ! get_option('permalink_structure')) {
            printf('<input type="hidden" name="page_id" value="%s" />', esc_attr((string) Portal::pageId()));
        }
        ?>

        <label class="screen-reader-text" for="kpts-search"><?php esc_html_e('Search tickets', 'kp-support'); ?></label>
        <input type="search"
            id="kpts-search"
            name="kpts_search"
            value="<?php echo esc_attr($kpts_current_search); ?>"
            placeholder="<?php esc_attr_e('Search tickets...', 'kp-support'); ?>" />

        <label class="screen-reader-text" for="kpts-filter-status"><?php esc_html_e('Filter by status', 'kp-support'); ?></label>
        <select id="kpts-filter-status" name="kpts_status">
            <option value="0"><?php esc_html_e('All statuses', 'kp-support'); ?></option>
            <?php foreach (PostTypes::terms(PostTypes::TAX_STATUS) as $_kpts_term) : ?>
                <option value="<?php echo esc_attr((string) $_kpts_term->term_id); ?>" <?php selected($kpts_current_status, (int) $_kpts_term->term_id); ?>>
                    <?php echo esc_html($_kpts_term->name); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($kpts_is_agent) : ?>
            <label class="screen-reader-text" for="kpts-filter-department"><?php esc_html_e('Filter by department', 'kp-support'); ?></label>
            <select id="kpts-filter-department" name="kpts_department">
                <option value="0"><?php esc_html_e('All departments', 'kp-support'); ?></option>
                <?php foreach (PostTypes::terms(PostTypes::TAX_DEPARTMENT) as $_kpts_term) : ?>
                    <option value="<?php echo esc_attr((string) $_kpts_term->term_id); ?>" <?php selected($kpts_current_department, (int) $_kpts_term->term_id); ?>>
                        <?php echo esc_html($_kpts_term->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <?php if ($kpts_agent_view) : ?>
            <input type="hidden" name="kpts_mine" value="0" />
        <?php endif; ?>

        <button type="submit" class="kpts-button kpts-button-secondary"><?php esc_html_e('Filter', 'kp-support'); ?></button>
    </form>

    <?php if (! $kpts_query->have_posts()) : ?>

        <p class="kpts-empty"><?php esc_html_e('There are no tickets to show.', 'kp-support'); ?></p>

    <?php else : ?>

        <ul class="kpts-ticket-list">
            <?php while ($kpts_query->have_posts()) : ?>
                <?php
                // step into the post
                $kpts_query->the_post();

                // grab what we need about it
                $kpts_id = get_the_ID();
                $kpts_status = Ticket::term($kpts_id, PostTypes::TAX_STATUS);
                $kpts_priority = Ticket::term($kpts_id, PostTypes::TAX_PRIORITY);
                $kpts_department = Ticket::term($kpts_id, PostTypes::TAX_DEPARTMENT);
                $kpts_activity = (string) get_post_meta($kpts_id, Ticket::META_LAST_ACTIVITY, true);
                $kpts_requester = get_userdata((int) get_post_meta($kpts_id, Access::META_REQUESTER, true));
                $kpts_assignee = get_userdata((int) get_post_meta($kpts_id, Access::META_ASSIGNEE, true));

                // the colour for the status badge
                $kpts_color = ($kpts_status instanceof \WP_Term)
                    ? (string) get_term_meta($kpts_status->term_id, 'kpts_color', true)
                    : '';
                $kpts_color = ($kpts_color !== '') ? $kpts_color : '#6c757d';
                ?>
                <li class="kpts-ticket-row">
                    <a class="kpts-ticket-link" href="<?php echo esc_url(Portal::ticketUrl($kpts_id)); ?>">

                        <span class="kpts-ticket-row-main">
                            <span class="kpts-ticket-number"><?php echo esc_html(Ticket::number($kpts_id)); ?></span>
                            <span class="kpts-ticket-title"><?php echo esc_html(get_the_title()); ?></span>
                            <span class="kpts-ticket-sub">
                                <?php if ($kpts_is_agent && $kpts_requester instanceof \WP_User) : ?>
                                    <?php echo esc_html($kpts_requester->display_name); ?> &middot;
                                <?php endif; ?>
                                <?php if ($kpts_department instanceof \WP_Term) : ?>
                                    <?php echo esc_html($kpts_department->name); ?> &middot;
                                <?php endif; ?>
                                <?php
                                // when something last happened on it
                                if ($kpts_activity !== '') {
                                    printf(
                                        /* translators: %s: human readable time difference */
                                        esc_html__('updated %s ago', 'kp-support'),
                                        esc_html(human_time_diff(strtotime($kpts_activity), current_time('timestamp')))
                                    );
                                }
                                ?>
                            </span>
                        </span>

                        <span class="kpts-ticket-row-meta">
                            <?php if ($kpts_priority instanceof \WP_Term) : ?>
                                <span class="kpts-priority-label"><?php echo esc_html($kpts_priority->name); ?></span>
                            <?php endif; ?>
                            <?php if ($kpts_status instanceof \WP_Term) : ?>
                                <span class="kpts-badge" style="background:<?php echo esc_attr($kpts_color); ?>;">
                                    <?php echo esc_html($kpts_status->name); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($kpts_is_agent) : ?>
                                <span class="kpts-assignee">
                                    <?php
                                    echo ($kpts_assignee instanceof \WP_User)
                                        ? esc_html($kpts_assignee->display_name)
                                        : esc_html__('Unassigned', 'kp-support');
                                    ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    </a>
                </li>
            <?php endwhile; ?>
        </ul>

        <?php
        // and the pagination, if there's more than one page of them
        if ($kpts_query->max_num_pages > 1) {

            // build the links
            $kpts_links = paginate_links(array(
                'base'      => add_query_arg('kpts_paged', '%#%', Portal::url()),
                'format'    => '',
                'current'   => max(1, (int) $kpts_query->get('paged')),
                'total'     => (int) $kpts_query->max_num_pages,
                'prev_text' => __('&larr; Previous', 'kp-support'),
                'next_text' => __('Next &rarr;', 'kp-support'),
                'type'      => 'array',
            ));

            // and render them out
            if (is_array($kpts_links)) {
                echo '<nav class="kpts-pagination">';
                foreach ($kpts_links as $_kpts_link) {
                    echo wp_kses_post($_kpts_link);
                }
                echo '</nav>';
            }
        }

        // put the global post back the way we found it
        wp_reset_postdata();
        ?>

    <?php endif; ?>
</div>