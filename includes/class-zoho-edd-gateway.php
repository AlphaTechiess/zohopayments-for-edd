<?php
defined('ABSPATH') || exit;

final class Zoho_EDD_Gateway {
    public const WIDGET_SCRIPT_URL = 'https://static.zohocdn.com/zpay/zpay-js/v1/zpayments.js';
    private Zoho_EDD_Settings $settings;
    private Zoho_EDD_API $api;

    public function __construct(Zoho_EDD_Settings $settings, Zoho_EDD_API $api) {
        $this->settings = $settings;
        $this->api      = $api;

        add_filter('edd_payment_gateways', [$this, 'register_gateway']);
        add_action('edd_' . ZOHO_EDD_GATEWAY_ID . '_cc_form', [$this, 'render_checkout_panel']);
        add_action('edd_gateway_' . ZOHO_EDD_GATEWAY_ID, [$this, 'process_purchase_fallback']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_checkout_assets']);
        add_action('wp_ajax_zoho_payments_edd_create_session', [$this, 'ajax_create_session']);
        add_action('wp_ajax_nopriv_zoho_payments_edd_create_session', [$this, 'ajax_create_session']);
        add_action('wp_ajax_zoho_payments_edd_order_status', [$this, 'ajax_order_status']);
        add_action('wp_ajax_nopriv_zoho_payments_edd_order_status', [$this, 'ajax_order_status']);
        add_action('edd_after_submit_refund_table', [$this, 'render_refund_checkbox']);
        add_action('edd_refund_order', [$this, 'process_edd_refund'], 10, 2);
    }

    public function register_gateway(array $gateways): array {
        $gateways[ZOHO_EDD_GATEWAY_ID] = [
            'admin_label'    => __('Zoho Payments', 'zoho-payments-for-edd'),
            'checkout_label' => __('Zoho Payments', 'zoho-payments-for-edd'),
            'supports'       => ['buy_now'],
        ];
        return $gateways;
    }

    public function render_checkout_panel(): void {
        $expected = $this->settings->get_currency();
        $currency = strtoupper((string)edd_get_currency());
        $env      = $this->settings->get_active_environment();
        $iconFile = ZOHO_EDD_PLUGIN_DIR . 'assets/icon.svg';
        $iconUrl  = ZOHO_EDD_PLUGIN_URL . 'assets/icon.svg' . (file_exists($iconFile) ? '?v=' . filemtime($iconFile) : '');
        ?>
        <fieldset id="zoho-payments-edd-fieldset" class="zoho-edd-checkout-box">
            <legend class="zoho-edd-legend"><img src="<?php echo esc_url($iconUrl); ?>" alt="" class="zoho-edd-icon" width="22" height="22" aria-hidden="true" /><span><?php esc_html_e('Zoho Payments', 'zoho-payments-for-edd'); ?></span></legend>
            <?php if ($expected !== $currency) : ?>
                <p class="zoho-edd-notice zoho-edd-notice-error">
                    <?php
                    /* translators: %s: required currency code */
                    echo esc_html(sprintf(__('Zoho Payments requires %s currency for the active edition. Please contact the store administrator.', 'zoho-payments-for-edd'), $expected));
                    ?>
                </p>
            <?php elseif (!$this->settings->is_configured($env)) : ?>
                <p class="zoho-edd-notice zoho-edd-notice-error"><?php esc_html_e('Zoho Payments is not fully configured yet. Please choose another payment method.', 'zoho-payments-for-edd'); ?></p>
            <?php else : ?>
                <p class="zoho-edd-description"><?php esc_html_e('Complete your payment securely via UPI, Credit/Debit Card, or Net Banking.', 'zoho-payments-for-edd'); ?></p>
                <?php if ($env === 'sandbox') : ?>
                    <p class="zoho-edd-sandbox-badge"><?php esc_html_e('Sandbox Mode Active — No real charges will be made.', 'zoho-payments-for-edd'); ?></p>
                <?php endif; ?>
                <div id="zoho-edd-status-container" class="zoho-edd-status" aria-live="polite" hidden></div>
            <?php endif; ?>
        </fieldset>
        <?php
    }

    public function enqueue_checkout_assets(): void {
        if (!function_exists('edd_is_checkout') || !edd_is_checkout() || !edd_is_gateway_active(ZOHO_EDD_GATEWAY_ID)) return;
        $env = $this->settings->get_active_environment();
        if (!$this->settings->is_configured($env) || $this->settings->get_currency() !== strtoupper((string)edd_get_currency())) return;

        $creds = $this->settings->get_credentials();
        $jsFile  = ZOHO_EDD_PLUGIN_DIR . 'assets/js/checkout.min.js';
        $jsVer   = file_exists($jsFile) ? (string)filemtime($jsFile) : ZOHO_EDD_VERSION;
        wp_enqueue_style('zoho-payments-edd-checkout', ZOHO_EDD_PLUGIN_URL . 'assets/css/checkout.min.css', [], ZOHO_EDD_VERSION);
        wp_enqueue_script('zoho-payments-widget', self::WIDGET_SCRIPT_URL, [], ZOHO_EDD_VERSION, true);
        wp_enqueue_script('zoho-payments-edd-checkout', ZOHO_EDD_PLUGIN_URL . 'assets/js/checkout.min.js', ['zoho-payments-widget'], $jsVer, true);

        wp_localize_script('zoho-payments-edd-checkout', 'zohoPaymentsEddConfig', [
            'gatewayId'  => ZOHO_EDD_GATEWAY_ID,
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('zoho_edd_checkout'),
            'accountId'  => $creds['account_id'],
            'apiKey'     => $creds['api_key'],
            'domain'     => $this->settings->get_widget_domain(),
            'isTestMode' => ($env === 'sandbox'),
            'i18n'       => [
                'initializing'      => __('Preparing secure Zoho Payments checkout...', 'zoho-payments-for-edd'),
                'processingTitle'   => __('Payment Processing', 'zoho-payments-for-edd'),
                'processingBody'    => __('Waiting for confirmation from Zoho Payments. Please do not close this page.', 'zoho-payments-for-edd'),
                'delayedTitle'      => __('Payment Still Processing', 'zoho-payments-for-edd'),
                'delayedBody'       => __('Confirmation is taking a bit longer. Your order will complete automatically once confirmed.', 'zoho-payments-for-edd'),
                'checkStatusBtn'    => __('Check Payment Status Again', 'zoho-payments-for-edd'),
                'failedTitle'       => __('Payment Failed', 'zoho-payments-for-edd'),
                'failedBody'        => __('Your payment could not be completed. Please try again.', 'zoho-payments-for-edd'),
                'widgetClosedTitle' => __('Checkout Closed', 'zoho-payments-for-edd'),
                'widgetClosed'      => __('Pop-up is closed. Click the Purchase button again.', 'zoho-payments-for-edd'),
                'widgetUnavailable' => __('Zoho Payments checkout script failed to load. Please refresh.', 'zoho-payments-for-edd'),
                'genericError'      => __('Unable to start Zoho Payments checkout. Please try again.', 'zoho-payments-for-edd'),
            ],
        ]);
    }

    public function ajax_create_session(): void {
        if (!check_ajax_referer('zoho_edd_checkout', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed. Please refresh and try again.', 'zoho-payments-for-edd')], 403);
        }

        $expected = $this->settings->get_currency();
        if ($expected !== strtoupper((string)edd_get_currency())) {
            /* translators: %s: required currency code */
            wp_send_json_error(['message' => sprintf(__('Zoho Payments requires %s currency for the active edition.', 'zoho-payments-for-edd'), $expected)], 400);
        }

        $env     = $this->settings->get_active_environment();
        $edition = $this->settings->get_edition();
        if (!$this->settings->is_configured($env, $edition)) {
            wp_send_json_error(['message' => __('Zoho Payments gateway is not configured.', 'zoho-payments-for-edd')], 400);
        }

        $cart = edd_get_cart_contents();
        if (empty($cart)) wp_send_json_error(['message' => __('Your cart is empty.', 'zoho-payments-for-edd')], 400);

        $email = isset($_POST['edd_email']) ? sanitize_email(wp_unslash($_POST['edd_email'])) : '';
        $first = isset($_POST['edd_first']) ? sanitize_text_field(wp_unslash($_POST['edd_first'])) : '';
        $last  = isset($_POST['edd_last']) ? sanitize_text_field(wp_unslash($_POST['edd_last'])) : '';
        $phone = isset($_POST['edd_phone']) ? sanitize_text_field(wp_unslash($_POST['edd_phone'])) : '';

        if ($email === '' && is_user_logged_in()) {
            $u     = wp_get_current_user();
            $email = $u->user_email;
            $first = $first !== '' ? $first : $u->first_name;
            $last  = $last !== '' ? $last : $u->last_name;
        }
        if (!is_email($email)) wp_send_json_error(['message' => __('Please enter a valid email address.', 'zoho-payments-for-edd')], 400);

        $amount = (float)round((float)edd_get_cart_total(), 2);
        if ($amount <= 0) wp_send_json_error(['message' => __('Order total must be greater than zero.', 'zoho-payments-for-edd')], 400);

        $discounts    = edd_get_cart_discounts();
        $purchase_key = strtolower(md5($email . gmdate('Y-m-d H:i:s') . wp_generate_password(12, false)));
        $purchase_data = [
            'price'        => $amount,
            'date'         => gmdate('Y-m-d H:i:s'),
            'user_email'   => $email,
            'purchase_key' => $purchase_key,
            'currency'     => $expected,
            'downloads'    => $cart,
            'user_info'    => [
                'id'         => is_user_logged_in() ? get_current_user_id() : 0,
                'email'      => $email,
                'first_name' => $first,
                'last_name'  => $last,
                'discount'   => $discounts ? implode(',', $discounts) : 'none',
                'address'    => [],
            ],
            'cart_details' => edd_get_cart_content_details(),
            'gateway'      => ZOHO_EDD_GATEWAY_ID,
            'status'       => 'pending',
        ];

        $order_id = edd_build_order($purchase_data);
        if (empty($order_id) || is_wp_error($order_id)) {
            wp_send_json_error(['message' => __('Failed to create pending order.', 'zoho-payments-for-edd')], 500);
        }

        $order            = edd_get_order($order_id);
        $order_number     = ($order && !empty($order->order_number)) ? (string)$order->order_number : (string)$order_id;
        $reference_number = substr('EDD-' . $order_number, 0, 50);
        $description      = sprintf('Order #%d', (int)$order_id);

        $session = $this->api->create_payment_session([
            'amount'           => $amount,
            'currency'         => $expected,
            'description'      => $description,
            'reference_number' => $reference_number,
            'meta_data'        => [['key' => 'Method', 'value' => 'EDD']],
        ], $env, $edition);

        if (is_wp_error($session)) {
            edd_update_order_status($order_id, 'failed');
            edd_add_note([
                'object_id'   => $order_id,
                'object_type' => 'order',
                /* translators: %s: error message */
                'content'     => sprintf(__('Zoho Payments session creation failed: %s', 'zoho-payments-for-edd'), $session->get_error_message()),
            ]);
            wp_send_json_error(['message' => $session->get_error_message()], 502);
        }

        $session_id = sanitize_text_field((string)$session['payments_session_id']);
        edd_add_order_meta($order_id, '_zoho_payments_session_id', $session_id);
        edd_add_order_meta($order_id, '_zoho_payments_env', $env);
        edd_add_order_meta($order_id, '_zoho_payments_edition', $edition);
        $purchase_data['order_id'] = $order_id;
        edd_set_purchase_session($purchase_data);

        $address = ['email' => $email];
        if (trim($first . ' ' . $last) !== '') $address['name'] = trim($first . ' ' . $last);
        if ($phone !== '') $address['phone'] = $phone;

        wp_send_json_success([
            'order_id'            => (int)$order_id,
            'order_number'        => $order_number,
            'order_key'           => $order ? (string)$order->payment_key : $purchase_key,
            'payments_session_id' => $session_id,
            'amount'              => number_format($amount, 2, '.', ''),
            'currency_code'       => $expected,
            'currency_symbol'     => $this->settings->get_currency_symbol(),
            'description'         => $description,
            'reference_number'    => $reference_number,
            'address'             => $address,
        ]);
    }

    public function ajax_order_status(): void {
        if (!check_ajax_referer('zoho_edd_checkout', 'nonce', false)) {
            wp_send_json_error(['message' => __('Invalid security token.', 'zoho-payments-for-edd')], 403);
        }
        $order_id  = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $order_key = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : '';
        $order     = $order_id ? edd_get_order($order_id) : false;

        if (!$order || $order->gateway !== ZOHO_EDD_GATEWAY_ID || $order_key === '' || !hash_equals((string)$order->payment_key, $order_key)) {
            wp_send_json_error(['message' => __('Order not found.', 'zoho-payments-for-edd')], 404);
        }

        $res = ['status' => (string)$order->status, 'redirect_url' => ''];
        if (in_array($order->status, ['complete', 'publish'], true)) {
            edd_empty_cart();
            $res['status']       = 'complete';
            $res['redirect_url'] = edd_get_success_page_uri();
        }
        wp_send_json_success($res);
    }

    public function process_purchase_fallback(): void {
        edd_set_error('zoho_edd_js_required', __('JavaScript is required to open the Zoho Payments checkout window.', 'zoho-payments-for-edd'));
        edd_send_back_to_checkout('?payment-mode=' . ZOHO_EDD_GATEWAY_ID);
    }

    public function render_refund_checkbox($order): void {
        if (!$order || $order->gateway !== ZOHO_EDD_GATEWAY_ID || (string)edd_get_order_meta($order->id, '_zoho_payments_payment_id', true) === '') return;
        echo '<div class="edd-form-group edd-zoho-payments-refund-wrapper"><div class="edd-form-group__control">'
            . '<input type="checkbox" id="edd-zoho-payments-refund" name="edd_zoho_payments_refund" class="edd-form-group__input" value="1" checked="checked" />'
            . '<label for="edd-zoho-payments-refund" class="edd-form-group__label">' . esc_html__('Refund payment in Zoho Payments', 'zoho-payments-for-edd') . '</label>'
            . '</div></div>';
    }

    public function process_edd_refund($order_id, $refund_id): void {
        if (!current_user_can('edit_shop_payments', $order_id) || (defined('ZOHO_EDD_PROCESSING_WEBHOOK_REFUND') && ZOHO_EDD_PROCESSING_WEBHOOK_REFUND)) return;

        $should_refund = false;
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce already verified by EDD core edd_refund_order action.
        if (!empty($_POST['data'])) {
            parse_str(wp_unslash($_POST['data']), $form_data); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $should_refund = !empty($form_data['edd_zoho_payments_refund']);
        } elseif (!empty($_POST['edd_zoho_payments_refund'])) {
            $should_refund = true;
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if (!$should_refund) return;

        $order      = edd_get_order($order_id);
        $payment_id = $order ? (string)edd_get_order_meta($order_id, '_zoho_payments_payment_id', true) : '';
        if (!$order || $order->gateway !== ZOHO_EDD_GATEWAY_ID || $payment_id === '') return;

        $refund_order  = edd_get_order($refund_id);
        $refund_amount = $refund_order ? abs((float)$refund_order->total) : abs((float)$order->total);
        if ($refund_amount <= 0) return;

        $env     = (string)edd_get_order_meta($order_id, '_zoho_payments_env', true);
        $env     = in_array($env, ['live', 'sandbox'], true) ? $env : $this->settings->get_active_environment();
        $edition = (string)edd_get_order_meta($order_id, '_zoho_payments_edition', true);
        $edition = in_array($edition, ['in', 'us'], true) ? $edition : $this->settings->get_edition();
        $symbol  = ($edition === 'us') ? '$' : '₹';

        $res = $this->api->create_refund($payment_id, [
            'amount'      => (float)round($refund_amount, 2),
            'reason'      => 'requested_by_customer',
            'type'        => 'initiated_by_merchant',
            'description' => sprintf('Refund for Order #%d', (int)$order_id),
            'meta_data'   => [['key' => 'Method', 'value' => 'EDD']],
        ], $env, $edition);

        if (is_wp_error($res)) {
            edd_add_note([
                'object_id'   => $order_id,
                'object_type' => 'order',
                /* translators: %s: error message */
                'content'     => sprintf(__('Zoho Payments API refund failed: %s', 'zoho-payments-for-edd'), $res->get_error_message()),
            ]);
            return;
        }

        $zoho_refund_id = sanitize_text_field((string)$res['refund_id']);
        $recorded       = edd_get_order_meta($order_id, '_zoho_payments_refund_ids', true);
        $recorded       = is_array($recorded) ? $recorded : [];
        if (!in_array($zoho_refund_id, $recorded, true)) {
            $recorded[] = $zoho_refund_id;
            edd_update_order_meta($order_id, '_zoho_payments_refund_ids', $recorded);
        }

        if ($refund_id) {
            edd_add_order_transaction([
                'object_id'      => $refund_id,
                'object_type'    => 'order',
                'transaction_id' => $zoho_refund_id,
                'gateway'        => ZOHO_EDD_GATEWAY_ID,
                'status'         => 'complete',
                'total'          => $refund_amount,
            ]);
        }

        edd_add_note([
            'object_id'   => $order_id,
            'object_type' => 'order',
            /* translators: 1: Zoho refund ID, 2: currency symbol, 3: formatted refund amount */
            'content'     => sprintf(__('Zoho Payments refund initiated (Refund ID: %1$s, Amount: %2$s%3$s).', 'zoho-payments-for-edd'), $zoho_refund_id, $symbol, number_format($refund_amount, 2)),
        ]);
    }
}
