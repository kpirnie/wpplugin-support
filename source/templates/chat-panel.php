<?php

/**
 * Template - The corner docked chat launcher and panel
 *
 * Fixed positioned and non modal on purpose, there's no backdrop and nothing
 * is overlaid, the page underneath stays scrollable and clickable.
 *
 * Override this by copying it to your-theme/kp-support/chat-panel.php
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 *
 * @var int                     $kpts_chat_id  The chat already open, or 0.
 * @var string                  $kpts_position Which corner it docks into.
 * @var string                  $kpts_label    The launcher label.
 * @var array<int, \WP_Comment> $kpts_messages Whatever has been said so far.
 * @var int                     $kpts_visitor  The current user's id.
 * @var string                  $kpts_state    The chat's state.
 */

declare(strict_types=1);

use KP\Support\Helpers\Template;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// tidy up what we were handed
$kpts_chat_id = isset($kpts_chat_id) ? (int) $kpts_chat_id : 0;
$kpts_position = isset($kpts_position) ? (string) $kpts_position : 'bottom-right';
$kpts_label = isset($kpts_label) ? (string) $kpts_label : '';
$kpts_messages = isset($kpts_messages) && is_array($kpts_messages) ? $kpts_messages : array();
$kpts_visitor = isset($kpts_visitor) ? (int) $kpts_visitor : 0;
$kpts_state = isset($kpts_state) ? (string) $kpts_state : '';

// work out the newest message we're rendering, the poller picks up from here
$kpts_latest = '';
foreach ($kpts_messages as $_kpts_message) {
    if ($_kpts_message->comment_date_gmt > $kpts_latest) {
        $kpts_latest = $_kpts_message->comment_date_gmt;
    }
}
?>
<div class="kpts-chat kpts-chat-<?php echo esc_attr($kpts_position); ?>"
    data-chat-id="<?php echo esc_attr((string) $kpts_chat_id); ?>"
    data-latest="<?php echo esc_attr($kpts_latest); ?>"
    data-state="<?php echo esc_attr($kpts_state); ?>">

    <button type="button" class="kpts-chat-launcher" aria-expanded="false" aria-controls="kpts-chat-panel">
        <span class="kpts-chat-launcher-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" focusable="false">
                <path fill="currentColor" d="M12 3C6.5 3 2 6.8 2 11.5c0 2.4 1.2 4.6 3.1 6.1L4 22l4.7-2.3c1 .3 2.1.4 3.3.4 5.5 0 10-3.8 10-8.6S17.5 3 12 3z" />
            </svg>
        </span>
        <span class="kpts-chat-launcher-label"><?php echo esc_html($kpts_label); ?></span>
    </button>

    <section id="kpts-chat-panel" class="kpts-chat-panel" hidden aria-label="<?php esc_attr_e('Live chat', 'kp-support'); ?>">

        <header class="kpts-chat-header">
            <span class="kpts-chat-title"><?php echo esc_html($kpts_label); ?></span>
            <span class="kpts-chat-status" role="status"></span>
            <button type="button" class="kpts-chat-end" hidden>
                <?php esc_html_e('End chat', 'kp-support'); ?>
            </button>
            <button type="button" class="kpts-chat-minimize" aria-label="<?php esc_attr_e('Minimize chat', 'kp-support'); ?>">
                <span aria-hidden="true">&times;</span>
            </button>
        </header>

        <ul class="kpts-chat-messages" aria-live="polite">
            <?php foreach ($kpts_messages as $_kpts_message) : ?>
                <?php
                // render each one through the same template the poller uses
                Template::render('chat-message', array(
                    'message' => array(
                        'id'      => (int) $_kpts_message->comment_ID,
                        'content' => wpautop(wp_kses_post($_kpts_message->comment_content)),
                        'author'  => $_kpts_message->comment_author,
                        'avatar'  => get_avatar_url((int) $_kpts_message->user_id, array('size' => 48)),
                        'isAgent' => (int) $_kpts_message->user_id !== $kpts_visitor,
                        'isMine'  => (int) $_kpts_message->user_id === $kpts_visitor,
                        'date'    => mysql2date(get_option('time_format'), $_kpts_message->comment_date),
                    ),
                ));
                ?>
            <?php endforeach; ?>
        </ul>

        <p class="kpts-chat-notice" role="alert" hidden></p>

        <form class="kpts-chat-form" novalidate>
            <label class="screen-reader-text" for="kpts-chat-input">
                <?php esc_html_e('Your message', 'kp-support'); ?>
            </label>
            <textarea id="kpts-chat-input"
                class="kpts-chat-input"
                name="content"
                rows="2"
                placeholder="<?php esc_attr_e('Type your message...', 'kp-support'); ?>"></textarea>
            <button type="submit" class="kpts-chat-send">
                <?php esc_html_e('Send', 'kp-support'); ?>
            </button>
        </form>
    </section>
</div>