<?php

if (!defined('ABSPATH')) {
    exit;
}

class NCX_CP_API_Gateway extends WC_Payment_Gateway {
    private const CHECKOUT_META_KEY = '_ncx_cp_checkout_id';
    private const SAVE_CARD_META_KEY = '_ncx_cp_should_save_card';
    private const CALLBACK_ACTION = 'ncx_cp_api_return';
    private const NOTIFICATION_ACTION = 'ncx_cp_api_notify';
    private const DUPE_CRON_HOOK = 'ncx_cp_api_dupe_check';
    private const SUCCESS_CODES = [
        '000.000.000',
        '000.000.100',
        '000.100.110',
        '000.100.111',
        '000.100.112',
    ];
    private const PENDING_CODES = [
        '000.200.000',
        '000.200.100',
    ];

    // ── Hardcoded (matches nochexapi pattern) ──────────────────────
    private const PAYMENT_TYPE          = 'DB';
    private const PAYMENT_BRANDS        = 'VISA MASTER';
    private const CREATE_REGISTRATION   = true;   // nochexapi: createRegistration = true
    private const INCLUDE_CART_DATA     = true;   // nochexapi: includeCartData    = true
    private const THREE_DS_ENABLED      = true;   // nochexapi: threeDv2           = true
    private const THREE_DS_WINDOW_DAYS  = 180;
    private const MERCHANT_COUNTRY      = 'GB';
    private const MERCHANT_CURRENCY     = 'GBP';

    // ── Hardcoded test credentials (same as nochexapi) ─────────────
    private const TEST_ENTITY_ID    = '8ac7a4ca7843f17d017844faa85f0829';
    private const TEST_ACCESS_TOKEN = 'OGFjN2E0Y2E3ODQzZjE3ZDAxNzg0NGY4MTFjNjA4MjR8V2hFMlB4WHdFcA';

    // ── Instance properties loaded from settings ──────────────────
    private string $region;
    private bool $test_mode;
    private string $display_mode;
    private string $primary_color;
    private string $accent_color;
    private bool $enable_dupe_check;
    private string $modal_cta_text;
    private string $inline_note_text;
    private string $inline_note_color;
    private string $inline_border_color;
    private string $inline_border_radius;
    private string $modal_backdrop_color;
    private bool $console_logging;
    private bool $server_logging;
    private array $log_levels;
    private ?WC_Logger $logger = null;

