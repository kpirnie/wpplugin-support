<?php

/**
 * Template - Profile management
 *
 * Override this by copying it to your-theme/kp-support/profile.php
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 *
 * @var \WP_User $user The user whose profile we're showing.
 */

declare(strict_types=1);

use KP\Support\Modules\Portal;
use KP\Support\Modules\Accounts;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// we need a real user
if (! isset($user) || ! $user instanceof \WP_User || $user->ID < 1) {
    return;
}
?>
<div class="kpts-portal kpts-portal-profile">

    <?php echo Accounts::renderMessages(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in renderMessages() ?>

    <p class="kpts-back">
        <a href="<?php echo esc_url(Portal::url()); ?>">&larr; <?php esc_html_e('Back to all tickets', 'kp-support'); ?></a>
    </p>

    <h2><?php esc_html_e('My Profile', 'kp-support'); ?></h2>

    <form method="post" class="kpts-profile-form">

        <?php wp_nonce_field('kpts_profile', 'kpts_profile_nonce'); ?>
        <input type="hidden" name="kpts_action" value="profile" />

        <div class="kpts-field-row">
            <p class="kpts-field">
                <label for="kpts-profile-first"><?php esc_html_e('First Name', 'kp-support'); ?></label>
                <input type="text"
                       id="kpts-profile-first"
                       name="kpts_first_name"
                       value="<?php echo esc_attr($user->first_name); ?>"
                       autocomplete="given-name" />
            </p>

            <p class="kpts-field">
                <label for="kpts-profile-last"><?php esc_html_e('Last Name', 'kp-support'); ?></label>
                <input type="text"
                       id="kpts-profile-last"
                       name="kpts_last_name"
                       value="<?php echo esc_attr($user->last_name); ?>"
                       autocomplete="family-name" />
            </p>
        </div>

        <p class="kpts-field">
            <label for="kpts-profile-email"><?php esc_html_e('Email Address', 'kp-support'); ?></label>
            <input type="email"
                   id="kpts-profile-email"
                   name="kpts_email"
                   value="<?php echo esc_attr($user->user_email); ?>"
                   autocomplete="email"
                   required />
        </p>

        <h3><?php esc_html_e('Change Password', 'kp-support'); ?></h3>
        <p class="kpts-help"><?php esc_html_e('Leave these blank to keep your current password.', 'kp-support'); ?></p>

        <div class="kpts-field-row">
            <p class="kpts-field">
                <label for="kpts-profile-pass"><?php esc_html_e('New Password', 'kp-support'); ?></label>
                <input type="password" id="kpts-profile-pass" name="kpts_password" autocomplete="new-password" minlength="8" />
            </p>

            <p class="kpts-field">
                <label for="kpts-profile-pass2"><?php esc_html_e('Confirm New Password', 'kp-support'); ?></label>
                <input type="password" id="kpts-profile-pass2" name="kpts_password_confirm" autocomplete="new-password" minlength="8" />
            </p>
        </div>

        <p class="kpts-form-actions">
            <button type="submit" class="kpts-button"><?php esc_html_e('Save Changes', 'kp-support'); ?></button>
            <a class="kpts-link" href="<?php echo esc_url(wp_logout_url(Portal::url())); ?>">
                <?php esc_html_e('Log Out', 'kp-support'); ?>
            </a>
        </p>
    </form>
</div>
