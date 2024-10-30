<?php
/*
Plugin Name:  Bleumi Pay Crypto Payments for WooCommerce
Plugin URI:   https://github.com/bleumipay/bleumipay-woocommerce/
Description:  Enable your WooCommerce store to accept digital currency payments with Bleumi Pay.
Version:      1.0.9
Author:       Bleumi Inc
Author URI:   https://bleumi.com/
License:      Copyright 2020 Bleumi, MIT License
License URI:  https://github.com/bleumipay/bleumipay-woocommerce/blob/master/LICENSE
GitHub Plugin URI: https://github.com/bleumipay/bleumipay-woocommerce
Text Domain:  bleumi.com
Domain Path:  /languages

WC requires at least: 3.0.9
WC tested up to: 4.1.1

*/

add_action('plugins_loaded', 'bpcp_init');

function bpcp_init()
{
	if (!class_exists('WC_Payment_Gateway')) {
        return;
	};
	
	define('BLEUMIPAY_PLUGIN_URL', plugin_dir_url(__FILE__));

	require(dirname(__FILE__) . '/includes/bleumipay_init.php');

	class WC_Gateway_Bleumipay extends WC_Payment_Gateway {

		/** @var bool Whether or not logging is enabled */
		public static $log_enabled = false;

		/** @var WC_Logger Logger instance */
		public static $log = false;

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
			$this->id                 = 'bleumipay';
			$this->has_fields         = false;
			$this->order_button_text  = __( 'Pay with Bleumi Pay', 'bleumipay' );
			$this->method_title       = __( 'Bleumi Pay', 'bleumipay' );
			$this->method_description = '<p>' .
				__( 'Accept digital currency payments (like Tether USD, USD Coin, Stasis EURO, CryptoFranc).', 'bleumipay' )
				. '</p>';

			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables.
			$this->title       = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
			$this->debug       = 'yes' === $this->get_option( 'debug', 'no' );

			self::$log_enabled = $this->debug;

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			self::init_api();	
		}

		/**
		 * Logging method.
		 */
		public static function log( $message, $level = 'info', $over_ride = true ) {
			if ( self::$log_enabled ) {
				if ( empty( self::$log ) ) {
					self::$log = wc_get_logger();
				}
				if ($over_ride) {
					self::$log->log( $level, $message, array( 'source' => 'bleumipay' ) );
				}
			}
		}

		/**
		 * Get gateway icon.
		 * @return string
		 */
		public function get_icon()
		{
			return apply_filters('woocommerce_gateway_icon', '<img src="'.BLEUMIPAY_PLUGIN_URL.'assets/images/icon.png"/>', $this->id);
		}

		/**
		 * Initialise Gateway Settings Form Fields.
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'        => array(
					'title'   => __( 'Enable/Disable', 'bleumipay' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Bleumi Pay', 'bleumipay' ),
					'default' => 'yes',
				),
				'title'          => array(
					'title'       => __( 'Title', 'bleumipay' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'bleumipay' ),
					'default'     => __( 'Pay with Digital Currencies', 'bleumipay' ),
					'desc_tip'    => true,
				),
				'description'    => array(
					'title'       => __( 'Description', 'bleumipay' ),
					'type'        => 'text',
					'desc_tip'    => true,
					'description' => __( 'This controls the description which the user sees during checkout.', 'bleumipay' ),
					'default'     => __( 'You can pay with Tether USD, USD Coin, Paxos, Binance USD, DAI, Stasis EURO, CrytoFranc and more', 'bleumipay' ),
				),
				'api_key'        => array(
					'title'       => __( 'API Key', 'bleumipay' ),
					'type'        => 'password',
					'default'     => '',
					'description' => sprintf(
						__(
							'You can view and manage your Bleumi Pay API keys from: %s',
							'bleumipay'
						),
						esc_url( 'https://pay.bleumi.com/app/' )
					),
				),
				'debug'          => array(
					'title'       => __( 'Debug log', 'bleumipay' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable logging', 'bleumipay' ),
					'default'     => 'no',
					'description' => sprintf( __( 'Log Bleumi Pay API events inside %s', 'bleumipay' ), '<code>' . WC_Log_Handler_File::get_log_file_path( 'bleumipay' ) . '</code>' ),
				),
			);
		}

		/**
		 * Validate the payment and change order status accordingly.
		 */
		public function bpcp_verify_payment() {
			$order_id = '';
			$query_string = urldecode($_SERVER['QUERY_STRING']); 
			$query_string = str_replace('&amp;', '&', $query_string);
			parse_str($query_string, $data); 
			/* check if order has been cancelled */
			if (isset($data['cancel_order']) && !is_null($data['cancel_order'])) {
				if ($data['cancel_order'] == 'true') {
					$order_id = $data['order_id'];
					if ($order_id != '') {
						\BleumiPay\PaymentGateway\BleumiPay_Helper::bpcp_log('bpcp_verify_payment: user cancelled order-id:'. $order_id);
						$order = wc_get_order( $order_id );
						$order->update_status('cancelled', __('User cancelled payment.', 'bleumipay'));
						$order->save();
						return;
					}
				}
			}

			/* do not proceed to validate if we are not on the appropriate page */
			if( !is_wc_endpoint_url( 'order-received' ) || empty( $data['hmac_input'] ) ) {
				return;
			} 
			
			$order_id = $data['id'];
			$order = wc_get_order( $order_id );
			$order->update_meta_data('bleumipay_payment_status',  'validating-payment');
			$order->save();
			$decoded_input = base64_decode($data['hmac_input']);
			\BleumiPay\PaymentGateway\BleumiPay_Helper::bpcp_log('bpcp_verify_payment: decoded_input:' . $decoded_input);
			$result  = \BleumiPay\PaymentGateway\BleumiPay_APIHandler::bpcp_validate_payment(	$data['hmac_alg'], 
														$data['hmac_keyId'], 
														$decoded_input, 
														$data['hmac_value'] 
													);
													
			if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
				\BleumiPay\PaymentGateway\BleumiPay_Helper::bpcp_log('bpcp_verify_payment: ' . $order_id . ': api request failed: '. $result[0]['message']);
				$order->update_meta_data('bleumipay_payment_status',  'pending');
				$order->save();
				return;
			} 

			$validation_result = $result[1];	
			\BleumiPay\PaymentGateway\BleumiPay_Helper::bpcp_log('bpcp_verify_payment: ' . $order_id . ': api result: ' . print_r( $validation_result, true) );
			
			//if validation is successful, change the Order status to "Processing"
			if ($validation_result) {
				$input_arr = explode ("|", $decoded_input); 
				$token_balance = array (
					'chain' => $input_arr[0],
					'addr' => $input_arr[2],
					'balance' => $input_arr[3],
				);
				$token_balances = array ($token_balance);
				$payment_info = array (
					'id' => $order_id,
					'token_balances' => $token_balances
				);
				$paid_amount =(float)$input_arr[3];
				$amount = $order->get_total();
				\BleumiPay\PaymentGateway\BleumiPay_Helper::bpcp_log('paid_amount: ' . $paid_amount . ': order amount: ' . $amount );
				if ($paid_amount >= $amount) {
					WC()->cart->empty_cart(); // Remove cart
					if (strpos($input_arr[0], 'alg_') !== false) {
						\BleumiPay\PaymentGateway\BleumiPay_Payments_CRON::bpcp_mark_order_as('processing', $order, 'awaitingconfirm', $payment_info, 'store');
					} else {
						\BleumiPay\PaymentGateway\BleumiPay_Payments_CRON::bpcp_mark_order_as('awaitingconfirm', $order, 'awaitingconfirm', $payment_info, 'store');
					}
				} else {
					$order->update_meta_data('bleumipay_payment_status',  'pending');
					$order->save();
				}
			} else {
				$order->update_meta_data('bleumipay_payment_status',  'pending');
				$order->save();
			}
		}

		/**
		 * Process the payment and return the result.
		 * @param  int $order_id
		 * @return array
		 */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			$order_total = $order->get_total();
			if (!isset($order_total) || empty($order_total))
			{
				$msg = 'Order amount is invalid or empty. Validation failed. Unable to proceed.';
				\BleumiPay\PaymentGateway\BleumiPay_Helper::bpcp_log('process_payment: ' . $msg );
				wc_add_notice( __( $msg , "bleumipay" ) );
				return array (
					'result'   => 'fail'
				);
			}

			// Create description for charge based on order's products. Ex: 1 x Product1, 2 x Product2
			$description = array();
			foreach ($order->get_items('line_item') as $item) {
				$description[] = $item['qty'] . ' × ' . $item['name'];
			}
			
			// Create a new payment.
			$id = (string) $order->get_id();
			$amount = (string) $order_total;
			$success_url = $this->get_return_url( $order );
			$cancel_url = $this->bpcp_get_cancel_url( $order );

			$currency = get_woocommerce_currency();
			$order->update_meta_data( 'bleumipay_order_currency', $currency );

			$result = \BleumiPay\PaymentGateway\BleumiPay_APIHandler::bpcp_create_payment(
				$id, 
				$currency, 
				$amount, 
				$success_url, 
				$cancel_url
			);

			if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
				$user_msg = 'Apologies. Checkout with Bleumi Pay does not appear to be working at the moment. ' . $result[0]['message'];
				\BleumiPay\PaymentGateway\BleumiPay_Helper::bpcp_log(__FUNCTION__ .': '. $result[0]['message'] );
				wc_add_notice( __( $user_msg , "bleumipay" ) );
				return array (
					'result'   => 'fail'
				);
			} else {
				$order->update_meta_data( 'bleumipay_payment_id', $id );
				$order->update_meta_data( 'bleumipay_payment_status', 'pending' );
				$order->update_meta_data( 'bleumipay_processing_completed', 'no' );
				$order->save();
				return array(
					'result'   => 'success',
					'redirect' => $result[1]['url'],
				);
			}
		}

		/**
		 * Get the cancel url.
		 * @param WC_Order $order Order object.
		 * @return string
		 */
		public function bpcp_get_cancel_url( $order ) {
			$return_url = $order->get_cancel_order_url();
			if ( is_ssl() || get_option( 'woocommerce_force_ssl_checkout' ) == 'yes' ) {
				$return_url = str_replace( 'http:', 'https:', $return_url );
			}
			return apply_filters( 'woocommerce_get_cancel_url', $return_url, $order );
		}
		
		/**
		 * Init the API class and set the API key etc.
		 */
		public function init_api() {
			\BleumiPay\PaymentGateway\BleumiPay_Helper::$log = get_class( $this ) . '::log';
			\BleumiPay\PaymentGateway\BleumiPay_APIHandler::$api_key = $this->get_option( 'api_key' );
		}

		public function payments_cron_job()
		{
			\BleumiPay\PaymentGateway\BleumiPay_Payments_CRON::bpcp_payments_cron_job();
		}

		public function orders_cron_job()
		{
			\BleumiPay\PaymentGateway\BleumiPay_Orders_CRON::bpcp_orders_cron_job();
		}

		public function retry_cron_job()
		{
			\BleumiPay\PaymentGateway\BleumiPay_Retry_CRON::bpcp_retry_cron_job();
		}

	}

}

