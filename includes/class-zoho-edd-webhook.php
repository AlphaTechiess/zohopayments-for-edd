<?php
defined('ABSPATH') || exit;

final class Zoho_EDD_Webhook {
    public const LISTENER_KEY = 'zoho-payments';
    private Zoho_EDD_Settings $settings;

    public function __construct(Zoho_EDD_Settings $settings) {
        $this->settings = $settings;
        add_action('init', [$this, 'listen_for_webhook']);
    }

    public static function get_webhook_url(): string {
        return add_query_arg('edd-listener', self::LISTENER_KEY, home_url('/'));
    }

    public function listen_for_webhook(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public webhook endpoint authenticated via HMAC-SHA256.
        if (!isset($_GET['edd-listener']) || sanitize_key(wp_unslash($_GET['edd-listener'])) !== self::LISTENER_KEY) return;
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : '';
        if ($method !== 'POST') $this->send_json(['status' => 'error', 'message' => 'Method not allowed'], 405);
        $this->handle_webhook();
    }

    private function get_signature_header(): string {
        if (!empty($_SERVER['HTTP_X_ZOHO_WEBHOOK_SIGNATURE'])) {
            return trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_X_ZOHO_WEBHOOK_SIGNATURE'])));
        }
        if (function_exists('getallheaders')) {
            foreach ((array)getallheaders() as $k => $v) {
                if (strcasecmp((string)$k, 'X-Zoho-Webhook-Signature') === 0) return trim((string)$v);
            }
        }
        return '';
    }

    private function send_json(array $data, int $status = 200): void {
        nocache_headers();
        wp_send_json($data, $status);
    }

    public static function verify_signature(string $raw_body, string $sig_header, string $signing_key): bool {
        if ($raw_body === '' || $sig_header === '' || $signing_key === '') return false;
        $ts = $sig = '';
        foreach (explode(',', $sig_header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                $k = trim($kv[0]);
                if ($k === 't') $ts = trim($kv[1]);
                elseif ($k === 'v' || $k === 'v1') $sig = trim($kv[1]);
            }
        }
        if ($ts === '' || $sig === '') return false;
        $tsSec = strlen($ts) > 11 ? (int)($ts / 1000) : (int)$ts;
        if (abs(time() - $tsSec) > 900) return false;
        return hash_equals(strtolower(hash_hmac('sha256', $ts . '.' . $raw_body, $signing_key)), strtolower($sig));
    }

    public static function get_metadata_value($meta_data, string $key): ?string {
        if (!is_array($meta_data)) return null;
        foreach ($meta_data as $item) {
            if (is_array($item) && isset($item['key']) && strcasecmp((string)$item['key'], $key) === 0) {
                return (string)($item['value'] ?? '');
            }
        }
        return null;
    }

    private function handle_webhook(): void {
        $raw_body = (string)file_get_contents('php://input');
        $payload  = $raw_body !== '' ? json_decode($raw_body, true) : null;
        if (!is_array($payload) || empty($payload['event_type'])) {
            $this->send_json(['status' => 'error', 'message' => 'Invalid payload'], 400);
        }

        $active_env = $this->settings->get_active_environment();
        $creds      = $this->settings->get_credentials();
        if ($creds['webhook_secret'] === '' || !self::verify_signature($raw_body, $this->get_signature_header(), $creds['webhook_secret'])) {
            $this->send_json(['status' => 'error', 'message' => 'Invalid webhook signature'], 401);
        }
        if (isset($payload['live_mode']) && ((false === $payload['live_mode']) ? 'sandbox' : 'live') !== $active_env) {
            $this->send_json(['status' => 'error', 'message' => 'Environment mismatch'], 403);
        }
        if ($creds['account_id'] !== '' && isset($payload['account_id']) && (string)$payload['account_id'] !== (string)$creds['account_id']) {
            $this->send_json(['status' => 'error', 'message' => 'Account ID mismatch'], 403);
        }

        $event_type   = sanitize_text_field((string)$payload['event_type']);
        $event_id     = (string)($payload['event_id_str'] ?? ($payload['event_id'] ?? ''));
        $event_object = is_array($payload['event_object'] ?? null) ? $payload['event_object'] : [];

        if (in_array($event_type, ['payment.succeeded', 'payment.failed'], true)) {
            $payment = is_array($event_object['payment'] ?? null) ? $event_object['payment'] : [];
            if (strcasecmp((string)self::get_metadata_value($payment['meta_data'] ?? [], 'Method'), 'EDD') !== 0) {
                $this->send_json(['status' => 'ok', 'message' => 'Ignored: non-EDD event.'], 200);
            }
            $this->process_payment_event($event_type, $event_id, $payment, $active_env);
        }

        if (in_array($event_type, ['refund.succeeded', 'refund.failed'], true)) {
            $refund     = is_array($event_object['refund'] ?? null) ? $event_object['refund'] : [];
            $method_val = self::get_metadata_value($refund['meta_data'] ?? [], 'Method');
            if ($method_val !== null && strcasecmp($method_val, 'EDD') !== 0) {
                $this->send_json(['status' => 'ok', 'message' => 'Ignored: non-EDD refund event.'], 200);
            }
            $pid   = sanitize_text_field((string)($refund['payment_id'] ?? ''));
            $order = $pid !== '' ? $this->find_order_by_payment_id($pid) : false;
            if (!$order) $this->send_json(['status' => 'ok', 'message' => 'Ignored: order not found.'], 200);
            $this->process_refund_event($event_type, $event_id, $refund, $order);
        }

        $this->send_json(['status' => 'ok', 'message' => 'Ignored: unhandled event.'], 200);
    }

