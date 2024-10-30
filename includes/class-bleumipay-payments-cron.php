<?php
namespace BleumiPay\PaymentGateway;
/*****************************************
 *
 * Bleumi Pay Payments CRON ("Payments Processor") functions 
 *
 * Check statuses/payment received in BleumiPay and update WC Orders.
 * 
 * All payments received after the last time this job run
 * are applied to the WC orders
 *	 
******************************************/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BleumiPay_Payments_CRON {

	public static function bpcp_payments_cron_job()
	{
		$data_source = 'payments-cron';
		$next_token = '';
		$updated_at = 0;
		
		//Get last_exec of payments-cron
		$col_name = 'bp_last_exec';
		$previous_execution_time = BleumiPay_Helper::bpcp_get_last_execution_time($col_name);
		BleumiPay_Helper::bpcp_log('payments_cron_job: looking for payment modified after : ' . $previous_execution_time);
		do {
			$result = BleumiPay_APIHandler::bpcp_get_payments($previous_execution_time, $next_token);
			if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
				BleumiPay_Helper::bpcp_log('payments_cron_job: get_payments api request failed. '. $result[0]['message'] . ' exiting payments-cron.');
				return $result[0];
			} 
			$payments = $result[1]['results'];
			if (is_null($payments)) {
				BleumiPay_Helper::bpcp_log('payments_cron_job: unable to fetch payments to process');
				$errorStatus = array (
					'code' => -1, 
					'message' => __('no payments data found.', 'bleumipay')
				);
				return $errorStatus;
			}
			$next_token = $result[1]['next_token'];
			if (is_null($next_token)) {
				$next_token = '';
			}
			foreach ($payments as $payment) {
				$order_id = $payment['id'];
				$updated_at = $payment['updated_at'];
				$args = array(
					'bleumipay_payment_id' => $order_id
				);
				$orders = wc_get_orders( $args );
				if (count($orders) == 1) {
					self::bpcp_sync_payment($orders[0], $data_source, $payment);
				} else if (count($orders) > 1) {
					foreach ($orders as $order) {
						$order_id = (string) $order->get_id();
						$retry_action = 'sync_payment';
						$code = 'E904';
						$msg = "payments_cron_job:'. $order_id .' conflict - multiple orders found for same payment id";
						BleumiPay_Exception_Handler::bpcp_log_hard_exception($order, $retry_action, $code, $msg);
						$order->save();
					}
				}
			} 
		} while ($next_token !== '');

		// Update last_exec for Orders_CRON
		if ($updated_at > 0 ) {
			BleumiPay_Helper::bpcp_update_last_execution_time($col_name, $updated_at + 1);
			BleumiPay_Helper::bpcp_log('payments_cron_job: setting last execution time: ' .  $updated_at);
		} 	
	}

	/**
	 * Update the status of an order because of payments received.
	 * Called by 'payments-cron'
	 * Also adds notes to WC Order about transaction hashes for transfers
	 */
	public static function bpcp_sync_payment($order, $data_source, $payment = null)
	{
		$order_id = (string) $order->get_id();
		$bp_hard_error = $order->get_meta('bleumipay_hard_error');
		// If there is a hard error (or) transient error action does not match, return
		$bp_transient_error = $order->get_meta('bleumipay_transient_error');
		$bp_retry_action = $order->get_meta('bleumipay_retry_action');
		if (($bp_hard_error == 'yes') || (($bp_transient_error == 'yes') && ($bp_retry_action != 'sync_payment'))) {
			$msg = 'sync_order:'. $order_id .' Skipping, hard error found (or) retry_action mismatch, order retry_action is : ' . $bp_retry_action;
			BleumiPay_Helper::bpcp_log($msg);
			return;
		} 

		// If already processing completed, no need to sync
		$bp_processing_completed = $order->get_meta('bleumipay_processing_completed');
		if ($bp_processing_completed == 'yes') {
			$msg = 'Processing already completed for this order. No further changes possible.';
			BleumiPay_Helper::bpcp_log( 'sync_payment:'. $order_id .' ' . $msg );
			return;
		}

		// Exit payments_cron update if bp_payment_status indicated operations are in progress or completed
		$order_status = $order->get_status(); 
		$bp_payment_status = $order->get_meta('bleumipay_payment_status');
		$invalid_bp_statuses = array('settle_in_progress', 'settled', 'settle_failed',  'refund_in_progress' ,  'refunded', 'refund_failed');
		if (in_array($bp_payment_status, $invalid_bp_statuses)) 		{
			$msg = 'sync_payment:'. $order_id .' exiting .. bp_status:' . $bp_payment_status .' order_status:'. $order_status;
			BleumiPay_Helper::bpcp_log( $msg );
			return;	
		}


		// skip payments_cron update if order was sync-ed by orders_cron in recently.
		$bp_data_source = $order->get_meta('bleumipay_data_source');
		$currentTime = strtotime(new \WC_DateTime()); //server unix time
		$date_modified = strtotime($order->get_date_modified()); // coverts formated date to unix time
		$minutes = BleumiPay_Helper::bpcp_getMinutesDiff($currentTime, $date_modified);
		if ( $minutes < BleumiPay_Helper::$cron_collision_safe_minutes ) {
			if (($data_source === 'payments-cron') && ( $bp_data_source === 'orders-cron') ) {
				$msg = __('sync_payment:'. $order_id .' skipping payment processing at this time as Orders_CRON processed this order recently, will be processing again later', 'bleumipay');
				BleumiPay_Exception_Handler::bpcp_log_transient_exception($order, 'sync_payment', 'E102', $msg);
				return;
			}
		}

		$result = BleumiPay_APIHandler::bpcp_get_payment_token_balance($payment, $order);
		if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
			//If balance of more than 1 token is found, mark the order as "multi_token".
			$msg = $result[0]['message'];
			if ($result[0]['code'] == -2) {
				$success = BleumiPay_Helper::bpcp_multi_token_order($order, $msg);
				if ($success) {
					BleumiPay_Helper::bpcp_log( "sync_order:". $order_id ." token balance error : ". $msg .", order status changed to 'Multi Token Payment' ");
				}
			}
			BleumiPay_Helper::bpcp_log( 'sync_payment:'. $order_id .' token balance error : '. $msg );
			return;
		} 
		$payment_info = $result[1];

		//Save the payment address info every-time
		$addresses = $payment_info['addresses'];
		if (isset($addresses) && !is_null($addresses)) {
			$order->update_meta_data('bleumipay_addresses', json_encode($addresses, JSON_PRETTY_PRINT) );
			$order->save();
		}
		$amount = (float) $payment_info['token_balances'][0]['balance'];
		BleumiPay_Helper::bpcp_log( 'sync_payment:'. $order_id .' amount : '. $amount );
		$order_amount = (float)  $order->get_total();
		BleumiPay_Helper::bpcp_log( 'sync_payment:'. $order_id .' order_amount : '. $order_amount );
		
		if ( !is_null($amount) && ($amount >= $order_amount) ) {
			$success = self::bpcp_mark_order_as('processing', $order, 'payment-received', $payment_info, $data_source);
			if ($success) {
				$msg = 'sync_payment:'. $order_id ."  changing status to 'processing'.";
			}
		} else {
			$msg = 'sync_payment:'. $order_id .'  bp_status:' . $bp_payment_status .' order_status:'. $order_status;
		}
		BleumiPay_Helper::bpcp_log( $msg );
		 

	}

	public static function bpcp_mark_order_as($new_status, $order, $new_bp_payment_status, $payment_info, $data_source) {
		$success = false;
		$order_id = (string) $order->get_id();
		$order_status = $order->get_status(); 
		$current_bp_status =$order->get_meta('bleumipay_payment_status');
		$valid_statuses = array();
		$valid_bp_statuses = array();
		switch ($new_status) {
			case "awaitingconfirm":
				$valid_statuses = array('pending');
				$valid_bp_statuses = array('pending', 'validating-payment');
				break;
			case "processing":
				$valid_statuses = array('awaitingconfirm', 'pending', 'multi-token');
				$valid_bp_statuses = array('pending', 'validating-payment',  'awaitingconfirm', 'multi-token');
				break;
			default:
				break;
		}
		if (in_array($current_bp_status, $valid_bp_statuses)) {
			$order->update_meta_data('bleumipay_payment_status',  $new_bp_payment_status);
			$order->update_meta_data('bleumipay_amount_paid',  $payment_info['token_balances'][0]['balance']);
			$order->update_meta_data('bleumipay_data_source',  $data_source);
			if (in_array($order_status, $valid_statuses)) {
				$order->update_status($new_status);
				BleumiPay_Helper::bpcp_log('mark_order_as:'. $order_id .' status set to: '. $new_status );
				$success = true;
			} else {
				BleumiPay_Helper::bpcp_log('mark_order_as:'. $order_id .' data_source: '. $data_source .' payment received when order is in: '. $order_status );
			}
			$order->save();
		}
		return $success;
	}

}