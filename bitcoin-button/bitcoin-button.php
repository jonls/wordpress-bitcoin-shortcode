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
	}
}
add_action('init', 'bitcoin_button_load_scripts_init_cb');

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
