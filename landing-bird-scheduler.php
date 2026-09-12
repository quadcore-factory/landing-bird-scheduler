<?php
/**
 * Plugin Name: Landing Bird Scheduler
 * Description: A lean, WooCommerce-backed booking calendar for one generic service.
 * Version: 0.1.3
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Landing Bird
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: landing-bird-scheduler
 */

defined('ABSPATH') || exit;
define('LB_SCHEDULER_VERSION', '0.1.3');
define('LB_SCHEDULER_FILE', __FILE__);
require_once __DIR__ . '/includes/class-landing-bird-scheduler.php';

register_activation_hook(__FILE__, ['Landing_Bird_Scheduler', 'activate']);
register_deactivation_hook(__FILE__, ['Landing_Bird_Scheduler', 'deactivate']);
add_action('plugins_loaded', static function () { Landing_Bird_Scheduler::boot(); });