//add 3 hour time interval to WordPress cron_schedules
function bpcp_add_cron_interval( $schedules ) {

	$time_interval = 'every_3_hours';  
	$seconds = 180*60;
	$text = 'Every 3 Hours';

	$schedules[$time_interval] = array (
		'interval' => $seconds,
		'display'  => esc_html__( $text ),
	);
	return $schedules;

}

add_filter('cron_schedules', 'bpcp_add_cron_interval');

// Setup cron job.
function bpcp_activation() {
	
	$delay1 = 15*60; // 15 mins delay
	$delay2 = 75*60; // 1 hr 15 mins 
	$delay3 = 135*60; // 2 hr 15 mins  
	$time_interval = 'every_3_hours';

	//Add cron schedules
	if (!wp_next_scheduled('bpcp_payments_cron_hook')) {
		wp_schedule_event( time() + $delay1, $time_interval, 'bpcp_payments_cron_hook');  
	}
	if (!wp_next_scheduled('bpcp_orders_cron_hook')) {
		wp_schedule_event( time() + $delay2, $time_interval, 'bpcp_orders_cron_hook'); 
	}
	if (!wp_next_scheduled('bpcp_retry_cron_hook')) {
		wp_schedule_event( time() + $delay3, $time_interval, 'bpcp_retry_cron_hook');
	}
	bpcp_install();
	
}


