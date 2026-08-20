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
 * @var int                     $kpts_visitor  The visitor's user id, guest or logged in.
 * @var string                  $kpts_state    The chat's state.
 * @var bool                    $kpts_online   Whether an agent is around.
 * @var bool                    $kpts_locked   Whether their details are fixed by a login.
 * @var array<string, string>   $kpts_prefill  What the pre-chat form starts out holding.
 * @var string                  $kpts_offline_message What to say when nobody is.
 */

declare(strict_types=1);

use KP\Support\Helpers\Template;
use KP\Support\Helpers\Chat;
use KP\Support\Modules\Attachments;
use KP\Support\Plugin;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// tidy up what we were handed
$kpts_chat_id = isset($kpts_chat_id) ? (int) $kpts_chat_id : 0;
$kpts_position = isset($kpts_position) ? (string) $kpts_position : 'bottom-right';
$kpts_label = isset($kpts_label) ? (string) $kpts_label : '';
$kpts_messages = isset($kpts_messages) && is_array($kpts_messages) ? $kpts_messages : array();
$kpts_visitor = isset($kpts_visitor) ? (int) $kpts_visitor : 0;
$kpts_state = isset($kpts_state) ? (string) $kpts_state : '';
$kpts_online = isset($kpts_online) ? (bool) $kpts_online : true;
$kpts_locked = isset($kpts_locked) ? (bool) $kpts_locked : false;
$kpts_prefill = isset($kpts_prefill) && is_array($kpts_prefill) ? $kpts_prefill : array();
$kpts_offline_message = isset($kpts_offline_message) ? (string) $kpts_offline_message : '';

// work out the newest message we're rendering, the poller picks up from here
$kpts_latest = '';
foreach ($kpts_messages as $_kpts_message) {
    if ($_kpts_message->comment_date_gmt > $kpts_latest) {
        $kpts_latest = $_kpts_message->comment_date_gmt;
    }
}

