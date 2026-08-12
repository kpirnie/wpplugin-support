<?php

/**
 * Template - A single threaded reply
 *
 * Override this by copying it to your-theme/kp-support/reply.php
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 *
 * @var \WP_Comment                $kpts_comment   The reply being rendered.
 * @var int                        $kpts_ticket_id The ticket it belongs to.
 * @var int                        $kpts_depth     How deep in the thread we are.
 * @var array<int, array<string, mixed>> $kpts_children Any nested replies.
 */

declare(strict_types=1);

use KP\Support\Helpers\Access;
use KP\Support\Modules\Replies;
use KP\Support\Modules\Attachments;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// we need a real reply to render
if (! isset($kpts_comment) || ! $kpts_comment instanceof \WP_Comment) {
    return;
}

// tidy up what we were handed
$kpts_ticket_id = isset($kpts_ticket_id) ? (int) $kpts_ticket_id : 0;
$kpts_depth = isset($kpts_depth) ? (int) $kpts_depth : 0;
$kpts_children = isset($kpts_children) && is_array($kpts_children) ? $kpts_children : array();

// work out who wrote it and what kind of reply it is
$kpts_comment_id = (int) $kpts_comment->comment_ID;
$kpts_author_id = (int) $kpts_comment->user_id;
$kpts_is_internal = Replies::isInternal($kpts_comment_id);
$kpts_is_agent = Access::isAgent($kpts_author_id);
$kpts_is_mine = ($kpts_author_id === get_current_user_id());

// build up the classes for this one
$kpts_classes = array('kpts-reply');
$kpts_classes[] = $kpts_is_internal ? 'kpts-reply-internal' : 'kpts-reply-public';
$kpts_classes[] = $kpts_is_agent ? 'kpts-reply-agent' : 'kpts-reply-customer';
$kpts_classes[] = $kpts_is_mine ? 'kpts-reply-mine' : '';

// and pull any files that came in on it
$kpts_files = get_comment_meta($kpts_comment_id, Attachments::META_REPLY_FILES, true);
$kpts_files = is_array($kpts_files) ? $kpts_files : array();
?>
<li id="kpts-reply-<?php echo esc_attr((string) $kpts_comment_id); ?>"
    class="<?php echo esc_attr(trim(implode(' ', array_filter($kpts_classes)))); ?>"
    data-reply-id="<?php echo esc_attr((string) $kpts_comment_id); ?>"
    data-depth="<?php echo esc_attr((string) $kpts_depth); ?>">

    <div class="kpts-reply-inner">

        <div class="kpts-reply-avatar">
            <?php echo get_avatar($kpts_author_id, 40, '', $kpts_comment->comment_author); ?>
        </div>

        <div class="kpts-reply-main">

            <div class="kpts-reply-meta">
                <span class="kpts-reply-author"><?php echo esc_html($kpts_comment->comment_author); ?></span>

                <?php if ($kpts_is_agent) : ?>
                    <span class="kpts-tag kpts-tag-agent"><?php esc_html_e('Agent', 'kp-support'); ?></span>
                <?php endif; ?>

                <?php if ($kpts_is_internal) : ?>
                    <span class="kpts-tag kpts-tag-internal"><?php esc_html_e('Internal note', 'kp-support'); ?></span>
                <?php endif; ?>

                <time class="kpts-reply-date" datetime="<?php echo esc_attr($kpts_comment->comment_date_gmt); ?>">
                    <?php
                    // show it in the site's configured format
                    echo esc_html(
                        mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $kpts_comment->comment_date)
                    );
                    ?>
                </time>
            </div>

            <div class="kpts-reply-content">
                <?php
                // this was already run through wp_kses when it was saved, and we
                // escape again here at the point of output
                echo wp_kses_post(wpautop($kpts_comment->comment_content));
                ?>
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

            <div class="kpts-reply-actions">
                <button type="button"
                        class="kpts-reply-to"
                        data-parent="<?php echo esc_attr((string) $kpts_comment_id); ?>"
                        data-author="<?php echo esc_attr($kpts_comment->comment_author); ?>">
                    <?php esc_html_e('Reply', 'kp-support'); ?>
                </button>
            </div>

        </div>
    </div>

    <?php if (! empty($kpts_children)) : ?>
        <ul class="kpts-replies kpts-replies-nested">
            <?php
            // render everything nested underneath this one
            foreach ($kpts_children as $_kpts_child) {
                echo Replies::renderNode($_kpts_child, $kpts_ticket_id, $kpts_depth + 1); // phpcs:ignore WordPress.Security.EscapeOutput -- the template escapes its own output
            }
            ?>
        </ul>
    <?php endif; ?>
</li>