    private function process_payment_event(string $event_type, string $event_id, array $payment, string $env): void {
        $order = $this->find_order_for_payment($payment);
        if (!$order) $this->send_json(['status' => 'ok', 'message' => 'Order not found'], 200);

        $order_id = (int)$order->id;
        if ($this->is_event_processed($order_id, $event_id)) {
            $this->send_json(['status' => 'ok', 'message' => 'Duplicate event ignored'], 200);
        }

        $payment_id = sanitize_text_field((string)($payment['payment_id'] ?? ''));
        $currency   = strtoupper(sanitize_text_field((string)($payment['currency'] ?? '')));
        $expected   = $this->settings->get_currency();
        $symbol     = $this->settings->get_currency_symbol();
        $amount     = (float)($payment['amount'] ?? 0);

        if ($event_type === 'payment.succeeded') {
            if ($expected !== $currency) {
                /* translators: 1: received currency, 2: expected currency */
                edd_add_note(['object_id' => $order_id, 'object_type' => 'order', 'content' => sprintf(__('Zoho Payments webhook rejected: unexpected currency "%1$s" (expected %2$s).', 'zoho-payments-for-edd'), $currency, $expected)]);
                $this->send_json(['status' => 'ok', 'message' => 'Currency mismatch'], 200);
            }
            $order_total = (float)round((float)$order->total, 2);
            if (abs($amount - $order_total) > 0.01) {
                /* translators: 1: currency symbol, 2: received amount, 3: expected amount */
                edd_add_note(['object_id' => $order_id, 'object_type' => 'order', 'content' => sprintf(__('Zoho Payments amount mismatch: received %1$s%2$s, expected %1$s%3$s.', 'zoho-payments-for-edd'), $symbol, number_format($amount, 2), number_format($order_total, 2))]);
                $this->send_json(['status' => 'ok', 'message' => 'Amount mismatch'], 200);
            }

            if (!in_array($order->status, ['complete', 'publish', 'refunded', 'partially_refunded'], true)) {
                if ($payment_id !== '') {
                    edd_update_order_meta($order_id, '_zoho_payments_payment_id', $payment_id);
                    edd_add_order_transaction(['object_id' => $order_id, 'object_type' => 'order', 'transaction_id' => $payment_id, 'gateway' => ZOHO_EDD_GATEWAY_ID, 'status' => 'complete', 'total' => $amount]);
                }
                $method_type = sanitize_text_field((string)($payment['payment_method']['type'] ?? ''));
                if ($method_type !== '') edd_update_order_meta($order_id, '_zoho_payments_method_type', $method_type);
                edd_update_order_meta($order_id, '_zoho_payments_env', $env);
                edd_update_order_status($order_id, 'complete');
                /* translators: 1: payment ID, 2: currency symbol, 3: amount */
                edd_add_note(['object_id' => $order_id, 'object_type' => 'order', 'content' => sprintf(__('Zoho Payments succeeded via webhook (Payment ID: %1$s, Amount: %2$s%3$s).', 'zoho-payments-for-edd'), $payment_id, $symbol, number_format($amount, 2))]);
            }
            $this->mark_event_processed($order_id, $event_id);
            $this->send_json(['status' => 'ok', 'message' => 'Payment completed'], 200);
        }

        if ($order->status === 'pending') edd_update_order_status($order_id, 'failed');
        $code = sanitize_text_field((string)($payment['failure_code'] ?? 'unknown'));
        /* translators: 1: payment ID, 2: failure code */
        edd_add_note(['object_id' => $order_id, 'object_type' => 'order', 'content' => sprintf(__('Zoho Payments failed via webhook (Payment ID: %1$s, Code: %2$s).', 'zoho-payments-for-edd'), $payment_id ?: 'N/A', $code)]);
        $this->mark_event_processed($order_id, $event_id);
        $this->send_json(['status' => 'ok', 'message' => 'Payment failure recorded'], 200);
    }

