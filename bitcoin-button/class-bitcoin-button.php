<?php

class Bitcoin_Button {
	
	protected $address_list = array(); // Contains all addresses used so far
	protected $blockchain_cache_time = 1800;
	
	public function __construct() {
		// Front-end only actions
		if ( !is_admin() ) {
			add_action( 'init', array( $this, 'load_scripts' ) );
			add_shortcode( 'bitcoin', array( $this, 'do_shortcode' ) );
		}
		
		// XHR callbacks
		add_action( 'wp_ajax_nopriv_bitcoin-address-info', array( $this, 'get_address_info' ) );
		add_action( 'wp_ajax_bitcoin-address-info', array( $this, 'get_address_info' ) );
		
		// Initialise valid addresses
		$this->address_list = get_option( 'bitcoin_address_list', array() );
	}
	
	public function load_scripts() {
		wp_enqueue_style( 'bitcoin-button', plugins_url( 'style.css', __FILE__ ) );
		wp_enqueue_script( 'bitcoin-button', plugins_url( 'script.js', __FILE__ ), array( 'jquery' ) );
		wp_localize_script( 'bitcoin-button', 'bitcoin_button', array( 'url' => admin_url( 'admin-ajax.php' ) ) );
	}
	
	public function get_address_info() {
		$address = @$_GET['address'];
		
		 // Bad request
		if ( ! $address ) {
			$this->send_failure( 400 );
		}

		$output = array( 'address' => $address );
		$data = get_transient('btcaddr-' . $address); // Previous blockchain request

		if ( $data === false ) {
			$response = wp_remote_get( 'http://blockchain.info/address/' . urlencode( $address ) . '?format=json&limit=0' );
			$code = wp_remote_retrieve_response_code( $response );

			if ( $code !== 200 )
				$this->send_data( $output );

			$data = json_decode( wp_remote_retrieve_body( $response ) );

			set_transient('btcaddr-' . $address, $data, $this->blockchain_cache_time);
		}

		// If there's a bad address, abort
		if ( ! isset( $data->address ) || $data->address != $address )
			$this->send_data();

		// Transaction data
		if ( isset( $data->n_tx ) )
			$output['transactions'] = intval( $data->n_tx );

		// Balance data
		if ( isset( $data->final_balance ) )
			$output['balance'] = intval( $data->final_balance );

		// Total data
		if ( isset( $data->total_received ) )
			$output['received'] = intval( $data->total_received );

		$this->send_data( $data );
	}
	
	public function do_shortcode( $atts ) {
		// Output
		$html = '';

		// Grab the address and info
		$address = isset( $atts['address'] ) ? trim( $atts['address'] ) : '';
		$info    = isset( $atts['info'] ) ? trim( $atts['info'] ) : 'received';

		// Fail if the address is empty
		if ( ! $address ) {
			return $html;
		}

		// Assign defaults for attributes
		$params = shortcode_atts(array(
			'amount'  => '',
			'label'   => '',
			'message' => ''
		), $atts);

		// Build bitcoin url with optional params
		$url = "bitcoin:{$address}";
		if ( $query = http_build_query( $params ) ) {
			$url .= "?{$query}";
		}

		// Whitelist this address (it's being used by the system) and then update available addresses
		// @todo: Find an alternative to saving addresses every shortcode parse
		$this->add_address($address);
		$this->update_address_list();
		
		if ( ! is_feed() ) {
			$html = '<a class="bitcoin-button" data-address="' . $address . '" data-info="' . $info . '" href="' . $url . '">Bitcoin</a>';
		} else {
			$html = 'Bitcoin: <a href="' . $url . '">' . (isset( $params['label'] ) && $params['label'] ? $params['label'] : $address) . '</a>';
		}
		
		return $html;
	}
	
	protected function update_address_list() {
		if ( count( $this->address_list ) ) {
			update_option( 'bitcoin_address_list', $this->address_list );
		}
	}
	
	protected function add_address( $address ) {
		$this->address_list[] = $address;
		array_unique( $this->address_list );
	}
	
	protected function is_valid_address( $address ) {
		// Only allow external queries that have been explicitly allowed.
		if ( ! in_array( $address, $this->address_list ) ) {
			$this->send_failure( 403 );
		}
	}
	
	protected function send_data( $data ) {
		/**
		 * 200: OK
		 */
		header( 'Content-Type: application/json' );
		header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $this->blockchain_cache_time ) . ' GMT' );
		status_header( 200 );
		
		echo json_encode( $data );
		
		exit;
	}
	
	protected function send_failure( $code = 404 ) {
		/**
		 * 400: Bad request
		 * 403: Forbidden/Not allowed
		 * 404: Not found
		 * 500: Internal server error
		 */
		header( 'Content-Type: text/plain' );
		status_header( $code );
		exit;
	}
}