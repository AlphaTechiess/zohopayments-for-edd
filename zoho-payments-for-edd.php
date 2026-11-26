<?php
/**
 * Plugin Name: Zoho Payments for EDD
 * Plugin URI: https://www.zoho.com/payments/
 * Description: Accept secure payments via Zoho Payments on Easy Digital Downloads 3.x with India & US editions, on-demand OAuth token caching, embedded checkout, and HMAC-verified webhooks.
 * Version: 1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: easy-digital-downloads
 * Author: Zoho Payments
 * Author URI: https://www.zoho.com/payments/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zoho-payments-for-edd
 */

defined('ABSPATH') || exit;

define('ZOHO_EDD_VERSION', '1.0');
define('ZOHO_EDD_PLUGIN_FILE', __FILE__);
define('ZOHO_EDD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZOHO_EDD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ZOHO_EDD_GATEWAY_ID', 'zoho_payments');

function zoho_payments_edd_activate(): void {
    if (!class_exists('Easy_Digital_Downloads') || !defined('EDD_VERSION') || version_compare(EDD_VERSION, '3.0', '<')) {
        deactivate_plugins(plugin_basename(ZOHO_EDD_PLUGIN_FILE));
        wp_die(
            esc_html__('Zoho Payments for EDD requires Easy Digital Downloads 3.0 or higher to be installed and active.', 'zoho-payments-for-edd'),
            esc_html__('Plugin Dependency Error', 'zoho-payments-for-edd'),
            ['back_link' => true]
        );
    }
}
register_activation_hook(__FILE__, 'zoho_payments_edd_activate');

register_deactivation_hook(__FILE__, static function () {
    delete_option('zoho_edd_tlock');
});

final class Zoho_Payments_EDD {
    private static ?self $instance = null;
    public Zoho_EDD_Settings $settings;
    public Zoho_EDD_OAuth $oauth;
    public Zoho_EDD_API $api;
    public Zoho_EDD_Gateway $gateway;
    public Zoho_EDD_Webhook $webhook;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'init'], 20);
        add_filter('plugin_action_links_' . plugin_basename(ZOHO_EDD_PLUGIN_FILE), [$this, 'action_links']);
    }

    public function init(): void {
        if (!class_exists('Easy_Digital_Downloads') || !defined('EDD_VERSION') || version_compare(EDD_VERSION, '3.0', '<')) {
            add_action('admin_notices', [$this, 'requirement_notice']);
            return;
        }
        foreach (['crypto', 'settings', 'oauth', 'api', 'gateway', 'webhook'] as $mod) {
            require_once ZOHO_EDD_PLUGIN_DIR . 'includes/class-zoho-edd-' . $mod . '.php';
        }
        $this->settings = new Zoho_EDD_Settings();
        $this->oauth    = new Zoho_EDD_OAuth($this->settings);
        $this->api      = new Zoho_EDD_API($this->settings, $this->oauth);
        $this->gateway  = new Zoho_EDD_Gateway($this->settings, $this->api);
        $this->webhook  = new Zoho_EDD_Webhook($this->settings);
    }

    public function requirement_notice(): void {
        if (current_user_can('activate_plugins')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Zoho Payments for EDD requires Easy Digital Downloads 3.0 or higher.', 'zoho-payments-for-edd') . '</p></div>';
        }
    }

    public function action_links(array $links): array {
        if (class_exists('Easy_Digital_Downloads')) {
            $url = admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways&section=' . ZOHO_EDD_GATEWAY_ID);
            array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'zoho-payments-for-edd') . '</a>');
        }
        return $links;
    }
}

function zoho_payments_edd(): Zoho_Payments_EDD {
    return Zoho_Payments_EDD::instance();
}
zoho_payments_edd();