    private function process_refund_event(string $event_type, string $event_id, array $refund, $order): void {
        $order_id = (int)$order->id;
        if ($this->is_event_processed($order_id, $event_id)) $this->send_json(['status' => 'ok', 'message' => 'Duplicate event ignored'], 200);

        $zoho_refund_id = sanitize_text_field((string)($refund['refund_id'] ?? ''));
        $refund_amount  = (float)($refund['amount'] ?? 0);
        $symbol         = $this->settings->get_currency_symbol();

        if ($event_type === 'refund.succeeded') {
            $recorded = edd_get_order_meta($order_id, '_zoho_payments_refund_ids', true);
            $recorded = is_array($recorded) ? $recorded : [];
            if ($zoho_refund_id !== '' && in_array($zoho_refund_id, $recorded, true)) {
                $this->mark_event_processed($order_id, $event_id);
                $this->send_json(['status' => 'ok', 'message' => 'Refund already recorded'], 200);
            }
            if ($zoho_refund_id !== '') {
                $recorded[] = $zoho_refund_id;
                edd_update_order_meta($order_id, '_zoho_payments_refund_ids', $recorded);
            }
            if (!defined('ZOHO_EDD_PROCESSING_WEBHOOK_REFUND')) define('ZOHO_EDD_PROCESSING_WEBHOOK_REFUND', true);
            $order_total = (float)round((float)$order->total, 2);
            if ($refund_amount >= ($order_total - 0.01) && $order->status !== 'refunded') {
                $edd_refund_id = edd_refund_order($order_id);
                if ($edd_refund_id && !is_wp_error($edd_refund_id) && $zoho_refund_id !== '') {
                    edd_add_order_transaction(['object_id' => (int)$edd_refund_id, 'object_type' => 'order', 'transaction_id' => $zoho_refund_id, 'gateway' => ZOHO_EDD_GATEWAY_ID, 'status' => 'complete', 'total' => $refund_amount]);
                }
            } elseif ($refund_amount > 0 && $order->status !== 'refunded') {
                edd_update_order_status($order_id, 'partially_refunded');
            }
            /* translators: 1: refund ID, 2: currency symbol, 3: amount */
            edd_add_note(['object_id' => $order_id, 'object_type' => 'order', 'content' => sprintf(__('Zoho Payments refund succeeded via webhook (Refund ID: %1$s, Amount: %2$s%3$s).', 'zoho-payments-for-edd'), $zoho_refund_id ?: 'N/A', $symbol, number_format($refund_amount, 2))]);
            $this->mark_event_processed($order_id, $event_id);
            $this->send_json(['status' => 'ok', 'message' => 'Refund synced'], 200);
        }

        $reason = sanitize_text_field((string)($refund['failure_reason'] ?? 'unknown'));
        /* translators: 1: refund ID, 2: failure reason */
        edd_add_note(['object_id' => $order_id, 'object_type' => 'order', 'content' => sprintf(__('Zoho Payments refund failed via webhook (Refund ID: %1$s, Reason: %2$s).', 'zoho-payments-for-edd'), $zoho_refund_id ?: 'N/A', $reason)]);
        $this->mark_event_processed($order_id, $event_id);
        $this->send_json(['status' => 'ok', 'message' => 'Refund failure logged'], 200);
    }

    private function find_order_for_payment(array $payment) {
        if (!empty($payment['payments_session_id'])) {
            $orders = edd_get_orders(['number' => 1, 'gateway' => ZOHO_EDD_GATEWAY_ID, 'meta_query' => [['key' => '_zoho_payments_session_id', 'value' => sanitize_text_field((string)$payment['payments_session_id'])]]]); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            if (!empty($orders[0])) return $orders[0];
        }
        if (!empty($payment['reference_number']) && strpos((string)$payment['reference_number'], 'EDD-') === 0) {
            $ref   = substr(sanitize_text_field((string)$payment['reference_number']), 4);
            $order = $ref !== '' ? edd_get_order_by('order_number', $ref) : false;
            if ($order && $order->gateway === ZOHO_EDD_GATEWAY_ID) return $order;
        }
        return false;
    }

    private function find_order_by_payment_id(string $payment_id) {
        $orders = edd_get_orders(['number' => 1, 'gateway' => ZOHO_EDD_GATEWAY_ID, 'meta_query' => [['key' => '_zoho_payments_payment_id', 'value' => $payment_id]]]); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        if (!empty($orders[0])) return $orders[0];
        if (function_exists('edd_get_order_id_from_transaction_id') && ($oid = edd_get_order_id_from_transaction_id($payment_id))) {
            $order = edd_get_order($oid);
            if ($order && $order->gateway === ZOHO_EDD_GATEWAY_ID) return $order;
        }
        return false;
    }

    private function is_event_processed(int $order_id, string $event_id): bool {
        if ($event_id === '') return false;
        $p = edd_get_order_meta($order_id, '_zoho_payments_processed_events', true);
        return is_array($p) && in_array($event_id, $p, true);
    }

    private function mark_event_processed(int $order_id, string $event_id): void {
        if ($event_id === '') return;
        $p = edd_get_order_meta($order_id, '_zoho_payments_processed_events', true);
        $p = is_array($p) ? $p : [];
        if (!in_array($event_id, $p, true)) {
            $p[] = $event_id;
            edd_update_order_meta($order_id, '_zoho_payments_processed_events', $p);
        }
    }
}
