<?php
namespace BleumiPay\PaymentGateway;
/*****************************************
 *
 * BleumiPay Retry CRON ("Retry failed transient actions") functions 
 *
 * Finds all the orders that failed during data synchronization
 * and re-performs them
 *
******************************************/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BleumiPay_Retry_CRON {

	  public static function bpcp_retry_cron_job() 
	  {
		$data_source = 'retry-cron';
		$args = array(
			'payment_method' => 'bleumipay',
			'bleumipay_transient_error' =>  'yes',
			'bleumipay_processing_completed' =>  'no',
			'orderby' => 'date_modified'
		);
		
		$retry_orders = wc_get_orders( $args );
  
		if (count($retry_orders ) > 0) {
			BleumiPay_Helper::bpcp_log('retry_cron_job : found orders count: ' .  count($retry_orders) . ' to process');
			foreach ($retry_orders as $order) {
				$action = $order->get_meta('bleumipay_retry_action');
				BleumiPay_Exception_Handler::bpcp_check_retry_count($order);
				
				$bp_hard_error = $order->get_meta('bleumipay_hard_error');
				if ($bp_hard_error == 'yes') {
					$order_id = (string) $order->get_id();
					BleumiPay_Helper::bpcp_log('retry_cron_job: Skipping, hard error found for order : ' .  $order_id);
				} else {
					BleumiPay_Helper::bpcp_log('retry_cron_job: $action: ' .  $action);
					switch ($action) {
						case "sync_order":
							BleumiPay_Orders_CRON::bpcp_sync_order($order, $data_source);
							break;
						case "sync_payment":
							BleumiPay_Payments_CRON::bpcp_sync_payment($order, $data_source);	
							break;	
						case "settle":
							$result = BleumiPay_APIHandler::bpcp_get_payment_token_balance(null, $order);
							if (is_null($result[0]['code'])) {
								BleumiPay_Orders_CRON::bpcp_settle_order($order, $result[1], $data_source);	
							}
							break;
						case "refund":
							$result = BleumiPay_APIHandler::bpcp_get_payment_token_balance(null, $order);
							if (is_null($result[0]['code'])) {
								BleumiPay_Orders_CRON::bpcp_refund_order($order, $result[1], $data_source);	
							}
							break;	
						default:
							break;
					}
				}
			}
		} 
	  }

}