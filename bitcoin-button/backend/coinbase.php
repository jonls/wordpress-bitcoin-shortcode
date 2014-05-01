<?php

class Coinbase_Backend {

	public static $name = 'Coinbase';
	public static $id   = 'coinbase';

	protected $plugin       = null;
	protected $coinbase_key = 'MISSING_KEY';

	public function __construct( $plugin ) {
		$this->plugin = $plugin;

		if ( ! is_admin() ) {
			add_action( 'init' , array( $this, 'load_scripts_init_cb' ) );
			add_action( 'template_redirect', array( $this, 'coinbase_callback' ) );
		}

		register_activation_hook( __FILE__ , array( $this, 'plugin_install' ) );

		$this->coinbase_key = get_option( 'bitcoin_button_coinbase_key', 'MISSING_KEY' );
	}

	public function load_scripts_init_cb() {
		global $wp;
		$wp->add_query_var( 'bitcoin_button_coinbase_cb' );
	}

	public function get_transaction_code( $widget ) {
		return $widget['code'];
	}

	public function get_payment_url( $widget ) {
		return 'https://coinbase.com/checkouts/' . $widget['code'];
	}

	/* Callback handler for coinbase */
	public function coinbase_callback() {
		/* Respond to coinbase callback when flag is set */
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

		/* Insert transaction */
		$this->plugin->add_transaction( $id, $ctime, $btc, $native, $code );

		exit;
	}

	public function plugin_install() {
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

	/* Handle options posted from options page */
	public function handle_options_post() {
		if ( isset( $_REQUEST['action'] ) &&
		     $_REQUEST['action'] == 'add-widget' &&
		     check_admin_referer( 'add-widget', 'add-widget-nonce' ) &&
		     isset( $_REQUEST['widget-id'] ) &&
		     isset( $_REQUEST['widget-code'] ) &&
		     isset( $_REQUEST['widget-info'] ) ) {

			/* Add new coinbase widget */
			$widget_id   = $_REQUEST['widget-id'];
			$widget_code = $_REQUEST['widget-code'];
			$widget_info = $_REQUEST['widget-info'];

			$this->plugin->add_widget( $widget_id, self::$id, $widget_info,
						   array( 'code' => $widget_code ) );
		} else if ( isset( $_REQUEST['action'] ) &&
			    $_REQUEST['action'] == 'delete-widget' &&
			    check_admin_referer( 'delete-widget', 'delete-widget-nonce' ) &&
			    isset( $_REQUEST['widget-id'] ) ) {

			/* Delete existing coinbase widget */
			$widget_id = $_REQUEST['widget-id'];

			$this->plugin->delete_widget( $widget_id );
		}
	}

	/* Create meta box for options page */
	public function options_meta_box() {
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

		echo '<form method="post">' .
			'<input type="hidden" name="backend" value="' . self::$id . '"/>' .
			'<input type="hidden" name="action" value="add-widget"/>';

		wp_nonce_field( 'add-widget', 'add-widget-nonce' );

		$info_options = array( 'count' => 'Count',
				       'received' => 'Received',
				       'off' => 'Off' );

		echo '<table style="width:100%;"><tbody>' .
			'<tr><th scope="col">Id</th>' .
			'<th scope="col">Code</th>' .
			'<th scope="col">Info</th>' .
			'<th scope="col"></th></tr>';
		foreach ( $this->plugin->get_widgets( self::$id ) as $key => $widget ) {
			$delete_args = array( 'page' => 'bitcoin-button',
					      'backend' => self::$id,
					      'action' => 'delete-widget',
					      'widget-id' => $key );
			$delete_url  = wp_nonce_url( admin_url( 'options-general.php?' . build_query( $delete_args ) ),
						     'delete-widget',
						     'delete-widget-nonce' );
			$widget_info = array_key_exists( $widget['info'], $info_options ) ? $widget['info'] : 'off';
			echo '<tr><td>' . esc_html( $key ) . '</td>' .
				'<td>' . esc_html( $widget['data']['code'] ) . '</td>' .
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
		echo '</form>';
	}
}
