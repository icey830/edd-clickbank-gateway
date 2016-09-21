<?php
/*
Plugin Name: Easy Digital Downloads - ClickBank Gateway
Plugin URI: https://easydigitaldownloads.com/extension/clickbank-gateway
Description: ClickBank gateway extension for Easy Digital Downloads.
Version: 1.2.2
Author: Brian Richards
Author URI: http://www.rzen.net
Text Domain: edd-clickbank-gateway
Domain Path: /languages/
*/

final class EDD_ClickBank_Gateway {

	/**
	 * ClickBank option for binding item numbers to downloads
	 *
	 * @var string
	 */
	public static $clickbank_option = 'clickbank_items';

	/**
	 * Construct
	 */
	public function __construct() {
		if ( class_exists( 'EDD_License' ) ) {
			$license = new EDD_License(
				__FILE__,
				'EDD ClickBank Gateway',
				'1.2.2',
				'Brian Richards'
			);
		}
		add_filter( 'edd_settings_gateways', array( $this, 'clickbank_settings' ) );
		add_action( 'add_meta_boxes',        array( $this, 'add_meta_box' ) );
		add_action( 'save_post',             array( $this, 'save_post' ) );
		add_action( 'edd_add_to_cart',       array( $this, 'edd_add_to_cart' ), 5 );
		add_action( 'init',                  array( $this, 'clickbank_process_payment' ) );
	}

	/**
	 * ClickBank settings
	 *
	 * @param array $settings
	 * @return array merged settings
	 */
	public function clickbank_settings( $settings ) {
		$clickbank_gateway_settings = array(
			array(
				'id'   => 'clickbank_settings',
				'name' => '<strong>' . __( 'ClickBank Settings', 'edd-clickbank-gateway' ) . '</strong>',
				'desc' => __( 'Configure the gateway settings', 'edd-clickbank-gateway' ),
				'type' => 'header'
			),
			array(
				'id'   => 'clickbank_account_nickname',
				'name' => __( 'Account Nickname', 'edd-clickbank-gateway' ),
				'desc' => '',
				'type' => 'text',
				'size' => 'regular',
			),
			array(
				'id'   => 'clickbank_secret_key',
				'name' => __( 'Secret Key', 'edd-clickbank-gateway' ),
				'desc' => '',
				'type' => 'text',
				'size' => 'regular',
			),
		);

		return array_merge( $settings, $clickbank_gateway_settings );

	}

	/**
	 * Register Clickbank metabox.
	 */
	public function add_meta_box() {
		add_meta_box(
			'edd_clickbank',
			__( 'ClickBank Item Number', 'edd-clickbank-gateway' ),
			array( $this, 'render_meta_box' ),
			'download',
			'side'
		);
	}

