<?php
defined('ABSPATH') || exit;

final class Zoho_EDD_OAuth {
    public const TOKEN_OPTION  = 'zoho_edd_oauth_tokens';
    public const REFRESH_LOCK  = 'zoho_edd_tlock';
    public const EXPIRY_BUFFER = 300;

    private Zoho_EDD_Settings $settings;
    private ?array $token_cache = null;

    public function __construct(Zoho_EDD_Settings $settings) {
        $this->settings = $settings;
        add_action('admin_post_zoho_payments_edd_oauth_start', [$this, 'handle_oauth_start']);
        add_action('admin_post_zoho_payments_edd_oauth_callback', [$this, 'handle_oauth_callback']);
        add_action('admin_post_zoho_payments_edd_oauth_disconnect', [$this, 'handle_oauth_disconnect']);
    }

    public static function get_redirect_uri(): string {
        return admin_url('admin-post.php?action=zoho_payments_edd_oauth_callback');
    }

    public static function get_scopes(string $env): string {
        $p = ($env === 'sandbox') ? 'ZohoPaySandbox' : 'ZohoPay';
        return "$p.payments.CREATE,$p.payments.READ,$p.payments.UPDATE,$p.refunds.CREATE,$p.refunds.READ";
    }

    private function get_accounts_base(string $edition): string {
        return 'https://accounts.zoho.' . $this->settings->get_tld($edition);
    }

    public function get_stored_tokens(bool $fresh = false): array {
        if (!$fresh && $this->token_cache !== null) return $this->token_cache;
        $raw = get_option(self::TOKEN_OPTION, []);
        if (!is_array($raw)) $raw = [];
        return $this->token_cache = [
            'edition'       => (($raw['edition'] ?? '') === 'us') ? 'us' : 'in',
            'env'           => (($raw['env'] ?? '') === 'sandbox') ? 'sandbox' : (!empty($raw['env']) ? 'live' : ''),
            'refresh_token' => !empty($raw['refresh_token']) ? Zoho_EDD_Crypto::decrypt((string)$raw['refresh_token']) : '',
            'access_token'  => !empty($raw['access_token']) ? Zoho_EDD_Crypto::decrypt((string)$raw['access_token']) : '',
            'expires_at'    => absint($raw['expires_at'] ?? 0),
        ];
    }

    private function save_tokens(array $tokens): void {
        update_option(self::TOKEN_OPTION, [
            'edition'       => (($tokens['edition'] ?? '') === 'us') ? 'us' : 'in',
            'env'           => (($tokens['env'] ?? '') === 'sandbox') ? 'sandbox' : 'live',
            'refresh_token' => !empty($tokens['refresh_token']) ? Zoho_EDD_Crypto::encrypt((string)$tokens['refresh_token']) : '',
            'access_token'  => !empty($tokens['access_token']) ? Zoho_EDD_Crypto::encrypt((string)$tokens['access_token']) : '',
            'expires_at'    => absint($tokens['expires_at'] ?? 0),
        ], false);
        $this->token_cache = null;
    }

    public function is_connected(string $env, ?string $edition = null): bool {
        $edition = $edition ?? $this->settings->get_edition();
        $tokens  = $this->get_stored_tokens();
        return $tokens['refresh_token'] !== '' && $tokens['env'] === $env && $tokens['edition'] === $edition;
    }

    public function invalidate_access_token(string $env, string $edition): void {
        $tokens                 = $this->get_stored_tokens();
        $tokens['edition']      = $edition;
        $tokens['env']          = $env;
        $tokens['access_token'] = '';
        $tokens['expires_at']   = 0;
        $this->save_tokens($tokens);
    }

    private function normalize_ttl($expires_in): int {
        $ttl = is_numeric($expires_in) ? (int)$expires_in : 3600;
        if ($ttl > 86400) $ttl = (int)floor($ttl / 1000);
        return max(300, min(3600, $ttl));
    }

