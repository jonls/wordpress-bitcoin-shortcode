<?php

define('ABSPATH', dirname(__FILE__).'/../../../');
require_once(ABSPATH.'wp-config.php');
require_once(ABSPATH.'wp-load.php');
require_once(ABSPATH.'wp-includes/functions.php');
require_once(ABSPATH.'wp-includes/formatting.php');

$blockchain_cache_time = 30*60;

if (!isset($_GET['address'])) {
    status_header(404);
    die();
}

$address = trim($_GET['address']);
if ($address === '') {
    status_header(404);
    die();
}

/* Only allow external queries that have been explicitly allowed. */
$address_list = get_option('bitcoin_address_list', array());
if (!in_array($address, $address_list)) {
    status_header(404);
    die();
}

/* Set JSON content type and caching policy. */
header('Content-Type: application/json');
header('Expires: '.gmdate('D, d M Y H:i:s', time() + $blockchain_cache_time).' GMT');

$output = array('address' => $address);

$data = get_transient('blockchain-address-'.$address);
if ($data === false) {
    $response = wp_remote_get('http://blockchain.info/address/'.urlencode($address).'?format=json&limit=0');
    $code = wp_remote_retrieve_response_code($response);

    if ($code != 200) {
        error_log('Response '.$code.' from blockchain.info');
        echo json_encode($output);
        die();
    }

    $data = json_decode(wp_remote_retrieve_body($response));

    set_transient('blockchain-address-'.$address, $data, $blockchain_cache_time);
}

if (!isset($data->address) || $data->address != $address) {
    echo json_encode($output);
    die();
}

if (isset($data->n_tx)) $output['transactions'] = intval($data->n_tx);
if (isset($data->final_balance)) $output['balance'] = intval($data->final_balance);
if (isset($data->total_received)) $output['received'] = intval($data->total_received);

echo json_encode($output);
