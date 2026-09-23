<?php
/*
Plugin Name: Payme
Plugin URI:  http://paycom.uz
Description: Payme Checkout Plugin for WooCommerce
Version: 1.5.2
Author: richman@mail.ru, support@paycom.uz
Text Domain: payme
Requires PHP: 7.4
Requires at least: 5.0
WC requires at least: 4.0
WC tested up to: 9.4
Domain Path: /lang
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 * Must run on 'before_woocommerce_init', before any order objects are touched.
 * Without this, the plugin talks to wp_postmeta directly (see legacy 1.4.8) and
 * silently stops working on any store where HPOS ("Custom order tables") is enabled,
 * which has been the default for new WooCommerce installs since WC 8.2.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

add_action('plugins_loaded', 'woocommerce_payme', 0);

if (!function_exists('getallheaders')) {
    function getallheaders()
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

function woocommerce_payme()
{
    load_plugin_textdomain('payme', false, dirname(plugin_basename(__FILE__)) . '/lang/');

    // Do nothing, if WooCommerce is not available
    if (!class_exists('WC_Payment_Gateway'))
        return;

    // Do not re-declare class
    if (class_exists('WC_PAYME'))
        return;

    class WC_PAYME extends WC_Payment_Gateway
    {
        /**
         * Per Payme Business protocol: an opened transaction that is not performed
         * within 12 hours (43 200 000 ms) must be auto-cancelled with reason "4"
         * (cancellation by timeout).
         */
        const TRANSACTION_TIMEOUT_MS = 43200000;
        const CANCEL_REASON_TIMEOUT = 4;

        protected $merchant_id;
        protected $merchant_key;
        protected $checkout_url;
        protected $return_url;
        protected $complete_order;

        public function __construct()
        {
            $plugin_dir = plugin_dir_url(__FILE__);
            $this->id = 'payme';
            $this->title = 'Payme';
            $this->description = __('Payment system Payme', 'payme');
            $this->icon = apply_filters('woocommerce_payme_icon', '' . $plugin_dir . 'payme.png');
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            // Populate options from the saved settings
            $this->merchant_id = $this->get_option('merchant_id');
            $this->merchant_key = $this->get_option('merchant_key');
            $this->checkout_url = $this->get_option('checkout_url');
            $this->return_url = $this->get_option('return_url');
            $this->complete_order = $this->get_option('complete_order');

            add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_api_wc_' . $this->id, [$this, 'callback']);
        }

        public function admin_options()
        {
            ?>
            <h3><?php _e('Payme', 'payme'); ?></h3>

            <p><?php _e('Configure checkout settings', 'payme'); ?></p>

            <p>
                <strong><?php _e('Your Web Cash Endpoint URL to handle requests is:', 'payme'); ?></strong>
                <em><?php echo esc_html(site_url('/?wc-api=wc_payme')); ?></em>
            </p>

            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
            <?php
        }

        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => __('Enable/Disable', 'payme'),
                    'type' => 'checkbox',
                    'label' => __('Enabled', 'payme'),
                    'default' => 'yes'
                ],
                'complete_order' => [
                    'title' => __('Order auto complete', 'payme'),
                    'type' => 'checkbox',
                    'label' => __('If disabled, you have to manually change order status to COMPLETE after success payment', 'payme'),
                    'default' => 'yes'
                ],
                'merchant_id' => [
                    'title' => __('Merchant ID', 'payme'),
                    'type' => 'text',
                    'description' => __('Obtain and set Merchant ID from the Paycom Merchant Cabinet', 'payme'),
                    'default' => ''
                ],
                'merchant_key' => [
                    'title' => __('KEY', 'payme'),
                    'type' => 'text',
                    'description' => __('Obtain and set KEY from the Paycom Merchant Cabinet', 'payme'),
                    'default' => ''
                ],
                'checkout_url' => [
                    'title' => __('Checkout URL', 'payme'),
                    'type' => 'text',
                    'description' => __('Set Paycom Checkout URL to submit a payment', 'payme'),
                    'default' => 'https://checkout.paycom.uz'
                ],
                'return_url' => [
                    'title' => __('Return URL', 'payme'),
                    'type' => 'text',
                    'description' => __('Set Paycom return URL', 'payme'),
                    'default' => site_url('/cart/?payme_success=1')
                ]
            ];
        }

        /**
         * Converts a WooCommerce monetary amount to Payme's "tiyin" integer format.
         * Centralised here so the checkout form and the callback amount check
         * can never drift apart (they used two different formulas before 1.5.0).
         *
         * @param float $amount
         * @return int
         */
        private function to_tiyin($amount)
        {
            return (int) round(((float) $amount) * 100);
        }

        /**
         * Builds the direct Payme "quick link" (GET method) checkout URL:
         * https://checkout.paycom.uz/base64(m=...;ac.order_id=...;a=...;c=...;l=...)
         * Returning this straight from process_payment()/receipt_page() sends
         * the customer to Payme in one hop, with no intermediate WooCommerce
         * "Pay for order" page - matching how the click.uz gateway does it.
         *
         * @see https://developer.help.paycom.uz/initsializatsiya-platezhey/otpravka-cheka-po-metodu-get/
         */
        private function build_payme_checkout_url(WC_Order $order)
        {
            $sum = $this->to_tiyin($order->get_total());

            $lang_codes = ['ru_RU' => 'ru', 'en_US' => 'en', 'uz_UZ' => 'uz'];
            $lang = isset($lang_codes[get_locale()]) ? $lang_codes[get_locale()] : 'en';

            // Params are delimited with ';' - the callback URL itself must
            // therefore never contain a literal ';' (a normal WP query string doesn't).
            $params = sprintf(
                'm=%s;ac.order_id=%s;a=%d;c=%s;l=%s',
                $this->merchant_id,
                $order->get_id(),
                $sum,
                $this->get_return_url($order),
                $lang
            );

            return trailingslashit($this->checkout_url) . base64_encode($params);
        }

        /**
         * The URL Payme redirects the customer's browser back to after payment
         * or cancellation. Built with add_query_arg() (not string concatenation)
         * so order_id/key are always well-formed, separate query parameters -
         * concatenating them onto a return_url that already has its own "?...”
         * query string (the default is site_url('/cart/?payme_success=1'))
         * used to produce a URL with two '?' characters, which made order_id
         * and key unrecoverable on the receiving end.
         */
        private function get_return_url(WC_Order $order)
        {
            return add_query_arg(
                [
                    'order_id' => $order->get_id(),
                    'key' => $order->get_order_key(),
                ],
                $this->return_url
            );
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            if (!$order) {
                wc_add_notice(__('Order not found.', 'payme'), 'error');
                return ['result' => 'failure'];
            }

            return [
                'result' => 'success',
                'redirect' => $this->build_payme_checkout_url($order)
            ];
        }

        /**
         * Handles the "Pay for order" retry entry point (My Account > Orders > Pay).
         * Redirects straight to Payme instead of rendering an intermediate page,
         * for the same reason and in the same way as process_payment() above.
         */
        public function receipt_page($order_id)
        {
            $order = wc_get_order($order_id);

            if (!$order) {
                return;
            }

            wp_redirect($this->build_payme_checkout_url($order));
            exit;
        }

        /**
         * Writes a line to the WooCommerce logs (payme-xxxxxxxx.log), so that
         * disputed payments/refunds can actually be investigated in production.
         *
         * @param string $message
         * @param string $level one of WC_Log_Levels
         */
        private function log($message, $level = 'info')
        {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->log($level, $message, ['source' => 'payme']);
            }
        }

        /**
         * Reads the Basic Auth "Authorization" header, with fallbacks for server
         * configurations where getallheaders()/HTTP_AUTHORIZATION are not populated
         * (common on some Nginx + PHP-FPM setups) but PHP_AUTH_USER/PW are.
         *
         * @return string
         */
        private function get_authorization_header()
        {
            $headers = getallheaders();
            if (!empty($headers['Authorization'])) {
                return $headers['Authorization'];
            }
            if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
                return $_SERVER['HTTP_AUTHORIZATION'];
            }
            if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            }
            if (isset($_SERVER['PHP_AUTH_USER'])) {
                return 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . ($_SERVER['PHP_AUTH_PW'] ?? ''));
            }
            return '';
        }

        /**
         * Endpoint method. This method handles requests from Paycom.
         */
        public function callback()
        {
            // Parse payload
            $raw = file_get_contents('php://input');
            $payload = json_decode($raw, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
                $this->respond($this->error_invalid_json());
            }

            // Authorize client
            $auth_header = $this->get_authorization_header();
            $decoded_key = html_entity_decode($this->merchant_key);
            $expected_credentials = base64_encode('Paycom:' . $decoded_key);

            if (
                !$auth_header ||
                !preg_match('/^\s*Basic\s+(\S+)\s*$/i', $auth_header, $matches) ||
                !hash_equals($expected_credentials, $matches[1])
            ) {
                $this->log('Callback rejected: invalid Authorization header.', 'warning');
                $this->respond($this->error_authorization($payload));
            }

            $method = isset($payload['method']) ? $payload['method'] : null;
            $callable_methods = [
                'CheckPerformTransaction',
                'CreateTransaction',
                'PerformTransaction',
                'CheckTransaction',
                'CancelTransaction',
                'ChangePassword',
            ];

            $response = (is_string($method) && in_array($method, $callable_methods, true))
                ? $this->{$method}($payload)
                : $this->error_unknown_method($payload);

            $this->respond($response);
        }

        /**
         * Responds and terminates request processing.
         * @param array $response specified response
         */
        private function respond($response)
        {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }

            echo json_encode($response);
            die();
        }

        /**
         * Gets order instance by id.
         * Uses wc_get_order() (HPOS-safe) instead of instantiating WC_Order
         * directly, and validates the result instead of relying on an exception
         * that WooCommerce does not reliably throw for a bad/missing ID.
         *
         * @param array $payload request payload
         * @return WC_Order found order by id
         */
        private function get_order(array $payload)
        {
            $order_id = isset($payload['params']['account']['order_id'])
                ? absint($payload['params']['account']['order_id'])
                : 0;

            $order = $order_id ? wc_get_order($order_id) : false;

            if (!$order) {
                $this->respond($this->error_order_id($payload));
            }

            return $order;
        }

        /**
         * Gets order instance by Payme transaction id.
         * Uses wc_get_orders() with a meta query instead of a raw SQL query
         * against $wpdb->postmeta: the raw query (a) breaks under WooCommerce
         * HPOS, where order meta no longer lives in wp_postmeta, and (b) had a
         * quoting bug ('%s' inside wpdb::prepare() double-quotes the value).
         *
         * @param array $payload request payload
         * @return WC_Order found order by transaction id
         */
        private function get_order_by_transaction($payload)
        {
            $transaction_id = isset($payload['params']['id']) ? sanitize_text_field($payload['params']['id']) : '';

            $order = false;
            if ($transaction_id !== '') {
                $orders = wc_get_orders([
                    'limit' => 1,
                    'meta_key' => '_payme_transaction_id',
                    'meta_value' => $transaction_id,
                    'return' => 'objects',
                ]);
                $order = !empty($orders) ? reset($orders) : false;
            }

            if (!$order) {
                $this->respond($this->error_transaction($payload));
            }

            return $order;
        }

        private function current_timestamp()
        {
            return round(microtime(true) * 1000);
        }

        private function get_create_time(WC_Order $order)
        {
            $value = $order->get_meta('_payme_create_time', true);
            return $value === '' ? 0.0 : (float) $value;
        }

        private function get_perform_time(WC_Order $order)
        {
            $value = $order->get_meta('_payme_perform_time', true);
            return $value === '' ? 0.0 : (float) $value;
        }

        private function get_cancel_time(WC_Order $order)
        {
            $value = $order->get_meta('_payme_cancel_time', true);
            return $value === '' ? 0.0 : (float) $value;
        }

        private function get_transaction_id(WC_Order $order)
        {
            return (string) $order->get_meta('_payme_transaction_id', true);
        }

        private function get_cancel_reason(WC_Order $order)
        {
            $value = (int) $order->get_meta('_cancel_reason', true);
            return $value ?: null;
        }

        /**
         * Whether the transaction opened for this order has been sitting
         * unperformed for longer than the protocol's 12-hour limit.
         */
        private function is_transaction_expired(WC_Order $order)
        {
            $create_time = $this->get_create_time($order);
            if (!$create_time) {
                return false;
            }
            return ($this->current_timestamp() - $create_time) > self::TRANSACTION_TIMEOUT_MS;
        }

        /**
         * Cancels an order whose transaction expired without being performed,
         * as required by the Payme Business protocol ("Отмена по таймауту").
         */
        private function expire_transaction(WC_Order $order)
        {
            $order->update_meta_data('_payme_cancel_time', $this->current_timestamp());
            $order->update_meta_data('_cancel_reason', self::CANCEL_REASON_TIMEOUT);
            $order->update_status('cancelled', __('Payme: transaction auto-cancelled after 12h timeout.', 'payme'));
            $order->save();

            $this->log(sprintf('Transaction expired (12h timeout) for order #%d.', $order->get_id()), 'notice');
        }

        /**
         * Order states from which a payment can still legitimately be started
         * or is already in flight. Used by CheckPerformTransaction.
         * NOTE: 'on-hold' - not 'processing' - is the intermediate "transaction
         * opened, awaiting confirmation" status; see resolve_state() below for why.
         */
        private function is_order_payable(WC_Order $order)
        {
            return in_array($order->get_status(), ['pending', 'on-hold'], true);
        }

        /**
         * Derives the Payme transaction state (1/2/-1/-2) from our own stored
         * timestamps rather than from the mutable WooCommerce order status.
         * Order status can be changed later by store staff or other plugins
         * for fulfilment reasons (e.g. processing -> on-hold for a backorder)
         * without that having anything to do with the underlying payment's
         * true state - so the payment state must not be read back off it.
         *
         * @return int|null 1 = opened, 2 = performed, -1 = cancelled, -2 = refunded, null = no transaction on this order.
         */
        private function resolve_state(WC_Order $order)
        {
            if ($this->get_cancel_time($order) > 0) {
                return $this->get_perform_time($order) > 0 ? -2 : -1;
            }
            if ($this->get_perform_time($order) > 0) {
                return 2;
            }
            if ($this->get_create_time($order) > 0) {
                return 1;
            }
            return null;
        }

        private function CheckPerformTransaction($payload)
        {
            $order = $this->get_order($payload);

            if (!isset($payload['params']['amount']) || $this->to_tiyin($order->get_total()) !== (int) $payload['params']['amount']) {
                return $this->error_amount($payload);
            }

            if (!$this->is_order_payable($order)) {
                return $this->error_order_id($payload);
            }

            return [
                'id' => $payload['id'],
                'result' => [
                    'allow' => true
                ],
                'error' => null
            ];
        }

        private function CreateTransaction($payload)
        {
            $order = $this->get_order($payload);

            if (!isset($payload['params']['amount']) || $this->to_tiyin($order->get_total()) !== (int) $payload['params']['amount']) {
                return $this->error_amount($payload);
            }

            $transaction_id = isset($payload['params']['id']) ? sanitize_text_field($payload['params']['id']) : '';
            $saved_transaction_id = $this->get_transaction_id($order);

            // A transaction already exists for this order (repeat/retried call).
            if ($saved_transaction_id !== '') {
                if ($transaction_id !== $saved_transaction_id) {
                    return $this->error_has_another_transaction($payload);
                }

                // Enforce the protocol's 12h timeout on our own opened transaction.
                if ($this->resolve_state($order) === 1 && $this->is_transaction_expired($order)) {
                    $this->expire_transaction($order);
                    return $this->error_cannot_perform($payload);
                }

                // Per protocol, a repeated CreateTransaction must reflect the
                // transaction's *actual current* state, not always "state: 1".
                return [
                    'id' => $payload['id'],
                    'result' => [
                        'create_time' => $this->get_create_time($order),
                        'transaction' => '000' . $order->get_id(),
                        'state' => $this->resolve_state($order)
                    ]
                ];
            }

            // No transaction yet: only allowed while the order still awaits its first payment attempt.
            if ($order->get_status() !== 'pending') {
                return $this->error_cannot_perform($payload);
            }

            $create_time = $this->current_timestamp();
            $order->update_meta_data('_payme_create_time', $create_time);
            $order->update_meta_data('_payme_transaction_id', $transaction_id);
            // 'on-hold' = WooCommerce's own "awaiting payment" status, and the
            // only pre-payment status WC_Order::payment_complete() will act on -
            // see PerformTransaction() below.
            $order->update_status('on-hold', __('Payme: transaction created, awaiting payment confirmation.', 'payme'));
            $order->save();

            $this->log(sprintf('CreateTransaction: order #%d, transaction %s.', $order->get_id(), $transaction_id));

            return [
                'id' => $payload['id'],
                'result' => [
                    'create_time' => $this->get_create_time($order),
                    'transaction' => '000' . $order->get_id(),
                    'state' => 1
                ]
            ];
        }

        private function PerformTransaction($payload)
        {
            $order = $this->get_order_by_transaction($payload);
            $state = $this->resolve_state($order);

            if ($state === 2) { // already performed - idempotent retry
                return [
                    'id' => $payload['id'],
                    'result' => [
                        'transaction' => '000' . $order->get_id(),
                        'perform_time' => $this->get_perform_time($order),
                        'state' => 2
                    ]
                ];
            }

            if ($state === -1 || $state === -2) {
                return $this->error_cancelled_transaction($payload);
            }

            if ($state !== 1) { // no transaction was ever opened for this order
                return $this->error_cannot_perform($payload);
            }

            if ($this->is_transaction_expired($order)) {
                $this->expire_transaction($order);
                return $this->error_cannot_perform($payload);
            }

            $perform_time = $this->current_timestamp();
            $order->update_meta_data('_payme_perform_time', $perform_time);
            $order->save();

            // Order is still 'on-hold' at this point, so payment_complete() will
            // actually run its full logic: set _date_paid, store the transaction
            // id, fire 'woocommerce_payment_complete', and move the status to
            // 'processing' or 'completed' depending on whether the order needs
            // shipping/processing at all.
            $order->payment_complete(isset($payload['params']['id']) ? sanitize_text_field($payload['params']['id']) : '');

            if ($this->complete_order === 'yes' && $order->get_status() !== 'completed') {
                $order->update_status('completed', __('Payme: payment performed.', 'payme'));
            } else {
                $order->add_order_note(__('Payme: payment performed.', 'payme'));
            }

            $this->log(sprintf('PerformTransaction: order #%d completed.', $order->get_id()));

            return [
                'id' => $payload['id'],
                'result' => [
                    'transaction' => '000' . $order->get_id(),
                    'perform_time' => $this->get_perform_time($order),
                    'state' => 2
                ]
            ];
        }

        private function CheckTransaction($payload)
        {
            $transaction_id = isset($payload['params']['id']) ? sanitize_text_field($payload['params']['id']) : '';
            $order = $this->get_order_by_transaction($payload);

            if ($transaction_id !== $this->get_transaction_id($order)) {
                return $this->error_transaction($payload);
            }

            $state = $this->resolve_state($order);
            if ($state === null) {
                return $this->error_transaction($payload);
            }

            return [
                'id' => $payload['id'],
                'result' => [
                    'create_time' => $this->get_create_time($order),
                    'perform_time' => $this->get_perform_time($order),
                    'cancel_time' => $this->get_cancel_time($order),
                    'transaction' => '000' . $order->get_id(),
                    'state' => $state,
                    'reason' => $this->get_cancel_reason($order)
                ],
                'error' => null
            ];
        }

        private function CancelTransaction($payload)
        {
            $order = $this->get_order_by_transaction($payload);

            $transaction_id = isset($payload['params']['id']) ? sanitize_text_field($payload['params']['id']) : '';
            if ($transaction_id !== $this->get_transaction_id($order)) {
                return $this->error_transaction($payload);
            }

            $reason = isset($payload['params']['reason']) ? (int) $payload['params']['reason'] : null;
            $state = $this->resolve_state($order);

            // Already cancelled/refunded: respond idempotently with the stored result.
            if ($state === -1 || $state === -2) {
                return [
                    'id' => $payload['id'],
                    'result' => [
                        'transaction' => '000' . $order->get_id(),
                        'cancel_time' => $this->get_cancel_time($order),
                        'state' => $state
                    ]
                ];
            }

            if ($state !== 1 && $state !== 2) {
                return $this->error_cancel($payload);
            }

            $cancel_time = $this->current_timestamp();
            $order->update_meta_data('_payme_cancel_time', $cancel_time);
            if ($reason !== null) {
                $order->update_meta_data('_cancel_reason', $reason);
            }

            if ($state === 2) { // was already paid -> refund
                $order->update_status('refunded', __('Payme: transaction refunded.', 'payme'));
                $order->save();
                $this->log(sprintf('CancelTransaction: order #%d refunded (reason %s).', $order->get_id(), $reason));

                return [
                    'id' => $payload['id'],
                    'result' => [
                        'transaction' => '000' . $order->get_id(),
                        'cancel_time' => $cancel_time,
                        'state' => -2
                    ]
                ];
            }

            // $state === 1: opened but never performed -> plain cancellation.
            $order->update_status('cancelled', __('Payme: transaction cancelled.', 'payme'));
            $order->save();
            $this->log(sprintf('CancelTransaction: order #%d cancelled (reason %s).', $order->get_id(), $reason));

            return [
                'id' => $payload['id'],
                'result' => [
                    'transaction' => '000' . $order->get_id(),
                    'cancel_time' => $cancel_time,
                    'state' => -1
                ]
            ];
        }

        private function ChangePassword($payload)
        {
            $new_password = isset($payload['params']['password']) ? (string) $payload['params']['password'] : '';

            if ($new_password === '' || $new_password === $this->merchant_key) {
                return $this->error_password($payload);
            }

            $woo_options = get_option('woocommerce_payme_settings');

            if (!$woo_options) {
                return $this->error_password($payload);
            }

            $woo_options['merchant_key'] = $new_password;
            $is_success = update_option('woocommerce_payme_settings', $woo_options);

            if (!$is_success) {
                return $this->error_password($payload);
            }

            $this->merchant_key = $new_password;

            return [
                'id' => $payload['id'],
                'result' => ['success' => true],
                'error' => null
            ];
        }

        private function error_password($payload)
        {
            return [
                'error' => [
                    'code' => -32400,
                    'message' => [
                        'ru' => __('Cannot change the password', 'payme'),
                        'uz' => __('Cannot change the password', 'payme'),
                        'en' => __('Cannot change the password', 'payme')
                    ],
                    'data' => 'password'
                ],
                'result' => null,
                'id' => $payload['id'] ?? null
            ];
        }

        private function error_invalid_json()
        {
            return [
                'error' => [
                    'code' => -32700,
                    'message' => [
                        'ru' => __('Could not parse JSON', 'payme'),
                        'uz' => __('Could not parse JSON', 'payme'),
                        'en' => __('Could not parse JSON', 'payme')
                    ],
                    'data' => null
                ],
                'result' => null,
                'id' => 0
            ];
        }

        private function error_order_id($payload)
        {
            return [
                'error' => [
                    'code' => -31099,
                    'message' => [
                        'ru' => __('Order number cannot be found', 'payme'),
                        'uz' => __('Order number cannot be found', 'payme'),
                        'en' => __('Order number cannot be found', 'payme')
                    ],
                    'data' => 'order'
                ],
                'result' => null,
                'id' => $payload['id'] ?? null
            ];
        }

        private function error_has_another_transaction($payload)
        {
            return [
                'error' => [
                    'code' => -31099,
                    'message' => [
                        'ru' => __('Other transaction for this order is in progress', 'payme'),
                        'uz' => __('Other transaction for this order is in progress', 'payme'),
                        'en' => __('Other transaction for this order is in progress', 'payme')
                    ],
                    'data' => 'order'
                ],
                'result' => null,
                'id' => $payload['id'] ?? null
            ];
        }

        private function error_amount($payload)
        {
            return [
                'error' => [
                    'code' => -31001,
                    'message' => [
                        'ru' => __('Order amount is incorrect', 'payme'),
                        'uz' => __('Order amount is incorrect', 'payme'),
                        'en' => __('Order amount is incorrect', 'payme')
                    ],
                    'data' => 'amount'
                ],
                'result' => null,
                'id' => $payload['id'] ?? null
            ];
        }

        /**
         * Generic "cannot perform this operation" error (code -31008), as used
         * by the official protocol for CreateTransaction/PerformTransaction in
         * states that aren't otherwise covered (including timeout cancellation).
         */
        private function error_cannot_perform($payload)
        {
            return [
                'error' => [
                    'code' => -31008,
                    'message' => [
                        'ru' => __('Unable to perform the operation', 'payme'),
                        'uz' => __('Unable to perform the operation', 'payme'),
                        'en' => __('Unable to perform the operation', 'payme')
                    ],
                    'data' => null
                ],
                'result' => null,
                'id' => $payload['id'] ?? null
            ];
        }

        private function error_unknown_method($payload)
        {
            return [
                'error' => [
                    'code' => -32601,
                    'message' => [
                        'ru' => __('Unknown method', 'payme'),
                        'uz' => __('Unknown method', 'payme'),
                        'en' => __('Unknown method', 'payme')
                    ],
                    'data' => $payload['method'] ?? null
                ],
                'result' => null,
                'id' => $payload['id'] ?? null
            ];
        }

        private function error_transaction($payload)
        {
            return [
                'error' => [
                    'code' => -31003,
                    'message' => [
                        'ru' => __('Transaction number is wrong', 'payme'),
                        'uz' => __('Transaction number is wrong', 'payme'),
                        'en' => __('Transaction number is wrong', 'payme')
                    ],
                    'data' => 'id'
                ],
                'result' => null,
                'id' => $payload['id'] ?? null
            ];
        }

        private function error_cancelled_transaction($payload)
        {
            return [
                'error' => [
                    'code' => -31008,
                    'message' => [
                        'ru' => __('Transaction was cancelled or refunded', 'payme'),
                        'uz' => __('Transaction was cancelled or refunded', 'payme'),
                        'en' => __('Transaction was cancelled or refunded', 'payme')
                    ],
                    'data' => 'order'
                ],
                'result' => null,
                'id' => $payload['id'] ?? null
            ];
        }

        private function error_cancel($payload)
        {
            return [
                'error' => [
                    'code' => -31007,
                    'message' => [
                        'ru' => __('It is impossible to cancel. The order is completed', 'payme'),
                        'uz' => __('It is impossible to cancel. The order is completed', 'payme'),
                        'en' => __('It is impossible to cancel. The order is completed', 'payme')
                    ],
                    'data' => 'order'
                ],
                'result' => null,
                'id' => $payload['id'] ?? null
            ];
        }

        private function error_authorization($payload)
        {
            return [
                'error' => [
                    'code' => -32504,
                    'message' => [
                        'ru' => __('Error during authorization', 'payme'),
                        'uz' => __('Error during authorization', 'payme'),
                        'en' => __('Error during authorization', 'payme')
                    ],
                    'data' => null
                ],
                'result' => null,
                'id' => $payload['id'] ?? null
            ];
        }
    }

    // Register new Gateway
    function add_payme_gateway($methods)
    {
        $methods[] = 'WC_PAYME';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_payme_gateway');
}

