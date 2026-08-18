<?php

/**
 * Template - A single chat message
 *
 * Rendered on its own when a message arrives over AJAX, so the markup the
 * poller injects matches what came down with the page exactly.
 *
 * Override this by copying it to your-theme/kp-support/chat-message.php
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 *
 * @var array<string, mixed> $kpts_message The message details.
 */

declare(strict_types=1);

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// tidy up what we were handed
$kpts_message = isset($kpts_message) && is_array($kpts_message) ? $kpts_message : array();

// nothing to render
if (empty($kpts_message)) {
    return;
}

// which side of the conversation it sits on
$kpts_side = ! empty($kpts_message['isMine']) ? 'mine' : 'theirs';
?>
<li class="kpts-chat-message kpts-chat-<?php echo esc_attr($kpts_side); ?><?php echo ! empty($kpts_message['isAgent']) ? ' kpts-chat-agent' : ''; ?>"
    data-message-id="<?php echo esc_attr((string) ($kpts_message['id'] ?? 0)); ?>">

    <img class="kpts-chat-avatar"
        src="<?php echo esc_url((string) ($kpts_message['avatar'] ?? '')); ?>"
        alt=""
        width="32"
        height="32"
        loading="lazy" />

    <div class="kpts-chat-bubble">
        <div class="kpts-chat-meta">
            <span class="kpts-chat-author"><?php echo esc_html((string) ($kpts_message['author'] ?? '')); ?></span>
            <span class="kpts-chat-time"><?php echo esc_html((string) ($kpts_message['date'] ?? '')); ?></span>
        </div>
        <div class="kpts-chat-content">
            <?php echo wp_kses_post((string) ($kpts_message['content'] ?? '')); ?>
        </div>
    </div>
</li>