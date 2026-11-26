<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

function zoho_edd_purge_site(): void {
    global $wpdb;
    delete_option('zoho_edd_oauth_tokens');
    delete_option('zoho_edd_tlock');
    $edd = get_option('edd_settings', []);
    if (is_array($edd)) {
        foreach (array_keys($edd) as $k) {
            if (strpos((string)$k, 'zoho_payments_') === 0) unset($edd[$k]);
        }
        if (isset($edd['gateways']['zoho_payments'])) unset($edd['gateways']['zoho_payments']);
        update_option('edd_settings', $edd);
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin uninstall cleanup.
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'zoho\_edd\_%' OR option_name LIKE '\_transient\_zoho\_edd\_%' OR option_name LIKE '\_transient\_timeout\_zoho\_edd\_%'");
    if (function_exists('wp_cache_flush')) wp_cache_flush();
}

zoho_edd_purge_site();

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids']) as $zoho_edd_blog_id) {
        switch_to_blog((int)$zoho_edd_blog_id);
        zoho_edd_purge_site();
        restore_current_blog();
    }
}