    public function __construct() {
        $this->id = 'ncx_cp_api';
        $this->method_title = __('NCX CopyAndPay', 'ncx-cp-api');
        $this->method_description = __('Accept credit and debit cards via OPP COPYandPAY.', 'ncx-cp-api');
        $this->has_fields = true;
        $this->order_button_text = __('Place order', 'ncx-cp-api');
        $this->supports = self::CREATE_REGISTRATION
            ? ['products', 'tokenization']
            : ['products'];

        $this->init_form_fields();
        $this->init_settings();
        $this->maybe_migrate_legacy_settings();
        $this->maybe_refresh_stale_defaults();

        $this->region = $this->get_option('region', 'eu');
        $this->test_mode = 'yes' === $this->get_option('test_mode', 'yes');
        $this->console_logging = 'yes' === $this->get_option('enable_console_log', 'no');
        $this->server_logging = 'yes' === $this->get_option('enable_server_log', 'no');
        $this->log_levels = (array) $this->get_option('log_levels', ['error', 'warning', 'info']);

        $this->enabled = $this->get_option('enabled', 'no');
        $this->title = $this->get_option('title', __('Pay with card', 'ncx-cp-api'));
        $this->description = $this->get_option('description', __('Checkout with Card', 'ncx-cp-api'));
        $this->display_mode = $this->get_option('display_mode', 'inline');
        $this->primary_color = $this->sanitize_color($this->get_option('primary_color', '#111827'));
        $this->accent_color = $this->sanitize_color($this->get_option('accent_color', '#F2F4F7'));
        $this->enable_dupe_check = 'yes' === $this->get_option('enable_dupe_check', 'no');
        $this->modal_cta_text = $this->get_option('modal_cta_text', __('Launch secure payment', 'ncx-cp-api'));
        $this->inline_note_text = $this->get_option('inline_note_text', __('Click “Place order” to load the secure card form without leaving this page.', 'ncx-cp-api'));
        $this->inline_note_color = $this->sanitize_color($this->get_option('inline_note_color', '#111827'));
        $this->inline_border_color = $this->sanitize_color($this->get_option('inline_border_color', '#E5E7EB'));
        $this->inline_border_radius = $this->sanitize_radius($this->get_option('inline_border_radius', '12px'));
        $this->modal_backdrop_color = $this->sanitize_color($this->get_option('modal_backdrop_color', '#0F172A'));

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_receipt_' . $this->id, [$this, 'render_receipt'], 10, 1);
        add_action('woocommerce_api_' . self::CALLBACK_ACTION, [$this, 'handle_result']);
        add_action('woocommerce_api_' . self::NOTIFICATION_ACTION, [$this, 'handle_notification']);
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_assets']);
        add_action('init', [$this, 'maybe_schedule_duplicate_guard']);
        add_action(self::DUPE_CRON_HOOK, [$this, 'run_duplicate_guard']);

        // Deregister token at OPP when customer deletes a saved card.
        add_action('woocommerce_payment_token_deleted', [$this, 'deregister_token_at_opp'], 10, 2);
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            // ── General ───────────────────────────────────────────────────
            'enabled' => [
                'title' => __('Enable/Disable', 'ncx-cp-api'),
                'type' => 'checkbox',
                'label' => __('Enable CopyAndPay', 'ncx-cp-api'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'ncx-cp-api'),
                'type' => 'text',
                'default' => __('Pay with card', 'ncx-cp-api'),
                'description' => __('This controls the title seen at checkout.', 'ncx-cp-api'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'ncx-cp-api'),
                'type' => 'text',
                'default' => __('Checkout with Card', 'ncx-cp-api'),
                'description' => __('This controls the description seen at checkout.', 'ncx-cp-api'),
                'desc_tip' => true,
            ],

            // ── Environment & Credentials ──────────────────────────────────
            'environment_heading' => [
                'title' => __('Environment & Credentials', 'ncx-cp-api'),
                'type' => 'title',
                'description' => __('Configure your OPP COPYandPAY environment and API credentials.', 'ncx-cp-api'),
            ],
            'test_mode' => [
                'title' => __('Current Endpoint mode', 'ncx-cp-api'),
                'type' => 'select',
                'default' => 'yes',
                'options' => [
                    'no'  => __('Live', 'ncx-cp-api'),
                    'yes' => __('Test', 'ncx-cp-api'),
                ],
            ],
            'region' => [
                'title' => __('Gateway Region', 'ncx-cp-api'),
                'type' => 'select',
                'default' => 'eu',
                'description' => __('Determines the OPP data-centre used for API calls. Currently GB (GBP) only.', 'ncx-cp-api'),
                'options' => [
                    'eu' => __('EU – United Kingdom / GBP', 'ncx-cp-api'),
                    // 'sa' => __('SA – South Africa', 'ncx-cp-api'),
                    // 'na' => __('NA – North America', 'ncx-cp-api'),
                    // 'ap' => __('AP – Asia-Pacific', 'ncx-cp-api'),
                ],
            ],
            'live_entity_id' => [
                'title' => __('Entity ID', 'ncx-cp-api') . ' <b style="color:green">(LIVE)</b>',
                'type' => 'text',
                'default' => '',
                'description' => __('Enabled channel for live card payments.', 'ncx-cp-api'),
                'desc_tip' => true,
            ],
            'live_access_token' => [
                'title' => __('Access Token', 'ncx-cp-api') . ' <b style="color:green">(LIVE)</b>',
                'type' => 'text',
                'default' => '',
            ],
            // Test credentials are hardcoded (matching nochexapi) — no admin fields needed.

            // ── Checkout Appearance ───────────────────────────────────────
            'appearance_heading' => [
                'title' => __('Checkout Appearance', 'ncx-cp-api'),
                'type' => 'title',
            ],
            'display_mode' => [
                'title' => __('Checkout layout', 'ncx-cp-api'),
                'type' => 'select',
                'description' => __('Choose how the COPYandPAY form is rendered on the order payment page.', 'ncx-cp-api'),
                'default' => 'inline',
                'options' => [
                    'inline' => __('Inline iframe (default)', 'ncx-cp-api'),
                    'modal' => __('Modal overlay', 'ncx-cp-api'),
                ],
            ],
            'modal_cta_text' => [
                'title' => __('Modal button label', 'ncx-cp-api'),
                'type' => 'text',
                'default' => __('Launch secure payment', 'ncx-cp-api'),
                'description' => __('Text used on the button that opens the modal experience.', 'ncx-cp-api'),
            ],
            'primary_color' => [
                'title' => __('Primary color', 'ncx-cp-api'),
                'type' => 'text',
                'default' => '#111827',
                'description' => __('Hex color applied to the widget header/CTA background.', 'ncx-cp-api'),
            ],
            'accent_color' => [
                'title' => __('Accent color', 'ncx-cp-api'),
                'type' => 'text',
                'default' => '#F2F4F7',
                'description' => __('Hex color for modal backdrop and borders.', 'ncx-cp-api'),
            ],
            'inline_note_text' => [
                'title' => __('Inline helper text', 'ncx-cp-api'),
                'type' => 'text',
                'default' => __('Click "Place order" to load the secure card form without leaving this page.', 'ncx-cp-api'),
                'description' => __('Displayed above the embedded COPYandPAY widget during checkout.', 'ncx-cp-api'),
            ],
            'inline_note_color' => [
                'title' => __('Inline note color', 'ncx-cp-api'),
                'type' => 'text',
                'default' => '#111827',
                'description' => __('Text color for the inline helper note.', 'ncx-cp-api'),
            ],
            'inline_border_color' => [
                'title' => __('Inline border color', 'ncx-cp-api'),
                'type' => 'text',
                'default' => '#E5E7EB',
                'description' => __('Border color surrounding the inline widget container.', 'ncx-cp-api'),
            ],
            'inline_border_radius' => [
                'title' => __('Inline border radius', 'ncx-cp-api'),
                'type' => 'text',
                'default' => '12px',
                'description' => __('Any valid CSS length (e.g. 12px, 0.75rem).', 'ncx-cp-api'),
            ],
            'modal_backdrop_color' => [
                'title' => __('Modal backdrop color', 'ncx-cp-api'),
                'type' => 'text',
                'default' => '#0F172A',
                'description' => __('Overlay color behind the modal widget.', 'ncx-cp-api'),
            ],

            // ── Advanced ──────────────────────────────────────────────────
            'advanced_heading' => [
                'title' => __('Advanced', 'ncx-cp-api'),
                'type' => 'title',
            ],
            'enable_dupe_check' => [
                'title' => __('Duplicate payment monitor', 'ncx-cp-api'),
                'type' => 'checkbox',
                'label' => __('Run an hourly task that warns about back-to-back attempts with the same cart.', 'ncx-cp-api'),
                'default' => 'no',
            ],

            // ── Logging & Diagnostics ─────────────────────────────────────
            'logging_heading' => [
                'title' => __('Logging & Diagnostics', 'ncx-cp-api'),
                'type' => 'title',
            ],
            'enable_console_log' => [
                'title' => __('Console logging', 'ncx-cp-api'),
                'type' => 'checkbox',
                'label' => __('Emit COPYandPAY events to the browser console (only enable for debugging).', 'ncx-cp-api'),
                'default' => 'no',
            ],
            'enable_server_log' => [
                'title' => __('Server logging', 'ncx-cp-api'),
                'type' => 'checkbox',
                'label' => __('Write gateway events to WooCommerce logs.', 'ncx-cp-api'),
                'default' => 'no',
            ],
            'log_levels' => [
                'title' => __('Log levels', 'ncx-cp-api'),
                'type' => 'multiselect',
                'description' => __('Only events matching one of the selected severities will be persisted.', 'ncx-cp-api'),
                'default' => ['emergency', 'critical', 'error', 'warning'],
                'options' => [
                    'critical'  => __('Critical', 'ncx-cp-api'),
                    'debug'     => __('Debugging', 'ncx-cp-api'),
                    'emergency' => __('Emergency', 'ncx-cp-api'),
                    'error'     => __('Error', 'ncx-cp-api'),
                    'info'      => __('Information', 'ncx-cp-api'),
                    'warning'   => __('Warning', 'ncx-cp-api'),
                ],
            ],
        ];
    }

    public function payment_fields() {
        if (!empty($this->description)) {
            echo wpautop(wp_kses_post($this->description));
        }

        // Remove payment_box padding to match nochexapi.
        echo '<style type="text/css">li.payment_method_' . esc_attr($this->id) . ' div.payment_box {padding: 0!important;}</style>';

        // Hidden field to carry the pre-created checkout ID into WooCommerce's form POST.
        echo '<input type="hidden" id="ncx_cp_checkout_id" name="ncx_cp_checkout_id" value="">';

        // Container where the OPP COPYandPAY widget will be mounted immediately via JS.
        echo '<div id="ncx-cp-inline-wrapper" class="ncx-cp-inline-wrapper">';
        echo '<div id="ncx-cp-inline-frame" class="ncx-cp-inline-frame"><p class="ncx-cp-inline-note">' . esc_html__( 'Loading secure card form…', 'ncx-cp-api' ) . '</p></div>';
        echo '</div>';

        // Debug: confirm the localized settings will be output, and tell user to check console.
        echo '<!-- NCX debug: payment_fields rendered, JS handle=ncx-cp-api-inline -->';
        echo '<script>console.log("NCX: payment_fields() HTML rendered on server");</script>';
    }

    public function is_available(): bool {
        if ('yes' !== $this->enabled || !parent::is_available()) {
            return false;
        }

        // Enforce accepted store currency (GB / GBP only for now).
        if (function_exists('get_woocommerce_currency') && get_woocommerce_currency() !== self::MERCHANT_CURRENCY) {
            return false;
        }

        $credentials = $this->get_active_credentials();

        return !empty($credentials['entity_id']) && !empty($credentials['access_token']);
    }

    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['result' => 'failure'];
        }

        // Phase 2 of nochexapi-inspired flow:
        // The checkout ID was pre-created from cart data (Phase 1 = AJAX).
        // Now we bind the real order data to that checkout via updateTransactionData.
        $checkout_id = isset($_POST['ncx_cp_checkout_id']) ? sanitize_text_field(wp_unslash($_POST['ncx_cp_checkout_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ('' === $checkout_id) {
            // Fallback: no pre-created ID (e.g. order-pay page). Create one from the order.
            $session = $this->request_checkout_session($order);
            if (is_wp_error($session)) {
                wc_add_notice($session->get_error_message(), 'error');
                return ['result' => 'failure'];
            }
            $checkout_id = $session['id'];
        } else {
            // Update the existing checkout with order data (shopperResultUrl, customer, billing, etc.).
            $update = $this->update_checkout_data($checkout_id, $order);
            if (is_wp_error($update)) {
                wc_add_notice($update->get_error_message(), 'error');
                return ['result' => 'failure'];
            }
        }

        $order->update_meta_data(self::CHECKOUT_META_KEY, $checkout_id);
        if (self::CREATE_REGISTRATION && $this->should_save_payment_method()) {
            $order->update_meta_data(self::SAVE_CARD_META_KEY, 'yes');
        }
        $order->save();

        // Tell JS to execute the already-mounted widget (card fields are already filled).
        // redirect = order-pay URL as safety net: if JS ever fails to intercept,
        // WC will send the customer here instead of to an empty string (which
        // caused nginx to 404 on /checkout/index.html).
        return [
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
            'payment_method' => $this->id,
            'checkout_id' => $checkout_id,
            'execute' => true,
            'order_id' => $order_id,
            'order_key' => $order->get_order_key(),
        ];
    }

    public function render_receipt(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            echo '<p>' . esc_html__('Unable to load order.', 'ncx-cp-api') . '</p>';
            return;
        }

        $checkout_id = (string) $order->get_meta(self::CHECKOUT_META_KEY, true);
        if ('' === $checkout_id) {
            $session = $this->request_checkout_session($order);
            if (is_wp_error($session)) {
                echo '<p>' . esc_html($session->get_error_message()) . '</p>';
                return;
            }
            $checkout_id = $session['id'];
            $order->update_meta_data(self::CHECKOUT_META_KEY, $checkout_id);
            $order->save();
        }

        if ($this->display_mode === 'modal') {
            $this->render_modal_trigger($order, $checkout_id);
        } else {
            echo '<p>' . esc_html__('Complete your payment below. The order will update automatically once confirmed.', 'ncx-cp-api') . '</p>';
            echo NCX_CP_API::render_widget_markup($checkout_id, self::PAYMENT_BRANDS); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    public function handle_result(): void {
        $order_id = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_key = isset($_GET['order_key']) ? sanitize_text_field(wp_unslash($_GET['order_key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $resource_path = isset($_GET['resourcePath']) ? sanitize_text_field(wp_unslash($_GET['resourcePath'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order = $order_id ? wc_get_order($order_id) : false;

        if (!$order || $order->get_order_key() !== $order_key) {
            wc_add_notice(__('Unable to match the order for this payment.', 'ncx-cp-api'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        // Idempotency guard — if the notification already finalised this order, just redirect.
        if (!in_array($order->get_status(), ['pending', 'on-hold', 'checkout-draft'], true)) {
            if ($order->is_paid()) {
                wp_safe_redirect($order->get_checkout_order_received_url());
            } else {
                wp_safe_redirect($order->get_checkout_payment_url());
            }
            exit;
        }

        if ('' === $resource_path) {
            $order->update_status('failed', __('Missing payment reference from COPYandPAY.', 'ncx-cp-api'));
            wc_add_notice(__('Payment was cancelled or failed before completion.', 'ncx-cp-api'), 'error');
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }

        $payment = $this->fetch_payment_details($resource_path);
        if (is_wp_error($payment)) {
            $order->update_status('failed', $payment->get_error_message());
            wc_add_notice($payment->get_error_message(), 'error');
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }

        // Verify payment data matches the order (amount, currency, cart_hash, order_key).
        $verification = $this->verify_payment_against_order($order, $payment);
        if (is_wp_error($verification)) {
            $order->update_status('failed', $verification->get_error_message());
            wc_add_notice(__('Payment verification failed. Please contact support.', 'ncx-cp-api'), 'error');
            $this->log_event('error', 'Payment verification failed on callback', [
                'order_id' => $order_id,
                'reason'   => $verification->get_error_message(),
            ]);
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }

        $state = $this->determine_state($payment);
        $result_code = (string) ($payment['result']['code'] ?? '');
        $transaction_id = (string) ($payment['id'] ?? '');

        if ('success' === $state) {
            $order->payment_complete($transaction_id);
            $order->add_order_note(sprintf(__('COPYandPAY approved (%s).', 'ncx-cp-api'), $result_code));
            if (self::CREATE_REGISTRATION && 'yes' === $order->get_meta(self::SAVE_CARD_META_KEY, true)) {
                $this->maybe_tokenize_card($order, $payment);
            }
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        if ('pending' === $state) {
            $order->update_status('on-hold', sprintf(__('COPYandPAY pending (%s).', 'ncx-cp-api'), $result_code));
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        $order->update_status('failed', sprintf(__('COPYandPAY declined (%s).', 'ncx-cp-api'), $result_code));
        wc_add_notice(__('Payment was declined. Please try another method.', 'ncx-cp-api'), 'error');
        wp_safe_redirect($order->get_checkout_payment_url());
        exit;
    }

    /**
     * Server-to-server notification handler (notificationUrl).
     *
     * OPP sends this asynchronously — even if the customer closes the browser.
     * We fetch the payment details and finalise the order if it is still pending.
     */
    public function handle_notification(): void {
        $order_id   = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_key  = isset($_GET['order_key']) ? sanitize_text_field(wp_unslash($_GET['order_key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $order = $order_id ? wc_get_order($order_id) : false;
        if (!$order || $order->get_order_key() !== $order_key) {
            wp_send_json(['status' => 'invalid_order'], 400);
            return;
        }

        // Only process if the order has not already been finalised.
        if (!in_array($order->get_status(), ['pending', 'on-hold'], true)) {
            wp_send_json(['status' => 'already_processed']);
            return;
        }

        // Read the resourcePath POSTed by OPP.
        $resource_path = isset($_POST['resourcePath']) ? sanitize_text_field(wp_unslash($_POST['resourcePath'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ('' === $resource_path) {
            wp_send_json(['status' => 'missing_resource_path'], 400);
            return;
        }

        $payment = $this->fetch_payment_details($resource_path);
        if (is_wp_error($payment)) {
            $this->log_event('error', 'Notification fetch failed', [
                'order_id' => $order_id,
                'error'    => $payment->get_error_message(),
            ]);
            wp_send_json(['status' => 'fetch_failed'], 502);
            return;
        }

        // Verify payment data matches the order.
        $verification = $this->verify_payment_against_order($order, $payment);
        if (is_wp_error($verification)) {
            $this->log_event('error', 'Notification payment verification failed', [
                'order_id' => $order_id,
                'reason'   => $verification->get_error_message(),
            ]);
            wp_send_json(['status' => 'verification_failed'], 400);
            return;
        }

        $state          = $this->determine_state($payment);
        $result_code    = (string) ($payment['result']['code'] ?? '');
        $transaction_id = (string) ($payment['id'] ?? '');

        if ('success' === $state) {
            $order->payment_complete($transaction_id);
            $order->add_order_note(sprintf(__('COPYandPAY approved via notification (%s).', 'ncx-cp-api'), $result_code));
            if (self::CREATE_REGISTRATION && 'yes' === $order->get_meta(self::SAVE_CARD_META_KEY, true)) {
                $this->maybe_tokenize_card($order, $payment);
            }
        } elseif ('pending' === $state) {
            $order->update_status('on-hold', sprintf(__('COPYandPAY pending via notification (%s).', 'ncx-cp-api'), $result_code));
        } else {
            $order->update_status('failed', sprintf(__('COPYandPAY declined via notification (%s).', 'ncx-cp-api'), $result_code));
        }

        $this->log_event('info', 'Notification processed', [
            'order_id' => $order_id,
            'state'    => $state,
            'code'     => $result_code,
        ]);

        wp_send_json(['status' => 'ok']);
    }

    private function request_checkout_session(WC_Order $order) {
        $credentials = $this->get_active_credentials();
        if (!$this->credentials_present($credentials)) {
            return new WP_Error('ncx_cp_missing_creds', __('CopyAndPay credentials are missing.', 'ncx-cp-api'));
        }

        $callback = add_query_arg(
            [
                'order_id' => $order->get_id(),
                'order_key' => $order->get_order_key(),
            ],
            WC()->api_request_url(self::CALLBACK_ACTION)
        );

        $body = [
            'entityId' => $credentials['entity_id'],
            'amount' => $this->format_amount($order->get_total()),
            'currency' => $order->get_currency(),
            'paymentType' => self::PAYMENT_TYPE,
            'merchantCountry' => self::MERCHANT_COUNTRY,
            'merchantTransactionId' => $order->get_id() . '-' . substr(md5($order->get_order_key() . wp_rand()), 0, 8),
            'shopperResultUrl' => $callback,
            'notificationUrl'  => add_query_arg(
                [
                    'order_id'  => $order->get_id(),
                    'order_key' => $order->get_order_key(),
                ],
                WC()->api_request_url(self::NOTIFICATION_ACTION)
            ),
            'customer.email' => $order->get_billing_email(),
            'customer.givenName' => $order->get_billing_first_name(),
            'customer.surname' => $order->get_billing_last_name(),
            'customer.ip' => $order->get_customer_ip_address(),
            'customer.browserFingerprint.value' => $order->get_customer_user_agent(),
            'card.holder' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
        ];

        $phone = $order->get_billing_phone();
        if (!empty($phone)) {
            $body['customer.mobile'] = $phone;
        }
        if ($order->get_user_id()) {
            $body['customer.merchantCustomerId'] = (string) $order->get_user_id();
        }

        $this->add_address_fields($body, 'billing', $order);
        if ($order->has_shipping_address()) {
            $this->add_address_fields($body, 'shipping', $order);
        }

        $body['cart.items[0].name'] = $this->get_cart_description($order);
        $body['customParameters[SHOPPER_amount]']    = $this->format_amount($order->get_total());
        $body['customParameters[SHOPPER_currency]']  = $order->get_currency();
        $body['customParameters[SHOPPER_order_key]'] = $order->get_order_key();
        $body['customParameters[SHOPPER_cart_hash]']  = $order->get_cart_hash();
        $body['customParameters[SHOPPER_platform]']  = 'WooCommerce';
        $body['customParameters[SHOPPER_plugin]']    = NCX_CP_API::VERSION;

        if (self::CREATE_REGISTRATION && $this->should_save_payment_method()) {
            $body['createRegistration'] = 'true';
            $body['standingInstruction.source'] = 'CIT';
            $body['standingInstruction.mode']   = 'INITIAL';
            $body['standingInstruction.type']   = 'UNSCHEDULED';
        }

        foreach ($this->build_three_ds_parameters($order) as $key => $value) {
            $body["customParameters[$key]"] = $value;
        }

        return $this->post_to_checkouts($credentials, $body);
    }

    /**
     * AJAX handler: create a checkout session from the current cart (Phase 1).
     * Mirrors nochexapi\'s genCheckoutIdOrder() \u2013 card fields appear immediately.
     */
    public function ajax_request_checkout_id(): void {
        check_ajax_referer('ncx_cp_checkout_nonce', 'security');

        if (!WC()->cart || WC()->cart->is_empty()) {
            wp_send_json_error(['message' => 'Cart is empty']);
            return;
        }

        $credentials = $this->get_active_credentials();
        if (!$this->credentials_present($credentials)) {
            wp_send_json_error(['message' => 'Credentials missing']);
            return;
        }

        $amount = WC()->cart->get_total('edit');
        if ((float) $amount <= 0) {
            wp_send_json_error(['message' => 'Cart total is zero']);
            return;
        }

        // Match nochexapi's createCheckoutArray: minimal payload for Phase 1.
        $body = [
            'entityId'    => $credentials['entity_id'],
            'paymentType' => self::PAYMENT_TYPE,
            'amount'      => number_format((float) $amount, 2, '.', ''),
            'currency'    => get_woocommerce_currency(),
        ];

        // Only send createRegistration for logged-in users (matches nochexapi).
        if (self::CREATE_REGISTRATION && is_user_logged_in()) {
            $body['createRegistration'] = 'true';
            $body['standingInstruction.mode']   = 'INITIAL';
            $body['standingInstruction.type']   = 'UNSCHEDULED';
            $body['standingInstruction.source'] = 'CIT';

            // Append existing saved-card registration IDs so OPP shows them
            // in the widget (matches nochexapi's createCheckoutArray L697-L707).
            $tokens = WC_Payment_Tokens::get_customer_tokens(get_current_user_id(), $this->id);
            $idx = 0;
            foreach ($tokens as $token) {
                $body['registrations[' . $idx . '].id'] = $token->get_token();
                $idx++;
            }
        }

        $result = $this->post_to_checkouts($credentials, $body);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        wp_send_json_success(['checkoutId' => $result['id']]);
    }

    /**
     * Phase 2: bind order data to an existing checkout ID.
     * POST to /v1/checkouts/{id} (mirrors nochexapi\'s updateTransactionData).
     */
    private function update_checkout_data(string $checkout_id, WC_Order $order) {
        $credentials = $this->get_active_credentials();
        if (!$this->credentials_present($credentials)) {
            return new WP_Error('ncx_cp_missing_creds', __('CopyAndPay credentials are missing.', 'ncx-cp-api'));
        }

        $region_host = $this->get_region_host();
        $callback = add_query_arg(
            [
                'order_id'  => $order->get_id(),
                'order_key' => $order->get_order_key(),
            ],
            WC()->api_request_url(self::CALLBACK_ACTION)
        );

        $body = [
            'entityId' => $credentials['entity_id'],
            'paymentType' => self::PAYMENT_TYPE,
            'amount' => $this->format_amount($order->get_total()),
            'currency' => $order->get_currency(),
            'merchantTransactionId' => (string) $order->get_id(),
            'shopperResultUrl' => $callback,
            'notificationUrl'  => add_query_arg(
                [
                    'order_id'  => $order->get_id(),
                    'order_key' => $order->get_order_key(),
                ],
                WC()->api_request_url(self::NOTIFICATION_ACTION)
            ),
            'customer.email' => $order->get_billing_email(),
            'customer.givenName' => $order->get_billing_first_name(),
            'customer.surname' => $order->get_billing_last_name(),
            'customer.ip' => $order->get_customer_ip_address(),
            'customer.browserFingerprint.value' => $order->get_customer_user_agent(),
            'card.holder' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'customer.merchantCustomerId' => (string) ($order->get_user_id() ?: 0),
            'customParameters[SHOPPER_amount]'    => $this->format_amount($order->get_total()),
            'customParameters[SHOPPER_currency]'  => $order->get_currency(),
            'customParameters[SHOPPER_order_key]' => $order->get_order_key(),
            'customParameters[SHOPPER_cart_hash]'  => $order->get_cart_hash(),
            'customParameters[SHOPPER_platform]'  => 'WooCommerce',
            'customParameters[SHOPPER_plugin]'    => NCX_CP_API::VERSION,
        ];

        $phone = $order->get_billing_phone();
        if (!empty($phone)) {
            $body['customer.mobile'] = $phone;
        }

        $this->add_address_fields($body, 'billing', $order);
        if ($order->has_shipping_address()) {
            $this->add_address_fields($body, 'shipping', $order);
        }

        $body['cart.items[0].name'] = $this->get_cart_description($order);

        foreach ($this->build_three_ds_parameters($order) as $key => $value) {
            $body["customParameters[$key]"] = $value;
        }

        $response = wp_remote_post(
            $region_host . '/v1/checkouts/' . rawurlencode($checkout_id),
            [
                'timeout' => 60,
                'headers' => $this->build_auth_headers($credentials),
                'body'    => $body,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $code = $data['result']['code'] ?? '';

        if ('000.200.101' !== $code) {
            $desc = $data['result']['description'] ?? 'Failed to update checkout';
            $this->log_event('error', 'update_checkout_data failed: ' . $code . ' - ' . $desc);
            return new WP_Error('ncx_cp_update_failed', $desc);
        }

        return $data;
    }

    /**
     * Common helper: POST to /v1/checkouts and return the parsed response.
     */
    private function post_to_checkouts(array $credentials, array $body) {
        $region_host = $this->get_region_host();

        $response = wp_remote_post(
            $region_host . '/v1/checkouts',
            [
                'timeout' => 60,
                'headers' => $this->build_auth_headers($credentials),
                'body'    => $body,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $raw_body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);
        $data = json_decode($raw_body, true);

        if (!isset($data['id'])) {
            $code = $data['result']['code'] ?? 'unknown';
            $desc = $data['result']['description'] ?? 'No checkout ID returned';
            $this->log_event('error', 'Checkout session failed: ' . $code . ' - ' . $desc . ' (HTTP ' . $http_code . ')');
            // Return the actual OPP error so it's visible in the frontend for debugging.
            return new WP_Error('ncx_cp_no_checkout', $code . ': ' . $desc);
        }

        return $data;
    }

    private function fetch_payment_details(string $resource_path) {
        $credentials = $this->get_active_credentials();
        if (!$this->credentials_present($credentials)) {
            return new WP_Error('ncx_cp_missing_creds', __('CopyAndPay credentials are missing. Save them in the plugin settings.', 'ncx-cp-api'));
        }

        $region_host = $this->get_region_host();
        $normalized_path = '/' . ltrim($resource_path, '/');
        $url = $region_host . $normalized_path;
        $url = add_query_arg('entityId', $credentials['entity_id'], $url);

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 60,
                'headers' => $this->build_auth_headers($credentials),
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['result']['code'])) {
            return new WP_Error('ncx_cp_invalid_result', __('Invalid response from COPYandPAY.', 'ncx-cp-api'));
        }

        return $data;
    }

    private function build_auth_headers(array $settings): array {
        return [
            'Content-Type'  => 'application/x-www-form-urlencoded; charset=UTF-8',
            'Authorization' => 'Bearer ' . $settings['access_token'],
        ];
    }

    /**
     * Deregister a stored card token at OPP when the customer deletes it in WooCommerce.
     * Mirrors nochexapi's tp_card_deregistration approach.
     */
    public function deregister_token_at_opp(int $token_id, WC_Payment_Token $token): void {
        if ($token->get_gateway_id() !== $this->id) {
            return;
        }

        $credentials = $this->get_active_credentials();
        if (!$this->credentials_present($credentials)) {
            return;
        }

        $region_host = $this->get_region_host();
        $url = $region_host . '/v1/registrations/' . rawurlencode($token->get_token());
        $url = add_query_arg('entityId', $credentials['entity_id'], $url);

        $response = wp_remote_request(
            $url,
            [
                'method'  => 'DELETE',
                'timeout' => 30,
                'headers' => $this->build_auth_headers($credentials),
            ]
        );

        if (is_wp_error($response)) {
            $this->log_event('error', 'Token deregistration failed', [
                'token_id' => $token_id,
                'error'    => $response->get_error_message(),
            ]);
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $code = (string) ($data['result']['code'] ?? '');

        if (in_array($code, ['000.000.000', '000.100.110'], true)) {
            $this->log_event('info', 'Token deregistered at OPP', ['token_id' => $token_id]);
        } else {
            $this->log_event('warning', 'Token deregistration returned unexpected code', [
                'token_id' => $token_id,
                'code'     => $code,
            ]);
        }
    }

    private function determine_state(array $payload): string {
        $code = (string) ($payload['result']['code'] ?? '');

        if (in_array($code, self::SUCCESS_CODES, true)) {
            return 'success';
        }

        if (in_array($code, self::PENDING_CODES, true)) {
            return 'pending';
        }

        return 'failure';
    }

    /**
     * Server-side verification of the payment response against order data.
     *
     * Mirrors nochexapi's parseResponseData checks: amount, currency, order_key, cart_hash.
     * The expected values were sent as customParameters during checkout session creation.
     *
     * @return true|WP_Error
     */
    private function verify_payment_against_order(WC_Order $order, array $payment) {
        $custom = $payment['customParameters'] ?? [];

        // Amount check.
        $expected_amount = $this->format_amount($order->get_total());
        $response_amount = (string) ($custom['SHOPPER_amount'] ?? '');
        if ('' !== $response_amount && $expected_amount !== $response_amount) {
            return new WP_Error('ncx_cp_amount_mismatch', sprintf(
                'Amount mismatch: expected %s, got %s',
                $expected_amount,
                $response_amount
            ));
        }

        // Currency check.
        $response_currency = (string) ($custom['SHOPPER_currency'] ?? '');
        if ('' !== $response_currency && $order->get_currency() !== $response_currency) {
            return new WP_Error('ncx_cp_currency_mismatch', sprintf(
                'Currency mismatch: expected %s, got %s',
                $order->get_currency(),
                $response_currency
            ));
        }

        // Order key check.
        $response_key = (string) ($custom['SHOPPER_order_key'] ?? '');
        if ('' !== $response_key && $order->get_order_key() !== $response_key) {
            return new WP_Error('ncx_cp_key_mismatch', 'Order key mismatch');
        }

        // Cart hash check.
        $response_hash = (string) ($custom['SHOPPER_cart_hash'] ?? '');
        if ('' !== $response_hash && $order->get_cart_hash() !== $response_hash) {
            return new WP_Error('ncx_cp_hash_mismatch', 'Cart hash mismatch — cart contents may have changed');
        }

        return true;
    }

    private function credentials_present(array $settings): bool {
        return !empty($settings['entity_id']) && !empty($settings['access_token']);
    }

    private function format_amount($amount): string {
        return wc_format_decimal($amount, wc_get_price_decimals(), false);
    }

    /**
     * Append address fields to the request body, skipping empty values (matching nochexapi approach).
     */
    private function add_address_fields(array &$body, string $type, WC_Order $order): void {
        $map = [
            'street1'  => 'get_' . $type . '_address_1',
            'street2'  => 'get_' . $type . '_address_2',
            'city'     => 'get_' . $type . '_city',
            'state'    => 'get_' . $type . '_state',
            'postcode' => 'get_' . $type . '_postcode',
            'country'  => 'get_' . $type . '_country',
        ];
        foreach ($map as $field => $method) {
            $value = $order->$method();
            if (!empty($value)) {
                $body["$type.$field"] = $value;
            }
        }
    }

    /**
     * Build a cart description string for the payload (mirrors nochexapi's getCartItemsOrderData).
     */
    private function get_cart_description(WC_Order $order): string {
        $parts = [];
        foreach ($order->get_items('line_item') as $item) {
            $parts[] = $item->get_name() . ' - ' . $item->get_quantity() . ' x ' . $item->get_total();
        }
        return mb_substr(implode(', ', $parts), 0, 255);
    }

    private function get_region_host(): string {
        $suffix = $this->test_mode ? '-test' : '-prod';
        return sprintf('https://%s%s.oppwa.com', $this->region, $suffix);
    }

    private function get_active_credentials(): array {
        if ($this->test_mode) {
            return [
                'entity_id'    => self::TEST_ENTITY_ID,
                'access_token' => self::TEST_ACCESS_TOKEN,
            ];
        }

        return [
            'entity_id'    => $this->get_option('live_entity_id', ''),
            'access_token' => $this->get_option('live_access_token', ''),
        ];
    }

    private function is_log_level_enabled(string $level): bool {
        if (!$this->server_logging) {
            return false;
        }
        return in_array(strtolower($level), $this->log_levels, true);
    }

    /**
     * One-time migration of legacy settings from the separate options page
     * (wp_options key "ncx_cp_api_settings") into the WooCommerce gateway settings.
     */
    private function maybe_migrate_legacy_settings(): void {
        $legacy = get_option('ncx_cp_api_settings');
        if (!is_array($legacy) || empty($legacy)) {
            return;
        }

        $keys = [
            'region', 'test_mode',
            'live_entity_id', 'live_access_token',
            'enable_console_log', 'enable_server_log', 'log_levels',
        ];

        $changed = false;
        foreach ($keys as $key) {
            if (!isset($legacy[$key])) {
                continue;
            }
            // Only migrate if the gateway setting is still at its default/empty value.
            $current = $this->get_option($key, '');
            if (is_array($current) ? !empty($current) : '' !== $current) {
                continue;
            }

            $value = $legacy[$key];
            // Convert booleans to WooCommerce 'yes'/'no' format.
            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }
            $this->settings[$key] = $value;
            $changed = true;
        }

        if ($changed) {
            update_option(
                $this->get_option_key(),
                apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings),
                'yes'
            );
        }
        // Remove the legacy option regardless — prevents re-running migration.
        delete_option('ncx_cp_api_settings');
    }

    /**
     * One-time reset of title / description when they still contain old defaults.
     */
    private function maybe_refresh_stale_defaults(): void {
        $stale = [
            'title' => [
                'old' => ['Credit / Debit Card', 'Credit/Debit Card'],
                'new' => 'Pay with card',
            ],
            'description' => [
                'old' => ['Pay securely via COPYandPAY.', 'Pay securely through COPYandPAY.'],
                'new' => 'Checkout with Card',
            ],
        ];

        $changed = false;
        foreach ($stale as $key => $map) {
            $current = $this->get_option($key, '');
            if (in_array($current, $map['old'], true)) {
                $this->settings[$key] = $map['new'];
                $changed = true;
            }
        }

        if ($changed) {
            update_option(
                $this->get_option_key(),
                apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings),
                'yes'
            );
        }
    }

    public function maybe_enqueue_assets(): void {
        if (!is_checkout() && !is_wc_endpoint_url('order-pay')) {
            return;
        }

        $style_handle = 'ncx-cp-api-inline-style';
        if (!wp_style_is($style_handle, 'enqueued')) {
            wp_register_style($style_handle, false);
            wp_enqueue_style($style_handle);
        }

        $css = sprintf(
            '.ncx-cp-modal__dialog{border:1px solid %1$s;background:%2$s;padding:1.5rem;max-width:520px;width:90%%;border-radius:12px;position:relative;box-shadow:0 25px 50px rgba(15,23,42,0.35);} '
            .'.ncx-cp-modal__backdrop{position:fixed;top:0;left:0;width:100vw;height:100vh;background:%6$s;z-index:9998;} '
            .'.ncx-cp-modal{position:fixed;top:0;left:0;width:100vw;height:100vh;display:flex;align-items:center;justify-content:center;z-index:9999;} '
            .'.ncx-cp-modal[hidden]{display:none;} '
            .'.ncx-cp-modal__close{position:absolute;top:0.5rem;right:0.5rem;background:transparent;border:none;font-size:1.5rem;color:%1$s;cursor:pointer;} '
            .'.ncx-cp-modal__open{background:%1$s!important;color:#fff!important;border:none!important;} '
            .'.ncx-cp-modal__open:hover{opacity:.9;} '
            .'.ncx-cp-inline-wrapper{border:1px solid %3$s;border-radius:%4$s;background:%2$s;padding:1.25rem;margin-top:1rem;} '
            .'.ncx-cp-inline-note{color:%5$s;margin:0 0 .75rem;font-size:.95rem;} '
            .'.ncx-cp-inline-frame{min-height:120px;} '
            .'.ncx-cp-inline-reset{margin-top:.75rem;} '
            .'body.ncx-cp-modal-lock{overflow:hidden;}',
            esc_attr($this->primary_color),
            esc_attr($this->accent_color),
            esc_attr($this->inline_border_color),
            esc_attr($this->inline_border_radius),
            esc_attr($this->inline_note_color),
            esc_attr($this->hex_to_rgba($this->modal_backdrop_color, 0.7))
        );

        // OPP COPYandPAY widget overrides (matches nochexapi cardsv2-style.css).
        $css .= ' '
            // Hide brand selector, card-holder row, and built-in submit button.
            .'.wpwl-group-brand,.wpwl-group-cardHolder,.wpwl-group-submit{display:none!important;} '
            // Full-width controls, no floats.
            .'.wpwl-control-cardNumber,.wpwl-control-expiry,.wpwl-control-cvv,.wpwl-control-cardHolder{width:100%!important;} '
            .'.wpwl-wrapper-cardNumber,.wpwl-wrapper-expiry,.wpwl-wrapper-cvv,.wpwl-wrapper-cardHolder{float:none!important;width:100%!important;position:unset!important;} '
            // Form resets.
            .'.wpwl-form{max-width:100%;margin:0;padding:0;} '
            .'.wpwl-form-has-inputs{padding:0!important;border:none!important;background-color:transparent!important;border-radius:0!important;box-shadow:none!important;} '
            // Control styling to match nochexapi.
            .'.wpwl-control-expiry,.wpwl-control-cardHolder{color:#545454!important;font-family:"Helvetica Neue",Helvetica,Arial,sans-serif!important;height:38px!important;border:1px solid #CCC!important;background:transparent!important;margin-bottom:1px!important;border-radius:0!important;box-shadow:none!important;-webkit-appearance:none!important;-moz-appearance:none!important;appearance:none!important;} '
            // Row layout.
            .'form.wpwl-form .form-row-first{float:left;width:45%!important;} '
            .'form.wpwl-form .form-row-last{float:right;width:45%!important;} '
            .'form.wpwl-form .form-row-wide{margin-bottom:1.75rem;width:100%!important;} '
            // Placeholder styling.
            .'form.wpwl-form .form-row input::placeholder{color:#CCC;font-size:14px;font-family:"Helvetica Neue",Helvetica,Arial,sans-serif;} '
            .'input.wpwl-control-expiry::placeholder{color:#CCC!important;font-size:14px!important;} '
            // Hint text.
            .'div.wpwl-hint{font-family:"Helvetica Neue",Helvetica,Arial,sans-serif;font-size:smaller!important;text-align:left!important;} '
            .'.wpwl-control{text-align:left;} '
            // Registration / saved-card styling.
            .'.wpwl-wrapper-registration-registrationId,.wpwl-wrapper-registration-brand{padding-right:4px!important;} '
            .'.wpwl-wrapper-registration-details{padding-right:10px!important;width:unset;margin-bottom:0;} '
            .'.wpwl-group-registration{border:none;margin-bottom:0;} '
            .'.wpwl-group-registration.wpwl-selected{border-color:#CCC!important;border:1px solid #CCC!important;border-radius:1px!important;} '
            .'.wpwl-group-registration.wpwl-selected label{color:#333!important;} '
            .'.wpwl-wrapper-registration-registrationId{width:unset!important;} '
            .'.wpwl-wrapper-registration-cvv{float:right!important;} '
            .'label.wpwl-registration{line-height:36px;} '
            .'div.wpwl-wrapper-registration-cvv{line-height:20px;} '
            .'div.wpwl-wrapper-registration .wpwl-control-cvv{margin-top:5px;} '
            .'div.wpwl-group{width:unset;} '
            .'div#wpwl-registrations{display:block;} '
            .'div.wpwl-group-registration{font-size:small!important;} '
            .'.wpwl-group-registration.wpwl-selected{border:none!important;} '
            .'div.wpwl-container{margin-bottom:0.5rem;} '
            // Custom labels (injected by JS, matching nochexapi's nochexapiFrameLabel).
            .'label.ncx-cp-frame-label{color:#333;font-size:11px;display:block;text-align:left;line-height:140%%;margin-bottom:3px;max-width:100%%;font-family:"Helvetica Neue",Helvetica,Arial,sans-serif;} '
            .'span.ncx-cp-required{color:#F00;} '
            // Dynamic brand icon (matches nochexapi wpwlDynBrand).
            .'#wpwlDynBrand{width:72px;padding:8px;position:absolute;right:0;top:18px;} '
            .'#wpwlDynBrandImg{border-radius:unset;height:-webkit-fill-available;margin:0!important;float:right;max-height:22px;} '
            // Save-card checkbox.
            .'.ncx-cp-save-card{margin-top:0.5rem;}';

        wp_add_inline_style($style_handle, $css);

        if (is_checkout()) {
            $script_handle = 'ncx-cp-api-inline';
            wp_enqueue_script(
                $script_handle,
                plugins_url('../assets/js/checkout-inline.js', __FILE__),
                ['jquery'],
                NCX_CP_API::VERSION,
                true
            );

            wp_localize_script(
                $script_handle,
                'ncxCpInline',
                [
                    'ajaxUrl'            => admin_url('admin-ajax.php'),
                    'nonce'              => wp_create_nonce('ncx_cp_checkout_nonce'),
                    'regionHost'         => $this->get_region_host(),
                    'brands'             => self::PAYMENT_BRANDS,
                    'displayMode'        => $this->display_mode,
                    'gatewayId'          => $this->id,
                    'createRegistration' => self::CREATE_REGISTRATION ? '1' : '0',
                    'loggedIn'           => is_user_logged_in() ? '1' : '0',
                ]
            );
        }

        if ($this->console_logging) {
            wp_enqueue_script('jquery');
            wp_add_inline_script(
                'jquery',
                'window.ncxCpApiLog=window.ncxCpApiLog||function(){if(window.console){console.log.apply(console,arguments);}};',
                'before'
            );
        }
    }

    private function render_modal_trigger(WC_Order $order, string $checkout_id): void {
        $modal_id = 'ncx-cp-modal-' . $order->get_id();
        $button_id = $modal_id . '-open';
        $widget = NCX_CP_API::render_widget_markup($checkout_id, self::PAYMENT_BRANDS);
        ?>
        <button type="button" class="button ncx-cp-modal__open" id="<?php echo esc_attr($button_id); ?>">
            <?php echo esc_html($this->modal_cta_text); ?>
        </button>
        <div id="<?php echo esc_attr($modal_id); ?>" class="ncx-cp-modal" hidden>
            <div class="ncx-cp-modal__backdrop" data-close="<?php echo esc_attr($modal_id); ?>"></div>
            <div class="ncx-cp-modal__dialog">
                <button type="button" class="ncx-cp-modal__close" data-close="<?php echo esc_attr($modal_id); ?>" aria-label="<?php esc_attr_e('Close payment modal', 'ncx-cp-api'); ?>">&times;</button>
                <?php echo $widget; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </div>
        <script>
            (function(){
                const modal = document.getElementById('<?php echo esc_js($modal_id); ?>');
                const openBtn = document.getElementById('<?php echo esc_js($button_id); ?>');
                if(!modal || !openBtn){return;}
                const toggleModal = (show)=>{
                    if(show){
                        modal.hidden = false;
                        document.body.classList.add('ncx-cp-modal-lock');
                    } else {
                        modal.hidden = true;
                        document.body.classList.remove('ncx-cp-modal-lock');
                    }
                };
                openBtn.addEventListener('click', function(){ toggleModal(true); });
                modal.querySelectorAll('[data-close="<?php echo esc_js($modal_id); ?>"]').forEach(function(el){
                    el.addEventListener('click', function(){ toggleModal(false); });
                });
                document.addEventListener('keydown', function(ev){
                    if(ev.key === 'Escape'){ toggleModal(false); }
                });
            })();
        </script>
        <?php
    }

    public function maybe_schedule_duplicate_guard(): void {
        $scheduled = wp_next_scheduled(self::DUPE_CRON_HOOK);
        if ($this->enable_dupe_check) {
            if (!$scheduled) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::DUPE_CRON_HOOK);
            }
        } elseif ($scheduled) {
            wp_clear_scheduled_hook(self::DUPE_CRON_HOOK);
        }
    }

    public function run_duplicate_guard(): void {
        if (!$this->enable_dupe_check) {
            return;
        }

        $orders = wc_get_orders([
            'limit' => 20,
            'status' => ['pending', 'on-hold'],
            'orderby' => 'date',
            'order' => 'DESC',
            'date_created' => '>' . gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS * 2),
        ]);

        $fingerprints = [];
        foreach ($orders as $order) {
            $fingerprint = md5(strtolower((string) $order->get_billing_email()) . '|' . $order->get_total());
            if (isset($fingerprints[$fingerprint])) {
                $this->log_event('warning', 'Potential duplicate payment attempt detected', [
                    'order_a' => $fingerprints[$fingerprint],
                    'order_b' => $order->get_id(),
                    'total' => $order->get_total(),
                ]);
            } else {
                $fingerprints[$fingerprint] = $order->get_id();
            }
        }
    }

    private function maybe_tokenize_card(WC_Order $order, array $payment): void {
        $order->delete_meta_data(self::SAVE_CARD_META_KEY);
        $order->save();

        if (!$order->get_user_id()) {
            return;
        }

        $registration_id = $payment['registrationId'] ?? '';
        $card = $payment['card'] ?? [];
        if ('' === $registration_id || empty($card)) {
            $this->log_event('info', 'Skipping tokenization due to missing registration data', ['order_id' => $order->get_id()]);
            return;
        }

        $existing = WC_Payment_Tokens::get_customer_tokens($order->get_user_id(), $this->id);
        foreach ($existing as $token) {
            if ($token->get_token() === $registration_id) {
                return;
            }
        }

        $token = new WC_Payment_Token_CC();
        $token->set_token($registration_id);
        $token->set_gateway_id($this->id);
        $token->set_user_id($order->get_user_id());
        $token->set_last4(substr((string) ($card['last4Digits'] ?? $card['number'] ?? '0000'), -4));
        $token->set_expiry_month(sprintf('%02d', (int) ($card['expiryMonth'] ?? 0)));
        $token->set_expiry_year((int) ($card['expiryYear'] ?? 0));
        $token->set_card_type(strtolower((string) ($card['brand'] ?? $card['paymentBrand'] ?? 'card')));
        if (!empty($card['holder'])) {
            $token->add_meta_data('holder', sanitize_text_field($card['holder']), true);
        }
        $token->save();

        $this->log_event('info', 'Stored COPYandPAY registration token', [
            'order_id' => $order->get_id(),
            'user_id' => $order->get_user_id(),
        ]);
    }

    private function should_save_payment_method(): bool {
        if (!self::CREATE_REGISTRATION || !$this->supports('tokenization') || !is_user_logged_in()) {
            return false;
        }

        $request_key = 'wc-' . $this->id . '-new-payment-method';
        if (isset($_POST[$request_key])) {
            $value = strtolower((string) wc_clean(wp_unslash($_POST[$request_key])));
            if (in_array($value, ['1', 'true', 'yes'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'no'], true)) {
                return false;
            }
        }

        return false; // slickOneClick: hardcoded off (nochexapi pattern)
    }

    private function sanitize_color(string $color): string {
        $color = trim($color);
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
            return $color;
        }

        return '#111827';
    }

    private function sanitize_radius(string $value): string {
        $value = trim($value);
        if ('' === $value) {
            return '12px';
        }

        if (preg_match('/^\d+(\.\d+)?(px|em|rem|%)$/', $value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return $value . 'px';
        }

        return '12px';
    }

    private function hex_to_rgba(string $hex, float $alpha): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $alpha = min(max($alpha, 0), 1);
        $int = hexdec($hex ?: '000000');
        $r = ($int >> 16) & 255;
        $g = ($int >> 8) & 255;
        $b = $int & 255;
        return sprintf('rgba(%d,%d,%d,%.2f)', $r, $g, $b, $alpha);
    }

    /**
     * Build 3DS v2 parameters matching nochexapi's get_threed_version_two_data().
     * nochexapi only sends ReqAuthMethod by default (threeDv2Params = ['ReqAuthMethod']).
     */
    private function build_three_ds_parameters(WC_Order $order): array {
        if (!self::THREE_DS_ENABLED) {
            return [];
        }

        $params = [];

        // ReqAuthMethod: 01 = guest, 02 = logged-in (matches nochexapi exactly).
        if (is_user_logged_in()) {
            $params['ReqAuthMethod'] = '02';
        } else {
            $params['ReqAuthMethod'] = '01';
        }

        return $params;
    }

    // determine_account_age_indicator removed — nochexapi only sends ReqAuthMethod by default.

    private function count_recent_orders(int $user_id): int {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }

        $window_start = gmdate('Y-m-d H:i:s', time() - (self::THREE_DS_WINDOW_DAYS * DAY_IN_SECONDS));
        $orders = wc_get_orders([
            'return' => 'ids',
            'customer_id' => $user_id,
            'status' => ['processing', 'completed', 'on-hold'],
            'date_created' => '>' . $window_start,
            'limit' => -1,
        ]);

        return is_array($orders) ? count($orders) : 0;
    }

    private function log_event(string $level, string $message, array $context = []): void {
        if (!$this->is_log_level_enabled($level)) {
            return;
        }

        $this->get_logger()->log($level, $message, array_merge(['source' => $this->id], $context));
    }

    private function get_logger(): WC_Logger {
        if (!$this->logger) {
            $this->logger = wc_get_logger();
        }

        return $this->logger;
    }
}
