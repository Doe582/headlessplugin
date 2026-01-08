<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RESTBridge_Razorpay_API {

    private $key;
    private $secret;

    public function __construct() {
        $this->key    = defined( 'RAZORPAY_KEY' ) ? RAZORPAY_KEY : '';
        $this->secret = defined( 'RAZORPAY_SECRET' ) ? RAZORPAY_SECRET : '';
    }

    /**
     * Register REST routes
     */
    public function register_routes() {

        register_rest_route( RESTBRIDGE_API_NAMESPACE, '/orders/create-draft', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_draft_order' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( RESTBRIDGE_API_NAMESPACE, '/razorpay/create-order', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_razorpay_order' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( RESTBRIDGE_API_NAMESPACE, '/razorpay/verify-payment', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'verify_payment' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( RESTBRIDGE_API_NAMESPACE, '/razorpay/mark-paid', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'mark_order_paid' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( RESTBRIDGE_API_NAMESPACE, '/orders/mark-draft', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'mark_order_draft' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * 1️⃣ Create pending Woo order from cart
     */
    public function create_draft_order( WP_REST_Request $request ) {

        if ( null === WC()->cart ) {
            return new WP_Error( 'no_cart', 'Cart not initialized', [ 'status' => 400 ] );
        }

        if ( WC()->cart->is_empty() ) {
            return new WP_Error( 'empty_cart', 'Cart is empty', [ 'status' => 400 ] );
        }

        $data = $request->get_json_params();

        $order = wc_create_order( [ 'status' => 'pending' ] );

        foreach ( WC()->cart->get_cart() as $item ) {
            $product = wc_get_product( $item['product_id'] );
            $order->add_product( $product, $item['quantity'] );
        }

        if ( ! empty( $data['billing_address'] ) ) {
            $order->set_address( $data['billing_address'], 'billing' );
        }

        if ( ! empty( $data['shipping_address'] ) ) {
            $order->set_address( $data['shipping_address'], 'shipping' );
        }

        $order->set_payment_method( 'razorpay' );
        $order->set_payment_method_title( 'Razorpay' );
        $order->calculate_totals();
        $order->save();

        return [
            'order_id' => $order->get_id(),
            'status'   => 'pending',
        ];
    }

    /**
     * 2️⃣ Create Razorpay order from Woo order
     */
    public function create_razorpay_order( WP_REST_Request $request ) {

        $wc_order_id = absint( $request->get_param( 'wc_order_id' ) );

        if ( ! $wc_order_id ) {
            return new WP_Error( 'missing_order', 'Woo order ID required', [ 'status' => 400 ] );
        }

        $order = wc_get_order( $wc_order_id );

        if ( ! $order || ! $order->has_status( 'pending' ) ) {
            return new WP_Error( 'invalid_order', 'Order not pending', [ 'status' => 400 ] );
        }

        $amount = (int) round( $order->get_total() * 100 );

        $payload = [
            'amount'          => $amount,
            'currency'        => 'INR',
            'receipt'         => 'wc_' . $wc_order_id,
            'payment_capture' => 1,
        ];

        $response = wp_remote_post(
            'https://api.razorpay.com/v1/orders',
            [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode( $this->key . ':' . $this->secret ),
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( $payload ),
                'timeout' => 30,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'razorpay_error', $response->get_error_message(), [ 'status' => 500 ] );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['id'] ) ) {
            return new WP_Error( 'razorpay_invalid', 'Invalid Razorpay response', [ 'status' => 500 ] );
        }

        $order->update_meta_data( '_razorpay_order_id', $body['id'] );
        $order->save();

        return $body;
    }

    /**
     * 3️⃣ Verify Razorpay signature
     */
    public function verify_payment( WP_REST_Request $request ) {

        $data = $request->get_json_params();

        $generated = hash_hmac(
            'sha256',
            $data['razorpay_order_id'] . '|' . $data['razorpay_payment_id'],
            $this->secret
        );

        if ( ! hash_equals( $generated, $data['razorpay_signature'] ) ) {
            return new WP_Error( 'invalid_signature', 'Signature verification failed', [ 'status' => 400 ] );
        }

        return [ 'success' => true ];
    }

    /**
     * 4️⃣ Mark order PAID
     */
    public function mark_order_paid( WP_REST_Request $request ) {

        $order_id   = absint( $request->get_param( 'order_id' ) );
        $payment_id = sanitize_text_field( $request->get_param( 'razorpay_payment_id' ) );

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error( 'order_not_found', 'Order not found', [ 'status' => 404 ] );
        }

        $order->payment_complete( $payment_id );
        $order->update_meta_data( '_razorpay_payment_id', $payment_id );
        $order->save();

        return [
            'success'  => true,
            'order_id' => $order_id,
        ];
    }

    /**
     * 5️⃣ Mark order as DRAFT on popup close
     */
    public function mark_order_draft( WP_REST_Request $request ) {

        $order_id = absint( $request->get_param( 'order_id' ) );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error( 'order_not_found', 'Order not found', [ 'status' => 404 ] );
        }

        // ✅ Use a VALID Woo status
        if ( $order->has_status( [ 'pending', 'on-hold' ] ) ) {

            $order->update_status(
                'cancelled', // or 'failed'
                'Razorpay popup closed by user'
            );
        }

        return [
            'success' => true,
            'status'  => $order->get_status(),
        ];
    }

}
