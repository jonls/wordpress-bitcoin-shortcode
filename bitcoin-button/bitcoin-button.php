<?php
/**
 * @package jonls.dk-Bitcoin-Button
 * @author Jon Lund Steffensen
 * @version 0.6
 */
/*
Plugin Name: jonls.dk-Bitcoin-Button
Plugin URI: http://jonls.dk/
Description: Shortcode for inserting a bitcoin button
Author: Jon Lund Steffensen
Version: 0.6
Author URI: http://jonls.dk/
*/


class Bitcoin_Button {

	protected $db_version = '1';
	protected $table_name = null;

	protected $coinbase_key     = 'MISSING_KEY';
	protected $coinbase_widgets = array();

	protected $options_page = null;

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'bitcoin_button_coinbase';

		/* User visible section */
		if ( ! is_admin() ) {
			add_action( 'init' , array( $this, 'load_scripts_init_cb' ) );
			add_shortcode( 'bitcoin' , array( $this, 'shortcode_handler' ) );

			add_action( 'template_redirect' , array( $this, 'generate_widget' ) );
			add_action( 'template_redirect' , array( $this, 'coinbase_callback' ) );
		}

		/* Admin section */
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			add_action( 'admin_init', array( $this, 'admin_init' ) );
		}

		register_activation_hook( __FILE__ , array( $this, 'plugin_install' ) );
		add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), array( $this, 'plugin_action_links') );

		$this->coinbase_key     = get_option( 'bitcoin_button_coinbase_key', 'MISSING_KEY' );
		$this->coinbase_widgets = get_option( 'bitcoin_button_coinbase_widgets', array() );
	}

	public function load_scripts_init_cb() {
		global $wp;
		$wp->add_query_var( 'bitcoin_button_widget' );
		$wp->add_query_var( 'bitcoin_button_coinbase_cb' );
	}


	/* Shortcode handler for "bitcoin" */
	public function shortcode_handler( $atts ) {
		$widget_id = $atts['id'];

		if ( ! isset( $this->coinbase_widgets[ $widget_id ] ) ) {
			return '<!-- bitcoin shortcode: unknown id -->';
		}

		$widget = $this->coinbase_widgets[ $widget_id ];
		$url    = 'https://coinbase.com/checkouts/' . $widget['code'];

		$t = null;
		if ( ! is_feed() ) {
			$t = '<iframe src="' . site_url() . '/?bitcoin_button_widget=' . urlencode( $widget_id ) . '"' .
				' width="250" height="22" frameborder="0" scrolling="no" title="Donate Bitcoin"' .
				' border="0" marginheight="0" marginwidth="0" allowtransparency="true"></iframe>';
		} else {
			$t = '<a href="' . $url . '" target="_blank">Donate Bitcoin</a>';
		}

		return $t;
	}


	/* Generate widget */
	public function generate_widget() {
		global $wpdb;

		/* Generate widget when flag is set */
		if ( ! get_query_var( 'bitcoin_button_widget' ) ) return;

		$widget_id = get_query_var( 'bitcoin_button_widget' );

		if ( ! isset( $this->coinbase_widgets[ $widget_id ] ) ) {
			status_header( 404 );
			exit;
		}

		$widget = $this->coinbase_widgets[ $widget_id ];
		$url    = 'https://coinbase.com/checkouts/' . $widget['code'];

		echo '<!doctype html>' .
			'<html><head>' .
			'<meta charset="utf-8"/>' .
			'<title>Bitcoin Button Widget</title>'.
			'<link rel="stylesheet" href="' . plugins_url( 'style.css' , __FILE__ ) . '"/>' .
			'</head><body marginwidth="0" marginheight="0">';

		echo '<a id="button" target="_blank" href="' . $url . '">Bitcoin</a>';
		if ($widget['info'] == 'received') {
			$btc = $wpdb->get_var( $wpdb->prepare( 'SELECT IFNULL(SUM(btc), 0) FROM ' . $this->table_name .
							       ' WHERE code = %s AND' .
							       ' YEAR(ctime) = YEAR(NOW())' , $widget['code'] ) );
			$btc = number_format( (float) $btc / 100000000 , 3 , '.' , '' );
			echo '<a id="counter" target="_blank" href="' . $url . '">' . $btc . ' &#3647;</a>';
		} else if ($widget['info'] == 'count') {
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT IFNULL(COUNT(*), 0) FROM ' . $this->table_name .
								 ' WHERE code = %s AND' .
								 ' YEAR(ctime) = YEAR(NOW())', $widget['code'] ) );
			echo '<a id="counter" target="_blank" href="' . $url . '">' . $count . '</a>';
		}

		echo '</body></html>';
		exit;
	}


	/* Callback handler for coinbase */
	public function coinbase_callback() {
		global $wpdb;

		/* Generate widget when flag is set */
		if ( ! get_query_var( 'bitcoin_button_coinbase_cb' ) ) return;

		if ( get_query_var( 'bitcoin_button_coinbase_cb' ) != $this->coinbase_key ) {
			status_header( 404 );
			exit;
		}

		/* Check request method */
		if ( $_SERVER['REQUEST_METHOD'] != 'POST' ) {
			header( 'Allow: POST' );
			status_header( 405 );
			exit;
		}

		$doc = json_decode( file_get_contents( 'php://input' ), TRUE );

		/* Validate document */
		if ( ! isset( $doc['order'] ) ||
		     ! isset( $doc['order']['id'] ) ||
		     ! isset( $doc['order']['created_at'] ) ||
		     ! isset( $doc['order']['status'] ) ||
		     $doc['order']['status'] != 'completed' ||
		     ! isset( $doc['order']['total_btc'] ) ||
		     ! isset( $doc['order']['total_btc']['cents'] ) ||
		     ! isset( $doc['order']['total_native'] ) ||
		     ! isset( $doc['order']['total_native']['cents'] ) ||
		     ! isset( $doc['order']['button'] ) ||
		     ! isset( $doc['order']['button']['id'] ) ) {
			echo 'Validation error';
			exit;
		}

		/* Parse document */
		$id = $doc['order']['id'];

		$ctime = strtotime( $doc['order']['created_at'] );
		if ( $ctime === FALSE ) exit;

		date_default_timezone_set( 'UTC' );
		$ctime = date( 'Y-m-d H:i:s' , $ctime );

		$btc    = $doc['order']['total_btc']['cents'];
		$native = $doc['order']['total_native']['cents'];

		$code = $doc['order']['button']['id'];

		/* Insert in database */
		$wpdb->insert( $this->table_name ,
			       array( 'id'     => $id,
				      'ctime'  => $ctime,
				      'btc'    => $btc,
				      'native' => $native,
				      'code'   => $code ) );

		exit;
	}


	/* Create database on activation */
	public function plugin_install() {
		global $wpdb;

		$sql = '
CREATE TABLE ' . $this->table_name . ' (
  id VARCHAR(15) NOT NULL,
  ctime TIMESTAMP NOT NULL,
  btc DECIMAL(20) NOT NULL,
  native DECIMAL(20) NOT NULL,
  code VARCHAR(50) NOT NULL,
  UNIQUE KEY id (id),
  KEY code (code, ctime)
);';

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		add_option( 'bitcoin_button_db_version', $this->db_version );

		/* Generate secret key */
		if ( get_option( 'bitcoin_button_coinbase_key' ) === false ) {
			$key = '';
			for ( $i = 0; $i < 32; $i++ ) {
				$key .= chr( mt_rand( 0, 255 ) );
			}
			$key = hash( 'sha1' , $key );
			add_option( 'bitcoin_button_coinbase_key' , $key );
		}
	}


	/* Install plugin action links */
	public function plugin_action_links( $links ) {
		$links[] = '<a href="' . get_admin_url( null, 'options-general.php?page=bitcoin-button' ) . '">Settings</a>';
		return $links;
	}


	/* Setup admin page */
	public function create_options_page() {
		/* Create actual options page */
		echo '<div class="wrap">' .
			'<h2>Bitcoin Shortcode</h2>' .
			'<form method="post">';

		/* These are required for sortable meta boxes but the form
		   containing the fields can be anywhere on the page. */
		wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false);
		wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false);

		echo '</form>';

		echo '<div id="poststuff">' .
			'<div id="post-body" class="metabox-holder columns-2">';

		echo '<div id="postbox-container-1" class="postbox-container">';
		do_meta_boxes( '', 'side', null );
		echo '</div>';

		echo '<div id="postbox-container-2" class="postbox-container">';
		do_meta_boxes( '', 'normal', null );
		echo '</div>';

		echo '</div></div></div>';
	}

	public function admin_menu() {
		/* Create options page */
		$this->options_page = add_options_page( 'Bitcoin Shortcode' ,
							'Bitcoin Shortcode' ,
							'manage_options' ,
							'bitcoin-button' ,
							array( $this, 'create_options_page' ) );

		/* Actions when loading options page */
		add_action( 'load-' . $this->options_page,
			    array( $this, 'add_options_meta_boxes' ) );
		add_action( 'admin_footer-' . $this->options_page,
			    array( $this, 'add_options_footer' ) );

		/* Actions for generating meta boxes */
		add_action( 'add_meta_boxes_' . $this->options_page,
			    array( $this, 'create_options_meta_boxes') );
	}

	public function add_options_meta_boxes() {
		global $wpdb;

		/* See if any options were posted */
		if ( ! empty( $_POST ) ||
		     isset( $_GET['action'] ) ) {
			if ( isset( $_REQUEST['action'] ) &&
			     $_REQUEST['action'] == 'add-coinbase-widget' &&
			     check_admin_referer( 'add-coinbase-widget', 'add-coinbase-widget-nonce' ) &&
			     isset( $_REQUEST['widget-id'] ) &&
			     isset( $_REQUEST['widget-code'] ) &&
			     isset( $_REQUEST['widget-info'] ) ) {

				/* Add new coinbase widget */
				$widget_id   = $_REQUEST['widget-id'];
				$widget_code = $_REQUEST['widget-code'];
				$widget_info = $_REQUEST['widget-info'];

				if ( ! isset( $this->coinbase_widgets[$widget_id] ) ) {
					$this->coinbase_widgets[$widget_id] = array( 'code' => $widget_code,
										     'info' => $widget_info );
					update_option( 'bitcoin_button_coinbase_widgets',
						       $this->coinbase_widgets );
				}
			} else if ( isset( $_REQUEST['action'] ) &&
				    $_REQUEST['action'] == 'delete-coinbase-widget' &&
				    check_admin_referer( 'delete-coinbase-widget', 'delete-coinbase-widget-nonce' ) &&
				    isset( $_REQUEST['widget-id'] ) ) {

				/* Delete existing coinbase widget */
				$widget_id = $_REQUEST['widget-id'];

				if ( isset( $this->coinbase_widgets[$widget_id] ) ) {
					unset( $this->coinbase_widgets[$widget_id] );
					update_option( 'bitcoin_button_coinbase_widgets',
						       $this->coinbase_widgets );
				}
			} else if ( isset( $_REQUEST['action'] ) &&
				    $_REQUEST['action'] == 'add-transaction' &&
				    check_admin_referer( 'add-transaction', 'add-transaction-nonce' ) &&
				    isset( $_REQUEST['transaction-code'] ) &&
				    isset( $_REQUEST['transaction-id'] ) &&
				    isset( $_REQUEST['transaction-time'] ) &&
				    isset( $_REQUEST['transaction-amount'] ) ) {

				/* Add transaction manually */
				$code = trim( $_REQUEST['transaction-code'] );
				$id = trim( $_REQUEST['transaction-id'] );
				$ctime = trim( $_REQUEST['transaction-time'] );
				$btc = floatval( $_REQUEST['transaction-amount'] ) * 100000;
				$native = 0;

				if ( strlen( $code ) > 0 &&
				     strlen( $id ) > 0 &&
				     strlen( $ctime ) > 0 &&
				     $btc > 0 ) {
					$wpdb->insert( $this->table_name,
						       array( 'id'     => $id,
							      'ctime'  => $ctime,
							      'btc'    => $btc,
							      'native' => $native,
							      'code'   => $code ) );
				}
			} else if ( isset( $_REQUEST['action'] ) &&
				    $_REQUEST['action'] == 'delete-transaction' &&
				    check_admin_referer( 'delete-transaction', 'delete-transaction-nonce' ) &&
				    isset( $_REQUEST['transaction-id'] ) ) {

				/* Delete transaction */
				$id = trim( $_REQUEST['transaction-id'] );

				if ( strlen( $id ) > 0 ) {
					$wpdb->delete( $this->table_name,
						       array( 'id' => $id ) );
				}
			}

			wp_redirect( admin_url( 'options-general.php?page=bitcoin-button' ) );
			exit;
		}

		/* Add the actual options page content */
		do_action( 'add_meta_boxes_' . $this->options_page, null );
		do_action( 'add_meta_boxes', $this->options_page, null );

		wp_enqueue_script( 'postbox' );

		add_screen_option( 'layout_columns', array( 'max' => 2, 'default' => 2) );
	}

	public function add_options_footer() {
		echo '<script>jQuery(document).ready(function(){postboxes.add_postbox_toggles(pagenow);});</script>';
	}

	public function create_options_meta_boxes() {
		/* Main */
		add_meta_box( 'coinbase-widgets',
			      'Coinbase Widgets',
			      array( $this, 'coinbase_widgets_meta_box' ),
			      $this->options_page,
			      'normal' );
		add_meta_box( 'transactions',
			      'Transactions',
			      array( $this, 'transactions_meta_box' ),
			      $this->options_page,
			      'normal' );
		add_meta_box( 'external-embed',
			      'Embed widgets externally',
			      array( $this, 'external_embed_meta_box' ),
			      $this->options_page,
			      'normal' );

		/* Side */
		add_meta_box( 'support-info',
			      'Support',
			      array( $this, 'support_info_meta_box' ),
			      $this->options_page,
			      'side' );
	}

	public function coinbase_widgets_meta_box() {
		echo '<ol><li>Go to <a target="_blank" href="https://coinbase.com/merchant_tools?link_type=hosted">' .
			'Coinbase Merchant Tools</a> and create a payment page for donations.</li>' .
			'<li>Use <code>' . site_url() . '/?bitcoin_button_coinbase_cb=' .
			urlencode( get_option( 'bitcoin_button_coinbase_key' ) ) . '</code>' .
			' as the <strong>Callback URL</strong> in Advanced Options.</li>' .
			'<li>A page will be generated using your settings. Take note of the alphanumeric code' .
			' in the page URL and add this code in the <strong>code</strong> field below.' .
			' (Example: <code>81c71f54a9579902c2b0258fc29d368f</code>)</li>' .
			'<li>Enter a name for your settings in the <strong>id</strong> field that will be used' .
			' when you add a shortcode (Example: Choose the ID <code>main</code> and use' .
			' <code>[bitcoin id="main"]</code> to add the widget in a post).</li></ol>';

		echo '<form method="post"><input type="hidden" name="action" value="add-coinbase-widget"/>';

		wp_nonce_field( 'add-coinbase-widget', 'add-coinbase-widget-nonce' );

		$info_options = array( 'count' => 'Count',
				       'received' => 'Received',
				       'off' => 'Off' );

		echo '<table style="width:100%;"><tbody>' .
			'<tr><th scope="col">Id</th>' .
			'<th scope="col">Code</th>' .
			'<th scope="col">Info</th>' .
			'<th scope="col"></th></tr>';
		foreach ( $this->coinbase_widgets as $key => $widget ) {
			$delete_args = array( 'page' => 'bitcoin-button',
					      'action' => 'delete-coinbase-widget',
					      'widget-id' => $key );
			$delete_url  = wp_nonce_url( admin_url( 'options-general.php?' . build_query( $delete_args ) ),
						     'delete-coinbase-widget',
						     'delete-coinbase-widget-nonce' );
			$widget_info = array_key_exists( $widget['info'], $info_options ) ? $widget['info'] : 'off';
			echo '<tr><td>' . esc_html( $key ) . '</td>' .
				'<td>' . esc_html( $widget['code'] ) . '</td>' .
				'<td>' . esc_html( $info_options[ $widget_info ] ) . '</td>' .
				'<td><a class="button delete" href="' . $delete_url . '">Delete</a></td></tr>';
		}

		echo '<tr><td><input style="width:100%;" type="text" name="widget-id"/></td>' .
			'<td><input style="width:100%;" type="text" name="widget-code"/></td>' .
			'<td><select style="width:100%;" name="widget-info">';

		foreach ( $info_options as $key => $value ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $value ) . '</option>';
		}

		echo '</select></td>' .
			'<td><input class="button button-primary" type="submit" value="Add"/></td></tr>' .
			'</tbody></table>';
	}

	public function transactions_meta_box() {
		global $wpdb;

		echo '<form method="post"><input type="hidden" name="action" value="add-transaction"/>';

		wp_nonce_field( 'add-transaction', 'add-transaction-nonce' );

		echo '<table style="width:100%;"><tbody>' .
			'<tr><th scope="col">Code</th>' .
			'<th scope="col">Id</th>' .
			'<th scope="col">Timestamp</th>' .
			'<th scope="col">Amount</th>' .
			'<th scope="col"></th></tr>';
		$txs = $wpdb->get_results( 'SELECT id, ctime, btc, code FROM ' . $this->table_name .
					   ' ORDER BY ctime DESC');
		foreach ( $txs as $tx ) {
			$delete_args = array( 'page' => 'bitcoin-button',
					      'action' => 'delete-transaction',
					      'transaction-id' => $tx->id );
			$delete_url  = wp_nonce_url( admin_url( 'options-general.php?' . build_query( $delete_args ) ),
						     'delete-transaction',
						     'delete-transaction-nonce' );
			echo '<tr><td>' . esc_html( $tx->code ) . '</td>' .
				'<td>' . esc_html( $tx->id ) . '</td>' .
				'<td>' . esc_html( $tx->ctime ) . '</td>' .
				'<td>' . esc_html( $tx->btc / 100000 ) . ' m&#3647</td>' .
				'<td><a class="button delete" href="' . $delete_url . '">Delete</a></td>' .
				'</tr>';
		}

		echo '<tr><td><input style="width:100%;" type="text" name="transaction-code"/></td>' .
			'<td><input style="width:100%;" type="text" name="transaction-id"/></td>' .
			'<td><input style="width:100%;" type="text" name="transaction-time"/></td>' .
			'<td><input style="width:100%;" type="text" name="transaction-amount"/></td>' .
			'<td><input class="button button-primary" type="submit" value="Add"/></td></tr>' .
			'</tbody></table>';
	}

	public function support_info_meta_box() {
		echo '<p>Please consider making a donation if you find this plugin useful.</p>'.
			'<p><iframe src="http://jonls.dk/?bitcoin_button_widget=main"' .
			' width="250" height="22" frameborder="0" scrolling="no" title="Donate Bitcoin"' .
			' border="0" marginheight="0" marginwidth="0" allowtransparency="true"></iframe></p>';
	}

	public function external_embed_meta_box() {
		echo '<p>Your widgets can be embedded in any web page by adding' .
			' the code snippet that is generated after selecting a widget in the list.</p>';

		echo '<table class="form-table"></tbody>' .
			'<tr><th scope="row"><label for="external-widget-select">Widget</label></th>' .
			'<td>';

		if ( count( $this->coinbase_widgets ) > 0 ) {
			echo '<select id="external-widget-select">';
			foreach ( $this->coinbase_widgets as $key => $widget ) {
				echo '<option name="' . esc_attr( $key ) . '">' . esc_html( $key ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<select id="external-widget-select" disabled="disabled"><option>Add a widget first</option></select>';
		}

		echo '<tr><th scope="row"><label for="external-widget-snippet">Code snippet</label></th>' .
			'<td><textarea class="large-text code" id="external-widget-snippet" readonly="readonly"' .
			' rows="5" style="width:100%;"></textarea></td></tr>';

		echo '</td></tr></tbody></table>';

		/* Generate snippet for external embedding */
		echo '<script>jQuery(document).ready(function(){' .
			'function update_snippet(widget) {' .
			'jQuery("#external-widget-snippet").val("' .
			'<iframe src=\"http://jonls.dk/?bitcoin_button_widget="+encodeURIComponent(widget)+"\"' .
			' width=\"250\" height=\"22\" frameborder=\"0\" scrolling=\"no\"' .
			' title=\"Donate Bitcoin\" border=\"0\" marginheight=\"0\" marginwidth=\"0\"' .
			' allowtransparency=\"true\"></iframe>");}' .
			'jQuery("#external-widget-select").change(function(){' .
			'update_snippet(jQuery(this).val());});' .
			'if (jQuery("#external-widget-select").is(":enabled")) {' .
			'update_snippet(jQuery("#external-widget-select").val());}});</script>';
	}

	public function admin_init() {
		register_setting( 'coinbase',
				  'bitcoin_button_coinbase_key' );
		add_settings_section( 'coinbase',
				      'Coinbase',
				      array( $this, 'coinbase_section_info' ) ,
				      'bitcoin-button' );
		add_settings_field( 'coinbase_key',
				    'Secret Key',
				    array( $this, 'coinbase_key_field' ) ,
				    'bitcoin-button',
				    'coinbase' );
	}
}

$bitcoin_button = new Bitcoin_Button();
