<?php
/**
 * @package jonls.dk-Bitcoin-Button
 * @author Jon Lund Steffensen
 * @version 0.7
 */
/*
Plugin Name: jonls.dk-Bitcoin-Button
Plugin URI: http://jonls.dk/
Description: Shortcode for inserting a bitcoin button
Author: Jon Lund Steffensen
Version: 0.7
Author URI: http://jonls.dk/
*/


require_once 'backend/coinbase.php';


class Bitcoin_Button {

	protected $db_version = '1';
	protected $table_name = null;

	protected $widgets  = array();
	protected $backends = array();

	protected $options_page = null;

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'bitcoin_button_coinbase';

		/* User visible section */
		if ( ! is_admin() ) {
			add_action( 'init' , array( $this, 'load_scripts_init_cb' ) );
			add_shortcode( 'bitcoin' , array( $this, 'shortcode_handler' ) );

			add_action( 'template_redirect', array( $this, 'generate_widget' ) );
		}

		/* Admin section */
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		}

		register_activation_hook( __FILE__ , array( $this, 'plugin_install' ) );
		add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), array( $this, 'plugin_action_links') );

		$this->widgets = get_option( 'bitcoin_button_widgets', array() );

		/* Load backends */
		$this->backends[ Coinbase_Backend::$id ] = new Coinbase_Backend( $this );
	}

	public function load_scripts_init_cb() {
		global $wp;
		$wp->add_query_var( 'bitcoin_button_widget' );
	}


	public function generate_widget_iframe( $widget_id ) {
		return '<iframe src="' . site_url() . '/?bitcoin_button_widget=' . urlencode( $widget_id ) . '"' .
			' width="250" height="22" frameborder="0" scrolling="no" title="Donate Bitcoin"' .
			' border="0" marginheight="0" marginwidth="0" allowtransparency="true"></iframe>';
	}

	/* Shortcode handler for "bitcoin" */
	public function shortcode_handler( $atts ) {
		$widget_id = $atts['id'];

		if ( ! isset( $this->widgets[ $widget_id ] ) ) {
			return '<!-- bitcoin shortcode: unknown id -->';
		}

		$widget = $this->widgets[ $widget_id ];
		if ( ! isset( $this->backends[ $widget['backend'] ] ) ) {
			return '<!-- bitcoin shortcode: unknown backend -->';
		}

		$backend = $this->backends[ $widget['backend'] ];
		$url     = $backend->get_payment_url( $widget['data'] );

		$t = null;
		if ( ! is_feed() ) {
			$t = $this->generate_widget_iframe( $widget_id );
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

		if ( ! isset( $this->widgets[ $widget_id ] ) ) {
			status_header( 404 );
			exit;
		}

		$widget = $this->widgets[ $widget_id ];
		if ( ! isset( $this->backends[ $widget['backend'] ] ) ) {
			status_header( 404 );
			exit;
		}

		$backend = $this->backends[ $widget['backend'] ];
		$url     = $backend->get_payment_url( $widget['data'] );
		$code    = $backend->get_transaction_code( $widget['data'] );

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
							       ' YEAR(ctime) = YEAR(NOW())' , $code ) );
			$btc = number_format( (float) $btc / 100000000 , 3 , '.' , '' );
			echo '<a id="counter" target="_blank" href="' . $url . '">' . $btc . ' &#3647;</a>';
		} else if ($widget['info'] == 'count') {
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT IFNULL(COUNT(*), 0) FROM ' . $this->table_name .
								 ' WHERE code = %s AND' .
								 ' YEAR(ctime) = YEAR(NOW())', $code ) );
			echo '<a id="counter" target="_blank" href="' . $url . '">' . $count . '</a>';
		}

		echo '</body></html>';
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
	}


	/* Install plugin action links */
	public function plugin_action_links( $links ) {
		$links[] = '<a href="' . get_admin_url( null, 'options-general.php?page=bitcoin-button' ) . '">Settings</a>';
		return $links;
	}


	/* Setup admin page */
	public function create_options_page() {
		/* Create options page */
		echo '<div class="wrap">' .
			'<h2>Bitcoin Shortcode</h2>' .
			'<form method="post">';

		/* These are required for sortable meta boxes but the form
		   containing the fields can be anywhere on the page. */
		wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false);
		wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false);

		echo '</form>';

		wp_enqueue_script( 'jquery-ui-tabs' );

		/* Create overview pane of options page */
		echo '<div id="overview-pane" class="postbox">' .
			'<ul class="category-tabs">' .
			'<li><a href="#overview-transactions">Transactions</a></li>' .
			'<li><a href="#overview-widgets">Widgets</a></li>' .
			'</ul>' .
			'<br class="clear"/>' .
			'<div id="overview-transactions" class="inside">';

		$this->create_transactions_table();

		echo'</div><div id="overview-widgets" class="hidden inside">';

		$this->create_widgets_table();

		echo '</div></div>';

		echo '<div id="poststuff">' .
			'<div id="post-body" class="metabox-holder columns-' .
			( ( get_current_screen()->get_columns() == 1 ) ? '1' : '2' ) .
			'">';

		/* Create containers for meta boxes */
		echo '<div id="postbox-container-1" class="postbox-container">';
		do_meta_boxes( '', 'side', null );
		echo '</div>';

		echo '<div id="postbox-container-2" class="postbox-container">';
		do_meta_boxes( '', 'normal', null );
		echo '</div>';

		echo '</div></div></div>';
	}

	protected function create_transactions_table() {
		global $wpdb;

		echo '<form method="post"><input type="hidden" name="action" value="add-transaction"/>';

		wp_nonce_field( 'add-transaction', 'add-transaction-nonce' );

		echo '<table style="width:100%;"><tbody>' .
			'<tr><th scope="col">Code</th>' .
			'<th scope="col">Id</th>' .
			'<th scope="col">Timestamp</th>' .
			'<th scope="col">Amount</th>' .
			'<th scope="col" style="width:1px;"></th></tr>';
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
				'<td style="width:1px;"><a class="button delete" href="' . $delete_url . '">Delete</a></td>' .
				'</tr>';
		}

		echo '<tr><td><input style="width:100%;" type="text" name="transaction-code"/></td>' .
			'<td><input style="width:100%;" type="text" name="transaction-id"/></td>' .
			'<td><input style="width:100%;" type="text" name="transaction-time"/></td>' .
			'<td><input style="width:100%;" type="text" name="transaction-amount"/></td>' .
			'<td style="width:1px;"><input class="button button-primary" type="submit" value="Add"/></td></tr>' .
			'</tbody></table>';
	}

	protected function create_widgets_table() {
		$info_options = array( 'count' => 'Count',
				       'received' => 'Received',
				       'off' => 'Off' );

		echo '<table style="width:100%;"><tbody>' .
			'<tr><th scope="col">Id</th>' .
			'<th scope="col">Backend</th>' .
			'<th scope="col">Info</th>' .
			'<th scope="col" style="width:1px;"></th></tr>';
		foreach ( $this->widgets as $key => $widget ) {
			$delete_args = array( 'page' => 'bitcoin-button',
					      'action' => 'delete-widget',
					      'widget-id' => $key );
			$delete_url  = wp_nonce_url( admin_url( 'options-general.php?' . build_query( $delete_args ) ),
						     'delete-widget',
						     'delete-widget-nonce' );
			$widget_info = array_key_exists( $widget['info'], $info_options ) ? $widget['info'] : 'off';
			echo '<tr><td>' . esc_html( $key ) . '</td>' .
				'<td>' . esc_html( $widget['backend'] ) . '</td>' .
				'<td>' . esc_html( $info_options[ $widget_info ] ) . '</td>' .
				'<td style="width:1px;"><a class="button delete" href="' . $delete_url . '">Delete</a></td></tr>';
		}

		echo '</tbody></table>';
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
			    array( $this, 'create_options_meta_boxes' ) );
	}

	/* Add new widget */
	public function add_widget( $widget_id, $backend_id, $info, $data ) {
		if ( ! isset( $this->widgets[ $widget_id ] ) ) {
			$this->widgets[ $widget_id ] = array( 'backend' => $backend_id,
							      'info' => $info,
							      'data' => $data );
			update_option( 'bitcoin_button_widgets',
				       $this->widgets );
		}
	}

	/* Delete widget by id */
	public function delete_widget( $widget_id ) {
		if ( isset( $this->widgets[ $widget_id ] ) ) {
			unset( $this->widgets[ $widget_id ] );
			update_option( 'bitcoin_button_widgets',
				       $this->widgets );
		}
	}

	/* Return array of widgets for a specific backend */
	public function get_widgets( $backend ) {
		$r = array();
		foreach ( $this->widgets as $key => $widget ) {
			if ( $widget['backend'] == $backend ) {
				$r[ $key ] = $widget;
			}
		}
		return $r;
	}

	/* Insert new transaction into database */
	public function add_transaction( $id, $ctime, $btc, $native, $code ) {
		global $wpdb;

		$wpdb->insert( $this->table_name,
			       array( 'id'     => $id,
				      'ctime'  => $ctime,
				      'btc'    => $btc,
				      'native' => $native,
				      'code'   => $code ) );
	}

	public function add_options_meta_boxes() {
		global $wpdb;

		/* See if any options were posted */
		if ( ! empty( $_POST ) ||
		     isset( $_GET['action'] ) ) {
			if ( isset( $_REQUEST['backend'] ) ) {

				/* Defer to backend */
				$backend_name = $_REQUEST['backend'];
				if ( isset( $this->backends[ $backend_name ] ) ) {
					$backend = $this->backends[ $backend_name ];
					$backend->handle_options_post();
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
					$this->add_transaction( $id, $ctime, $btc,
								$native, $code );
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
			} else if ( isset( $_REQUEST['action'] ) &&
				    $_REQUEST['action'] == 'delete-widget' &&
				    check_admin_referer( 'delete-widget', 'delete-widget-nonce' ) &&
				    isset( $_REQUEST['widget-id'] ) ) {

				/* Delete existing coinbase widget */
				$widget_id = $_REQUEST['widget-id'];

				$this->delete_widget( $widget_id );
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
		/* Postboxes toggle and rearrangement */
		echo '<script>jQuery(document).ready(function(){postboxes.add_postbox_toggles(pagenow);});</script>';

		/* Postbox tabs */
		echo '<script>jQuery(document).ready(function($){$("#overview-pane .hidden").removeClass("hidden");' .
			'$("#overview-pane").tabs();})</script>';
	}

	public function create_options_meta_boxes() {
		/* Backend meta boxes */
		foreach ( $this->backends as $id => $backend ) {
			add_meta_box( $id . '-backend',
				      $backend::$name,
				      array( $backend, 'options_meta_box' ),
				      $this->options_page,
				      'normal' );
		}

		/* Main */
		add_meta_box( 'external-embed',
			      'Embed Widgets Externally',
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

	public function support_info_meta_box() {
		echo '<p>Please consider making a donation if you find this plugin useful.</p>'.
			'<p><iframe src="http://jonls.dk/?bitcoin_button_widget=main"' .
			' width="250" height="22" frameborder="0" scrolling="no" title="Donate Bitcoin"' .
			' border="0" marginheight="0" marginwidth="0" allowtransparency="true"></iframe></p>';
	}

	public function external_embed_meta_box() {
		echo '<p>Your widgets can be embedded in any web page by adding' .
			' the code snippet that is generated after selecting a widget in the list.</p>';

		echo '<table class="form-table"><tbody>' .
			'<tr><th scope="row"><label for="external-widget-select">Widget</label></th>' .
			'<td>';

		if ( count( $this->widgets ) > 0 ) {
			echo '<select id="external-widget-select">';
			foreach ( $this->widgets as $key => $widget ) {
				echo '<option name="' . esc_attr( $key ) . '">' . esc_html( $key ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<select id="external-widget-select" disabled="disabled"><option>Add a widget first</option></select>';
		}

		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="external-widget-snippet">Code snippet</label></th>' .
			'<td><textarea class="large-text code" id="external-widget-snippet" readonly="readonly"' .
			' rows="5" style="width:100%;"></textarea></td></tr>';

		echo '</tbody></table>';

		/* Generate snippet for external embedding */
		echo '<script>jQuery(document).ready(function(){' .
			'function update_snippet(widget) {' .
			'jQuery("#external-widget-snippet").val("' .
			'<iframe src=\"' . esc_url( site_url() ) . '/?bitcoin_button_widget="+encodeURIComponent(widget)+"\"' .
			' width=\"250\" height=\"22\" frameborder=\"0\" scrolling=\"no\"' .
			' title=\"Donate Bitcoin\" border=\"0\" marginheight=\"0\" marginwidth=\"0\"' .
			' allowtransparency=\"true\"></iframe>");}' .
			'jQuery("#external-widget-select").change(function(){' .
			'update_snippet(jQuery(this).val());});' .
			'if (jQuery("#external-widget-select").is(":enabled")) {' .
			'update_snippet(jQuery("#external-widget-select").val());}});</script>';
	}
}

$bitcoin_button = new Bitcoin_Button();
