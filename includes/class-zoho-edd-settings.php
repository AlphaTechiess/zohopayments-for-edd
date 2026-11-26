<?php
defined('ABSPATH') || exit;

final class Zoho_EDD_Settings {
    public function __construct() {
        add_filter('edd_settings_sections_gateways', [$this, 'register_section']);
        add_filter('edd_settings_gateways', [$this, 'register_settings']);
        add_filter('edd_settings_gateways_sanitize', [$this, 'sanitize_settings']);
        add_action('edd_zoho_payments_edition', [$this, 'render_edition_toggle']);
        add_action('edd_zoho_payments_sandbox_mode', [$this, 'render_sandbox_toggle']);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function register_section(array $sections): array {
        $sections[ZOHO_EDD_GATEWAY_ID] = __('Zoho Payments', 'zoho-payments-for-edd');
        return $sections;
    }

    public function sanitize_settings($input) {
        if (is_array($input)) {
            if (isset($input['zoho_payments_edition'])) {
                $input['zoho_payments_edition'] = ($input['zoho_payments_edition'] === 'us') ? 'us' : 'in';
            }
            if (array_key_exists('zoho_payments_sandbox_mode', $input)) {
                $input['zoho_payments_sandbox_mode'] = !empty($input['zoho_payments_sandbox_mode']) ? '1' : '';
            }
            delete_option('zoho_edd_tlock');
        }
        return $input;
    }

    public function register_settings(array $settings): array {
        $settings[ZOHO_EDD_GATEWAY_ID] = [
            ['id' => 'zoho_payments_general_header', 'name' => '<strong>' . esc_html__('Zoho Payments Configuration', 'zoho-payments-for-edd') . '</strong>', 'desc' => esc_html__('Configure your Zoho Payments ORG OAuth credentials, API Key, and Webhook Signing Key.', 'zoho-payments-for-edd'), 'type' => 'header'],
            ['id' => 'zoho_payments_edition', 'name' => esc_html__('Edition', 'zoho-payments-for-edd'), 'type' => 'hook'],
            ['id' => 'zoho_payments_sandbox_mode', 'name' => esc_html__('Enable Sandbox', 'zoho-payments-for-edd'), 'type' => 'hook'],
            ['id' => 'zoho_payments_endpoints_info', 'name' => esc_html__('Plugin Endpoints', 'zoho-payments-for-edd'), 'desc' => $this->render_endpoints_html(Zoho_EDD_OAuth::get_redirect_uri(), Zoho_EDD_Webhook::get_webhook_url()), 'type' => 'descriptive_text'],
            ['id' => 'zoho_payments_oauth_status', 'name' => esc_html__('OAuth Connection', 'zoho-payments-for-edd'), 'desc' => $this->render_oauth_control_html(), 'type' => 'descriptive_text'],
            ['id' => 'zoho_payments_account_id', 'name' => esc_html__('Account ID', 'zoho-payments-for-edd'), 'desc' => esc_html__('Found in Zoho Payments > Settings > Account Details.', 'zoho-payments-for-edd'), 'type' => 'text', 'size' => 'regular'],
            ['id' => 'zoho_payments_client_id', 'name' => esc_html__('Client ID', 'zoho-payments-for-edd'), 'desc' => esc_html__('From Zoho API Console (ORG Client) > Client Secret tab.', 'zoho-payments-for-edd'), 'type' => 'text', 'size' => 'regular'],
            ['id' => 'zoho_payments_client_secret', 'name' => esc_html__('Client Secret', 'zoho-payments-for-edd'), 'desc' => esc_html__('From Zoho API Console (ORG Client) > Client Secret tab.', 'zoho-payments-for-edd'), 'type' => 'password', 'size' => 'regular'],
            ['id' => 'zoho_payments_api_key', 'name' => esc_html__('API Key', 'zoho-payments-for-edd'), 'desc' => esc_html__('Publishable Checkout Widget API Key from Zoho Payments > Developer Space.', 'zoho-payments-for-edd'), 'type' => 'text', 'size' => 'regular'],
            ['id' => 'zoho_payments_webhook_secret', 'name' => esc_html__('Webhook Signing Key', 'zoho-payments-for-edd'), 'desc' => esc_html__('HMAC-SHA256 signing key from Zoho Payments > Developer Space > Webhooks.', 'zoho-payments-for-edd'), 'type' => 'password', 'size' => 'regular'],
        ];
        return $settings;
    }

    public function render_edition_toggle(): void {
        $ed    = $this->get_edition();
        $is_us = ($ed === 'us');
        ?>
        <div class="zp-ed-cards" id="zp-edition-cards" role="radiogroup" aria-label="<?php esc_attr_e('Select Edition', 'zoho-payments-for-edd'); ?>">
            <input type="hidden" id="zp-edition-input" name="edd_settings[zoho_payments_edition]" value="<?php echo esc_attr($ed); ?>" />
            <button type="button" class="zp-ed-card<?php echo !$is_us ? ' is-active' : ''; ?>" data-edition="in" role="radio" aria-checked="<?php echo !$is_us ? 'true' : 'false'; ?>">
                <span class="zp-flag" aria-hidden="true">🇮🇳</span>
                <span><?php esc_html_e('India', 'zoho-payments-for-edd'); ?></span>
            </button>
            <button type="button" class="zp-ed-card<?php echo $is_us ? ' is-active' : ''; ?>" data-edition="us" role="radio" aria-checked="<?php echo $is_us ? 'true' : 'false'; ?>">
                <span class="zp-flag" aria-hidden="true">🇺🇸</span>
                <span><?php esc_html_e('United States', 'zoho-payments-for-edd'); ?></span>
            </button>
        </div>
        <p class="description"><?php esc_html_e('Choose India if your Zoho account is on zoho.in, or United States if it is on zoho.com.', 'zoho-payments-for-edd'); ?></p>
        <?php
    }

    public function render_sandbox_toggle(): void {
        $is_sandbox = ($this->get_active_environment() === 'sandbox');
        ?>
        <div class="zp-toggle-row">
            <label class="zp-switch" for="zp-sandbox-toggle" aria-label="<?php esc_attr_e('Toggle Sandbox Mode', 'zoho-payments-for-edd'); ?>">
                <input type="hidden" name="edd_settings[zoho_payments_sandbox_mode]" value="" />
                <input type="checkbox" id="zp-sandbox-toggle" name="edd_settings[zoho_payments_sandbox_mode]" value="1" <?php checked($is_sandbox); ?> />
                <span class="zp-slider"></span>
            </label>
        </div>
        <?php
    }

    private function render_endpoints_html(string $redirect_uri, string $webhook_url): string {
        $org_url = 'https://api-console.zoho.com/add?client_type=ORG';
        ob_start();
        ?>
        <div class="zp-endpoints">
            <div class="zp-endpoint-group">
                <strong><?php esc_html_e('Authorized Redirect URI:', 'zoho-payments-for-edd'); ?></strong>
                <a href="<?php echo esc_url($org_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Create Zoho ORG Client', 'zoho-payments-for-edd'); ?> &rarr;</a>
                <div class="zp-code-row">
                    <code id="zoho-edd-redirect-uri"><?php echo esc_html($redirect_uri); ?></code>
                    <button type="button" class="button button-secondary zoho-edd-copy-btn" data-copy-target="zoho-edd-redirect-uri"><?php esc_html_e('Copy', 'zoho-payments-for-edd'); ?></button>
                </div>
            </div>
            <div>
                <strong><?php esc_html_e('Webhook URL (payment.succeeded, payment.failed, refund.succeeded, refund.failed):', 'zoho-payments-for-edd'); ?></strong>
                <div class="zp-code-row">
                    <code id="zoho-edd-webhook-url"><?php echo esc_html($webhook_url); ?></code>
                    <button type="button" class="button button-secondary zoho-edd-copy-btn" data-copy-target="zoho-edd-webhook-url"><?php esc_html_e('Copy', 'zoho-payments-for-edd'); ?></button>
                </div>
            </div>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    private function render_oauth_control_html(): string {
        $edition  = $this->get_edition();
        $env      = $this->get_active_environment();
        $oauth    = zoho_payments_edd()->oauth;
        $creds    = $this->get_credentials();
        $is_ready = ($creds['account_id'] !== '' && $creds['client_id'] !== '' && $creds['client_secret'] !== '');
        $tokens   = $oauth ? $oauth->get_stored_tokens() : [];
        $is_conn  = !empty($tokens['refresh_token']) && $tokens['env'] === $env && $tokens['edition'] === $edition;
        $label    = sprintf('%s · %s', ($edition === 'us' ? 'US' : 'IN'), ($env === 'sandbox' ? __('Sandbox', 'zoho-payments-for-edd') : __('Production', 'zoho-payments-for-edd')));
        $mismatch = !$is_conn && !empty($tokens['refresh_token']) && ($tokens['env'] !== $env || $tokens['edition'] !== $edition);

        $connect_url    = wp_nonce_url(add_query_arg(['action' => 'zoho_payments_edd_oauth_start', 'env' => $env, 'edition' => $edition], admin_url('admin-post.php')), 'zoho_edd_oauth_start_' . $edition . '_' . $env);
        $disconnect_url = wp_nonce_url(add_query_arg(['action' => 'zoho_payments_edd_oauth_disconnect'], admin_url('admin-post.php')), 'zoho_edd_oauth_disconnect');

        ob_start();
        if ($is_conn) {
            /* translators: %s: edition and environment label */
            echo '<p class="zp-oauth-status"><span class="zp-badge zp-badge--ok">' . esc_html(sprintf(__('Connected (%s)', 'zoho-payments-for-edd'), $label)) . '</span></p>';
            /* translators: %s: edition and environment label */
            echo '<p class="zp-oauth-actions"><a href="' . esc_url($connect_url) . '" class="button button-secondary">' . esc_html(sprintf(__('Reconnect (%s)', 'zoho-payments-for-edd'), $label)) . '</a> ';
            echo '<a href="' . esc_url($disconnect_url) . '" class="button button-link-delete">' . esc_html__('Disconnect & Revoke Token', 'zoho-payments-for-edd') . '</a></p>';
        } else {
            /* translators: %s: edition and environment label */
            echo '<p class="zp-oauth-status"><span class="zp-badge zp-badge--off">' . esc_html(sprintf(__('Not Connected (%s)', 'zoho-payments-for-edd'), $label)) . '</span>';
            if ($mismatch) {
                /* translators: %s: edition and environment label */
                echo ' <span class="zp-mismatch">' . esc_html(sprintf(__('Edition or environment changed — save your %s credentials and reconnect OAuth.', 'zoho-payments-for-edd'), $label)) . '</span>';
            }
            echo '</p><p class="zp-oauth-actions">';
            if ($is_ready) {
                /* translators: %s: edition and environment label */
                echo '<a href="' . esc_url($connect_url) . '" class="button button-primary">' . esc_html(sprintf(__('Connect Zoho Payments (%s)', 'zoho-payments-for-edd'), $label)) . '</a>';
            } else {
                echo '<em>' . esc_html__('Save your Account ID, Client ID, and Client Secret below first, then click Connect.', 'zoho-payments-for-edd') . '</em>';
            }
            echo '</p>';
        }
        return (string)ob_get_clean();
    }

    public function get_edition(): string { return (edd_get_option('zoho_payments_edition', 'in') === 'us') ? 'us' : 'in'; }
    public function get_tld(?string $edition = null): string { return (($edition ?? $this->get_edition()) === 'us') ? 'com' : 'in'; }
    public function get_widget_domain(): string { return ($this->get_edition() === 'us') ? 'US' : 'IN'; }
    public function get_currency(): string { return ($this->get_edition() === 'us') ? 'USD' : 'INR'; }
    public function get_currency_symbol(): string { return ($this->get_edition() === 'us') ? '$' : '₹'; }
    public function get_active_environment(): string { return !empty(edd_get_option('zoho_payments_sandbox_mode', false)) ? 'sandbox' : 'live'; }

    public function get_credentials(): array {
        return [
            'account_id'     => trim((string)edd_get_option('zoho_payments_account_id', '')),
            'client_id'      => trim((string)edd_get_option('zoho_payments_client_id', '')),
            'client_secret'  => trim((string)edd_get_option('zoho_payments_client_secret', '')),
            'api_key'        => trim((string)edd_get_option('zoho_payments_api_key', '')),
            'webhook_secret' => trim((string)edd_get_option('zoho_payments_webhook_secret', '')),
        ];
    }

    public function is_configured(?string $env = null, ?string $edition = null): bool {
        $env     = $env ?? $this->get_active_environment();
        $edition = $edition ?? $this->get_edition();
        $creds   = $this->get_credentials();
        $oauth   = zoho_payments_edd()->oauth;
        return $creds['account_id'] !== '' && $creds['api_key'] !== '' && $creds['webhook_secret'] !== '' && $oauth && $oauth->is_connected($env, $edition);
    }

    public function admin_notices(): void {
        if (!current_user_can('manage_shop_settings')) return;

        if (function_exists('edd_is_gateway_active') && edd_is_gateway_active(ZOHO_EDD_GATEWAY_ID)) {
            $expected = $this->get_currency();
            $current  = function_exists('edd_get_currency') ? strtoupper((string)edd_get_currency()) : '';
            if ($expected !== $current) {
                /* translators: %s: expected currency code */
                echo '<div class="notice notice-warning"><p>' . esc_html(sprintf(__('Zoho Payments for EDD requires your EDD store currency to be set to %s for the selected edition.', 'zoho-payments-for-edd'), $expected)) . '</p></div>';
            }
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Admin redirect notice parameters.
        if (empty($_GET['zoho_edd_notice'])) return;
        $notice = sanitize_key(wp_unslash($_GET['zoho_edd_notice']));
        $env    = (isset($_GET['zoho_edd_env']) && sanitize_key(wp_unslash($_GET['zoho_edd_env'])) === 'sandbox') ? __('Sandbox', 'zoho-payments-for-edd') : __('Production', 'zoho-payments-for-edd');
        $detail = isset($_GET['zoho_edd_detail']) ? sanitize_text_field(wp_unslash($_GET['zoho_edd_detail'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $map = [
            /* translators: %s: environment label */
            'connected'             => ['success', sprintf(__('Zoho Payments (%s) connected successfully via OAuth 2.0.', 'zoho-payments-for-edd'), $env)],
            'disconnected'          => ['info', __('Zoho Payments disconnected and refresh token revoked.', 'zoho-payments-for-edd')],
            'missing_credentials'   => ['error', __('Please save your Account ID, Client ID, and Client Secret before connecting.', 'zoho-payments-for-edd')],
            'invalid_state'         => ['error', __('OAuth state validation failed or expired. Please try connecting again.', 'zoho-payments-for-edd')],
            /* translators: %s: environment label */
            'auth_denied'           => ['error', sprintf(__('Zoho Payments (%s) authorization was cancelled or denied.', 'zoho-payments-for-edd'), $env)],
            'missing_refresh_token' => ['error', __('Zoho did not return a refresh token. Ensure access_type=offline and revoke old tokens if needed.', 'zoho-payments-for-edd')],
            /* translators: %s: environment label */
            'token_exchange_failed' => ['error', sprintf(__('Failed to exchange authorization code for %s tokens.', 'zoho-payments-for-edd'), $env) . ($detail ? ' ' . $detail : '')],
        ];
        if (isset($map[$notice])) {
            printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($map[$notice][0]), esc_html($map[$notice][1]));
        }
    }

    public function enqueue_admin_assets(string $hook_suffix): void {
        if ($hook_suffix !== 'download_page_edd-settings') return;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading settings section tab slug.
        $section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';
        if ($section !== ZOHO_EDD_GATEWAY_ID) return;

        wp_enqueue_style('zoho-payments-edd-admin', ZOHO_EDD_PLUGIN_URL . 'assets/css/admin.min.css', [], ZOHO_EDD_VERSION);
        wp_enqueue_script('zoho-payments-edd-admin', ZOHO_EDD_PLUGIN_URL . 'assets/js/admin.min.js', [], ZOHO_EDD_VERSION, true);
    }
}
