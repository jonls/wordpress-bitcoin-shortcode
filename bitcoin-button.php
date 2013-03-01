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
    $info = isset($atts['info']) ? $atts['info'] : 'balance';
	$t = null;

	if (!is_feed()) {
		$t = '<a class="bitcoin-button" data-address="'.$address.'" data-info="'.$info.'" href="bitcoin:'.$address.'">Bitcoin</a>';
	} else {
		$t = 'Bitcoin: <a href="bitcoin:'.$address.'">'.$address.'</a>';
	}

	return $t;
}
add_shortcode('bitcoin', 'bitcoin_button_shortcode_handler');
