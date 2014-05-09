<?php

class Static_Backend {

	public static $name = 'Static Address';
	public static $id   = 'static';

	protected $plugin = null;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	public function get_transaction_code( $widget ) {
		return $widget['address'];
	}

	public function get_payment_url( $widget ) {
		return 'bitcoin:' . $widget['address'];
	}

	/* Handle options posted from options page */
	public function handle_options_post() {
		if ( isset( $_REQUEST['action'] ) &&
		     $_REQUEST['action'] == 'add-widget' &&
		     check_admin_referer( 'add-widget', 'add-widget-nonce' ) &&
		     isset( $_REQUEST['widget-id'] ) &&
		     isset( $_REQUEST['widget-address'] ) &&
		     isset( $_REQUEST['widget-info'] ) ) {

			/* Add new coinbase widget */
			$widget_id      = $_REQUEST['widget-id'];
			$widget_address = $_REQUEST['widget-address'];
			$widget_info    = $_REQUEST['widget-info'];

			$this->plugin->add_widget( $widget_id, self::$id, $widget_info,
						   array( 'address' => $widget_address ) );

			wp_redirect( admin_url( 'options-general.php?page=bitcoin-button&section=widgets' ) );
			exit;
		}
	}

	/* Create meta box for options page */
	public function options_meta_box() {
		echo '<form method="post">' .
			'<input type="hidden" name="backend" value="' . self::$id . '"/>' .
			'<input type="hidden" name="action" value="add-widget"/>';

		wp_nonce_field( 'add-widget', 'add-widget-nonce' );

		$info_options = array( 'count' => 'Count',
				       'received' => 'Received',
				       'off' => 'Off' );

		echo '<table class="form-table"><tbody>' .
			'<tr><th scope="row"><label for="static-widget-id">Widget ID</label></th>' .
			'<td><input style="width:100%;" type="text" id="static-widget-id"' .
			' name="widget-id" placeholder="my-widget"/></td></tr>' .
			'<tr><th scope="row"><label for="static-widget-address">Address</label></th>' .
			'<td><input style="width:100%;" type="text" id="static-widget-address"' .
			' name="widget-address" placeholder="1JwSSubhmg6iPtRjtyqhUYYH7bZg3Lfy1T"/></td></tr>' .
			'<tr><th score="row"><label for="static-widget-info">Info</label></th>' .
			'<td><select id="static-widget-info" name="widget-info">';

		foreach ( $info_options as $key => $value ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $value ) . '</option>';
		}

		echo '</select></td></tr>' .
			'<tr><th scope="row"></th><td><input class="button button-primary" type="submit" value="Create widget"/></td></tr>';
		echo '</tbody></table></form>';
	}
}
