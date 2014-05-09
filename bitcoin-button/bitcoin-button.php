<?php
/**
 * @package jonls.dk-Bitcoin-Button
 * @author Jon Lund Steffensen
 * @version 0.8
 */
/*
Plugin Name: jonls.dk-Bitcoin-Button
Plugin URI: http://jonls.dk/
Description: Shortcode for inserting a bitcoin button
Author: Jon Lund Steffensen
Version: 0.8
Author URI: http://jonls.dk/
*/


require_once 'backend/static.php';
require_once 'backend/coinbase.php';


class Bitcoin_Button {

	protected $db_version = '2';
	protected $table_name = null;

	protected $widgets  = array();
	protected $backends = array();
	protected $styles   = array();

	protected $options_page = null;

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'bitcoin_button_transaction';

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
		add_action( 'plugins_loaded', array( $this, 'plugin_update_db_check' ) );
		add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), array( $this, 'plugin_action_links') );

		$this->widgets = get_option( 'bitcoin_button_widgets', array() );
		$this->styles = get_option( 'bitcoin_button_styles', array() );

		/* Load styles not already loaded */
		if ( count( $this->styles ) == 0 ) $this->scan_widget_styles();

		/* Load backends */
		$this->backends[ Static_Backend::$id ] = new Static_Backend( $this );
		$this->backends[ Coinbase_Backend::$id ] = new Coinbase_Backend( $this );
	}

	public function load_scripts_init_cb() {
		global $wp;
		$wp->add_query_var( 'bitcoin_button_widget' );
	}


	public function generate_widget_iframe( $widget_id ) {
		$widget   = $this->widgets[ $widget_id ];
		$style_id = isset( $widget['style']['id'] ) ? $widget['style']['id'] : 'compact.css';
		$style    = $this->styles[ $style_id ];
		return '<iframe src="' . site_url() . '/?bitcoin_button_widget=' . urlencode( $widget_id ) . '"' .
			' width="' . $style['width'] . '" height="' . $style['height']. '" frameborder="0"' .
			' scrolling="no" title="Donate Bitcoin"' .
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

		$backend_id = $widget['backend'];
		$backend    = $this->backends[ $backend_id ];
		$url        = $backend->get_payment_url( $widget['data'] );
		$code       = $backend->get_transaction_code( $widget['data'] );

		$style_id   = isset( $widget['style']['id'] ) ? $widget['style']['id'] : 'compact.css';
		$style      = $this->styles[ $style_id ];

		echo '<!doctype html>' .
			'<html><head>' .
			'<meta charset="utf-8"/>' .
			'<title>Bitcoin Button Widget</title>' .
			'<link rel="stylesheet" href="' . plugins_url( 'style/' . $style_id, __FILE__ ) . '"/>' .
			'</head><body marginwidth="0" marginheight="0">';

		echo '<a id="button" target="_blank" href="' . $url . '"><span id="button-text">Bitcoin</span></a>';
		if ( $widget['info'] == 'received' ) {
			$amount = $wpdb->get_var( $wpdb->prepare( 'SELECT IFNULL(SUM(amount), 0) FROM ' . $this->table_name .
								  ' WHERE backend = %s AND code = %s AND' .
								  ' YEAR(ctime) = YEAR(NOW())' , $backend_id, $code ) );
			$amount = number_format( (float) $amount / 100000000 , 3 , '.' , '' );
			echo '<a id="counter" target="_blank" href="' . $url . '">' .
				'<span id="counter-text">' . $amount . ' &#3647;</span></a>';
		} else if ( $widget['info'] == 'count' ) {
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT IFNULL(COUNT(*), 0) FROM ' . $this->table_name .
								 ' WHERE backend = %s AND code = %s AND' .
								 ' YEAR(ctime) = YEAR(NOW())', $backend_id, $code ) );
			echo '<a id="counter" target="_blank" href="' . $url . '">' .
				'<span id="counter-text">' . $count . '</span></a>';
		}

		echo '</body></html>';
		exit;
	}


	/* Create database on activation */
	public function plugin_install() {
		global $wpdb;

		$installed_version = get_option( 'bitcoin_button_db_version' );
		if ( $installed_version != $this->db_version ) {

			$sql = '
CREATE TABLE ' . $this->table_name . ' (
  id VARCHAR(32) NOT NULL,
  backend VARCHAR(32) NOT NULL,
  ctime TIMESTAMP NOT NULL,
  amount DECIMAL(20) NOT NULL,
  native DECIMAL(20) NOT NULL,
  code VARCHAR(64) NOT NULL,
  UNIQUE KEY id (backend, id),
  KEY code (backend, code, ctime),
  KEY ctime (ctime)
);';

			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
			dbDelta( $sql );

			update_option( 'bitcoin_button_db_version', $this->db_version );
		}
	}

	/* Ensure that plugin database is up to date */
	public function plugin_update_db_check() {
		$installed_version = get_option( 'bitcoin_button_db_version' );
		if ( $installed_version != $this->db_version ) {
			$this->plugin_install();
		}
	}


	/* Install plugin action links */
	public function plugin_action_links( $links ) {
		$links[] = '<a href="' . get_admin_url( null, 'options-general.php?page=bitcoin-button' ) . '">Settings</a>';
		return $links;
	}


	/* Scan for widget style files */
	protected function scan_widget_styles() {
		$this->styles = array();

		$style_dir = plugin_dir_path( __FILE__ ) . 'style';
		$dh        = opendir( $style_dir );
		if ( ! $dh ) return;

		while ( true ) {
			$entry = readdir( $dh );
			if ( $entry === false ) break;

			$entry_path = $style_dir . '/' . $entry;

			if ( ! is_file( $entry_path ) ) continue;

			$metadata = get_file_data( $entry_path,
						   array( 'name' => 'Name',
							  'width' => 'Width',
							  'height' => 'Height' ) );
			if ( isset( $metadata['name'] ) &&
			     isset( $metadata['width'] ) &&
			     isset( $metadata['height'] ) ) {
				$this->styles[ $entry ] = array(
					'name' => $metadata['name'],
					'width' => intval( $metadata['width'] ),
					'height' => intval( $metadata['height'] ) );
			}
		}

		update_option( 'bitcoin_button_styles',
			       $this->styles );
	}


	/* Setup admin page */
	public function create_options_page() {
		if ( $_GET['section'] == 'transactions' ) {
			$this->create_transactions_section();
		} else if ( $_GET['section'] == 'widgets' ) {
			$this->create_widgets_section();
		} else {
			$this->create_main_section();
		}
	}

	/* Create main section, the main options page */
	protected function create_main_section() {
		/* Create options page */
		echo '<div class="wrap">' .
			'<h2>Bitcoin Shortcode</h2>' .
			'<form method="post">';

		/* These are required for sortable meta boxes but the form
		   containing the fields can be anywhere on the page. */
		wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false);
		wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false);

		echo '</form>';

		/* Create overview pane of options page */
		echo '<div id="overview-pane" class="postbox">' .
			'<div id="overview-transactions" class="inside">';

		echo '<p>Value of transactions by day / &#181;&#3647</p>';

		$this->create_transactions_chart( 'amount' );

		echo '<ul>';

		/* Link to transactions */
		$args = array( 'page' => 'bitcoin-button',
			       'section' => 'transactions' );
		echo '<li><a href="' .
			esc_url( admin_url( 'options-general.php?' . build_query( $args ) ) ) .
			'">List of Transactions</a></li>';

		/* List of widgets */
		$args = array( 'page' => 'bitcoin-button',
			       'section' => 'widgets' );
		echo '<li><a href="' .
			esc_url( admin_url( 'options-general.php?' . build_query( $args ) ) ) .
			'">List of Widgets</a></li>';

		echo '</ul>';

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

	/* Create transactions section */
	protected function create_transactions_section() {
		/* Create options page */
		echo '<div class="wrap">' .
			'<h2>Transactions</h2>';

		$args = array( 'page' => 'bitcoin-button' );
		echo '<p><a href="' . esc_url( admin_url( 'options-general.php?' . build_query( $args ) ) ) .
			'">Back to options</a></p>';

		echo '<div class="postbox"><div id="transactions" class="inside">';

		$this->create_transactions_table();

		echo '</div></div></div>';
	}

	protected function create_transactions_chart( $info='count' ) {
		global $wpdb;

		date_default_timezone_set( 'UTC' );

		echo '<svg xmlns="http://www.w3.org/2000/svg" id="transaction-stats"' .
			' style="width:100%;height:200px;">' .
			'<style>.stat-bar { fill: #2ea2cc; } .stat-bar:hover { fill: #0074a2; }' .
			' .x-label { text-anchor: middle; font-size: 0.8em; }' .
			' .y-label { text-anchor: end; alignment-baseline: middle; font-size: 0.8em; }</style>';

		/* Collect data points */
		$max_y = 1;
		$days = $wpdb->get_results( 'SELECT COUNT(*) AS count, SUM(amount) AS amount, DATE(ctime) AS day,' .
					    '  DATEDIFF(NOW(), DATE(ctime)) AS datediff FROM ' . $this->table_name .
					    ' GROUP BY day HAVING datediff < 30 AND datediff >= 0 ORDER BY day ASC' );
		$data = array();
		foreach ( $days as $day ) {
			$p = array( 'index' => $day->datediff,
				    'text' => $day->day );
			if ( $info == 'amount' ) {
				$p['value'] = $day->amount / 100;
			} else {
				$p['value'] = $day->count;
			}
			$data[] = $p;

			if ( $p['value'] > $max_y ) $max_y = $p['value'];
		}

		/* Find maximum y and step between horizontal lines */
		$step_y = pow( 10, floor( log10( $max_y ) ) );
		$step_count = ceil( (float) $max_y / $step_y );
		while ( $step_count < 4 ) {
			if ( $step_y < 2 ) break;
			$step_y /= 2;
			$step_count *= 2;
		}
		$max_y =  $step_count * $step_y;

		/* Create embedded svg that will stretch to full size of canvas */
		echo '<svg x="5%" y="5%" width="95%" height="85%"' .
			' viewBox="0 0 30 100" preserveAspectRatio="none">' .
			'<g transform="matrix(1 0 0 -1 0 100)">';

		/* Draw horizontal lines */
		for ( $i = 0; $i <= $max_y; $i += $step_y ) {
			$h = ( $i / $max_y ) * 100;
			echo '<line x1="0" y1="' . $h . '" x2="30" y2="' . $h . '" stroke="black" stroke-width="0.25"' .
				' vector-effect="non-scaling-stroke"/>';
		}

		/* Draw bars */
		foreach ( $data as $p ) {
			$x = 29 - $p['index'];
			$h = ( (float) $p['value'] / $max_y ) * 100;
			echo '<rect x="' . $x . '" y="0" width="0.9" height="' . $h . '" class="stat-bar"/>';
		}

		echo '</g></svg>';

		/* Draw x-axis labels */
		for ( $i = 1; $i < 30; $i += 2 ) {
			$text = date( 'M j', time() - ( 29 - $i )*24*60*60 );
			echo '<text x="' . ( 5 + ( 95 * ( $i + 0.5 ) / 30 ) ) . '%"' .
				' y="98%" class="x-label">' . esc_html( $text ) . '</text>';
		}

		/* Draw y-axis labels */
		for ( $i = 0; $i <= $step_count; $i++ ) {
			echo '<text x="4%" y="' . ( 90 - ( ( 85 * $i ) / $step_count ) ) . '%" class="y-label">' .
				( $i * $step_y ) . '</text>';
		}

		echo '</svg>';
	}

	protected function create_transactions_table() {
		global $wpdb;

		echo '<form method="post"><input type="hidden" name="action" value="add-transaction"/>';

		wp_nonce_field( 'add-transaction', 'add-transaction-nonce' );

		echo '<table style="width:100%;"><tbody>' .
			'<tr><th scope="col">Id</th>' .
			'<th scope="col">Backend</th>' .
			'<th scope="col">Code</th>' .
			'<th scope="col">Timestamp (UTC)</th>' .
			'<th scope="col">Amount / &#181;&#3647;</th>' .
			'<th scope="col" style="width:1px;"></th></tr>';
		$txs = $wpdb->get_results( 'SELECT id, backend, code, ctime, amount FROM ' . $this->table_name .
					   ' ORDER BY ctime DESC');
		foreach ( $txs as $tx ) {
			$delete_args = array( 'page' => 'bitcoin-button',
					      'action' => 'delete-transaction',
					      'transaction-id' => $tx->id );
			$delete_url  = wp_nonce_url( admin_url( 'options-general.php?' . build_query( $delete_args ) ),
						     'delete-transaction',
						     'delete-transaction-nonce' );
			echo '<tr><td>' . esc_html( $tx->id ) . '</td>' .
				'<td>' . esc_html( $tx->backend ) . '</td>' .
				'<td>' . esc_html( $tx->code ) . '</td>' .
				'<td>' . esc_html( $tx->ctime ) . '</td>' .
				'<td style="text-align:right;">' .
				esc_html( number_format( (float) $tx->amount / 100 , 2 , '.' , ' ' ) ) . '</td>' .
				'<td style="width:1px;"><a class="button delete" href="' . $delete_url . '">Delete</a></td>' .
				'</tr>';
		}

		date_default_timezone_set( 'UTC' );

		echo '<tr><td><input style="width:100%;" type="text" name="transaction-id"' .
			' placeholder="67LSEOSY5I"/></td>' .
			'<td><input style="width:100%;" type="text" name="transaction-backend"' .
			' placeholder="coinbase"/></td>' .
			'<td><input style="width:100%;" type="text" name="transaction-code"' .
			' placeholder="81c71f54a9579902c2b0258fc29d368f"/></td>' .
			'<td><input style="width:100%;" type="text" name="transaction-time"' .
			' value="' . date( 'Y-m-d H:i:s' ) . '"/></td>' .
			'<td><input style="width:100%;text-align:right;" type="number" name="transaction-amount"/></td>' .
			'<td style="width:1px;"><input class="button button-primary" type="submit" value="Add"/></td></tr>' .
			'</tbody></table></form>';
	}


	/* Create widgets sections */
	protected function create_widgets_section() {
		/* Create options page */
		echo '<div class="wrap">' .
			'<h2>Widgets</h2>';

		$args = array( 'page' => 'bitcoin-button' );
		echo '<p><a href="' . esc_url( admin_url( 'options-general.php?' . build_query( $args ) ) ) .
			'">Back to options</a></p>';

		echo '<div class="postbox"><div id="widgets" class="inside">';

		$example_id = 'my-widget';
		if ( count( $this->widgets ) > 0 ) {
			$example_id = array_shift( array_keys( $this->widgets ) );
		}
		echo '<p>Use the ID to add the widgets to a post or page' .
			' (Example: <code>[bitcoin id="' . esc_html( $example_id ) . '"]</code>).</p>';

		$this->create_widgets_table();

		echo '</div></div></div>';
	}

	protected function create_widgets_table() {
		$info_options = array( 'count' => 'Count',
				       'received' => 'Received',
				       'off' => 'Off' );

		echo '<table style="width:100%;"><tbody>' .
			'<tr><th scope="col">Id</th>' .
			'<th scope="col">Backend</th>' .
			'<th scope="col">Info</th>' .
			'<th scope="col">Style</th>' .
			'<th scope="col" style="width:1px;"></th></tr>';
		foreach ( $this->widgets as $key => $widget ) {
			$delete_args = array( 'page' => 'bitcoin-button',
					      'action' => 'delete-widget',
					      'widget-id' => $key );
			$delete_url  = wp_nonce_url( admin_url( 'options-general.php?' . build_query( $delete_args ) ),
						     'delete-widget',
						     'delete-widget-nonce' );
			$widget_info = array_key_exists( $widget['info'], $info_options ) ? $widget['info'] : 'off';

			$widget_style = '(unknown)';
			if ( isset( $this->styles[ $widget['style']['id'] ] ) ) {
				$style = $this->styles[ $widget['style']['id'] ];
				$widget_style = sprintf( '%s (%dx%d)', $style['name'],
							 $style['width'], $style['height'] );
			}

			echo '<tr><td>' . esc_html( $key ) . '</td>' .
				'<td>' . esc_html( $widget['backend'] ) . '</td>' .
				'<td>' . esc_html( $info_options[ $widget_info ] ) . '</td>' .
				'<td>' . esc_html( $widget_style ) . '</td>' .
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
							      'data' => $data,
							      'style' => array( 'id' => 'compact.css' ) );
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
	public function add_transaction( $id, $ctime, $amount, $native, $backend, $code ) {
		global $wpdb;

		$wpdb->insert( $this->table_name,
			       array( 'id'      => $id,
				      'ctime'   => $ctime,
				      'amount'  => $amount,
				      'native'  => $native,
				      'backend' => $backend,
				      'code'    => $code ) );
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
				$backend = trim( $_REQUEST['transaction-backend'] );
				$code = trim( $_REQUEST['transaction-code'] );
				$id = trim( $_REQUEST['transaction-id'] );
				$ctime = trim( $_REQUEST['transaction-time'] );
				$amount = floatval( $_REQUEST['transaction-amount'] ) * 100;
				$native = 0;

				if ( strlen( $code ) > 0 &&
				     strlen( $id ) > 0 &&
				     strlen( $ctime ) > 0 &&
				     isset( $this->backends[ $backend ] ) &&
				     $amount > 0 ) {
					$this->add_transaction( $id, $ctime, $amount,
								$native, $backend, $code );
				}

				wp_redirect( admin_url( 'options-general.php?page=bitcoin-button&section=transactions' ) );
				exit;
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

				wp_redirect( admin_url( 'options-general.php?page=bitcoin-button&section=transactions' ) );
				exit;
			} else if ( isset( $_REQUEST['action'] ) &&
				    $_REQUEST['action'] == 'delete-widget' &&
				    check_admin_referer( 'delete-widget', 'delete-widget-nonce' ) &&
				    isset( $_REQUEST['widget-id'] ) ) {

				/* Delete existing widget */
				$widget_id = $_REQUEST['widget-id'];

				$this->delete_widget( $widget_id );

				wp_redirect( admin_url( 'options-general.php?page=bitcoin-button&section=widgets' ) );
				exit;
			} else if ( isset( $_REQUEST['action'] ) &&
				    $_REQUEST['action'] == 'edit-style' &&
				    check_admin_referer( 'edit-style', 'edit-style-nonce' ) &&
				    isset( $_REQUEST['widget-id'] ) &&
				    isset( $_REQUEST['widget-style'] ) ) {

				/* Edit widget style */
				$widget_id    = $_REQUEST['widget-id'];
				$widget_style = $_REQUEST['widget-style'];

				if ( isset( $this->widgets[ $widget_id ] ) &&
				     isset( $this->styles[ $widget_style ] ) ) {
					$this->widgets[ $widget_id ]['style'] =
						array( 'id' => $widget_style );
					update_option( 'bitcoin_button_widgets',
						       $this->widgets );
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
		/* Postboxes toggle and rearrangement */
		echo '<script>jQuery(document).ready(function(){postboxes.add_postbox_toggles(pagenow);});</script>';
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
		add_meta_box( 'widget-style',
			      'Widget Style',
			      array( $this, 'widget_style_meta_box' ),
			      $this->options_page,
			      'normal' );

		/* Side */
		add_meta_box( 'plugin-info',
			      'Plugin Info',
			      array( $this, 'plugin_info_meta_box' ),
			      $this->options_page,
			      'side' );
	}

	public function plugin_info_meta_box() {
		echo '<ul><li><a href="https://github.com/jonls/wordpress-bitcoin-shortcode">Project page</a></li>' .
			'<li><a href="http://jonls.dk">Author Website</a></li>' .
			'<li><a href="https://twitter.com/pineappleaddict">Twitter</a></li></ul>' .
			'<p>Please consider making a donation if you find this plugin useful.</p>'.
			'<p><iframe src="http://jonls.dk/?bitcoin_button_widget=main"' .
			' width="250" height="22" frameborder="0" scrolling="no" title="Donate Bitcoin"' .
			' border="0" marginheight="0" marginwidth="0" allowtransparency="true"></iframe></p>';
	}

	public function widget_style_meta_box() {
		$this->scan_widget_styles();

		echo '<p>Assign a style to a widget to change how it looks.</p>';

		echo '<form method="post">' .
			'<input type="hidden" name="action" value="edit-style"/>';

		wp_nonce_field( 'edit-style', 'edit-style-nonce' );

		echo '<table class="form-table"><tbody>' .
			'<tr><th scope="row"><label for="style-widget-select">Widget</label></th>' .
			'<td>';

		if ( count( $this->widgets ) > 0 ) {
			echo '<select id="style-widget-select" name="widget-id">';
			foreach ( $this->widgets as $key => $widget ) {
				echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $key ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<select id="style-widget-select" disabled="disabled">' .
				'<option value="">Add a widget first</option></select>';
		}

		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="style-widget-style">Style</label></th>' .
			'<td>';

		if ( count( $this->styles ) > 0 ) {
			echo '<select id="style-widget-style" name="widget-style">';
			foreach ( $this->styles as $key => $style ) {
				$text = sprintf( '%s (%dx%d)', $style['name'],
						 $style['width'], $style['height'] );
				echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $text ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<select id="style-widget-style" disabled="disabled">' .
				'<option value="">No style found</option></select>';
		}

		echo '</td></tr>' .
			'<tr><th scope="row"></th><td><input class="button button-primary" type="submit" value="Save style"/></td></tr>';


		echo '</tbody></table>';
		echo '</form>';
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
				echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $key ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<select id="external-widget-select" disabled="disabled">' .
				'<option value="">Add a widget first</option></select>';
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