register_activation_hook( __FILE__, 'bpcp_activation' );

function bpcp_deactivation() {

	// remove cron schedules
	wp_clear_scheduled_hook('bpcp_orders_cron_hook');
	wp_clear_scheduled_hook('bpcp_payments_cron_hook');
	wp_clear_scheduled_hook('bpcp_retry_cron_hook');

}
register_deactivation_hook( __FILE__, 'bpcp_deactivation' );


// Adding Bleumi Pay Gateway
function bpcp_wc_add_bleumipay_class( $methods ) {
	$methods[] = 'WC_Gateway_Bleumipay';
	return $methods;
}
add_filter( 'woocommerce_payment_gateways', 'bpcp_wc_add_bleumipay_class' );

// To configure cron job schedules
function bpcp_payments_cron_fn()
{
	$gateway = WC()->payment_gateways()->payment_gateways()['bleumipay'];
	$gateway->payments_cron_job();
}

function bpcp_orders_cron_fn()
{
	$gateway = WC()->payment_gateways()->payment_gateways()['bleumipay'];
	$gateway->orders_cron_job();
}

function bpcp_retry_cron_fn()
{
	$gateway = WC()->payment_gateways()->payment_gateways()['bleumipay'];
	$gateway->retry_cron_job();
}
add_action( 'bpcp_payments_cron_hook', 'bpcp_payments_cron_fn' );
add_action( 'bpcp_orders_cron_hook', 'bpcp_orders_cron_fn' );
add_action( 'bpcp_retry_cron_hook', 'bpcp_retry_cron_fn' );

