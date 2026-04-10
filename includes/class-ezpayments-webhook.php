<?php
/**
 * ezPayments Webhook Handler
 *
 * Handles incoming webhooks from ezPayments and updates WooCommerce orders.
 *
 * @package EzPayments_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EzPayments_Webhook {

    /**
     * Maximum age of a webhook signature in seconds.
     */
    const SIGNATURE_TOLERANCE = 300; // 5 minutes

    /**
     * Maximum allowed clock skew into the future (seconds).
     */
    const MAX_FUTURE_SKEW = 30;

    /**
     * Maximum webhook requests per IP per minute.
     */
    const RATE_LIMIT = 60;

    /**
     * Initialize webhook routes.
     */
    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    /**
     * Register the webhook REST route.
     */
    public static function register_routes() {
        register_rest_route( 'ezpayments/v1', '/webhook', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_webhook' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Get the webhook URL.
     *
     * @return string
     */
    public static function get_webhook_url() {
        return rest_url( 'ezpayments/v1/webhook' );
    }

    /**
     * Handle an incoming webhook request.
     *
     * @param WP_REST_Request $request The webhook request.
     * @return WP_REST_Response
     */
    public static function handle_webhook( $request ) {
        // Rate limiting.
        if ( self::is_rate_limited() ) {
            return new WP_REST_Response( array( 'error' => 'Too Many Requests' ), 429 );
        }

        $raw_body  = $request->get_body();
        $signature = $request->get_header( 'X-EzPayments-Signature' );

        if ( empty( $signature ) || empty( $raw_body ) ) {
            return new WP_REST_Response( array( 'error' => 'Missing signature or body' ), 400 );
        }

        // Get webhook secret from gateway settings.
        $gateway_settings = get_option( 'woocommerce_ezpayments_settings', array() );
        $webhook_secret   = isset( $gateway_settings['webhook_secret'] ) ? $gateway_settings['webhook_secret'] : '';

        if ( empty( $webhook_secret ) ) {
            return new WP_REST_Response( array( 'error' => 'Webhook not configured' ), 500 );
        }

        // Verify signature.
        if ( ! self::verify_signature( $webhook_secret, $signature, $raw_body ) ) {
            wc_get_logger()->warning(
                'ezPayments: Webhook signature verification failed. Possible misconfiguration or attack.',
                array( 'source' => 'ezpayments' )
            );
            return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 401 );
        }

        // Parse payload.
        $payload = json_decode( $raw_body, true );
        if ( null === $payload && json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_REST_Response( array( 'error' => 'Invalid JSON payload' ), 400 );
        }
        if ( empty( $payload ) || ! isset( $payload['event_type'] ) ) {
            return new WP_REST_Response( array( 'error' => 'Invalid payload' ), 400 );
        }

        $event_type = sanitize_text_field( $payload['event_type'] );
        $event_data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();

        // Dispatch event.
        switch ( $event_type ) {
            case 'payment_link.paid':
                self::handle_payment_link_paid( $event_data );
                break;

            case 'payment_link.expired':
                self::handle_payment_link_cancelled( $event_data, 'expired' );
                break;

            case 'payment_link.cancelled':
                self::handle_payment_link_cancelled( $event_data, 'cancelled' );
                break;

            default:
                // Return 200 for unrecognized events to prevent retries.
                break;
        }

        return new WP_REST_Response( array( 'received' => true ), 200 );
    }

    /**
     * Simple IP-based rate limiting using transients.
     *
     * @return bool True if request should be rejected.
     */
    private static function is_rate_limited() {
        $ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        $rate_key = 'ezpay_wh_rate_' . md5( $ip );
        $count    = (int) get_transient( $rate_key );

        if ( $count >= self::RATE_LIMIT ) {
            return true;
        }

        set_transient( $rate_key, $count + 1, 60 );
        return false;
    }

    /**
     * Verify the webhook signature.
     *
     * @param string $secret    The webhook secret.
     * @param string $signature The X-EzPayments-Signature header value.
     * @param string $raw_body  The raw request body.
     * @return bool
     */
    private static function verify_signature( $secret, $signature, $raw_body ) {
        // Parse signature header: t=<timestamp>,v1=<hash>
        $parts    = array();
        $segments = explode( ',', $signature );

        foreach ( $segments as $segment ) {
            $pair = explode( '=', $segment, 2 );
            if ( count( $pair ) !== 2 ) {
                return false;
            }
            $k = trim( $pair[0] );
            // Reject duplicate keys to prevent injection attacks.
            if ( isset( $parts[ $k ] ) ) {
                return false;
            }
            $parts[ $k ] = trim( $pair[1] );
        }

        if ( ! isset( $parts['t'] ) || ! isset( $parts['v1'] ) ) {
            return false;
        }

        // Validate timestamp is a plausible numeric Unix timestamp.
        if ( ! ctype_digit( (string) $parts['t'] ) || (int) $parts['t'] < 1000000000 ) {
            return false;
        }

        $timestamp     = (int) $parts['t'];
        $provided_hash = $parts['v1'];
        $now           = time();

        // Reject future timestamps beyond small clock skew tolerance.
        // Reject timestamps older than SIGNATURE_TOLERANCE.
        if ( $timestamp > $now + self::MAX_FUTURE_SKEW || $timestamp < $now - self::SIGNATURE_TOLERANCE ) {
            return false;
        }

        // Compute expected signature.
        $message       = $timestamp . '.' . $raw_body;
        $expected_hash = hash_hmac( 'sha256', $message, $secret );

        return hash_equals( $expected_hash, $provided_hash );
    }

    /**
     * Handle a payment_link.paid event.
     *
     * @param array $data The event data.
     */
    private static function handle_payment_link_paid( $data ) {
        $payment_link_id = isset( $data['id'] ) ? sanitize_text_field( $data['id'] ) : '';

        if ( empty( $payment_link_id ) ) {
            return;
        }

        // Transient-based lock to prevent race conditions from concurrent webhook deliveries.
        $lock_key = 'ezpay_lock_' . md5( $payment_link_id );
        if ( false === set_transient( $lock_key, 1, 60 ) ) {
            // Lock already held — another process is handling this event.
            if ( get_transient( $lock_key ) ) {
                return;
            }
        }

        try {
            $order = self::find_order_by_payment_link( $payment_link_id, array( 'pending', 'on-hold' ) );

            if ( ! $order ) {
                return;
            }

            // Idempotency guard: use transaction_id to prevent duplicate processing.
            if ( $order->get_transaction_id() ) {
                return;
            }
            $order->set_transaction_id( $payment_link_id );
            $order->save();

            // Mark order as paid.
            $payment_method_type = isset( $data['payment_method_type'] ) ? sanitize_text_field( $data['payment_method_type'] ) : '';
            $paid_at             = isset( $data['paid_at'] ) ? sanitize_text_field( $data['paid_at'] ) : '';

            $order->payment_complete( $payment_link_id );
            $order->add_order_note(
                sprintf(
                    /* translators: 1: payment method type, 2: payment link ID */
                    __( 'ezPayments payment completed via %1$s. Payment Link: %2$s', 'ezpayments-woocommerce' ),
                    $payment_method_type ?: 'unknown',
                    $payment_link_id
                )
            );

            if ( $paid_at ) {
                $order->update_meta_data( '_ezpayments_paid_at', $paid_at );
            }
            if ( $payment_method_type ) {
                $order->update_meta_data( '_ezpayments_payment_method_type', $payment_method_type );
            }
            $order->save();

            // Reduce stock after confirmed payment.
            wc_reduce_stock_levels( $order->get_id() );
        } finally {
            delete_transient( $lock_key );
        }
    }

    /**
     * Handle payment_link.expired or payment_link.cancelled events.
     *
     * @param array  $data   The event data.
     * @param string $reason Either 'expired' or 'cancelled'.
     */
    private static function handle_payment_link_cancelled( $data, $reason ) {
        $payment_link_id = isset( $data['id'] ) ? sanitize_text_field( $data['id'] ) : '';

        if ( empty( $payment_link_id ) ) {
            return;
        }

        $order = self::find_order_by_payment_link( $payment_link_id, array( 'pending', 'on-hold' ) );

        if ( ! $order || $order->has_status( 'cancelled' ) ) {
            return;
        }

        $order->update_status(
            'cancelled',
            sprintf(
                /* translators: 1: reason (expired/cancelled) */
                __( 'ezPayments payment link %s.', 'ezpayments-woocommerce' ),
                $reason
            )
        );
    }

    /**
     * Find a WooCommerce order by ezPayments payment link ID.
     *
     * @param string $payment_link_id The payment link UUID.
     * @param array  $statuses        Order statuses to search within.
     * @return WC_Order|null
     */
    private static function find_order_by_payment_link( $payment_link_id, $statuses = array() ) {
        $args = array(
            'meta_key'   => '_ezpayments_link_id',
            'meta_value' => $payment_link_id,
            'limit'      => 1,
            'return'     => 'objects',
        );

        if ( ! empty( $statuses ) ) {
            $args['status'] = $statuses;
        }

        $orders = wc_get_orders( $args );

        return ! empty( $orders ) ? $orders[0] : null;
    }
}
