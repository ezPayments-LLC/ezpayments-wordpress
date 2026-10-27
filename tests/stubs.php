<?php
/**
 * Minimal WordPress and WooCommerce stand-ins for exercising the webhook
 * handler outside WordPress. Only what EzPayments_Webhook calls is defined.
 *
 * Orders live in $GLOBALS['ezpay_test_orders'], keyed by order ID.
 *
 * @package EzPayments_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['ezpay_test_options']    = array();
$GLOBALS['ezpay_test_transients'] = array();
$GLOBALS['ezpay_test_orders']     = array();
$GLOBALS['ezpay_test_logs']       = array();

class WP_REST_Response {
    public $data;
    public $status;

    public function __construct( $data = null, $status = 200 ) {
        $this->data   = $data;
        $this->status = $status;
    }

    public function get_status() {
        return $this->status;
    }

    public function get_data() {
        return $this->data;
    }
}

class WP_REST_Request {
    private $body;
    private $headers = array();

    public function __construct( $body, $headers = array() ) {
        $this->body = $body;
        foreach ( $headers as $name => $value ) {
            $this->headers[ self::canonical( $name ) ] = $value;
        }
    }

    public function get_body() {
        return $this->body;
    }

    public function get_header( $name ) {
        $key = self::canonical( $name );
        return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : null;
    }

    private static function canonical( $name ) {
        return str_replace( '-', '_', strtolower( $name ) );
    }
}

class EzPay_Test_Logger {
    public function warning( $message, $context = array() ) {
        $GLOBALS['ezpay_test_logs'][] = $message;
    }

    public function error( $message, $context = array() ) {
        $GLOBALS['ezpay_test_logs'][] = $message;
    }
}

class EzPay_Test_Order {
    public $id;
    public $status;
    public $transaction_id = '';
    public $meta           = array();
    public $notes          = array();
    public $paid_with      = null;
    public $stock_reduced  = false;

    public function __construct( $id, $link_id, $status ) {
        $this->id                          = $id;
        $this->status                      = $status;
        $this->meta['_ezpayments_link_id'] = $link_id;
    }

    public function get_id() {
        return $this->id;
    }

    public function get_transaction_id() {
        return $this->transaction_id;
    }

    public function set_transaction_id( $id ) {
        $this->transaction_id = $id;
    }

    public function save() {
        return $this->id;
    }

    public function payment_complete( $transaction_id ) {
        $this->paid_with = $transaction_id;
        $this->status    = 'processing';
    }

    public function add_order_note( $note ) {
        $this->notes[] = $note;
    }

    public function update_meta_data( $key, $value ) {
        $this->meta[ $key ] = $value;
    }

    public function has_status( $status ) {
        return in_array( $this->status, (array) $status, true );
    }

    public function update_status( $status, $note = '' ) {
        $this->status  = $status;
        $this->notes[] = $note;
    }
}

function add_action( $hook, $callback ) {}

function register_rest_route( $namespace, $route, $args ) {}

function rest_url( $path = '' ) {
    return 'https://shop.test/wp-json/' . $path;
}

function __( $text, $domain = 'default' ) {
    return $text;
}

function sanitize_text_field( $value ) {
    return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
}

function wp_unslash( $value ) {
    return $value;
}

function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['ezpay_test_options'] ) ? $GLOBALS['ezpay_test_options'][ $name ] : $default;
}

function get_transient( $key ) {
    return isset( $GLOBALS['ezpay_test_transients'][ $key ] ) ? $GLOBALS['ezpay_test_transients'][ $key ] : false;
}

function set_transient( $key, $value, $expiration = 0 ) {
    $GLOBALS['ezpay_test_transients'][ $key ] = $value;
    return true;
}

function delete_transient( $key ) {
    unset( $GLOBALS['ezpay_test_transients'][ $key ] );
    return true;
}

function wc_get_logger() {
    return new EzPay_Test_Logger();
}

function wc_get_orders( $args ) {
    $found = array();
    foreach ( $GLOBALS['ezpay_test_orders'] as $order ) {
        if ( ( $order->meta[ $args['meta_key'] ] ?? null ) !== $args['meta_value'] ) {
            continue;
        }
        if ( ! empty( $args['status'] ) && ! $order->has_status( $args['status'] ) ) {
            continue;
        }
        $found[] = $order;
    }
    return array_slice( $found, 0, $args['limit'] );
}

function wc_reduce_stock_levels( $order_id ) {
    $GLOBALS['ezpay_test_orders'][ $order_id ]->stock_reduced = true;
}

require_once dirname( __DIR__ ) . '/includes/class-ezpayments-webhook.php';