	/**
	 * Render Clickbank metabox.
	 *
	 * @param object $post
	 */
	public function render_meta_box( $post ) {
		global $edd_options;

		$item = self::get_clickbank_item( $post->ID );
		wp_nonce_field( plugin_basename( __FILE__ ), 'clickbank_nonce' );
		?>
		<div class="tagsdiv">
			<?php if ( empty( $edd_options['clickbank_account_nickname'] ) || empty( $edd_options['clickbank_secret_key'] ) ) : ?>
				<p><a href="<?php echo admin_url( 'edit.php?post_type=download&page=edd-settings&tab=gateways' ); ?>"><?php _e( 'Update your ClickBank payment gateway settings.', 'edd-clickbank-gateway' ); ?></a></p>
			<?php elseif ( edd_is_ajax_enabled() ) : ?>
				<p><a href="<?php echo admin_url( 'edit.php?post_type=download&page=edd-settings&tab=misc' ); ?>"><?php _e( 'Disable AJAX Checkout to support the ClickBank payment gateway.', 'edd-clickbank-gateway' ) ?></a></p>
			<?php else : ?>
				<p><input type="text" autocomplete="off" class="widefat" name="clickbank_item" id="clickbank_item" value="<?php echo esc_attr( $item ); ?>"></p>
				<p class="howto"><?php _e('Redirect user to the given ClickBank item during checkout.', 'edd-clickbank-gateway'); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save post data.
	 *
	 * @param string $post_id
	 */
	public function save_post( $post_id ) {
		if (
			isset( $_POST['clickbank_nonce'] )
			&& wp_verify_nonce( $_POST['clickbank_nonce'], plugin_basename( __FILE__ ) )
			&& ! defined( 'DOING_AUTOSAVE' )
			&& current_user_can( 'edit_post', $post_id )
		) {
			$item = ! empty( $_POST['clickbank_item'] ) ? esc_html( $_POST['clickbank_item'] ) : null;
			self::update_clickbank_items( $item, $post_id );
		}
	}

	/**
	 * Add clickbank item to cart.
	 *
	 * @param array $data
	 */
	public function edd_add_to_cart( $data ) {
		global $edd_options;

		$item = self::get_clickbank_item( $data['download_id'] );

		if ( ! empty( $item ) && ! empty( $edd_options['clickbank_account_nickname'] ) && ! empty( $edd_options['clickbank_secret_key'] ) ) {
			// http://ITEM.VENDOR.pay.clickbank.net
			wp_redirect( sprintf(
				'http://%1$s.%2$s.pay.clickbank.net/',
				$item,
				$edd_options['clickbank_account_nickname']
			) );
			die;
		}
	}

	/**
	 * Process payment for clickbank gateway.
	 */
	public function clickbank_process_payment() {
		global $edd_options;

		if ( self::clickbank_data_received() && ! empty( $edd_options['clickbank_secret_key'] ) ) {
			$name       = explode( ' ', rawurldecode( $_GET['cname'] ), 2 );
			$email      = sanitize_email( rawurldecode( $_GET['cemail'] ) );
			$key        = $edd_options['clickbank_secret_key'];
			$receipt    = $_GET['cbreceipt'];
			$time       = absint( $_GET['time'] );
			$item       = esc_html( $_GET['item'] );
			$product_id = self::get_edd_product_id( $item );
			$cbpop      = $_GET['cbpop'];
			$xxpop      = strtoupper( substr( sha1( "$key|$receipt|$time|$item" ), 0, 8 ) );

			// Confirm cbpop is valid, and unused, and product exists
			if ( $cbpop == $xxpop && ! self::get_used_key( $cbpop ) && false !== $product_id ) {

				self::set_current_session( $product_id );
				$user_info = self::build_user_info( $name, $email );
				$purchase_data = self::build_purchase_data( $user_info, $time );
				edd_set_purchase_session( $purchase_data );

				$payment = edd_insert_payment( $purchase_data );
				$key     = edd_get_payment_key( $payment );
				if ( $payment ) {
					self::add_used_key( $payment, $cbpop );
					edd_update_payment_status( $payment, 'complete' );
					edd_empty_cart();
					wp_redirect( add_query_arg( 'payment_key', $key, edd_get_success_page_uri() ) ); exit;
				}
			}
		}
	}

	private static function clickbank_data_received() {
		return ( ! empty( $_GET['item'] ) && ! empty( $_GET['cbreceipt'] ) && ! empty( $_GET['time'] ) && ! empty( $_GET['cbpop'] ) && ! empty( $_GET['cname'] ) && ! empty( $_GET['cemail'] ) );
	}

	private static function get_used_key( $payment_key = '' ) {
		global $wpdb;
		$payment_id = $wpdb->get_var( $wpdb->prepare(
			"
			SELECT post_id
			FROM   $wpdb->postmeta
			WHERE  meta_key = '_edd_clickbank_cbpop'
			       AND meta_value = %s
			",
			$payment_key
		) );
		return absint( $payment_id );
	}

	private static function add_used_key( $payment_id = 0, $payment_key = '' ) {
		update_post_meta( absint( $payment_id ), '_edd_clickbank_cbpop', $payment_key );
	}

	private static function set_current_session( $product_id = 0 ) {
		EDD()->session->set( 'edd_cart', array(
			array(
				'id' => absint( $product_id ),
				'options' => array(),
			),
		) );
	}

	private static function build_user_info( $name = array(), $email = '' ) {
		return array(
			'id'         => get_current_user_id(),
			'email'      => $email,
			'first_name' => ! empty( $name[0] ) ? $name[0] : '',
			'last_name'  => ! empty( $name[1] ) ? $name[1] : '',
			'discount'   => 'none',
		);
	}

	private static function build_purchase_data( $user_info = array(), $time = 0 ) {
		global $edd_options;
		$_POST['edd-gateway'] = 'ClickBank';
		return array(
			'downloads'    => edd_get_cart_contents(),
			'subtotal'     => edd_get_cart_subtotal(),
			'discount'     => edd_get_cart_discounted_amount(),
			'tax'          => edd_get_cart_tax(),
			'price'        => edd_get_cart_total(),
			'purchase_key' => strtolower( md5( uniqid() ) ),
			'user_email'   => $user_info['email'],
			'date'         => date( 'Y-m-d H:i:s', absint( $time ) ),
			'user_info'    => $user_info,
			'post_data'    => array(),
			'cart_details' => edd_get_cart_content_details(),
			'gateway'      => $_POST['edd-gateway'],
			'card_info'    => array(),
			'currency'     => $edd_options['currency'],
			'status'       => 'pending',
		);
	}

	private static function get_edd_product_id( $item = 0 ) {
		$clickbank_items = get_option( self::$clickbank_option, array() );
		return array_search( $item, $clickbank_items );
	}

	private static function get_clickbank_item( $product_id = 0 ) {
		$clickbank_items = get_option( self::$clickbank_option, array() );
		return isset( $clickbank_items[ $product_id ] ) ? absint( $clickbank_items[ $product_id ] ) : null;
	}

	private static function update_clickbank_items( $item = 0, $post_id = 0 ) {
		$clickbank_items = get_option( self::$clickbank_option, array() );

		// Only save the item ID if it's not already set for another post
		if ( ! empty( $item ) && false === self::get_edd_product_id( $item ) ) {
			$clickbank_items[ $post_id ] = $item;
		}

		// Delete setting for this post if we no longer have an item ID
		if ( empty( $item ) && isset( $clickbank_items[ $post_id ] ) ) {
			unset( $clickbank_items[ $post_id ] );
		}

		update_option( self::$clickbank_option, $clickbank_items );
	}
}
$edd_clickbank_gateway = new EDD_ClickBank_Gateway;
