<?php
/*
Plugin Name: Bitcoin Payment Gateway
Plugin URI: https://github.com/lightningcheckout/wp-lnc-bitcoin/tree/lnc
Forked from: https://github.com/lnbits/woocommerce-payment-gateway
Description: Accept onchain bitcoin and bitcoin via the lightning network. Brought to you by Lightning Checkout.
Version: 0.6
Author: Lightning Checkout
Author URI: https://lightningcheckout.eu
*/

require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/lightningcheckout/wp-lnc-bitcoin/',
	__FILE__,
	'wp-lnc-bitcoin'
);


//Set the branch that contains the stable release.
$myUpdateChecker->setBranch('lnc');

//Optional: If you're using a private repository, specify the access token like this:
//$myUpdateChecker->setAuthentication('your-token-here');

//If you want to use release assets, call the enableReleaseAssets() method after creating the update checker instance:
$myUpdateChecker->getVcsApi()->enableReleaseAssets();

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

    add_action("init", "btcpayment_callback_endpoint");
    function btcpayment_callback_endpoint()
    {
        add_rewrite_rule("^btcpayment-callback/([^/]+)/?", 'index.php?btcpayment_callback=1&order_id=$matches[1]', "top");
    }

    add_action("query_vars", "btcpayment_callback_query_vars");
    function btcpayment_callback_query_vars($vars)
    {
        $vars[] = "btcpayment_callback";
        $vars[] = "order_id";
        return $vars;
    }

    add_action("template_redirect", "btcpayment_callback_template_redirect");
    function btcpayment_callback_template_redirect()
    {
        if (get_query_var("btcpayment_callback"))
        {
            // Check for the request method
            if ($_SERVER["REQUEST_METHOD"] === "POST")
            {
                $order_id = get_query_var("order_id"); // Retrieve the parameter value from the URL
                $postBody = file_get_contents("php://input");
                $postData = json_decode($postBody, true); // Assuming it's JSON data
                // Use $postData for further processing
                if ($postData !== null)
                {
                    // Get charge id from payload
                    $request_charge = $postData["id"];

                    // Get charge id from order
                    $order = wc_get_order($order_id);
                    $order_charge_id = $order->get_meta("lnbits_satspay_server_payment_id");

                    // Check if order charge id equals charge id in request
                    if ($request_charge == $order_charge_id)
                    {
                        // If not already marked as paid.
                        if ($order && !$order->is_paid())
                        {
                            // Get an instance of WC_Gateway_LNbits_Satspay_Server, call check_payment method
                            $lnbits_gateway = new WC_Gateway_LNbits_Satspay_Server();
                            $r = $lnbits_gateway->api->checkChargePaid($order_charge_id);
                            if ($r["status"] == 200)
                            {
                                if ($r["response"]["paid"] == true && !$order->is_paid())
                                {
                                    $order->add_order_note("Payment completed (webhook).");
                                    $order->payment_complete();
                                    $order->save();
                                }
                            }
                            die();
                        }
                    }
                    else
                    {
                        header("HTTP/1.1 400 Bad Request");
                        echo "400 Bad Request";
                        die();
                    }
                }
                else
                {
                    header("HTTP/1.1 400 Bad Request");
                    echo "400 Bad Request";
                    die();
                }
            }
            else
            {
                header("HTTP/1.1 405 Method Not Allowed");
                echo "Method Not Allowed";
                die();
            }
        }
    }

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
                    'type'        => 'hidden',
                    'default'     => __(''),
                ),
                'lnbits_satspay_server_url'           => array(
                    'type'        => 'hidden',
                    'default'     => 'https://pay.lightningcheckout.eu',
                ),
                'lnbits_satspay_server_api_key'       => array(
                    'title'       => __('API Key', 'woocommerce'),
                    'type'        => 'text',
                    'default'     => '',
                ),
                'lnbits_satspay_watch_only_wallet_id' => array(
                    'title'       => __('Onchain wallet ID', 'woocommerce'),
                    'type'        => 'text',
                    'default'     => '',
                ),
                'lnbits_satspay_wallet_id'            => array(
                    'title'       => __('Lightning wallet ID', 'woocommerce'),
                    'type'        => 'text',
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

            $invoice_expiry_time = 720;

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
                    $order->add_order_note('Payment completed (checkout).');
                    $order->payment_complete();
                    $order->save();
                }
            } else {
                // TODO: handle non 200 response status
            }
            die();
        }

    }

}
