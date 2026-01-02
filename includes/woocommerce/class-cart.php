<?php
/**
 * Plugin Name: Styluza Address Type & Bank Offer – Listener
 * Description: Exposes address_type and bank_offer to Store API Cart & Checkout.
 */
defined('ABSPATH') || exit;

class Styluza_Address_Type_Listener {

    const ADDRESS_SESSION_KEY   = 'styluza_address_type';
    const BANK_SESSION_KEY      = 'styluza_bank_offer';

    const USER_META_KEY  = '_styluza_address_type';
    const ORDER_META_KEY = '_styluza_address_type';
    const BANK_ORDER_META_KEY = '_styluza_bank_offer';

    public function __construct() {

        // Ensure session keys
        add_action('woocommerce_init', [$this, 'init_session'], 5);

        // Restore address type from user meta
        add_action('woocommerce_init', [$this, 'restore_from_user_meta'], 10);

        // Sync bank offer from Customizer
        add_action('woocommerce_init', [$this, 'sync_bank_offer'], 15);

        // Inject into Store API
        add_filter('rest_post_dispatch', [$this, 'inject_into_store_api'], 10, 3);

        // Save into order
        add_action('woocommerce_checkout_create_order', [$this, 'save_to_order'], 10, 2);
    }

    /* ---------------------------------
     * Init session keys
     * --------------------------------- */
    public function init_session() {

        if (!WC()->session) {
            return;
        }

        if (WC()->session->get(self::ADDRESS_SESSION_KEY) === null) {
            WC()->session->set(self::ADDRESS_SESSION_KEY, '');
        }

        if (WC()->session->get(self::BANK_SESSION_KEY) === null) {
            WC()->session->set(self::BANK_SESSION_KEY, []);
        }
    }

    /* ---------------------------------
     * Restore address type from user meta
     * --------------------------------- */
    public function restore_from_user_meta() {

        if (!is_user_logged_in() || !WC()->session) {
            return;
        }

        $type = get_user_meta(get_current_user_id(), self::USER_META_KEY, true);

        if ($type !== '') {
            WC()->session->set(self::ADDRESS_SESSION_KEY, $type);
        }
    }

    /* ---------------------------------
     * Sync Bank Offer from Customizer
     * --------------------------------- */
    public function sync_bank_offer() {

        if (!WC()->session) {
            return;
        }

        $enabled = (bool) get_theme_mod('styluza_bank_offer_enable', true);

        if (!$enabled) {
            WC()->session->set(self::BANK_SESSION_KEY, []);
            return;
        }

        $content = get_theme_mod('styluza_bank_offer_content', '');
        $offers  = array_filter(array_map('trim', explode("\n", $content)));

        $bank_offer = [
            'enabled'        => true,
            'title'          => get_theme_mod('styluza_bank_offer_title', 'Bank Offer'),
            'offers'         => array_values($offers),
            'show_more_text' => get_theme_mod('styluza_bank_offer_show_more_text', 'Show More'),
        ];

        WC()->session->set(self::BANK_SESSION_KEY, $bank_offer);
    }

    /* ---------------------------------
     * Inject into Store API Cart & Checkout
     * --------------------------------- */
    public function inject_into_store_api($response, $server, $request) {

        if (
            ! $request instanceof WP_REST_Request ||
            ! $response instanceof WP_REST_Response ||
            ! WC()->session
        ) {
            return $response;
        }

        $route = $request->get_route();

        if (
            $route !== '/wc/store/v1/cart' &&
            $route !== '/wc/store/v1/checkout'
        ) {
            return $response;
        }

        $data = $response->get_data();

        // Address type
        $data['address_type'] = (string) WC()->session->get(
            self::ADDRESS_SESSION_KEY,
            ''
        );

        // Bank Offer
        $data['bank_offer'] = WC()->session->get(
            self::BANK_SESSION_KEY,
            []
        );

        $response->set_data($data);

        return $response;
    }

    /* ---------------------------------
     * Save to order meta
     * --------------------------------- */
    public function save_to_order($order, $data) {

        if (!WC()->session) {
            return;
        }

        $order->update_meta_data(
            self::ORDER_META_KEY,
            WC()->session->get(self::ADDRESS_SESSION_KEY, '')
        );

        $order->update_meta_data(
            self::BANK_ORDER_META_KEY,
            WC()->session->get(self::BANK_SESSION_KEY, [])
        );
    }
}

new Styluza_Address_Type_Listener();


add_action( 'rest_api_init', function () {
  register_rest_route( 'headless/v1', '/nonce', [
    'methods'  => 'GET',
    'callback' => function () {
      return [
        'nonce' => wp_create_nonce( 'wp_rest' ),
      ];
    },
    'permission_callback' => '__return_true',
  ]);
});

remove_action('template_redirect', 'redirect_canonical');

add_action('template_redirect', function () {
    if (strpos($_SERVER['REQUEST_URI'], '/styluza/app') === 0) {
        // Stop ALL canonical redirects for proxied routes
        remove_action('template_redirect', 'redirect_canonical');
        remove_action('template_redirect', 'wc_redirect_canonical');
    }
}, 0);
