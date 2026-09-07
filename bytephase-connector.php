<?php

/**
 * Plugin Name:       BytePhase Connector
 * Plugin URI:        https://bytephase.com/integrations/wordpress/
 * Description:        Capture WordPress form enquiries into BytePhase repair shop management software as leads and repair check-ins. BytePhase does all the field mapping and business logic.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            BytePhase
 * Author URI:        https://bytephase.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bytephase-connector
 *
 * @package BytePhase\Connector
 */

use BytePhase\Connector\Plugin;

defined('ABSPATH') || exit;

define('BYTEPHASE_CONNECTOR_VERSION', '1.0.1');
define('BYTEPHASE_CONNECTOR_FILE', __FILE__);
define('BYTEPHASE_CONNECTOR_PATH', plugin_dir_path(__FILE__));
define('BYTEPHASE_CONNECTOR_URL', plugin_dir_url(__FILE__));

// Prefer Composer's autoloader in dev; fall back to a bundled PSR-4 loader so the
// plugin runs when shipped without a vendor/ directory.
if (is_readable(BYTEPHASE_CONNECTOR_PATH . 'vendor/autoload.php')) {
    require BYTEPHASE_CONNECTOR_PATH . 'vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'BytePhase\\Connector\\';

        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = BYTEPHASE_CONNECTOR_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';

        if (is_readable($path)) {
            require $path;
        }
    });
}

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});
