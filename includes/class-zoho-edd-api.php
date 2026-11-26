<?php
defined('ABSPATH') || exit;

final class Zoho_EDD_API {
    private Zoho_EDD_Settings $settings;
    private Zoho_EDD_OAuth $oauth;

    public function __construct(Zoho_EDD_Settings $settings, Zoho_EDD_OAuth $oauth) {
        $this->settings = $settings;
        $this->oauth    = $oauth;
    }

    private function base_url(string $env, string $edition): string {
        $tld = $this->settings->get_tld($edition);
        return ($env === 'sandbox') ? "https://paymentssandbox.zoho.{$tld}/api/v1" : "https://payments.zoho.{$tld}/api/v1";
    }

    private function request(string $method, string $path, array $body = [], ?string $env = null, ?string $edition = null, bool $isRetry = false) {
        $env     = $env ?? $this->settings->get_active_environment();
        $edition = $edition ?? $this->settings->get_edition();
        $creds   = $this->settings->get_credentials();
        if ($creds['account_id'] === '') {
            return new WP_Error('zoho_edd_no_account', __('Zoho Payments Account ID is missing.', 'zoho-payments-for-edd'));
        }

        $token = $this->oauth->get_access_token($env, $edition, $isRetry);
        if (is_wp_error($token)) return $token;

        $url  = add_query_arg('account_id', $creds['account_id'], $this->base_url($env, $edition) . $path);
        $args = [
            'method'  => strtoupper($method),
            'timeout' => 25,
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ];
        if ($body && in_array($args['method'], ['POST', 'PUT', 'PATCH'], true)) {
            $args['body'] = wp_json_encode($body);
        }

        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) return $res;

        $code = (int)wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($data)) $data = [];

        if ($code === 401 && !$isRetry) {
            $this->oauth->invalidate_access_token($env, $edition);
            return $this->request($method, $path, $body, $env, $edition, true);
        }
        if ($code >= 400) {
            $msg = (string)($data['message'] ?? ($data['error'] ?? __('Zoho Payments API error.', 'zoho-payments-for-edd')));
            return new WP_Error('zoho_edd_api_error', $msg, ['status' => $code, 'response' => $data]);
        }
        return $data;
    }

    public function create_payment_session(array $payload, string $env, string $edition) {
        $res = $this->request('POST', '/paymentsessions', $payload, $env, $edition);
        if (is_wp_error($res)) return $res;
        $session = $res['payments_session'] ?? ($res['payment_session'] ?? $res);
        if (empty($session['payments_session_id'])) {
            return new WP_Error('zoho_edd_invalid_session', __('Zoho Payments did not return a payment session ID.', 'zoho-payments-for-edd'));
        }
        return $session;
    }

    public function create_refund(string $payment_id, array $payload, string $env, string $edition) {
        $res = $this->request('POST', '/payments/' . rawurlencode($payment_id) . '/refunds', $payload, $env, $edition);
        if (is_wp_error($res)) return $res;
        $refund = $res['refund'] ?? $res;
        if (empty($refund['refund_id'])) {
            return new WP_Error('zoho_edd_invalid_refund', __('Zoho Payments did not return a refund ID.', 'zoho-payments-for-edd'));
        }
        return $refund;
    }
}
