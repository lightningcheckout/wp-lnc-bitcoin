<?php
/*
Plugin Name: Bitcoin Payment Gateway
Plugin URI: https://github.com/lightningcheckout/wp-lnc-bitcoin/tree/lnc
Forked from: https://github.com/lnbits/woocommerce-payment-gateway
Description: Accept onchain bitcoin and bitcoin via the lightning network. Brought to you by Lightning Checkout.
Version: 0.3
Author: Lightning Checkout
Author URI: https://lightningcheckout.eu
*/


if( ! class_exists( 'WP_LNC_Bitcoin_Updater' ) ){
	include_once( plugin_dir_path( __FILE__ ) . 'updater.php' );
}

$updater = new WP_LNC_Bitcoin_Updater( __FILE__ ); // instantiate our class
$updater->set_username( 'lightningcheckout' ); // set username
$updater->set_repository( 'wp-lnc-bitcoin' ); // set repo
$updater->initialize();

add_action('plugins_loaded', 'lnbits_satspay_server_init');

require_once(__DIR__ . '/includes/init.php');

use LNbitsSatsPayPlugin\Utils;
use LNbitsSatsPayPlugin\API;

// This is the entry point of the plugin, where everything is registered/hooked up into WordPress.
function lnbits_satspay_server_init()
{
    if ( ! class_exists('WC_Payment_Gateway')) {
        return;
    };

    // Set the cURL timeout to 15 seconds. When requesting a lightning invoice
    // If using a lnbits instance that is funded by a lnbits instance on Tor, a short timeout can result in failures.
    add_filter('http_request_args', 'lnbits_satspay_server_http_request_args', 100, 1);
    function lnbits_satspay_server_http_request_args($r) //called on line 237
    {
        $r['timeout'] = 15;
        return $r;
    }

    add_action('http_api_curl', 'lnbits_satspay_server_http_api_curl', 100, 1);
    function lnbits_satspay_server_http_api_curl($handle) //called on line 1315
    {
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($handle, CURLOPT_TIMEOUT, 15);
    }

    // Register the gateway, essentially a controller that handles all requests.
    function add_lnbits_satspay_server_gateway($methods)
    {
        $methods[] = 'WC_Gateway_LNbits_Satspay_Server';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_lnbits_satspay_server_gateway');

    /**
     * Grab latest post title by an author!
     */
    function lnbits_satspay_server_add_payment_complete_callback($data)
    {
        $order_id = $data["id"];
        $order    = wc_get_order($order_id);
        $order->add_order_note('Payment is settled and has been credited to your Lightning Checkout account.');
        $payment_hash = $order->get_meta('lnbits_satspay_server_payment_hash');
        $order->payment_complete();
        $order->save();
        error_log("PAID");
        echo(json_encode(array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_order_received_url(),
            'paid'     => true
        )));

        if (empty($order_id)) {
            return null;
        }
    }

    add_action("rest_api_init", function () {
        register_rest_route("lnbits_satspay_server/v1", "/payment_complete/(?P<id>\d+)", array(
            "methods"  => "GET",
            "callback" => "lnbits_satspay_server_add_payment_complete_callback",
        ));
    });


    // Defined here, because it needs to be defined after WC_Payment_Gateway is already loaded.
    class WC_Gateway_LNbits_Satspay_Server extends WC_Payment_Gateway {
        public function __construct()
        {
            global $woocommerce;

            $this->id                 = 'lnbits';
            $this->icon               = plugin_dir_url(__FILE__) . 'assets/bitcoin-icon.png';
            $this->has_fields         = false;
            $this->method_title       = 'Bitcoin';
            $this->method_description = 'Accept bitcoin via Lightning Checkout';

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option('title');
            $this->description = $this->get_option('description');

            $url       = $this->get_option('lnbits_satspay_server_url');
            $api_key   = $this->get_option('lnbits_satspay_server_api_key');
            $wallet_id   = $this->get_option('lnbits_satspay_wallet_id');
            $watch_only_wallet_id   = $this->get_option('lnbits_satspay_watch_only_wallet_id');
            $this->api = new API($url, $api_key, $wallet_id, $watch_only_wallet_id);

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
                $this,
                'process_admin_options'
            ));
            add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'check_payment'));

            // This action allows us to set the order to complete even if in a local dev environment
            add_action('woocommerce_thankyou', array(
                $this,
                'check_payment'
            ));
        }

        /**
         * Render admin options/settings.
         */
        public function admin_options()
        {
            ?>
            <h3><?php _e('Accepting Bitcoin', 'woothemes'); ?></h3>
            <p><?php _e('Accept bitcoin instantly via Lightning Checkout.', 'woothemes'); ?></p>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
            <?php

        }

        /**
         * Generate config form fields, shown in admin->WooCommerce->Settings.
         */
        public function init_form_fields()
        {
            // echo("init_form_fields");
            $this->form_fields = array(
                'enabled'                             => array(
                    'title'       => __('Bitcoin payments', 'woocommerce'),
                    'label'       => __('', 'woocommerce'),
                    'type'        => 'checkbox',
                    'description' => 'Enable',
                    'default'     => 'no',
                ),
                'title'                               => array(
                    'title'       => __('Title', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('The payment method title which a customer sees at the checkout of your store.', 'woocommerce'),
                    'default'     => __('Bitcoin', 'woocommerce'),
                ),
                'description'                         => array(
                    'title'       => __('Description', 'woocommerce'),
                    'type'        => 'textarea',
                    'description' => __('The payment method description which a customer sees at the checkout of your store.', 'woocommerce'),
                    'default'     => __(''),
                ),
                'lnbits_satspay_server_url'           => array(
                    'title'       => __('Lightning Checkout url', 'woocommerce'),
                    'description' => __('The URL where your LNbits server is running.', 'woocommerce'),
                    'type'        => 'text',
                    'default'     => 'https://pay.lightningcheckout.eu',
                ),
                'lnbits_satspay_server_api_key'       => array(
                    'title'       => __('API Key', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('Received from out support team.', 'woocommerce'),
                    'default'     => '',
                ),
                'lnbits_satspay_watch_only_wallet_id' => array(
                    'title'       => __('Onchain Wallet', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('Received from out support team.', 'woocommerce'),
                    'default'     => '',
                ),
                'lnbits_satspay_wallet_id'            => array(
                    'title'       => __('Lightning Wallet', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('Received from out support team.', 'woocommerce'),
                    'default'     => '',
                ),
            );
        }


        /**
         * ? Output for thank you page.
         */
        public function thankyou()
        {
            if ($description = $this->get_description()) {
                echo esc_html(wpautop(wptexturize($description)));
            }
        }


        /**
         * Called from checkout page, when "Place order" hit, through AJAX.
         *
         * Call LNbits API to create an invoice, and store the invoice in the order metadata.
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            // This will be stored in the invoice (ie. can be used to match orders in LNbits)
            $memo = get_bloginfo('name') . " order " . $order->get_id() . " (" . $order->get_total() . " " .  get_woocommerce_currency() . ")";

            $amount = Utils::convert_to_satoshis($order->get_total(), get_woocommerce_currency());

            $invoice_expiry_time = 15;
            // Call LNbits server to create invoice
            $r = $this->api->createCharge($amount, $memo, $order_id, $invoice_expiry_time);

            if ($r['status'] === 200) {
                $resp = $r['response'];
                $order->add_meta_data('lnbits_satspay_server_payment_id', $resp['id'], true);
                $order->add_meta_data('lnbits_satspay_server_invoice', $resp['payment_request'], true);
                $order->add_meta_data('lnbits_satspay_server_payment_hash', $resp['payment_hash'], true);
                $order->save();

                $url          = sprintf("%s/satspay/%s",
                    rtrim($this->get_option('lnbits_satspay_server_url'), '/'),
                        $resp['id']
                    );
                $redirect_url = $url;

                return array(
                    "result"   => "success",
                    "redirect" => $redirect_url
                );
            } else {
                error_log("Lightning Checkout API failure. Status=" . $r['status']);
                error_log($r['response']);

                return array(
                    "result"   => "failure",
                    "messages" => array("Failed to create Lightning Checkout invoice.")
                );
            }
        }


        /**
         * Checks whether given invoice was paid, using LNbits API,
         * and updates order metadata in the database.
         */
        public function check_payment()
        {
            $order_id = wc_get_order_id_by_order_key($_REQUEST['key']);
            $order        = wc_get_order($order_id);
            $lnbits_payment_id = $order->get_meta('lnbits_satspay_server_payment_id');
            $r            = $this->api->checkChargePaid($lnbits_payment_id);

            if ($r['status'] == 200) {
                if ($r['response']['paid'] == true) {
                    $order->add_order_note('Payment is settled and has been credited to your Lightning Checkout account.');
                    $order->payment_complete();
                    $order->save();
                    error_log("PAID");
                }
            } else {
                // TODO: handle non 200 response status
            }
            die();
        }

    }

}