/////////////// success (return) page ///////////////

add_filter('query_vars', 'payme_success_query_vars');
function payme_success_query_vars($query_vars)
{
    $query_vars[] = 'payme_success';
    $query_vars[] = 'order_id';
    return $query_vars;
}

/**
 * Handles the buyer-facing return from Payme's checkout.
 *
 * Rewritten in 1.5.0 to stop hooking into the global 'the_title'/'the_content'
 * filters (which used to overwrite the title/content of every post rendered on
 * the same request - including widgets and menus - not just the payment
 * status message), and to verify the order key before showing payment status,
 * since this endpoint previously trusted a plain numeric order_id from the URL.
 *
 * Fixed in 1.5.2: order_id/key are now read from a properly built query
 * string (see get_return_url()) instead of being naively concatenated onto
 * a return_url that already had its own "?payme_success=1" query string -
 * that used to produce a URL with two '?' characters, so order_id and key
 * were never actually recoverable here. The static "payme_success=1" marker
 * never varied with the real outcome anyway, so it's dropped in favour of
 * checking the order's real status, which is what actually reflects payment.
 */
add_action('parse_request', 'payme_success_parse_request');
function payme_success_parse_request(&$wp)
{
    if (!array_key_exists('payme_success', $wp->query_vars)) {
        return;
    }

    if (!class_exists('WC_PAYME') || !function_exists('wc_get_order')) {
        return;
    }

    $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
    $order = $order_id ? wc_get_order($order_id) : false;

    $submitted_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';

    if (!$order || !hash_equals((string) $order->get_order_key(), $submitted_key)) {
        wc_add_notice(__('An error occurred during payment. Try again or contact your administrator.', 'payme'), 'error');
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    if (in_array($order->get_status(), ['pending', 'on-hold'], true)) {
        // Payme's own PerformTransaction callback has not landed yet (or was
        // rejected); do not claim success until payment has actually been
        // confirmed server-to-server. 'on-hold' = transaction opened, not yet performed.
        wp_safe_redirect($order->get_cancel_order_url());
        exit;
    }

    if (in_array($order->get_status(), ['cancelled', 'refunded', 'failed'], true)) {
        wc_add_notice(__('An error occurred during payment. Try again or contact your administrator.', 'payme'), 'error');
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    if (WC()->cart) {
        WC()->cart->empty_cart();
    }

    wc_add_notice(__('Thank you for your purchase!', 'payme'), 'success');
    wp_safe_redirect($order->get_checkout_order_received_url());
    exit;
}
