<?php

/**
 * Template - The new ticket form
 *
 * Override this by copying it to your-theme/kp-support/new-ticket.php
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 *
 * @var array<int, \WP_Term> $kpts_departments The available departments.
 * @var array<int, \WP_Term> $kpts_categories  The available categories.
 * @var array<int, \WP_Term> $kpts_priorities  The available priorities.
 */

declare(strict_types=1);

use KP\Support\Plugin;
use KP\Support\Modules\Portal;
use KP\Support\Modules\Accounts;
use KP\Support\Modules\Attachments;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// tidy up what we were handed
$kpts_departments = isset($kpts_departments) && is_array($kpts_departments) ? $kpts_departments : array();
$kpts_categories = isset($kpts_categories) && is_array($kpts_categories) ? $kpts_categories : array();
$kpts_priorities = isset($kpts_priorities) && is_array($kpts_priorities) ? $kpts_priorities : array();

// what's required, and whether files are switched on
$kpts_require_department = (bool) Plugin::opt('require_department', true);
$kpts_require_category = (bool) Plugin::opt('require_category', false);
$kpts_allow_files = (bool) Plugin::opt('allow_attachments', true);
?>
<div class="kpts-portal kpts-portal-new">

    <?php echo Accounts::renderMessages(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in renderMessages() ?>

    <p class="kpts-back">
        <a href="<?php echo esc_url(Portal::url()); ?>">&larr; <?php esc_html_e('Back to all tickets', 'kp-support'); ?></a>
    </p>

    <h2><?php esc_html_e('Open A Ticket', 'kp-support'); ?></h2>

    <form class="kpts-new-ticket-form" method="post" enctype="multipart/form-data">

        <p class="kpts-field">
            <label for="kpts-subject">
                <?php esc_html_e('Subject', 'kp-support'); ?> <span class="kpts-required">*</span>
            </label>
            <input type="text" id="kpts-subject" name="subject" required maxlength="200" />
        </p>

        <div class="kpts-field-row">

            <?php if (! empty($kpts_departments)) : ?>
                <p class="kpts-field">
                    <label for="kpts-department">
                        <?php esc_html_e('Department', 'kp-support'); ?>
                        <?php if ($kpts_require_department) : ?><span class="kpts-required">*</span><?php endif; ?>
                    </label>
                    <select id="kpts-department" name="department" <?php echo $kpts_require_department ? 'required' : ''; ?>>
                        <option value=""><?php esc_html_e('Choose a department...', 'kp-support'); ?></option>
                        <?php foreach ($kpts_departments as $_kpts_term) : ?>
                            <option value="<?php echo esc_attr((string) $_kpts_term->term_id); ?>">
                                <?php echo esc_html($_kpts_term->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
            <?php endif; ?>

            <?php if (! empty($kpts_categories)) : ?>
                <p class="kpts-field">
                    <label for="kpts-category">
                        <?php esc_html_e('Category', 'kp-support'); ?>
                        <?php if ($kpts_require_category) : ?><span class="kpts-required">*</span><?php endif; ?>
                    </label>
                    <select id="kpts-category" name="category" <?php echo $kpts_require_category ? 'required' : ''; ?>>
                        <option value=""><?php esc_html_e('Choose a category...', 'kp-support'); ?></option>
                        <?php foreach ($kpts_categories as $_kpts_term) : ?>
                            <option value="<?php echo esc_attr((string) $_kpts_term->term_id); ?>">
                                <?php echo esc_html($_kpts_term->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
            <?php endif; ?>

            <?php if (! empty($kpts_priorities)) : ?>
                <p class="kpts-field">
                    <label for="kpts-priority"><?php esc_html_e('Priority', 'kp-support'); ?></label>
                    <select id="kpts-priority" name="priority">
                        <?php foreach ($kpts_priorities as $_kpts_term) : ?>
                            <option value="<?php echo esc_attr((string) $_kpts_term->term_id); ?>"
                                <?php selected($_kpts_term->slug, 'normal'); ?>>
                                <?php echo esc_html($_kpts_term->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
            <?php endif; ?>
        </div>

        <p class="kpts-field">
            <label for="kpts-message">
                <?php esc_html_e('How can we help?', 'kp-support'); ?> <span class="kpts-required">*</span>
            </label>
            <textarea id="kpts-message" name="message" rows="8" required
                      placeholder="<?php esc_attr_e('Tell us as much as you can about what is going on...', 'kp-support'); ?>"></textarea>
        </p>

        <?php if ($kpts_allow_files) : ?>
            <p class="kpts-field">
                <label class="kpts-file-label">
                    <input type="file" class="kpts-file-input" name="kpts_files[]" multiple />
                    <span><?php esc_html_e('Attach files', 'kp-support'); ?></span>
                </label>
                <span class="kpts-file-help">
                    <?php
                    // let them know the limits up front
                    printf(
                        /* translators: %1$s: maximum file size, %2$d: maximum number of files */
                        esc_html__('Up to %2$d files, %1$s each.', 'kp-support'),
                        esc_html(size_format(Attachments::maxSize())),
                        (int) Plugin::opt('max_attachments', 5)
                    );
                    ?>
                </span>
            </p>
            <ul class="kpts-file-list"></ul>
        <?php endif; ?>

        <div class="kpts-form-error" role="alert" hidden></div>

        <p class="kpts-form-actions">
            <button type="submit" class="kpts-button kpts-submit-ticket">
                <?php esc_html_e('Open Ticket', 'kp-support'); ?>
            </button>
        </p>
    </form>
</div>
