<?php
/**
 * @package jonls.dk-Bitcoin-Button
 * @author Jon Lund Steffensen
 * @version 0.4
 */
/*
Plugin Name: jonls.dk-Bitcoin-Button
Plugin URI: http://jonls.dk/
Description: Shortcode for inserting a bitcoin button
Author: Jon Lund Steffensen
Version: 0.4
Author URI: http://jonls.dk/
*/


global $bitcoin_button_db_version;
$bitcoin_button_db_version = '1';


function bitcoin_button_load_scripts_init_cb() {
	if (!is_admin()) {
        wp_enqueue_style('bitcoin-button', plugins_url('/style.css', __FILE__));
	}
}
add_action('init', 'bitcoin_button_load_scripts_init_cb');


/* Shortcode handler for "bitcoin" */
function bitcoin_button_shortcode_handler($atts) {
    global $wpdb;

    $code = $atts['code'];
    $url = 'https://coinbase.com/checkouts/'.$code;
    $info = isset($atts['info']) ? $atts['info'] : 'received';

    $table_name = $wpdb->prefix.'bitcoin_button_coinbase';

    $t = null;
    if (!is_feed()) {
        $t = '<div class="bitcoin-div">'.
            '<a class="bitcoin-button" target="_blank" href="'.$url.'">Bitcoin</a>';
        if ($info == 'received') {
            $btc = $wpdb->get_var($wpdb->prepare('SELECT IFNULL(SUM(btc), 0) FROM '.$table_name.' WHERE code = %s', $code));
            $btc = number_format((float)$btc / 100000000, 3, '.', '');
            $t .= '<a class="bitcoin-counter" target="_blank" href="'.$url.'">'.$btc.' &#3647;</a>';
        } else if ($info == 'count') {
            $count = $wpdb->get_var($wpdb->prepare('SELECT IFNULL(COUNT(*), 0) FROM '.$table_name.' WHERE code = %s', $code));
            $t .= '<a class="bitcoin-counter" target="_blank" href="'.$url.'">'.$count.'</a>';
        }
        $t .= '</div>';
    } else {
        $t = '<a href="'.$url.'" target="_blank">Bitcoin</a>';
    }

	return $t;
}
add_shortcode('bitcoin', 'bitcoin_button_shortcode_handler');


/* Callback handler for coinbase */
function bitcoin_button_coinbase_callback() {
    global $wpdb;

    /* Only activate on specific URL */
    if ($_SERVER['REQUEST_URI'] != '/coinbase_callback/testthisisasecret') return;

    /* Check request method */
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
        header('Allow: POST');
        status_header(405);
        exit;
    }

    $doc = json_decode(file_get_contents('php://input'), TRUE);

    /* Validate document */
    if (!isset($doc['order']) ||
        !isset($doc['order']['id']) ||
        !isset($doc['order']['created_at']) ||
        !isset($doc['order']['status']) ||
        $doc['order']['status'] != 'completed' ||
        !isset($doc['order']['total_btc']) ||
        !isset($doc['order']['total_btc']['cents']) ||
        !isset($doc['order']['total_native']) ||
        !isset($doc['order']['total_native']['cents']) ||
        !isset($doc['order']['button']) ||
        !isset($doc['order']['button']['id'])) {
        echo 'Validation error';
        exit;
    }

    /* Parse document */
    $id = $doc['order']['id'];

    $ctime = strtotime($doc['order']['created_at']);
    if ($ctime === FALSE) exit;

    date_default_timezone_set('UTC');
    $ctime = date('Y-m-d H:i:s', $ctime);

    $btc = $doc['order']['total_btc']['cents'];
    $native = $doc['order']['total_native']['cents'];

    $code = $doc['order']['button']['id'];

    /* Insert in database */
    $table_name = $wpdb->prefix.'bitcoin_button_coinbase';
    $wpdb->insert($table_name, array('id' => $id, 'ctime' => $ctime,
                                     'btc' => $btc, 'native' => $native,
                                     'code' => $code));

    exit;
}
add_action('init', 'bitcoin_button_coinbase_callback');


/* Create database on activation */
function bitcoin_button_install() {
    global $wpdb;
    global $bitcoin_button_db_version;

    $table_name = $wpdb->prefix.'bitcoin_button_coinbase';

    $sql = "CREATE TABLE $table_name (
  id VARCHAR(15) NOT NULL,
  ctime TIMESTAMP NOT NULL,
  btc DECIMAL(20) NOT NULL,
  native DECIMAL(20) NOT NULL,
  code VARCHAR(50) NOT NULL,
  UNIQUE KEY id (id),
  KEY code (code, ctime)
);";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    add_option('bitcoin_button_db_version', $bitcoin_button_db_version);
}
register_activation_hook(__FILE__, 'bitcoin_button_install');
