<?php
/**
 * Tests for EzPayments_Webhook::handle_webhook.
 *
 * @package EzPayments_WooCommerce
 */

use PHPUnit\Framework\TestCase;

class WebhookTest extends TestCase {

    const SECRET  = 'whsec_test_secret';
    const LINK_ID = '8f14e45f-ceea-4e67-a7a1-5c1b2d3e4f50';

    protected function setUp(): void {
        $GLOBALS['ezpay_test_options']    = array(
            'woocommerce_ezpayments_settings' => array( 'webhook_secret' => self::SECRET ),
        );
        $GLOBALS['ezpay_test_transients'] = array();
        $GLOBALS['ezpay_test_logs']       = array();
        $GLOBALS['ezpay_test_orders']     = array(
            1042 => new EzPay_Test_Order( 1042, self::LINK_ID, 'on-hold' ),
        );
    }

    /**
     * The body ezPayments sends for payment_link.paid, captured from the
     * backend's PaymentLinkResponseSerializer.
     */
    private function paid_body() {
        return file_get_contents( __DIR__ . '/fixtures/payment_link_paid.json' );
    }

    private function deliver( $body, $event = null, $timestamp = null, $secret = self::SECRET ) {
        $timestamp = null === $timestamp ? time() : $timestamp;
        $headers   = array(
            'X-EzPayments-Signature' => 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret ),
            'X-EzPayments-Delivery'  => 'b5d1c0de-0000-4000-8000-000000000001',
        );
        if ( null !== $event ) {
            $headers['X-EzPayments-Event'] = $event;
        }
        return EzPayments_Webhook::handle_webhook( new WP_REST_Request( $body, $headers ) );
    }

    private function order() {
        return $GLOBALS['ezpay_test_orders'][1042];
    }

    public function test_backend_paid_delivery_marks_order_paid() {
        $response = $this->deliver( $this->paid_body(), 'payment_link.paid' );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'processing', $this->order()->status );
        $this->assertSame( self::LINK_ID, $this->order()->get_transaction_id() );
        $this->assertSame( 'card', $this->order()->meta['_ezpayments_payment_method_type'] );
        $this->assertSame( '2026-10-28T09:30:00Z', $this->order()->meta['_ezpayments_paid_at'] );
        $this->assertTrue( $this->order()->stock_reduced );
    }

    public function test_repeated_paid_delivery_is_idempotent() {
        $this->deliver( $this->paid_body(), 'payment_link.paid' );
        $this->order()->notes = array();

        $response = $this->deliver( $this->paid_body(), 'payment_link.paid' );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( array(), $this->order()->notes );
    }

    public function test_backend_expired_delivery_cancels_order() {
        $response = $this->deliver( wp_json( array( 'id' => self::LINK_ID ) ), 'payment_link.expired' );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'cancelled', $this->order()->status );
    }

    public function test_backend_cancelled_delivery_cancels_order() {
        $body             = json_decode( $this->paid_body(), true );
        $body['status']   = 'cancelled';
        $body['paid_at']  = null;
        $response         = $this->deliver( wp_json( $body ), 'payment_link.cancelled' );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'cancelled', $this->order()->status );
    }

    public function test_wrapped_body_without_event_header_is_still_accepted() {
        $body = wp_json(
            array(
                'event_type' => 'payment_link.paid',
                'data'       => json_decode( $this->paid_body(), true ),
            )
        );

        $response = $this->deliver( $body );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'processing', $this->order()->status );
    }

    public function test_missing_event_type_is_rejected() {
        $response = $this->deliver( $this->paid_body() );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'on-hold', $this->order()->status );
    }

    public function test_cancelled_body_replayed_as_paid_does_not_mark_order_paid() {
        $body           = json_decode( $this->paid_body(), true );
        $body['status'] = 'cancelled';

        $response = $this->deliver( wp_json( $body ), 'payment_link.paid' );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'on-hold', $this->order()->status );
        $this->assertSame( '', $this->order()->get_transaction_id() );
    }

    public function test_expired_body_replayed_as_paid_does_not_mark_order_paid() {
        $response = $this->deliver( wp_json( array( 'id' => self::LINK_ID ) ), 'payment_link.paid' );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'on-hold', $this->order()->status );
    }

    public function test_paid_body_replayed_as_cancelled_does_not_cancel_order() {
        $response = $this->deliver( $this->paid_body(), 'payment_link.cancelled' );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'on-hold', $this->order()->status );
    }

    public function test_unknown_event_is_acknowledged_without_changes() {
        $response = $this->deliver( $this->paid_body(), 'payment_link.created' );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'on-hold', $this->order()->status );
    }

    public function test_invalid_signature_is_rejected() {
        $response = $this->deliver( $this->paid_body(), 'payment_link.paid', null, 'whsec_wrong' );

        $this->assertSame( 401, $response->get_status() );
        $this->assertSame( 'on-hold', $this->order()->status );
    }

    public function test_stale_signature_is_rejected() {
        $response = $this->deliver( $this->paid_body(), 'payment_link.paid', time() - 600 );

        $this->assertSame( 401, $response->get_status() );
    }

    public function test_non_object_json_is_rejected() {
        $this->assertSame( 400, $this->deliver( '"paid"', 'payment_link.paid' )->get_status() );
        $this->assertSame( 400, $this->deliver( '{}', 'payment_link.paid' )->get_status() );
        $this->assertSame( 400, $this->deliver( '{not json', 'payment_link.paid' )->get_status() );
    }
}

function wp_json( $data ) {
    return json_encode( $data );
}
