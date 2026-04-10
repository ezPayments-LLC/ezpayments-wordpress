<?php
/**
 * ezPayments WooCommerce Payment Gateway
 *
 * Extends WC_Payment_Gateway to integrate ezPayments payment links
 * into the WooCommerce checkout flow.
 *
 * @package EzPayments_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Gateway_EzPayments extends WC_Payment_Gateway {

    /**
     * Whether test mode is enabled.
     *
     * @var bool
     */
    private $testmode;

    /**
     * Test API key.
     *
     * @var string
     */
    private $test_api_key;

    /**
     * Live API key.
     *
     * @var string
     */
    private $live_api_key;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id                 = 'ezpayments';
        $this->icon               = EZPAYMENTS_PLUGIN_URL . 'assets/images/ezpayments-icon.svg';
        $this->has_fields         = false;
        $this->method_title       = __( 'ezPayments', 'ezpayments-woocommerce' );
        $this->method_description = __( 'Accept payments via ezPayments. Customers are redirected to a secure payment page to complete their purchase.', 'ezpayments-woocommerce' );
        $this->supports           = array( 'products' );

        // Load settings.
        $this->init_form_fields();
        $this->init_settings();

        $this->title        = $this->get_option( 'title' );
        $this->description  = $this->get_option( 'description' );
        $this->testmode     = 'yes' === $this->get_option( 'testmode' );
        $this->test_api_key = $this->get_option( 'test_api_key' );
        $this->live_api_key = $this->get_option( 'live_api_key' );

        // Save settings hook.
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

        // Display admin notices.
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
    }

    /**
     * Initialize gateway settings form fields.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled'     => array(
                'title'   => __( 'Enable/Disable', 'ezpayments-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable ezPayments', 'ezpayments-woocommerce' ),
                'default' => 'no',
            ),
            'title'       => array(
                'title'       => __( 'Title', 'ezpayments-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Payment method title shown to customers at checkout.', 'ezpayments-woocommerce' ),
                'default'     => __( 'ezPayments', 'ezpayments-woocommerce' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'ezpayments-woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description shown to customers at checkout.', 'ezpayments-woocommerce' ),
                'default'     => __( 'Pay securely via ezPayments. You will be redirected to complete your payment.', 'ezpayments-woocommerce' ),
                'desc_tip'    => true,
            ),
            'testmode'    => array(
                'title'       => __( 'Test Mode', 'ezpayments-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable test mode', 'ezpayments-woocommerce' ),
                'description' => __( 'When enabled, the test API key will be used. No real charges will be made.', 'ezpayments-woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'test_api_key' => array(
                'title'       => __( 'Test Secret Key', 'ezpayments-woocommerce' ),
                'type'        => 'password',
                'description' => __( 'Your ezPayments test secret key (starts with sk_test_). Found in your ezPayments dashboard under Settings > API Keys. Do not use the publishable key (pk_) here.', 'ezpayments-woocommerce' ),
                'default'     => '',
                'desc_tip'    => true,
                'placeholder' => 'sk_test_...',
            ),
            'live_api_key' => array(
                'title'       => __( 'Live Secret Key', 'ezpayments-woocommerce' ),
                'type'        => 'password',
                'description' => __( 'Your ezPayments live secret key (starts with sk_live_). Found in your ezPayments dashboard under Settings > API Keys. Do not use the publishable key (pk_) here.', 'ezpayments-woocommerce' ),
                'default'     => '',
                'desc_tip'    => true,
                'placeholder' => 'sk_live_...',
            ),
            'webhook_info' => array(
                'title'       => __( 'Webhook Status', 'ezpayments-woocommerce' ),
                'type'        => 'title',
                'description' => $this->get_webhook_status_html(),
            ),
        );
    }

    /**
     * Get the active API key based on the current mode.
     *
     * @return string
     */
    private function get_api_key() {
        return $this->testmode ? $this->test_api_key : $this->live_api_key;
    }

    /**
     * Get an API client instance.
     *
     * @param string|null $api_key Optional API key override.
     * @return EzPayments_API|null
     */
    private function get_api_client( $api_key = null ) {
        $key = $api_key ?: $this->get_api_key();
        if ( empty( $key ) ) {
            return null;
        }
        return new EzPayments_API( $key );
    }

    /**
     * Process the payment for a given order.
     *
     * Creates a payment link via the ezPayments API and redirects
     * the customer to the hosted payment page.
     *
     * @param int $order_id WooCommerce order ID.
     * @return array Result array with 'result' and 'redirect' keys.
     */
    public function process_payment( $order_id ) {
        if ( ! is_numeric( $order_id ) || $order_id <= 0 ) {
            return array( 'result' => 'failure' );
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( __( 'Order not found.', 'ezpayments-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        $api = $this->get_api_client();

        if ( ! $api ) {
            wc_add_notice( __( 'Payment gateway is not configured. Please contact the store administrator.', 'ezpayments-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // Build line item description.
        $description_parts = array();
        foreach ( $order->get_items() as $item ) {
            $description_parts[] = $item->get_name() . ' x' . $item->get_quantity();
        }
        $description = implode( ', ', $description_parts );
        if ( strlen( $description ) > 1000 ) {
            $description = substr( $description, 0, 997 ) . '...';
        }

        // Build a non-expiring cancel URL using order key (not nonce-based).
        $cancel_url = add_query_arg(
            array(
                'cancel_order' => 'true',
                'order_id'     => $order->get_id(),
                'order_key'    => $order->get_order_key(),
            ),
            wc_get_checkout_url()
        );

        // Create payment link.
        $params = array(
            'amount'           => (float) $order->get_total(),
            'description'      => $description,
            'customer_name'    => $order->get_formatted_billing_full_name(),
            'customer_email'   => $order->get_billing_email(),
            'reference_number' => (string) $order->get_order_number(),
            'success_url'      => $this->get_return_url( $order ),
            'cancel_url'       => $cancel_url,
            'metadata'         => array(
                'source'       => 'woocommerce',
                'order_id'     => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'site_url'     => get_site_url(),
            ),
        );

        $result = $api->create_payment_link( $params );

        if ( is_wp_error( $result ) ) {
            wc_get_logger()->error(
                'ezPayments: Failed to create payment link - ' . wp_strip_all_tags( $result->get_error_message() ),
                array( 'source' => 'ezpayments' )
            );
            wc_add_notice( __( 'Unable to process payment. Please try again or choose another payment method.', 'ezpayments-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // Validate the redirect URL belongs to ezPayments.
        $link_id  = isset( $result['id'] ) ? sanitize_text_field( $result['id'] ) : '';
        $link_url = isset( $result['url'] ) ? esc_url_raw( $result['url'] ) : '';

        if ( empty( $link_url ) || ! EzPayments_API::is_valid_redirect_url( $link_url ) ) {
            wc_get_logger()->error(
                'ezPayments: Invalid redirect URL returned from API.',
                array( 'source' => 'ezpayments' )
            );
            wc_add_notice( __( 'Payment gateway error. Please try again.', 'ezpayments-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // Store payment link data on the order.
        $order->update_meta_data( '_ezpayments_link_id', $link_id );
        $order->update_meta_data( '_ezpayments_link_url', $link_url );
        $order->update_meta_data( '_ezpayments_mode', $this->testmode ? 'test' : 'live' );

        // Set order to on-hold to indicate awaiting off-site payment.
        $order->update_status( 'on-hold', __( 'Awaiting ezPayments payment.', 'ezpayments-woocommerce' ) );

        // Clear cart (stock reduction happens in webhook handler after payment confirmation).
        WC()->cart->empty_cart();

        return array(
            'result'   => 'success',
            'redirect' => $link_url,
        );
    }

    /**
     * Process and save admin options, then register webhooks.
     */
    public function process_admin_options() {
        parent::process_admin_options();

        // Reload settings after save.
        $this->init_settings();
        $this->testmode     = 'yes' === $this->get_option( 'testmode' );
        $this->test_api_key = $this->get_option( 'test_api_key' );
        $this->live_api_key = $this->get_option( 'live_api_key' );

        // Validate that secret keys are used, not publishable keys.
        $has_error = false;
        if ( ! empty( $this->test_api_key ) && strpos( $this->test_api_key, 'sk_test_' ) !== 0 ) {
            WC_Admin_Settings::add_error( __( 'Invalid Test Secret Key. It must start with sk_test_. You may have entered a publishable key (pk_) by mistake.', 'ezpayments-woocommerce' ) );
            $has_error = true;
        }
        if ( ! empty( $this->live_api_key ) && strpos( $this->live_api_key, 'sk_live_' ) !== 0 ) {
            WC_Admin_Settings::add_error( __( 'Invalid Live Secret Key. It must start with sk_live_. You may have entered a publishable key (pk_) by mistake.', 'ezpayments-woocommerce' ) );
            $has_error = true;
        }

        if ( $has_error ) {
            return;
        }

        // Validate API keys against the server.
        foreach ( array( 'test' => $this->test_api_key, 'live' => $this->live_api_key ) as $mode => $key ) {
            if ( ! empty( $key ) ) {
                $api    = new EzPayments_API( $key );
                $result = $api->list_webhook_endpoints();
                if ( is_wp_error( $result ) ) {
                    WC_Admin_Settings::add_error(
                        sprintf(
                            /* translators: %s: mode (test/live) */
                            __( 'Could not authenticate %s API key with ezPayments. Please verify the key is correct.', 'ezpayments-woocommerce' ),
                            $mode
                        )
                    );
                    continue;
                }
                // Key is valid — register webhook for this mode.
                $this->maybe_register_webhook( $mode, $key );
            }
        }

        // Ensure the active mode's webhook secret is used for verification.
        $this->update_webhook_secret_for_active_mode();
    }

    /**
     * Register a webhook endpoint if one doesn't already exist for the given mode.
     *
     * @param string $mode    Either 'test' or 'live'.
     * @param string $api_key The API key for this mode.
     */
    private function maybe_register_webhook( $mode, $api_key ) {
        if ( empty( $api_key ) ) {
            return;
        }

        $option_key  = 'webhook_endpoint_id_' . $mode;
        $secret_key  = 'webhook_secret_' . $mode;
        $existing_id = $this->get_option( $option_key );
        $api         = new EzPayments_API( $api_key );

        // Verify existing webhook endpoint still exists.
        if ( ! empty( $existing_id ) ) {
            $endpoints = $api->list_webhook_endpoints();
            if ( ! is_wp_error( $endpoints ) ) {
                $results = isset( $endpoints['results'] ) ? $endpoints['results'] : $endpoints;
                if ( is_array( $results ) ) {
                    foreach ( $results as $endpoint ) {
                        if ( isset( $endpoint['id'] ) && $endpoint['id'] === $existing_id ) {
                            return; // Webhook still exists, no action needed.
                        }
                    }
                }
            }
        }

        // Enforce HTTPS for webhook URL.
        $webhook_url = EzPayments_Webhook::get_webhook_url();
        if ( strpos( $webhook_url, 'https://' ) !== 0 ) {
            wc_get_logger()->warning(
                'ezPayments: Webhook URL is not HTTPS. Registration skipped for security.',
                array( 'source' => 'ezpayments' )
            );
            WC_Admin_Settings::add_error( __( 'ezPayments requires HTTPS for webhook delivery. Your site does not appear to use HTTPS.', 'ezpayments-woocommerce' ) );
            return;
        }

        // Create new webhook endpoint.
        $events = array( 'payment_link.paid', 'payment_link.expired', 'payment_link.cancelled' );
        $result = $api->create_webhook_endpoint( $webhook_url, $events );

        if ( is_wp_error( $result ) ) {
            wc_get_logger()->error(
                'ezPayments: Failed to register ' . esc_html( $mode ) . ' webhook - ' . wp_strip_all_tags( $result->get_error_message() ),
                array( 'source' => 'ezpayments' )
            );
            return;
        }

        // Store the webhook endpoint ID and secret.
        $endpoint_id = isset( $result['id'] ) ? sanitize_text_field( $result['id'] ) : '';
        if ( empty( $endpoint_id ) ) {
            return;
        }

        $this->update_option( $option_key, $endpoint_id );

        // Store webhook signing secret.
        if ( isset( $result['secret'] ) ) {
            $this->update_option( $secret_key, $result['secret'] );

            // Set the webhook_secret to the active mode's secret.
            $active_mode = $this->testmode ? 'test' : 'live';
            if ( $mode === $active_mode ) {
                $this->update_option( 'webhook_secret', $result['secret'] );
            }
        }

        wc_get_logger()->info(
            'ezPayments: Registered ' . esc_html( $mode ) . ' webhook endpoint successfully.',
            array( 'source' => 'ezpayments' )
        );
    }

    /**
     * Cleanup webhook endpoints on plugin deactivation.
     */
    public static function cleanup_webhooks() {
        $settings = get_option( 'woocommerce_ezpayments_settings', array() );

        foreach ( array( 'test', 'live' ) as $mode ) {
            $api_key     = isset( $settings[ $mode . '_api_key' ] ) ? $settings[ $mode . '_api_key' ] : '';
            $endpoint_id = isset( $settings[ 'webhook_endpoint_id_' . $mode ] ) ? $settings[ 'webhook_endpoint_id_' . $mode ] : '';

            if ( ! empty( $api_key ) && ! empty( $endpoint_id ) ) {
                $api    = new EzPayments_API( $api_key );
                $result = $api->delete_webhook_endpoint( $endpoint_id );
                if ( is_wp_error( $result ) ) {
                    if ( function_exists( 'wc_get_logger' ) ) {
                        wc_get_logger()->warning(
                            'ezPayments: Could not delete ' . esc_html( $mode ) . ' webhook on deactivation.',
                            array( 'source' => 'ezpayments' )
                        );
                    }
                }
            }

            // Always clear stored IDs to prevent stale state on reactivation.
            unset( $settings[ 'webhook_endpoint_id_' . $mode ] );
            unset( $settings[ 'webhook_secret_' . $mode ] );
        }

        unset( $settings['webhook_secret'] );
        update_option( 'woocommerce_ezpayments_settings', $settings );
    }

    /**
     * Get webhook status HTML for the settings page.
     *
     * @return string
     */
    private function get_webhook_status_html() {
        $statuses = array();

        foreach ( array( 'test', 'live' ) as $mode ) {
            $endpoint_id = $this->get_option( 'webhook_endpoint_id_' . $mode );
            if ( ! empty( $endpoint_id ) ) {
                $statuses[] = sprintf(
                    '<span style="color: #46b450;">&#10003;</span> %s mode webhook registered',
                    esc_html( ucfirst( $mode ) )
                );
            } else {
                $api_key = $this->get_option( $mode . '_api_key' );
                if ( ! empty( $api_key ) ) {
                    $statuses[] = sprintf(
                        '<span style="color: #dc3232;">&#10007;</span> %s mode webhook not registered — save settings to register',
                        esc_html( ucfirst( $mode ) )
                    );
                }
            }
        }

        if ( empty( $statuses ) ) {
            return esc_html__( 'Enter your API keys and save to automatically register webhooks.', 'ezpayments-woocommerce' );
        }

        $webhook_url = EzPayments_Webhook::get_webhook_url();
        $statuses[]  = '<br><small>Webhook URL: <code>' . esc_html( $webhook_url ) . '</code></small>';

        return implode( '<br>', $statuses );
    }

    /**
     * Display admin notices for configuration issues.
     */
    public function admin_notices() {
        if ( 'yes' !== $this->enabled ) {
            return;
        }

        if ( $this->testmode && empty( $this->test_api_key ) ) {
            $message = sprintf(
                /* translators: %s: settings page URL */
                __( 'ezPayments: Test mode is enabled but no test API key is set. <a href="%s">Configure settings</a>.', 'ezpayments-woocommerce' ),
                esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=ezpayments' ) )
            );
            echo '<div class="notice notice-warning"><p>' . wp_kses_post( $message ) . '</p></div>';
        }

        if ( ! $this->testmode && empty( $this->live_api_key ) ) {
            $message = sprintf(
                /* translators: %s: settings page URL */
                __( 'ezPayments: Live mode is enabled but no live API key is set. <a href="%s">Configure settings</a>.', 'ezpayments-woocommerce' ),
                esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=ezpayments' ) )
            );
            echo '<div class="notice notice-error"><p>' . wp_kses_post( $message ) . '</p></div>';
        }
    }

    /**
     * Ensure the active mode's webhook secret is used for signature verification
     * when the mode changes.
     */
    public function update_webhook_secret_for_active_mode() {
        $active_mode = $this->testmode ? 'test' : 'live';
        $mode_secret = $this->get_option( 'webhook_secret_' . $active_mode );
        if ( ! empty( $mode_secret ) ) {
            $this->update_option( 'webhook_secret', $mode_secret );
        }
    }
}
