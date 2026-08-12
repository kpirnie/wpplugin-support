<?php

/**
 * KP Support - Main plugin bootstrap
 *
 * Sets up our constants, registers the class autoloader, pulls in the field
 * framework, and fires up the plugin on plugins_loaded.
 *
 * Plugin Name:       KP Support
 * Plugin URI:        https://kevinpirnie.com/
 * Description:       A full-featured support ticket system with AJAX chat, threaded replies, departments, priorities, attachments, and a front-end customer portal.
 * Version:           1.0.13
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Kevin Pirnie
 * Author URI:        https://kevinpirnie.com/
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       kp-support
 * Domain Path:       /languages
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// setup our constants, only if they're not already there
defined('KP_SUPPORT_VERSION') || define('KP_SUPPORT_VERSION', '1.0.13');
defined('KP_SUPPORT_FILE') || define('KP_SUPPORT_FILE', __FILE__);
defined('KP_SUPPORT_DIR') || define('KP_SUPPORT_DIR', plugin_dir_path(__FILE__));
defined('KP_SUPPORT_URL') || define('KP_SUPPORT_URL', plugin_dir_url(__FILE__));
defined('KP_SUPPORT_BASENAME') || define('KP_SUPPORT_BASENAME', plugin_basename(__FILE__));

// register our own PSR-4 style autoloader, we don't want a hard composer dependency at runtime
spl_autoload_register(function (string $class): void {

    // the namespace prefix we care about
    $prefix = 'KP\\Support\\';
    $length = strlen($prefix);

    // if this class isn't ours, get out
    if (strncmp($prefix, $class, $length) !== 0) {
        return;
    }

    // build the path to the class file
    $relative = substr($class, $length);
    $path = KP_SUPPORT_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';

    // pull it in if we can actually read it
    if (is_readable($path)) {
        require_once $path;
    }
});

// pull in composer's autoloader if it's been installed, this is how we get the field framework
if (is_readable(KP_SUPPORT_DIR . 'vendor/autoload.php')) {
    require_once KP_SUPPORT_DIR . 'vendor/autoload.php';
}

// if composer wasn't used, try to find the framework's loader directly
if (! class_exists('\KP\WPFieldFramework\Loader')) {

    // the spots we're willing to look for it
    $possible = array(
        KP_SUPPORT_DIR . 'vendor/kevinpirnie/kpt-wpfieldframework/src/Loader.php',
        WP_PLUGIN_DIR . '/kpt-wpfieldframework/src/Loader.php',
    );

    // loop them, and pull in the first one we find
    foreach ($possible as $_path) {
        if (is_readable($_path)) {
            require_once $_path;
            break;
        }
    }
}

// hook up activation and deactivation
register_activation_hook(__FILE__, array('\KP\Support\Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('\KP\Support\Plugin', 'deactivate'));

// fire us up!
add_action('plugins_loaded', function (): void {
    \KP\Support\Plugin::instance()->run();
}, 10);
