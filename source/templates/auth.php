<?php

/**
 * Template - Login and registration
 *
 * Override this by copying it to your-theme/kp-support/auth.php
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 *
 * @var bool   $allow_registration Whether registration is open.
 * @var string $default_tab        Which tab to show first.
 * @var string $redirect           Where to send them afterwards.
 */

declare(strict_types=1);

use KP\Support\Modules\Accounts;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// tidy up what we were handed
$allow_registration = ! empty($allow_registration);
$default_tab = (isset($default_tab) && $default_tab === 'register') ? 'register' : 'login';

// if registration is closed we can only ever show the login
if (! $allow_registration) {
    $default_tab = 'login';
}
?>
<div class="kpts-portal kpts-portal-auth">

    <?php echo Accounts::renderMessages(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in renderMessages() ?>

    <?php if ($allow_registration) : ?>
        <div class="kpts-tabs" role="tablist">
            <button type="button"
                    class="kpts-tab <?php echo ($default_tab === 'login') ? 'is-active' : ''; ?>"
                    data-tab="login"
                    role="tab">
                <?php esc_html_e('Log In', 'kp-support'); ?>
            </button>
            <button type="button"
                    class="kpts-tab <?php echo ($default_tab === 'register') ? 'is-active' : ''; ?>"
                    data-tab="register"
                    role="tab">
                <?php esc_html_e('Create An Account', 'kp-support'); ?>
            </button>
        </div>
    <?php endif; ?>

    <div class="kpts-tab-panel kpts-panel-login <?php echo ($default_tab === 'login') ? 'is-active' : ''; ?>" data-panel="login">

        <h2><?php esc_html_e('Log In', 'kp-support'); ?></h2>

        <form method="post" class="kpts-auth-form">

            <?php wp_nonce_field('kpts_login', 'kpts_login_nonce'); ?>
            <input type="hidden" name="kpts_action" value="login" />

            <p class="kpts-field">
                <label for="kpts-login-user"><?php esc_html_e('Email or Username', 'kp-support'); ?></label>
                <input type="text" id="kpts-login-user" name="kpts_login" autocomplete="username" required />
            </p>

            <p class="kpts-field">
                <label for="kpts-login-pass"><?php esc_html_e('Password', 'kp-support'); ?></label>
                <input type="password" id="kpts-login-pass" name="kpts_password" autocomplete="current-password" required />
            </p>

            <p class="kpts-field kpts-field-inline">
                <label for="kpts-remember">
                    <input type="checkbox" id="kpts-remember" name="kpts_remember" value="1" />
                    <?php esc_html_e('Keep me logged in', 'kp-support'); ?>
                </label>
            </p>

            <p class="kpts-form-actions">
                <button type="submit" class="kpts-button"><?php esc_html_e('Log In', 'kp-support'); ?></button>
                <a class="kpts-link" href="<?php echo esc_url(wp_lostpassword_url()); ?>">
                    <?php esc_html_e('Lost your password?', 'kp-support'); ?>
                </a>
            </p>
        </form>
    </div>

    <?php if ($allow_registration) : ?>
        <div class="kpts-tab-panel kpts-panel-register <?php echo ($default_tab === 'register') ? 'is-active' : ''; ?>" data-panel="register">

            <h2><?php esc_html_e('Create An Account', 'kp-support'); ?></h2>

            <form method="post" class="kpts-auth-form">

                <?php wp_nonce_field('kpts_register', 'kpts_register_nonce'); ?>
                <input type="hidden" name="kpts_action" value="register" />

                <?php
                // a honeypot, real people never fill this in because they never see it
                ?>
                <p class="kpts-hp" aria-hidden="true">
                    <label for="kpts-website"><?php esc_html_e('Leave this empty', 'kp-support'); ?></label>
                    <input type="text" id="kpts-website" name="kpts_website" tabindex="-1" autocomplete="off" />
                </p>

                <div class="kpts-field-row">
                    <p class="kpts-field">
                        <label for="kpts-first-name"><?php esc_html_e('First Name', 'kp-support'); ?></label>
                        <input type="text" id="kpts-first-name" name="kpts_first_name" autocomplete="given-name" />
                    </p>

                    <p class="kpts-field">
                        <label for="kpts-last-name"><?php esc_html_e('Last Name', 'kp-support'); ?></label>
                        <input type="text" id="kpts-last-name" name="kpts_last_name" autocomplete="family-name" />
                    </p>
                </div>

                <p class="kpts-field">
                    <label for="kpts-reg-email"><?php esc_html_e('Email Address', 'kp-support'); ?> <span class="kpts-required">*</span></label>
                    <input type="email" id="kpts-reg-email" name="kpts_email" autocomplete="email" required />
                </p>

                <p class="kpts-field">
                    <label for="kpts-reg-pass"><?php esc_html_e('Password', 'kp-support'); ?> <span class="kpts-required">*</span></label>
                    <input type="password" id="kpts-reg-pass" name="kpts_password" autocomplete="new-password" minlength="8" required />
                </p>

                <p class="kpts-field">
                    <label for="kpts-reg-pass2"><?php esc_html_e('Confirm Password', 'kp-support'); ?> <span class="kpts-required">*</span></label>
                    <input type="password" id="kpts-reg-pass2" name="kpts_password_confirm" autocomplete="new-password" minlength="8" required />
                </p>

                <p class="kpts-form-actions">
                    <button type="submit" class="kpts-button"><?php esc_html_e('Create Account', 'kp-support'); ?></button>
                </p>
            </form>
        </div>
    <?php endif; ?>
</div>
