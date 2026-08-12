<?php

/**
 * Template - The reply thread and the reply box
 *
 * Shared by the front-end portal and the wp-admin ticket screen, so both run
 * exactly the same chat.
 *
 * Override this by copying it to your-theme/kp-support/thread.php
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 *
 * @var int                              $ticket_id    The ticket id.
 * @var array<int, array<string, mixed>> $replies      The threaded replies.
 * @var bool                             $can_reply    Whether they can post a reply.
 * @var bool                             $can_internal Whether they can post internal notes.
 * @var string                           $context      Either portal or admin.
 */

declare(strict_types=1);

use KP\Support\Plugin;
use KP\Support\Modules\Replies;
use KP\Support\Modules\Attachments;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// tidy up what we were handed
$ticket_id = isset($ticket_id) ? (int) $ticket_id : 0;
$replies = isset($replies) && is_array($replies) ? $replies : array();
$can_reply = ! empty($can_reply);
$can_internal = ! empty($can_internal);
$context = isset($context) ? (string) $context : 'portal';

// work out the newest reply we're rendering, the poller picks up from here
$latest = '';
$flat = Replies::forTicket($ticket_id, $can_internal);
foreach ($flat as $_reply) {
    if ($_reply->comment_date_gmt > $latest) {
        $latest = $_reply->comment_date_gmt;
    }
}

// are attachments even switched on
$allow_files = (bool) Plugin::opt('allow_attachments', true);
?>
<div class="kpts-thread kpts-context-<?php echo esc_attr($context); ?>"
     data-ticket-id="<?php echo esc_attr((string) $ticket_id); ?>"
     data-latest="<?php echo esc_attr($latest); ?>">

    <ul class="kpts-replies kpts-replies-root">
        <?php if (empty($replies)) : ?>
            <li class="kpts-no-replies"><?php esc_html_e('No replies yet.', 'kp-support'); ?></li>
        <?php else : ?>
            <?php
            // render the whole tree
            foreach ($replies as $_node) {
                echo Replies::renderNode($_node, $ticket_id, 0); // phpcs:ignore WordPress.Security.EscapeOutput -- the reply template escapes its own output
            }
            ?>
        <?php endif; ?>
    </ul>

    <?php if ($can_reply) : ?>
        <form class="kpts-reply-form" method="post" enctype="multipart/form-data">

            <div class="kpts-replying-to" hidden>
                <span class="kpts-replying-to-text"></span>
                <button type="button" class="kpts-cancel-reply"><?php esc_html_e('Cancel', 'kp-support'); ?></button>
            </div>

            <input type="hidden" name="parent" class="kpts-reply-parent" value="0" />

            <label class="screen-reader-text" for="kpts-reply-content-<?php echo esc_attr((string) $ticket_id); ?>">
                <?php esc_html_e('Your reply', 'kp-support'); ?>
            </label>
            <textarea id="kpts-reply-content-<?php echo esc_attr((string) $ticket_id); ?>"
                      class="kpts-reply-content-input"
                      name="content"
                      rows="4"
                      placeholder="<?php esc_attr_e('Type your reply...', 'kp-support'); ?>"></textarea>

            <div class="kpts-reply-toolbar">

                <?php if ($allow_files) : ?>
                    <label class="kpts-file-label">
                        <input type="file" class="kpts-file-input" name="kpts_files[]" multiple />
                        <span><?php esc_html_e('Attach files', 'kp-support'); ?></span>
                    </label>
                <?php endif; ?>

                <?php if ($can_internal) : ?>
                    <label class="kpts-internal-toggle">
                        <input type="checkbox" class="kpts-internal-input" name="internal" value="1" />
                        <span><?php esc_html_e('Internal note (customers will not see this)', 'kp-support'); ?></span>
                    </label>
                <?php endif; ?>

                <button type="submit" class="kpts-button kpts-send-reply">
                    <?php esc_html_e('Send Reply', 'kp-support'); ?>
                </button>
            </div>

            <?php if ($allow_files) : ?>
                <ul class="kpts-file-list"></ul>
                <p class="kpts-file-help">
                    <?php
                    // let them know the limits up front
                    printf(
                        /* translators: %1$s: maximum file size, %2$d: maximum number of files */
                        esc_html__('Up to %2$d files, %1$s each.', 'kp-support'),
                        esc_html(size_format(Attachments::maxSize())),
                        (int) Plugin::opt('max_attachments', 5)
                    );
                    ?>
                </p>
            <?php endif; ?>

            <div class="kpts-reply-error" role="alert" hidden></div>
        </form>
    <?php else : ?>
        <p class="kpts-notice kpts-notice-info">
            <?php esc_html_e('This ticket is closed and cannot be replied to.', 'kp-support'); ?>
        </p>
    <?php endif; ?>
</div>
