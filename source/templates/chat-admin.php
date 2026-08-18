<?php

/**
 * Template - The agent chat screen
 *
 * Queue on the left, whichever chat they picked on the right. The conversation
 * side reuses the same panel markup the front end does, so one script drives
 * both.
 *
 * Override this by copying it to your-theme/kp-support/chat-admin.php
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 *
 * @var array<int, string> $kpts_agents      The agents a chat can be handed to.
 * @var bool               $kpts_can_assign  Whether they can reassign.
 * @var bool               $kpts_can_convert Whether they can convert.
 * @var bool               $kpts_allow_files The attachment setting.
 */

declare(strict_types=1);

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// tidy up what we were handed
$kpts_agents = isset($kpts_agents) && is_array($kpts_agents) ? $kpts_agents : array();
$kpts_can_assign = ! empty($kpts_can_assign);
$kpts_can_convert = ! empty($kpts_can_convert);
$kpts_allow_files = ! empty($kpts_allow_files);
?>
<div class="wrap">

    <h1><?php esc_html_e('Live Chat', 'kp-support'); ?></h1>

    <div class="kpts-chat-admin">

        <div class="kpts-chat-queue">

            <div class="kpts-chat-queue-tabs">
                <button type="button" class="kpts-chat-queue-tab is-active" data-filter="mine">
                    <?php esc_html_e('Mine', 'kp-support'); ?>
                </button>
                <button type="button" class="kpts-chat-queue-tab" data-filter="unassigned">
                    <?php esc_html_e('Waiting', 'kp-support'); ?>
                </button>
                <button type="button" class="kpts-chat-queue-tab" data-filter="all">
                    <?php esc_html_e('All', 'kp-support'); ?>
                </button>
            </div>

            <ul class="kpts-chat-queue-list" aria-live="polite">
                <li class="kpts-chat-queue-empty"><?php esc_html_e('Loading...', 'kp-support'); ?></li>
            </ul>
        </div>

        <div class="kpts-chat-workspace kpts-chat" data-chat-id="0" data-latest="" data-state="">

            <div class="kpts-chat-toolbar">

                <span class="kpts-chat-visitor-name"></span>

                <?php if ($kpts_can_assign) : ?>
                    <label class="screen-reader-text" for="kpts-chat-assignee">
                        <?php esc_html_e('Assign this chat', 'kp-support'); ?>
                    </label>
                    <select id="kpts-chat-assignee" class="kpts-chat-assignee" disabled>
                        <option value="0"><?php esc_html_e('Unassigned', 'kp-support'); ?></option>
                        <?php foreach ($kpts_agents as $_kpts_id => $_kpts_name) : ?>
                            <option value="<?php echo esc_attr((string) $_kpts_id); ?>">
                                <?php echo esc_html($_kpts_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <?php if ($kpts_can_convert) : ?>
                    <button type="button" class="button kpts-chat-convert" disabled>
                        <?php esc_html_e('Convert To Ticket', 'kp-support'); ?>
                    </button>
                <?php endif; ?>

                <button type="button" class="button kpts-chat-end" disabled>
                    <?php esc_html_e('Close Chat', 'kp-support'); ?>
                </button>

                <a class="button button-secondary kpts-chat-ticket-link" href="#" hidden>
                    <?php esc_html_e('View Ticket', 'kp-support'); ?>
                </a>
            </div>

            <section class="kpts-chat-panel" aria-label="<?php esc_attr_e('Chat conversation', 'kp-support'); ?>">

                <header class="kpts-chat-header">
                    <span class="kpts-chat-title"><?php esc_html_e('Conversation', 'kp-support'); ?></span>
                    <span class="kpts-chat-status" role="status"></span>
                </header>

                <ul class="kpts-chat-messages" aria-live="polite">
                    <li class="kpts-chat-queue-empty"><?php esc_html_e('Pick a chat from the queue to get started.', 'kp-support'); ?></li>
                </ul>

                <p class="kpts-chat-notice" role="alert" hidden></p>

                <form class="kpts-chat-form" novalidate hidden>

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
                            placeholder="<?php esc_attr_e('Type your reply...', 'kp-support'); ?>"></textarea>

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
            </section>
        </div>
    </div>
</div>