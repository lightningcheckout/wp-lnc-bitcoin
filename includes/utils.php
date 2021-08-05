<?php
namespace LNBitsPlugin;

// use OpenNode\OpenNode;
// use OpenNode\Merchant;
// use OpenNode\OrderIsNotValid;
// use OpenNode\OrderNotFound;




class Utils {
    public static function convert_to_satoshis($amount, $currency) {
        error_log($amount." ".$currency);
        $c = new CurlWrapper();
        $resp = $c->get('https://blockchain.info/tobtc', array(
            'currency' => $currency,
            'value' => $amount
        ), array());

        if ($resp['status'] != 200) {
            throw new \Exception('Blockchain.info request for currency conversion failed. Got status '.$resp['status']);
        }


        return (int)round($resp['response'] * 100000000);
    }
}

class CurlWrapper {

    private function request($method, $url, $params, $headers, $data) {
        $curl = curl_init();

        $url = add_query_arg($params, $url);

        $curl_options = array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL            => $url
        );

        if ($method == 'POST') {
            // $headers[] = 'Content-Type: application/json';
            array_merge($curl_options, array(CURLOPT_POST => 1));
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method == 'GET') {
            // array_merge($curl_options, array(CURLOPT_GET => 1));
        }

        // error_log('headers for url='.$url);
        // // error_log((string)$headers);
        // error_log('content-type='.$headers['Content-Type']);
        $user_agent = "WordPress lnbits-for-woocommerce CURL";
        curl_setopt_array($curl, $curl_options);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_USERAGENT, $user_agent);
        // curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $curlopt_ssl_verifypeer);

        error_log("curl exec");
        $raw_resp = curl_exec($curl);
        error_log($raw_resp);
        $response    = json_decode($raw_resp, TRUE);
        $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);


        return array(
            'status' => $http_status,
            'response' => $response
        );
    }


    public function get($url, $params, $headers) {
        return $this->request('GET', $url, $params, $headers, array());
    }

    public function post($url, $params, $data, $headers) {
        return $this->request('POST', $url, $params, $headers, $data);
    }

    // public function test() {
    //     return "test";
    // }
    // private $order;

    // public function __construct($order)
    // {
    //     $this->order = $order;
    // }

    // public function toHash()
    // {
    //     return $this->order;
    // }

    // public function __get($name)
    // {
    //     return $this->order[$name];
    // }

    // public static function find($orderId, $options = array(), $authentication = array())
    // {
    //     try {
    //         return self::findOrFail($orderId, $options, $authentication);
    //     } catch (OrderNotFound $e) {
    //         return false;
    //     }
    // }

    // public static function findOrFail($orderId, $options = array(), $authentication = array())
    // {
    //     $order = OpenNode::request('/checkouts/status/' . $orderId, 'GET', array(), $authentication);

    //     return new self($order);
    // }

    // public static function create($params, $options = array(), $authentication = array())
    // {
    //     try {
    //         return self::createOrFail($params, $options, $authentication);
    //     } catch (OrderIsNotValid $e) {
    //         return false;
    //     }
    // }

    // public static function createOrFail($params, $options = array(), $authentication = array())
    // {
    //     $order = OpenNode::request('/orders', 'POST', $params, $authentication);

    //     return new self($order);
    // }
}