//To invoke the bpcp_verify_payment function
function bpcp_validate_payment() {
	$gateway = WC()->payment_gateways()->payment_gateways()['bleumipay'];
	return $gateway->bpcp_verify_payment();
}
add_action( 'template_redirect', 'bpcp_validate_payment' );

/**
 * Add order Bleumi Pay meta after General and before Billing
 *
 * @see: https://rudrastyh.com/woocommerce/customize-order-details.html
 *
 * @param WC_Order $order WC order instance
 */
function bpcp_order_meta_general( $order )
{
	if ($order->get_payment_method() == 'bleumipay') {
		?>

		<br class="clear"/>
		<h3>Bleumi Pay Data</h3>
		<div class="">
			<p>Bleumi Pay Reference # <?php echo esc_html($order->get_meta('bleumipay_payment_id')); ?></p>
		</div>

		<?php
	}
}
add_action( 'woocommerce_admin_order_data_after_order_details', 'bpcp_order_meta_general' );
add_action( 'woocommerce_order_details_after_order_table', 'bpcp_order_meta_general' );


/*
* Add custom link
* The url will be http://yourwordpress/wp-admin/admin.php?=wc-settings&tab=checkout
*/
function bpcp_add_action_link_payment($links)
{
	$plugin_links = array(
		'<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=bleumipay') . '">' . __('Settings', 'bleumipay') . '</a>',
	);
	return array_merge($plugin_links, $links);
}
add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), 'bpcp_add_action_link_payment');


/**
 * Creates the bleumi_cron_schedule table used by CRON jobs.
 */
function bpcp_install()
{
	global $wpdb;
	require_once(ABSPATH . '/wp-admin/includes/upgrade.php');
	$charset_collate = $wpdb->get_charset_collate();

	$table_name = $wpdb->prefix . "bleumi_cron_schedule";
	if ($wpdb->get_var("show tables like '$table_name'") != $table_name) {
		$sql = "CREATE TABLE $table_name (
			control_id BIGINT(20) UNSIGNED NOT NULL,
			bp_last_exec BIGINT(20) UNSIGNED NOT NULL,
			wc_last_exec BIGINT(20) UNSIGNED NOT NULL,
			PRIMARY KEY (control_id)
			) $charset_collate;";
		dbDelta($sql);
	}
}

