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
 * @var \WP_Comment                $comment   The reply being rendered.
 * @var int                        $ticket_id The ticket it belongs to.
 * @var int                        $depth     How deep in the thread we are.
 * @var array<int, array<string, mixed>> $children Any nested replies.
 */

declare(strict_types=1);

use KP\Support\Helpers\Access;
use KP\Support\Modules\Replies;
use KP\Support\Modules\Attachments;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// we need a real reply to render
if (! isset($comment) || ! $comment instanceof \WP_Comment) {
    return;
}

// tidy up what we were handed
$ticket_id = isset($ticket_id) ? (int) $ticket_id : 0;
$depth = isset($depth) ? (int) $depth : 0;
$children = isset($children) && is_array($children) ? $children : array();

// work out who wrote it and what kind of reply it is
$comment_id = (int) $comment->comment_ID;
$author_id = (int) $comment->user_id;
$is_internal = Replies::isInternal($comment_id);
$is_agent = Access::isAgent($author_id);
$is_mine = ($author_id === get_current_user_id());

// build up the classes for this one
$classes = array('kpts-reply');
$classes[] = $is_internal ? 'kpts-reply-internal' : 'kpts-reply-public';
$classes[] = $is_agent ? 'kpts-reply-agent' : 'kpts-reply-customer';
$classes[] = $is_mine ? 'kpts-reply-mine' : '';

// and pull any files that came in on it
$files = get_comment_meta($comment_id, Attachments::META_REPLY_FILES, true);
$files = is_array($files) ? $files : array();
?>
<li id="kpts-reply-<?php echo esc_attr((string) $comment_id); ?>"
    class="<?php echo esc_attr(trim(implode(' ', array_filter($classes)))); ?>"
    data-reply-id="<?php echo esc_attr((string) $comment_id); ?>"
    data-depth="<?php echo esc_attr((string) $depth); ?>">

    <div class="kpts-reply-inner">

        <div class="kpts-reply-avatar">
            <?php echo get_avatar($author_id, 40, '', $comment->comment_author); ?>
        </div>

        <div class="kpts-reply-main">

            <div class="kpts-reply-meta">
                <span class="kpts-reply-author"><?php echo esc_html($comment->comment_author); ?></span>

                <?php if ($is_agent) : ?>
                    <span class="kpts-tag kpts-tag-agent"><?php esc_html_e('Agent', 'kp-support'); ?></span>
                <?php endif; ?>

                <?php if ($is_internal) : ?>
                    <span class="kpts-tag kpts-tag-internal"><?php esc_html_e('Internal note', 'kp-support'); ?></span>
                <?php endif; ?>

                <time class="kpts-reply-date" datetime="<?php echo esc_attr($comment->comment_date_gmt); ?>">
                    <?php
                    // show it in the site's configured format
                    echo esc_html(
                        mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $comment->comment_date)
                    );
                    ?>
                </time>
            </div>

            <div class="kpts-reply-content">
                <?php
                // this was already run through wp_kses when it was saved, and we
                // escape again here at the point of output
                echo wp_kses_post(wpautop($comment->comment_content));
                ?>
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

            <div class="kpts-reply-actions">
                <button type="button"
                        class="kpts-reply-to"
                        data-parent="<?php echo esc_attr((string) $comment_id); ?>"
                        data-author="<?php echo esc_attr($comment->comment_author); ?>">
                    <?php esc_html_e('Reply', 'kp-support'); ?>
                </button>
            </div>

        </div>
    </div>

    <?php if (! empty($children)) : ?>
        <ul class="kpts-replies kpts-replies-nested">
            <?php
            // render everything nested underneath this one
            foreach ($children as $_child) {
                echo Replies::renderNode($_child, $ticket_id, $depth + 1); // phpcs:ignore WordPress.Security.EscapeOutput -- the template escapes its own output
            }
            ?>
        </ul>
    <?php endif; ?>
</li>
