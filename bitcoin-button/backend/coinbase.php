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

		/* Validate document. The following code should only return error
		   headers on temporary errors that are expected to be resolved if
		   Coinbase tries to activate the callback later. */
		if ( ! isset( $doc['order'] ) ||
		     ! isset( $doc['order']['status'] ) ||
		     $doc['order']['status'] != 'completed' ||
		     ! isset( $doc['order']['total_btc'] ) ||
		     ! isset( $doc['order']['total_btc']['cents'] ) ||
		     ! isset( $doc['order']['total_native'] ) ||
		     ! isset( $doc['order']['total_native']['cents'] ) ||
		     ! isset( $doc['order']['button'] ) ) {
			echo 'Validation error';
			exit;
		}

		/* We also expect order.id, order.created_at, and order.button.id
		   to be set, but unfortunately they are not set when a callback test
		   is run from Coinbase. We have to handle this so the callback test
		   will run successfully. */

		/* Parse document */
		if ( isset( $doc['order']['id'] ) ) {
			$id = $doc['order']['id'];
		} else {
			$id = 'TEST_CALLBACK';
		}

		if ( isset( $doc['order']['created_at'] ) ) {
			$ctime = strtotime( $doc['order']['created_at'] );
			if ( $ctime === FALSE ) exit;
		} else {
			$ctime = time();
		}

		date_default_timezone_set( 'UTC' );
		$ctime = date( 'Y-m-d H:i:s' , $ctime );

		$btc    = $doc['order']['total_btc']['cents'];
		$native = $doc['order']['total_native']['cents'];

		if ( isset( $doc['order']['button']['id'] ) ) {
			$code = $doc['order']['button']['id'];
		} else {
			$code = 'TEST_CALLBACK';
		}

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

		echo '<table class="form-table"><tbody>' .
			'<tr><th scope="row"><label for="coinbase-widget-id">Widget ID</label></th>' .
			'<td><input style="width:100%;" type="text" id="coinbase-widget-id"' .
			' name="widget-id" placeholder="my-widget"/></td></tr>' .
			'<tr><th scope="row"><label for="coinbase-widget-code">Code</label></th>' .
			'<td><input style="width:100%;" type="text" id="coinbase-widget-code"' .
			' name="widget-code" placeholder="81c71f54a9579902c2b0258fc29d368f"/></td></tr>' .
			'<tr><th score="row"><label for="coinbase-widget-info">Info</label></th>' .
			'<td><select id="coinbase-widget-info" name="widget-info">';

		foreach ( $info_options as $key => $value ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $value ) . '</option>';
		}

		echo '</select></td></tr>' .
			'<tr><th scope="row"></th><td><input class="button button-primary" type="submit" value="Create widget"/></td></tr>';
		echo '</tbody></table></form>';
	}
}
