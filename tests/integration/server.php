<?php
/**
 * Router for `php -S` that serves the plugin's webhook handler, so the
 * ezPayments backend can deliver real signed webhooks to it.
 *
 * Environment:
 *   EZPAY_WEBHOOK_SECRET  The signing secret of the backend webhook endpoint.
 *   EZPAY_STATE_FILE      JSON file holding the orders; read before and
 *                         written after every delivery:
 *                         {"orders": [{"id": 1, "link_id": "...", "status": "on-hold"}]}
 *
 * @package EzPayments_WooCommerce
 */

require dirname( __DIR__ ) . '/stubs.php';

$state_file = getenv( 'EZPAY_STATE_FILE' );
$state      = json_decode( file_get_contents( $state_file ), true );

$GLOBALS['ezpay_test_options']['woocommerce_ezpayments_settings'] = array(
    'webhook_secret' => getenv( 'EZPAY_WEBHOOK_SECRET' ),
);

foreach ( $state['orders'] as $row ) {
    $order                 = new EzPay_Test_Order( $row['id'], $row['link_id'], $row['status'] );
    $order->transaction_id = isset( $row['transaction_id'] ) ? $row['transaction_id'] : '';
    $GLOBALS['ezpay_test_orders'][ $row['id'] ] = $order;
}

$request  = new WP_REST_Request( file_get_contents( 'php://input' ), getallheaders() );
$response = EzPayments_Webhook::handle_webhook( $request );

$state['orders'] = array();
foreach ( $GLOBALS['ezpay_test_orders'] as $order ) {
    $state['orders'][] = array(
        'id'             => $order->id,
        'link_id'        => $order->meta['_ezpayments_link_id'],
        'status'         => $order->status,
        'transaction_id' => $order->transaction_id,
        'stock_reduced'  => $order->stock_reduced,
    );
}
file_put_contents( $state_file, json_encode( $state, JSON_PRETTY_PRINT ) );

http_response_code( $response->get_status() );
header( 'Content-Type: application/json' );
echo json_encode( $response->get_data() );