/**
 * Handle a custom 'bleumipay_payment_id' query var to get orders with the 'bleumipay_payment_id' meta.
 * @param array $query - Args for WP_Query.
 * @param array $query_vars - Query vars from WC_Order_Query.
 * @return array modified $query
 */
function bpcp_handle_custom_query_var($query, $query_vars)
{
	if (!empty($query_vars['bleumipay_payment_id'])) {
		$query['meta_query'][] = array(
			'key' => 'bleumipay_payment_id',
			'value' => esc_attr($query_vars['bleumipay_payment_id']),
		);
	}
	if (!empty($query_vars['bleumipay_transient_error'])) {
		$query['meta_query'][] = array(
			'key' => 'bleumipay_transient_error',
			'value' => esc_attr($query_vars['bleumipay_transient_error']),
		);
	}
	if (!empty($query_vars['bleumipay_payment_status'])) {
		$query['meta_query'][] = array(
			'key' => 'bleumipay_payment_status',
			'value' => esc_attr($query_vars['bleumipay_payment_status']),
		);
	}
	if (!empty($query_vars['bleumipay_processing_completed'])) {
		$query['meta_query'][] = array(
			'key' => 'bleumipay_processing_completed',
			'value' => esc_attr($query_vars['bleumipay_processing_completed']),
		);
	}
	return $query;
}
add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', 'bpcp_handle_custom_query_var', 10, 2 );

/**
 * Register new statuses
 * with ID "wc-awaitingconfirm" and label "Awaiting Payment Confirmation"
 * with ID "wc-multi-token" and label "Multi Token Payment"
 */
add_action( 'init', 'bpcp_wc_register_new_statuses' );

function bpcp_wc_register_new_statuses() {
	register_post_status( 'wc-awaitingconfirm', array(
		'label'                     => _x( 'Awaiting Payment Confirmation', 'WooCommerce Order status', 'bleumipay' ),
		'public'                    => true,
		'show_in_admin_all_list'    => true, 
		'show_in_admin_status_list' => true,
		'exclude_from_search'       => false, 
		'label_count'               => _n_noop( 'Awaiting Payment Confirmation <span class="count">(%s)</span>', 'Awaiting Payment Confirmation <span class="count">(%s)</span>' ),
	) );
	register_post_status( 'wc-multi-token', array(
		'label'                     => _x( 'Multi Token Payment', 'WooCommerce Order status', 'bleumipay' ),
		'public'                    => true,
		'show_in_admin_all_list'    => true, 
		'show_in_admin_status_list' => true,
		'exclude_from_search'       => false, 
		'label_count'               => _n_noop( 'Multi Token Payment <span class="count">(%s)</span>', 'Multi Token Payment <span class="count">(%s)</span>' ),
	) );
}

/**
 * Register wc-awaitingconfirm, wc-multi-token statuses as valid for payment.
 */
add_filter( 'woocommerce_valid_order_statuses_for_payment', 'bpcp_wc_status_valid_for_payment', 10, 2 );
function bpcp_wc_status_valid_for_payment( $statuses, $order ) {
	$statuses[] = array('wc-awaitingconfirm', 'wc-multi-token');
	return $statuses;
}


add_filter( 'wc_order_statuses', 'bpcp_wc_add_status' );
/**
 * Add registered status to list of WC Order statuses
 * @param array $wc_statuses_arr Array of all order statuses on the website.
 */
function bpcp_wc_add_status( $wc_statuses_arr ) {
	$new_statuses_arr = array();

	// Add new order statuses after payment pending.
	foreach ( $wc_statuses_arr as $id => $label ) {
		$new_statuses_arr[ $id ] = $label;

		if ( 'wc-pending' === $id ) {  // after "Payment Pending" status.
			$new_statuses_arr['wc-awaitingconfirm'] = __( 'Awaiting Payment Confirmation', 'bleumipay' );
			$new_statuses_arr['wc-multi-token'] = __( 'Multi Token Payment', 'bleumipay' );
		}
	}

	return $new_statuses_arr;
}