    public function handle_oauth_start(): void {
        if (!current_user_can('manage_shop_settings')) {
            wp_die(esc_html__('Permission denied.', 'zoho-payments-for-edd'), 403);
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked immediately on next line.
        $edition = (isset($_GET['edition']) && sanitize_key(wp_unslash($_GET['edition'])) === 'us') ? 'us' : 'in';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked immediately on next line.
        $env = (isset($_GET['env']) && sanitize_key(wp_unslash($_GET['env'])) === 'sandbox') ? 'sandbox' : 'live';
        check_admin_referer('zoho_edd_oauth_start_' . $edition . '_' . $env);

        $creds = $this->settings->get_credentials();
        if ($creds['account_id'] === '' || $creds['client_id'] === '' || $creds['client_secret'] === '') {
            $this->redirect_with_notice('missing_credentials', $env);
        }

        $state = wp_generate_password(32, false, false);
        set_transient('zoho_edd_oauth_state_' . $state, ['edition' => $edition, 'env' => $env, 'user_id' => get_current_user_id()], 10 * MINUTE_IN_SECONDS);

        add_filter('allowed_redirect_hosts', static function (array $hosts): array {
            $hosts[] = 'accounts.zoho.in';
            $hosts[] = 'accounts.zoho.com';
            return $hosts;
        });

        $soid_prefix = ($env === 'sandbox') ? 'zohopaysandbox.' : 'zohopay.';
        $auth_url    = add_query_arg([
            'scope'         => self::get_scopes($env),
            'client_id'     => $creds['client_id'],
            'soid'          => $soid_prefix . $creds['account_id'],
            'state'         => $state,
            'response_type' => 'code',
            'redirect_uri'  => self::get_redirect_uri(),
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ], $this->get_accounts_base($edition) . '/oauth/v2/org/auth');

        wp_safe_redirect($auth_url);
        exit;
    }

    public function handle_oauth_callback(): void {
        if (!current_user_can('manage_shop_settings')) {
            wp_die(esc_html__('Permission denied.', 'zoho-payments-for-edd'), 403);
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth 2.0 callback verified via one-time state transient.
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $code  = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $state_data = $state !== '' ? get_transient('zoho_edd_oauth_state_' . $state) : false;
        if ($state !== '') delete_transient('zoho_edd_oauth_state_' . $state);

        if (!is_array($state_data) || empty($state_data['env']) || (int)($state_data['user_id'] ?? 0) !== get_current_user_id()) {
            $this->redirect_with_notice('invalid_state', 'live');
        }

        $edition = (($state_data['edition'] ?? '') === 'us') ? 'us' : 'in';
        $env     = ($state_data['env'] === 'sandbox') ? 'sandbox' : 'live';

        if ($error !== '' || $code === '') $this->redirect_with_notice('auth_denied', $env);

        $creds = $this->settings->get_credentials();
        if ($creds['client_id'] === '' || $creds['client_secret'] === '') {
            $this->redirect_with_notice('missing_credentials', $env);
        }

        $res = wp_remote_post($this->get_accounts_base($edition) . '/oauth/v2/token', [
            'timeout' => 20,
            'body'    => [
                'code'          => $code,
                'client_id'     => $creds['client_id'],
                'client_secret' => $creds['client_secret'],
                'redirect_uri'  => self::get_redirect_uri(),
                'grant_type'    => 'authorization_code',
            ],
        ]);
        if (is_wp_error($res)) $this->redirect_with_notice('token_exchange_failed', $env, $res->get_error_message());

        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($body) || empty($body['access_token'])) {
            $this->redirect_with_notice('token_exchange_failed', $env, (string)($body['error'] ?? 'Unknown OAuth error'));
        }

        $existing      = $this->get_stored_tokens();
        $refresh_token = !empty($body['refresh_token']) ? (string)$body['refresh_token'] : (($existing['env'] === $env && $existing['edition'] === $edition) ? $existing['refresh_token'] : '');
        if ($refresh_token === '') $this->redirect_with_notice('missing_refresh_token', $env);

        $this->save_tokens([
            'edition'       => $edition,
            'env'           => $env,
            'refresh_token' => $refresh_token,
            'access_token'  => (string)$body['access_token'],
            'expires_at'    => time() + $this->normalize_ttl($body['expires_in'] ?? 3600),
        ]);
        $this->redirect_with_notice('connected', $env);
    }

    public function handle_oauth_disconnect(): void {
        if (!current_user_can('manage_shop_settings')) {
            wp_die(esc_html__('Permission denied.', 'zoho-payments-for-edd'), 403);
        }
        check_admin_referer('zoho_edd_oauth_disconnect');

        $tokens = $this->get_stored_tokens();
        if ($tokens['refresh_token'] !== '') {
            wp_remote_post($this->get_accounts_base($tokens['edition']) . '/oauth/v2/token/revoke', [
                'timeout' => 15,
                'body'    => ['token' => $tokens['refresh_token']],
            ]);
        }
        delete_option(self::TOKEN_OPTION);
        delete_option(self::REFRESH_LOCK);
        $this->token_cache = null;
        $this->redirect_with_notice('disconnected', $this->settings->get_active_environment());
    }

    public function get_access_token(string $env, ?string $edition = null, bool $force_refresh = false) {
        $edition = $edition ?? $this->settings->get_edition();
        $tokens  = $this->get_stored_tokens();

        if ($tokens['refresh_token'] === '' || $tokens['env'] !== $env || $tokens['edition'] !== $edition) {
            return new WP_Error('zoho_edd_not_connected', __('Zoho Payments is not authorized for the active edition/environment.', 'zoho-payments-for-edd'));
        }
        if (!$force_refresh && $tokens['access_token'] !== '' && $tokens['expires_at'] > (time() + self::EXPIRY_BUFFER)) {
            return $tokens['access_token'];
        }

        $hasLock = add_option(self::REFRESH_LOCK, time(), '', 'no');
        if (!$hasLock) {
            if (!$force_refresh && $tokens['access_token'] !== '' && $tokens['expires_at'] > (time() + 30)) {
                return $tokens['access_token'];
            }
            for ($i = 0; $i < 5; $i++) {
                usleep(400000);
                wp_cache_delete(self::TOKEN_OPTION, 'options');
                $reloaded = $this->get_stored_tokens(true);
                if ($reloaded['env'] === $env && $reloaded['edition'] === $edition && $reloaded['access_token'] !== '' && $reloaded['expires_at'] > (time() + self::EXPIRY_BUFFER)) {
                    return $reloaded['access_token'];
                }
            }
            $lockAt = (int)get_option(self::REFRESH_LOCK, 0);
            if ($lockAt === 0 || (time() - $lockAt) > 30) {
                delete_option(self::REFRESH_LOCK);
                $hasLock = add_option(self::REFRESH_LOCK, time(), '', 'no');
            }
        }

        try {
            $creds = $this->settings->get_credentials();
            if ($creds['client_id'] === '' || $creds['client_secret'] === '') {
                return new WP_Error('zoho_edd_missing_credentials', __('Zoho Payments Client ID or Client Secret is missing.', 'zoho-payments-for-edd'));
            }

            $res = wp_remote_post($this->get_accounts_base($edition) . '/oauth/v2/token', [
                'timeout' => 20,
                'body'    => [
                    'refresh_token' => $tokens['refresh_token'],
                    'client_id'     => $creds['client_id'],
                    'client_secret' => $creds['client_secret'],
                    'grant_type'    => 'refresh_token',
                ],
            ]);
            if (is_wp_error($res)) return $res;

            $body = json_decode(wp_remote_retrieve_body($res), true);
            if (!is_array($body) || empty($body['access_token'])) {
                return new WP_Error('zoho_edd_refresh_failed', (string)($body['error'] ?? __('Failed to refresh Zoho Payments access token.', 'zoho-payments-for-edd')));
            }

            $this->save_tokens([
                'edition'       => $edition,
                'env'           => $env,
                'refresh_token' => $tokens['refresh_token'],
                'access_token'  => (string)$body['access_token'],
                'expires_at'    => time() + $this->normalize_ttl($body['expires_in'] ?? 3600),
            ]);
            return (string)$body['access_token'];
        } finally {
            if ($hasLock) delete_option(self::REFRESH_LOCK);
        }
    }

    private function redirect_with_notice(string $status, string $env, string $detail = ''): void {
        $args = [
            'post_type'       => 'download',
            'page'            => 'edd-settings',
            'tab'             => 'gateways',
            'section'         => ZOHO_EDD_GATEWAY_ID,
            'zoho_edd_notice' => sanitize_key($status),
            'zoho_edd_env'    => sanitize_key($env),
        ];
        if ($detail !== '') $args['zoho_edd_detail'] = rawurlencode(substr(wp_strip_all_tags($detail), 0, 160));
        wp_safe_redirect(add_query_arg($args, admin_url('edit.php')));
        exit;
    }
}