// are attachments even switched on
$kpts_allow_files = (bool) Plugin::opt('allow_attachments', true);
?>
<div class="kpts-chat kpts-chat-docked kpts-chat-<?php echo esc_attr($kpts_position); ?><?php echo $kpts_online ? '' : ' kpts-chat-offline'; ?>"
    data-chat-id="<?php echo esc_attr((string) $kpts_chat_id); ?>"
    data-latest="<?php echo esc_attr($kpts_latest); ?>"
    data-online="<?php echo $kpts_online ? '1' : '0'; ?>"
    data-state="<?php echo esc_attr($kpts_state); ?>">

    <button type="button" class="kpts-chat-launcher" aria-expanded="false" aria-controls="kpts-chat-panel">
        <span class="kpts-chat-launcher-icon kpts-chat-launcher-open" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" focusable="false">
                <path fill="currentColor" d="M12 3C6.5 3 2 6.8 2 11.5c0 2.4 1.2 4.6 3.1 6.1L4 22l4.7-2.3c1 .3 2.1.4 3.3.4 5.5 0 10-3.8 10-8.6S17.5 3 12 3z" />
            </svg>
        </span>
        <span class="kpts-chat-launcher-icon kpts-chat-launcher-close" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" focusable="false">
                <path fill="currentColor" d="M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7 2.9 18.3 9.2 12 2.9 5.7 4.3 4.3l6.3 6.3 6.3-6.3z" />
            </svg>
        </span>
        <span class="kpts-chat-launcher-label"><?php echo esc_html($kpts_label); ?></span>
    </button>

    <section id="kpts-chat-panel" class="kpts-chat-panel" hidden aria-label="<?php esc_attr_e('Live chat', 'kp-support'); ?>">

        <header class="kpts-chat-header">
            <span class="kpts-chat-title"><?php echo esc_html((string) Plugin::opt('chat_label', __('Need help?', 'kp-support'))); ?></span>
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
                // whatever files came in on it
                $_kpts_files = array();
                foreach (Chat::messageFiles((int) $_kpts_message->comment_ID) as $_kpts_file) {

                    // skip anything malformed
                    if (empty($_kpts_file['key']) || empty($_kpts_file['name'])) {
                        continue;
                    }

                    // describe it
                    $_kpts_files[] = array(
                        'name' => (string) $_kpts_file['name'],
                        'url'  => Attachments::chatUrl($kpts_chat_id, (string) $_kpts_file['key']),
                        'size' => size_format((int) ($_kpts_file['size'] ?? 0)),
                    );
                }

                Template::render('chat-message', array(
                    'message' => array(
                        'id'          => (int) $_kpts_message->comment_ID,
                        'content'     => wpautop(wp_kses_post($_kpts_message->comment_content)),
                        'author'      => $_kpts_message->comment_author,
                        'avatar'      => get_avatar_url((int) $_kpts_message->user_id, array('size' => 48)),
                        'isAgent'     => (int) $_kpts_message->user_id !== $kpts_visitor,
                        'isMine'      => (int) $_kpts_message->user_id === $kpts_visitor,
                        'date'        => mysql2date(get_option('time_format'), $_kpts_message->comment_date),
                        'attachments' => $_kpts_files,
                    ),
                ));
                ?>
            <?php endforeach; ?>
        </ul>

        <?php if (! $kpts_online && $kpts_chat_id < 1) : ?>
            <form class="kpts-chat-offline-form" novalidate>

                <p class="kpts-chat-offline-message"><?php echo esc_html($kpts_offline_message); ?></p>


                <?php if (! $kpts_locked) : ?>
                    <div class="kpts-chat-name-row">
                        <label class="screen-reader-text" for="kpts-chat-offline-first">
                            <?php esc_html_e('First name', 'kp-support'); ?>
                        </label>
                        <input type="text"
                            id="kpts-chat-offline-first"
                            class="kpts-chat-offline-first"
                            name="first_name"
                            autocomplete="given-name"
                            placeholder="<?php esc_attr_e('First name', 'kp-support'); ?>" />

                        <label class="screen-reader-text" for="kpts-chat-offline-last">
                            <?php esc_html_e('Last name', 'kp-support'); ?>
                        </label>
                        <input type="text"
                            id="kpts-chat-offline-last"
                            class="kpts-chat-offline-last"
                            name="last_name"
                            autocomplete="family-name"
                            placeholder="<?php esc_attr_e('Last name', 'kp-support'); ?>" />
                    </div>

                    <label class="screen-reader-text" for="kpts-chat-offline-email">
                        <?php esc_html_e('Email address', 'kp-support'); ?>
                    </label>
                    <input type="email"
                        id="kpts-chat-offline-email"
                        class="kpts-chat-offline-email"
                        name="email"
                        autocomplete="email"
                        placeholder="<?php esc_attr_e('Email address', 'kp-support'); ?>" />
                <?php endif; ?>

                <label class="screen-reader-text" for="kpts-chat-offline-subject">
                    <?php esc_html_e('Subject', 'kp-support'); ?>
                </label>
                <input type="text"
                    id="kpts-chat-offline-subject"
                    class="kpts-chat-offline-subject"
                    name="subject"
                    placeholder="<?php esc_attr_e('Subject', 'kp-support'); ?>" />

                <label class="screen-reader-text" for="kpts-chat-offline-body">
                    <?php esc_html_e('Your message', 'kp-support'); ?>
                </label>
                <textarea id="kpts-chat-offline-body"
                    class="kpts-chat-offline-body"
                    name="message"
                    rows="4"
                    placeholder="<?php esc_attr_e('How can we help?', 'kp-support'); ?>"></textarea>

                <?php if ($kpts_allow_files) : ?>
                    <ul class="kpts-chat-file-list" hidden></ul>
                    <label class="kpts-chat-offline-attach">
                        <span><?php esc_html_e('Attach files', 'kp-support'); ?></span>
                        <input type="file" class="kpts-chat-files" name="kpts_files[]" multiple />
                    </label>
                <?php endif; ?>

                <button type="submit" class="kpts-chat-send">
                    <?php esc_html_e('Send', 'kp-support'); ?>
                </button>
            </form>
        <?php endif; ?>

        <p class="kpts-chat-notice" role="alert" hidden></p>

        <?php if ($kpts_online) : ?>
            <form class="kpts-chat-start-form" novalidate<?php echo ($kpts_chat_id > 0) ? ' hidden' : ''; ?>>

                <p class="kpts-chat-start-intro"><?php esc_html_e('Tell us who you are and we will get somebody with you.', 'kp-support'); ?></p>

                <div class="kpts-chat-name-row">
                    <label class="screen-reader-text" for="kpts-chat-start-first">
                        <?php esc_html_e('First name', 'kp-support'); ?>
                    </label>
                    <input type="text"
                        id="kpts-chat-start-first"
                        class="kpts-chat-start-first"
                        name="first_name"
                        autocomplete="given-name"
                        value="<?php echo esc_attr((string) ($kpts_prefill['first_name'] ?? '')); ?>"
                        placeholder="<?php esc_attr_e('First name', 'kp-support'); ?>"
                        <?php echo $kpts_locked ? 'readonly' : ''; ?> />

                    <label class="screen-reader-text" for="kpts-chat-start-last">
                        <?php esc_html_e('Last name', 'kp-support'); ?>
                    </label>
                    <input type="text"
                        id="kpts-chat-start-last"
                        class="kpts-chat-start-last"
                        name="last_name"
                        autocomplete="family-name"
                        value="<?php echo esc_attr((string) ($kpts_prefill['last_name'] ?? '')); ?>"
                        placeholder="<?php esc_attr_e('Last name', 'kp-support'); ?>"
                        <?php echo $kpts_locked ? 'readonly' : ''; ?> />
                </div>

                <label class="screen-reader-text" for="kpts-chat-start-email">
                    <?php esc_html_e('Email address', 'kp-support'); ?>
                </label>
                <input type="email"
                    id="kpts-chat-start-email"
                    class="kpts-chat-start-email"
                    name="email"
                    autocomplete="email"
                    value="<?php echo esc_attr((string) ($kpts_prefill['email'] ?? '')); ?>"
                    placeholder="<?php esc_attr_e('Email address', 'kp-support'); ?>"
                    <?php echo $kpts_locked ? 'readonly' : ''; ?> />

                <label class="screen-reader-text" for="kpts-chat-start-message">
                    <?php esc_html_e('Your message', 'kp-support'); ?>
                </label>
                <textarea id="kpts-chat-start-message"
                    class="kpts-chat-start-message"
                    name="message"
                    rows="3"
                    placeholder="<?php esc_attr_e('How can we help?', 'kp-support'); ?>"></textarea>

                <button type="submit" class="kpts-chat-start-send">
                    <?php esc_html_e('Start chat', 'kp-support'); ?>
                </button>
            </form>
        <?php endif; ?>

        <?php if ($kpts_online || $kpts_chat_id > 0) : ?>
            <form class="kpts-chat-form" novalidate<?php echo ($kpts_chat_id > 0) ? '' : ' hidden'; ?>>

                <?php if ($kpts_allow_files) : ?>
                    <ul class="kpts-chat-file-list" hidden></ul>
                <?php endif; ?>

                <div class="kpts-chat-form-row">

                    <label class="screen-reader-text" for="kpts-chat-input">
                        <?php esc_html_e('Your message', 'kp-support'); ?>
                    </label>
                    <textarea id="kpts-chat-input"
                        class="kpts-chat-input"
                        name="content"
                        rows="2"
                        placeholder="<?php esc_attr_e('Type your message...', 'kp-support'); ?>"></textarea>

                    <?php if ($kpts_allow_files) : ?>
                        <label class="kpts-chat-attach" title="<?php esc_attr_e('Attach files', 'kp-support'); ?>">
                            <span class="screen-reader-text"><?php esc_html_e('Attach files', 'kp-support'); ?></span>
                            <span class="kpts-chat-attach-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="18" height="18" focusable="false">
                                    <path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M21 11.5l-8.8 8.8a5 5 0 01-7.1-7.1l9-9a3.5 3.5 0 015 5l-9 9a2 2 0 01-2.8-2.8l8.3-8.3" />
                                </svg>
                            </span>
                            <input type="file" class="kpts-chat-files" name="kpts_files[]" multiple />
                        </label>
                    <?php endif; ?>

                    <button type="submit" class="kpts-chat-send">
                        <?php esc_html_e('Send', 'kp-support'); ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</div>