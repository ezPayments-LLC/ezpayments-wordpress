<?php
/**
 * ezPayments API Client
 *
 * Handles communication with the ezPayments V3 Merchant API.
 *
 * @package EzPayments_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EzPayments_API {

    /**
     * Allowed API host domains.
     *
     * @var array
     */
    const ALLOWED_HOSTS = array( 'app.ezpayments.co', 'sandbox.ezpayments.co' );

    /**
     * Allowed payment page host domains for redirect validation.
     *
     * @var array
     */
    const ALLOWED_REDIRECT_HOSTS = array( 'app.ezpayments.co', 'sandbox.ezpayments.co', 'pay.ezpayments.co' );

    /**
     * API key for authentication.
     *
     * @var string
     */
    private $api_key;

    /**
     * Base URL for the API.
     *
     * @var string
     */
    private $base_url;

    /**
     * Constructor.
     *
     * @param string $api_key The API key (sk_live_xxx or sk_test_xxx).
     */
    public function __construct( $api_key ) {
        $this->api_key  = $api_key;
        $this->base_url = 'https://app.ezpayments.co';
    }

    /**
     * Validate that a redirect URL belongs to an allowed ezPayments domain.
     *
     * @param string $url The URL to validate.
     * @return bool
     */
    public static function is_valid_redirect_url( $url ) {
        if ( empty( $url ) ) {
            return false;
        }

        $parsed = wp_parse_url( $url );

        if ( ! isset( $parsed['scheme'] ) || 'https' !== $parsed['scheme'] ) {
            return false;
        }

        if ( ! isset( $parsed['host'] ) || ! in_array( $parsed['host'], self::ALLOWED_REDIRECT_HOSTS, true ) ) {
            return false;
        }

        return true;
    }

    /**
     * Create a payment link.
     *
     * @param array $params Payment link parameters.
     * @return array|WP_Error Decoded response or WP_Error.
     */
    public function create_payment_link( $params ) {
        return $this->request( 'POST', '/api/v3/payment-links/', $params );
    }

    /**
     * Get a payment link by ID.
     *
     * @param string $id Payment link UUID.
     * @return array|WP_Error Decoded response or WP_Error.
     */
    public function get_payment_link( $id ) {
        return $this->request( 'GET', '/api/v3/payment-links/' . $id . '/' );
    }

    /**
     * Create a webhook endpoint.
     *
     * @param string $url    The webhook URL.
     * @param array  $events Events to subscribe to.
     * @return array|WP_Error Decoded response or WP_Error.
     */
    public function create_webhook_endpoint( $url, $events ) {
        return $this->request( 'POST', '/api/v3/webhook-endpoints/', array(
            'url'         => $url,
            'events'      => $events,
            'description' => 'WooCommerce webhook endpoint',
        ) );
    }

    /**
     * List webhook endpoints.
     *
     * @return array|WP_Error Decoded response or WP_Error.
     */
    public function list_webhook_endpoints() {
        return $this->request( 'GET', '/api/v3/webhook-endpoints/' );
    }

    /**
     * Delete a webhook endpoint.
     *
     * @param string $id Webhook endpoint UUID.
     * @return array|WP_Error Decoded response or WP_Error.
     */
    public function delete_webhook_endpoint( $id ) {
        return $this->request( 'DELETE', '/api/v3/webhook-endpoints/' . $id . '/' );
    }

    /**
     * Make an API request.
     *
     * @param string $method   HTTP method.
     * @param string $endpoint API endpoint path.
     * @param array  $body     Request body (for POST/PATCH).
     * @return array|WP_Error Decoded response data or WP_Error.
     */
    private function request( $method, $endpoint, $body = array() ) {
        $url = $this->base_url . $endpoint;

        $args = array(
            'method'    => $method,
            'timeout'   => 30,
            'sslverify' => true,
            'headers'   => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'User-Agent'    => 'ezPayments-WooCommerce/' . EZPAYMENTS_VERSION,
            ),
        );

        if ( in_array( $method, array( 'POST', 'PATCH', 'PUT' ), true ) && ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body_raw    = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body_raw, true );

        // Handle HTTP errors.
        if ( $status_code >= 400 ) {
            $error_message = 'API request failed';

            if ( isset( $data['error']['message'] ) ) {
                $error_message = sanitize_text_field( $data['error']['message'] );
            } elseif ( isset( $data['detail'] ) ) {
                $error_message = sanitize_text_field( $data['detail'] );
            }

            return new WP_Error(
                'ezpayments_api_error',
                $error_message,
                array(
                    'status_code' => $status_code,
                )
            );
        }

        // V3 API wraps successful responses in a "data" key.
        if ( isset( $data['data'] ) ) {
            return $data['data'];
        }

        return $data;
    }
}
