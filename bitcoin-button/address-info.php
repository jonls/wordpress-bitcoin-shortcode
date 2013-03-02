<?php

define('ABSPATH', dirname(__FILE__).'/../../../');
require_once(ABSPATH.'wp-config.php');
require_once(ABSPATH.'wp-load.php');
require_once(ABSPATH.'wp-includes/wp-db.php');
require_once(ABSPATH.'wp-includes/functions.php');
require_once(ABSPATH.'wp-includes/formatting.php');

if (!isset($_GET['address'])) {
    status_header(404);
    die();
}

$address = trim($_GET['address']);
if ($address === '') {
    status_header(404);
    die();
}

$data = get_transient('blockchain-address-'.$address);
if ($data === false) {
    $response = wp_remote_get('http://blockchain.info/address/'.urlencode($address).'?format=json&limit=0');
    $code = wp_remote_retrieve_response_code($response);

    if ($code != 200) {
        status_header(400);
        die();
    }

    $data = json_decode(wp_remote_retrieve_body($response));

    set_transient('blockchain-address-'.$address, $data, 30*60);
}

if (!isset($data->address) || $data->address != $address) {
    status_header(400);
    die();
}

if (!isset($data->n_tx) ||
    !isset($data->final_balance) ||
    !isset($data->total_received)) {
    status_header(400);
    die();
}

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

header('Content-Type: application/json');

echo ('{"address":"'.$address.'",'.
      '"transactions":'.intval($data->n_tx).','.
      '"received":'.intval($data->total_received).','.
      '"balance":'.intval($data->final_balance).'}');
