<?php
/**
 * @package jonls.dk-Bitcoin-Button
 * @author Jon Lund Steffensen
 * @version 0.1
 */
/*
Plugin Name: jonls.dk-Bitcoin-Button
Plugin URI: http://jonls.dk/
Description: Shortcode for inserting a bitcoin button
Author: Jon Lund Steffensen
Version: 0.1
Author URI: http://jonls.dk/
*/

function bitcoin_button_load_scripts_init_cb() {
	if (!is_admin()) {
        wp_enqueue_style('bitcoin-button', plugins_url('/style.css', __FILE__));
		wp_enqueue_script('bitcoin-button', plugins_url('/script.js', __FILE__), array('jquery'));
        wp_localize_script('bitcoin-button', 'bitcoin_button_ajax', array('url' => admin_url('admin-ajax.php')));
	}
}
add_action('init', 'bitcoin_button_load_scripts_init_cb');


/* Shortcode handler for "bitcoin" */
function bitcoin_button_shortcode_handler($atts) {
    $address = $atts['address'];
    $info = isset($atts['info']) ? $atts['info'] : 'received';
    $amount = isset($atts['amount']) ? $atts['amount'] : null;
    $label = isset($atts['label']) ? $atts['label'] : null;
    $message = isset($atts['message']) ? $atts['message'] : null;

    /* Build bitcoin url */
    $url = 'bitcoin:'.$address;
    $params = array();
    if (!is_null($amount)) $params[] = 'amount='.urlencode($amount);
    if (!is_null($label)) $params[] = 'label='.urlencode($label);
    if (!is_null($message)) $params[] = 'message='.urlencode($message);
    if (count($params) > 0) $url .= '?'.implode('&', $params);

    /* Allow this address to be queried externally from address-info.php. */
    $address_list = get_option('bitcoin_address_list', array());
    $address_list[] = $address;
    update_option('bitcoin_address_list', $address_list);

	$t = null;
	if (!is_feed()) {
		$t = '<a class="bitcoin-button" data-address="'.$address.'" data-info="'.$info.'" href="'.$url.'">Bitcoin</a>';
	} else {
		$t = 'Bitcoin: <a href="'.$url.'">'.(!is_null($label) ? $label : $address).'</a>';
	}

	return $t;
}
add_shortcode('bitcoin', 'bitcoin_button_shortcode_handler');


/* AJAX handler for Bitcoin address data */
function bitcoin_button_get_address_info() {
    $blockchain_cache_time = 30*60;

    if (!isset($_GET['address'])) {
        header('Content-Type: text/plain');
        status_header(404);
        echo 'Invalid address';
        exit;
    }

    $address = trim($_GET['address']);
    if ($address === '') {
        header('Content-Type: text/plain');
        status_header(404);
        echo 'Invalid address';
        exit;
    }

    /* Only allow external queries that have been explicitly allowed. */
    $address_list = get_option('bitcoin_address_list', array());
    if (!in_array($address, $address_list)) {
        header('Content-Type: text/plain');
        status_header(404);
        echo 'Address not allowed';
        exit;
    }

    header('Content-Type: application/json');

    /* Set JSON content type and caching policy. */
    header('Expires: '.gmdate('D, d M Y H:i:s', time() + $blockchain_cache_time).' GMT');

    $output = array('address' => $address);

    $data = get_transient('blockchain-address-'.$address);
    if ($data === false) {
        $response = wp_remote_get('http://blockchain.info/address/'.urlencode($address).'?format=json&limit=0');
        $code = wp_remote_retrieve_response_code($response);

        if ($code != 200) {
            error_log('Response '.$code.' from blockchain.info');
            echo json_encode($output);
            exit;
        }

        $data = json_decode(wp_remote_retrieve_body($response));

        set_transient('blockchain-address-'.$address, $data, $blockchain_cache_time);
    }

    if (!isset($data->address) || $data->address != $address) {
        echo json_encode($output);
        exit;
    }

    if (isset($data->n_tx)) $output['transactions'] = intval($data->n_tx);
    if (isset($data->final_balance)) $output['balance'] = intval($data->final_balance);
    if (isset($data->total_received)) $output['received'] = intval($data->total_received);

    echo json_encode($output);
    exit;
}
add_action('wp_ajax_nopriv_bitcoin-address-info', 'bitcoin_button_get_address_info');
add_action('wp_ajax_bitcoin-address-info', 'bitcoin_button_get_address_info');
