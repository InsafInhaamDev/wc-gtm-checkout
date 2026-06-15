<?php
/**
 * Plugin Name: WC GTM Checkout Tracking
 * Description: Injects a Google Tag Manager container and pushes a GA4 "purchase" event to the dataLayer on the WooCommerce order-received (thank-you) page.
 * Version:     1.0.0
 * Author:      Insaf Inhaam
 * License:     GPL-2.0-or-later
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

class WC_GTM_Checkout {

	const OPTION_KEY = 'wc_gtm_checkout_settings';

	public function __construct() {
		// Admin settings page.
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Container snippet output.
		add_action( 'wp_head', array( $this, 'output_gtm_head' ), 1 );
		add_action( 'wp_body_open', array( $this, 'output_gtm_body' ), 1 );

		// Purchase event on the thank-you page (client-side).
		add_action( 'woocommerce_thankyou', array( $this, 'push_purchase_event' ), 10, 1 );

		// Capture the GA client id during checkout so the server-side fallback can attribute the purchase.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'capture_client_id' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'capture_client_id' ), 10, 1 );

		// Server-side fallback: fire when payment is confirmed, in case the customer never returned to the thank-you page.
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_send_server_purchase' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_send_server_purchase' ), 10, 1 );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	private function get_settings() {
		return wp_parse_args(
			get_option( self::OPTION_KEY, array() ),
			array(
				'container_id'        => '',
				'enabled'             => 'yes',
				'server_fallback'     => 'no',
				'ga4_measurement_id'  => '',
				'ga4_api_secret'      => '',
			)
		);
	}

	public function add_settings_page() {
		add_submenu_page(
			'woocommerce',
			__( 'GTM Checkout Tracking', 'wc-gtm-checkout' ),
			__( 'GTM Checkout', 'wc-gtm-checkout' ),
			'manage_options',
			'wc-gtm-checkout',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'wc_gtm_checkout_group',
			self::OPTION_KEY,
			array( $this, 'sanitize_settings' )
		);
	}

	public function sanitize_settings( $input ) {
		$clean = array();

		$container_id = isset( $input['container_id'] ) ? strtoupper( trim( $input['container_id'] ) ) : '';
		// Only accept a valid GTM-XXXXXX format, otherwise store empty.
		$clean['container_id'] = preg_match( '/^GTM-[A-Z0-9]+$/', $container_id ) ? $container_id : '';

		$clean['enabled'] = ( isset( $input['enabled'] ) && 'yes' === $input['enabled'] ) ? 'yes' : 'no';

		// Server-side fallback settings.
		$clean['server_fallback'] = ( isset( $input['server_fallback'] ) && 'yes' === $input['server_fallback'] ) ? 'yes' : 'no';

		$measurement_id = isset( $input['ga4_measurement_id'] ) ? strtoupper( trim( $input['ga4_measurement_id'] ) ) : '';
		$clean['ga4_measurement_id'] = preg_match( '/^G-[A-Z0-9]+$/', $measurement_id ) ? $measurement_id : '';

		$clean['ga4_api_secret'] = isset( $input['ga4_api_secret'] ) ? sanitize_text_field( trim( $input['ga4_api_secret'] ) ) : '';

		if ( 'yes' === $clean['server_fallback'] && ( '' === $clean['ga4_measurement_id'] || '' === $clean['ga4_api_secret'] ) ) {
			add_settings_error(
				self::OPTION_KEY,
				'incomplete_server',
				__( 'Server-side fallback needs both a GA4 Measurement ID (G-XXXX) and a Measurement Protocol API secret.', 'wc-gtm-checkout' )
			);
		}

		if ( '' !== $container_id && '' === $clean['container_id'] ) {
			add_settings_error(
				self::OPTION_KEY,
				'invalid_container',
				__( 'Container ID must look like GTM-XXXXXX.', 'wc-gtm-checkout' )
			);
		}

		return $clean;
	}

	public function render_settings_page() {
		$settings = $this->get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GTM Checkout Tracking', 'wc-gtm-checkout' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'wc_gtm_checkout_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wc_gtm_container_id"><?php esc_html_e( 'GTM Container ID', 'wc-gtm-checkout' ); ?></label>
						</th>
						<td>
							<input
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[container_id]"
								id="wc_gtm_container_id"
								type="text"
								class="regular-text"
								placeholder="GTM-XXXXXX"
								value="<?php echo esc_attr( $settings['container_id'] ); ?>"
							/>
							<p class="description"><?php esc_html_e( 'Found in Google Tag Manager → Admin → Container ID.', 'wc-gtm-checkout' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tracking', 'wc-gtm-checkout' ); ?></th>
						<td>
							<label>
								<input
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]"
									type="checkbox"
									value="yes"
									<?php checked( $settings['enabled'], 'yes' ); ?>
								/>
								<?php esc_html_e( 'Enable container output and purchase tracking', 'wc-gtm-checkout' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Server-side fallback (optional)', 'wc-gtm-checkout' ); ?></h2>
				<p class="description" style="max-width:640px;">
					<?php esc_html_e( 'Sends the purchase to GA4 directly from the server via the Measurement Protocol when payment is confirmed — covers customers who never return to the thank-you page (e.g. some bank/redirect gateways). Deduplicated against the client-side event per order.', 'wc-gtm-checkout' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable fallback', 'wc-gtm-checkout' ); ?></th>
						<td>
							<label>
								<input
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[server_fallback]"
									type="checkbox"
									value="yes"
									<?php checked( $settings['server_fallback'], 'yes' ); ?>
								/>
								<?php esc_html_e( 'Send a server-side purchase when the thank-you page did not fire', 'wc-gtm-checkout' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wc_gtm_ga4_id"><?php esc_html_e( 'GA4 Measurement ID', 'wc-gtm-checkout' ); ?></label>
						</th>
						<td>
							<input
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ga4_measurement_id]"
								id="wc_gtm_ga4_id"
								type="text"
								class="regular-text"
								placeholder="G-XXXXXXX"
								value="<?php echo esc_attr( $settings['ga4_measurement_id'] ); ?>"
							/>
							<p class="description"><?php esc_html_e( 'GA4 → Admin → Data Streams → your web stream.', 'wc-gtm-checkout' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wc_gtm_api_secret"><?php esc_html_e( 'Measurement Protocol API secret', 'wc-gtm-checkout' ); ?></label>
						</th>
						<td>
							<input
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ga4_api_secret]"
								id="wc_gtm_api_secret"
								type="password"
								class="regular-text"
								autocomplete="off"
								value="<?php echo esc_attr( $settings['ga4_api_secret'] ); ?>"
							/>
							<p class="description"><?php esc_html_e( 'GA4 → Admin → Data Streams → your stream → Measurement Protocol API secrets → Create.', 'wc-gtm-checkout' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Container snippet
	 * ------------------------------------------------------------------- */

	private function is_active() {
		$settings = $this->get_settings();
		return 'yes' === $settings['enabled'] && '' !== $settings['container_id'];
	}

	private function container_id() {
		$settings = $this->get_settings();
		return $settings['container_id'];
	}

	public function output_gtm_head() {
		if ( ! $this->is_active() ) {
			return;
		}
		$id = $this->container_id();
		?>
<!-- Google Tag Manager -->
<script>window.dataLayer = window.dataLayer || [];(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','<?php echo esc_js( $id ); ?>');</script>
<!-- End Google Tag Manager -->
		<?php
	}

	public function output_gtm_body() {
		if ( ! $this->is_active() ) {
			return;
		}
		$id = $this->container_id();
		?>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr( $id ); ?>"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Purchase event
	 * ------------------------------------------------------------------- */

	public function push_purchase_event( $order_id ) {
		if ( ! $this->is_active() || ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Guard against double-counting on page refresh, or against the server-side fallback firing first.
		if ( $order->get_meta( '_gtm_purchase_tracked' ) ) {
			return;
		}

		$payload = array(
			'event'     => 'purchase',
			'ecommerce' => $this->build_ecommerce( $order ),
		);

		// Mark as tracked (client-side) so a refresh or the server fallback won't fire it again.
		$order->update_meta_data( '_gtm_purchase_tracked', 'client' );
		$order->save();

		// Clear the previous ecommerce object, then push the purchase.
		echo "\n<!-- WC GTM Checkout: purchase event -->\n";
		echo '<script>window.dataLayer = window.dataLayer || [];';
		echo 'window.dataLayer.push({ ecommerce: null });';
		echo 'window.dataLayer.push(' . wp_json_encode( $payload ) . ');</script>' . "\n";
	}

	/**
	 * Build the GA4 ecommerce object (transaction + line items) shared by the
	 * client-side dataLayer push and the server-side Measurement Protocol call.
	 */
	private function build_ecommerce( $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$product   = $item->get_product();
			$item_data = array(
				'item_id'   => $product ? ( $product->get_sku() ? $product->get_sku() : $product->get_id() ) : $item->get_product_id(),
				'item_name' => $item->get_name(),
				'quantity'  => (int) $item->get_quantity(),
				'price'     => (float) wc_format_decimal( $order->get_item_total( $item, false ), wc_get_price_decimals() ),
			);

			if ( $product ) {
				$categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
					$item_data['item_category'] = $categories[0];
				}
				if ( $item->get_variation_id() ) {
					$item_data['item_variant'] = (string) $item->get_variation_id();
				}
			}

			$items[] = $item_data;
		}

		$coupons = method_exists( $order, 'get_coupon_codes' ) ? $order->get_coupon_codes() : array();

		return array(
			'transaction_id' => $order->get_order_number(),
			'value'          => (float) wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
			'tax'            => (float) wc_format_decimal( $order->get_total_tax(), wc_get_price_decimals() ),
			'shipping'       => (float) wc_format_decimal( $order->get_shipping_total(), wc_get_price_decimals() ),
			'currency'       => $order->get_currency(),
			'coupon'         => ! empty( $coupons ) ? implode( ',', $coupons ) : '',
			'items'          => $items,
		);
	}

	/* ---------------------------------------------------------------------
	 * Server-side fallback (GA4 Measurement Protocol)
	 * ------------------------------------------------------------------- */

	/**
	 * Persist the visitor's GA client id (from the _ga cookie) at checkout so the
	 * server-side event can be attributed to the same user/session in GA4.
	 */
	public function capture_client_id( $order_id ) {
		if ( empty( $order_id ) || empty( $_COOKIE['_ga'] ) ) {
			return;
		}

		// _ga cookie looks like "GA1.1.1234567890.1681234567" — client id is the last two segments.
		$parts = explode( '.', sanitize_text_field( wp_unslash( $_COOKIE['_ga'] ) ) );
		if ( count( $parts ) < 4 ) {
			return;
		}
		$client_id = $parts[ count( $parts ) - 2 ] . '.' . $parts[ count( $parts ) - 1 ];

		$order = wc_get_order( $order_id );
		if ( $order && ! $order->get_meta( '_gtm_ga_client_id' ) ) {
			$order->update_meta_data( '_gtm_ga_client_id', $client_id );
			$order->save();
		}
	}

	/**
	 * Fire a server-to-server purchase via the GA4 Measurement Protocol when payment
	 * is confirmed, unless the client-side thank-you event already covered this order.
	 */
	public function maybe_send_server_purchase( $order_id ) {
		$settings = $this->get_settings();

		if ( 'yes' !== $settings['server_fallback'] || '' === $settings['ga4_measurement_id'] || '' === $settings['ga4_api_secret'] ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Already tracked client-side (or previously sent server-side) — don't double count.
		if ( $order->get_meta( '_gtm_purchase_tracked' ) ) {
			return;
		}

		$ecommerce = $this->build_ecommerce( $order );

		// Measurement Protocol uses event params, not the dataLayer "ecommerce" wrapper.
		$event_params = array(
			'transaction_id' => $ecommerce['transaction_id'],
			'value'          => $ecommerce['value'],
			'tax'            => $ecommerce['tax'],
			'shipping'       => $ecommerce['shipping'],
			'currency'       => $ecommerce['currency'],
			'items'          => $ecommerce['items'],
		);
		if ( '' !== $ecommerce['coupon'] ) {
			$event_params['coupon'] = $ecommerce['coupon'];
		}

		$client_id = $order->get_meta( '_gtm_ga_client_id' );
		if ( ! $client_id ) {
			// No cookie captured (guest via redirect gateway); synthesise a stable id from the order.
			$client_id = $order->get_order_number() . '.' . $order->get_date_created()->getTimestamp();
		}

		$body = array(
			'client_id' => $client_id,
			'events'    => array(
				array(
					'name'   => 'purchase',
					'params' => $event_params,
				),
			),
		);

		$url = add_query_arg(
			array(
				'measurement_id' => $settings['ga4_measurement_id'],
				'api_secret'     => $settings['ga4_api_secret'],
			),
			'https://www.google-analytics.com/mp/collect'
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout'  => 5,
				'blocking' => true,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$order->add_order_note( 'GTM server-side purchase failed: ' . $response->get_error_message() );
			return;
		}

		// Mark tracked (server-side) so the thank-you page won't also fire.
		$order->update_meta_data( '_gtm_purchase_tracked', 'server' );
		$order->save();
	}
}

new WC_GTM_Checkout();