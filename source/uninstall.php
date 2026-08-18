<?php

/**
 * Uninstall - Clean everything up
 *
 * Runs when the plugin is deleted, not just deactivated. The plugin itself is
 * not loaded at this point, so we register our own autoloader for the couple of
 * classes we need and do the rest with plain WordPress functions.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

// this file only ever runs from WordPress's uninstall routine
if (! defined('WP_UNINSTALL_PLUGIN')) {
    die('No direct script access allowed');
}

// and only ever for us
if (WP_UNINSTALL_PLUGIN !== plugin_basename(__DIR__ . '/kp-support.php')) {
    die('No direct script access allowed');
}

// we need our constants, the plugin file itself isn't loaded here
defined('KP_SUPPORT_DIR') || define('KP_SUPPORT_DIR', plugin_dir_path(__FILE__));

// register a minimal autoloader for our own classes
spl_autoload_register(function (string $class): void {

    // the namespace prefix we care about
    $prefix = 'KP\\Support\\';
    $length = strlen($prefix);

    // if this class isn't ours, get out
    if (strncmp($prefix, $class, $length) !== 0) {
        return;
    }

    // build the path and pull it in if we can read it
    $path = KP_SUPPORT_DIR . 'src/' . str_replace('\\', '/', substr($class, $length)) . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

// if the site owner asked us to leave their data alone, stop right here
$kpts_options = get_option('kpts_settings', array());
if (is_array($kpts_options) && ! empty($kpts_options['keep_data_on_uninstall'])) {
    return;
}

/*
 * Delete every ticket, along with its replies and meta.
 *
 * We work in batches so a big install doesn't blow the memory limit, and we let
 * wp_delete_post handle the comments and meta rather than reaching into the
 * tables ourselves.
 */
do {

    // grab the next batch of tickets and chats
    $kpts_tickets = get_posts(array(
        'post_type'   => array('kpts_ticket', 'kpts_chat'),
        'post_status' => 'any',
        'numberposts' => 100,
        'fields'      => 'ids',
    ));

    // delete each one for good
    foreach ($kpts_tickets as $_kpts_ticket) {
        wp_delete_post((int) $_kpts_ticket, true);
    }
} while (! empty($kpts_tickets));

// now clear out the terms in each of our taxonomies
$kpts_taxonomies = array('kpts_department', 'kpts_category', 'kpts_priority', 'kpts_status');

// walk each one
foreach ($kpts_taxonomies as $_kpts_tax) {

    // pull every term in it
    $kpts_terms = get_terms(array(
        'taxonomy'   => $_kpts_tax,
        'hide_empty' => false,
        'fields'     => 'ids',
    ));

    // nothing there, or the taxonomy isn't registered right now
    if (is_wp_error($kpts_terms) || empty($kpts_terms)) {
        continue;
    }

    // and delete each term
    foreach ($kpts_terms as $_kpts_term) {
        wp_delete_term((int) $_kpts_term, $_kpts_tax);
    }
}

/*
 * Remove the attachment directory and everything in it.
 *
 * We resolve the real path and confirm it's still inside the uploads directory
 * before we delete anything, and we never run these paths through a text
 * sanitizer because that corrupts legitimate path characters.
 */
$kpts_uploads = wp_upload_dir();
$kpts_base = untrailingslashit($kpts_uploads['basedir']);
$kpts_dir = realpath($kpts_base . '/kpts-attachments');
$kpts_real_base = realpath($kpts_base);

// only if it resolved, and only if it's genuinely inside uploads
if ($kpts_dir !== false && $kpts_real_base !== false && str_starts_with($kpts_dir, $kpts_real_base . DIRECTORY_SEPARATOR)) {

    // we need the filesystem api to remove any of this
    if (! function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    // set it up
    WP_Filesystem();

    // and pull the global it populates
    global $wp_filesystem;

    // walk the tree from the bottom up so directories are empty when we hit them
    $kpts_iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($kpts_dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    // and remove each thing we find
    foreach ($kpts_iterator as $_kpts_thing) {

        // directories get removed, everything else gets unlinked
        if ($_kpts_thing->isDir()) {
            if ($wp_filesystem instanceof WP_Filesystem_Base) {
                $wp_filesystem->rmdir($_kpts_thing->getPathname());
            }
            continue;
        }

        // unlink the file
        wp_delete_file($_kpts_thing->getPathname());
    }

    // and finally the directory itself
    if ($wp_filesystem instanceof WP_Filesystem_Base) {
        $wp_filesystem->rmdir($kpts_dir);
    }
}

// strip our roles and capabilities back off
if (class_exists('\KP\Support\Modules\Roles')) {
    \KP\Support\Modules\Roles::removeRoles();
}

// drop our settings
delete_option('kpts_settings');
delete_option('kpts_caps_version');

// clear anything we had scheduled
wp_clear_scheduled_hook('kpts_daily_maintenance');

// and clean up any user meta we set
delete_metadata('user', 0, 'kpts_departments', '', true);
delete_metadata('user', 0, 'kpts_signature', '', true);
