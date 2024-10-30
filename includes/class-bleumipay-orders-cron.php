<?php
namespace BleumiPay\PaymentGateway;
/*****************************************
 *
 * Bleumi Pay Orders CRON ("Orders Updater") functions 
 *
 * Updates WooCommerce Order Statuses changes to Bleumi Pay
 *  
 * Any status updates in orders of WooCommerce is posted to BleumiPay
 * in this function
 *	 
******************************************/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BleumiPay_Orders_CRON {

	public static function bpcp_orders_cron_job() 
	{
		$data_source = 'orders-cron';
		$field_name = 'wc_last_exec';
		 
		//Get last_execution_time of orders-cron
		$last_run_at = BleumiPay_Helper::bpcp_get_last_execution_time($field_name);
		BleumiPay_Helper::bpcp_log('orders_cron_job: looking for orders modified after: ' . $last_run_at);
		//Fail order that are awaiting payment confirmation after cut-off time.
		self::bpcp_fail_unconfirmed_payment_orders($data_source);
		//Update Bleumi Pay of order changes
		$last_modified = self::bpcp_sync_orders($last_run_at, $data_source);
		//To verify the status of settle_in_progress orders
		self::bpcp_verify_settle_operation_statuses($data_source);
		//To verify the status of refund_in_progress orders
		self::bpcp_verify_refund_operation_statuses($data_source);
		//To ensure balance in all tokens are refunded
		self::bpcp_verify_complete_refund($data_source);
		//Set last_execution_time for orders-cron
		if (!empty($last_modified)) {
			BleumiPay_Helper::bpcp_update_last_execution_time($field_name, $last_modified);
			BleumiPay_Helper::bpcp_log('orders_cron_job: setting last execution time: ' . $last_modified);
		} 
	}

	/**
	 * Find modified orders after last orders-cron job run 
	*/
	public static function bpcp_sync_orders ($last_run_at, $data_source) {
		$date_modified  = null;
		$args = array(
			'payment_method' => 'bleumipay',
			'date_modified' => '>' . $last_run_at,
			'orderby' => 'date_modified'
		);
		$orders = wc_get_orders($args);
		foreach ($orders as $order) {
			$order_modified_date = self::bpcp_sync_order($order, $data_source);
			if (!is_null($order_modified_date)) {
				$date_modified = $order_modified_date;
			}
		}
		return $date_modified;
	}

	/**
	 * Updates latest order info to Bleumi Pay
	 * Called by orders-cron, retry-cron, orderhook handler
	*/
	public static function bpcp_sync_order($order, $data_source) {

		$bp_hard_error = $order->get_meta('bleumipay_hard_error');
		$bp_transient_error = $order->get_meta('bleumipay_transient_error');
		$bp_retry_action = $order->get_meta('bleumipay_retry_action');
		$order_id = (string) $order->get_id();
		$order_modified_date = strtotime($order->get_date_modified()); // coverts formated date to unix time
		
		// If there is a hard error, return
		if (($bp_hard_error == 'yes')) {
			$msg = 'sync_order:'. $order_id .' Skipping, hard error found. ';
			BleumiPay_Helper::bpcp_log($msg);
			return $order_modified_date;
		} 

		// If there is a transient error & retry_action does not match, return
		if ( (($bp_transient_error == 'yes') && ($bp_retry_action != 'sync_order'))) {
			$msg = 'sync_order:'. $order_id .' Skipping, transient error found and retry_action does not match, order retry_action is : ' . $bp_retry_action;
			BleumiPay_Helper::bpcp_log($msg);
			return;
		} 

		//If Bleumi Pay id not found, return
		$bleumipay_payment_id = $order->get_meta('bleumipay_payment_id');
		if (is_null($bleumipay_payment_id) || empty($bleumipay_payment_id)) {	
			$msg = 'sync_order:'. $order_id .' bleumipay_payment_id not set for order-id: ' . $order_id;
			BleumiPay_Helper::bpcp_log($msg);
			return $order_modified_date;
		}

		//If Bleumi Pay processing completed, return
		$bp_processing_completed = $order->get_meta('bleumipay_processing_completed');
		if ($bp_processing_completed == 'yes') {
			$msg = 'Processing already completed for this order. No further changes possible.';
			BleumiPay_Helper::bpcp_log( 'sync_order:'. $order_id .' ' . $msg );
			return $order_modified_date;
		}

		//If order is in settle_in_progress or refund_in_progress, return
		$bp_payment_status = $order->get_meta('bleumipay_payment_status');
		if (($bp_payment_status == 'refund_in_progress')||($bp_payment_status == 'settle_in_progress')) {
			return $order_modified_date;
		}
		
		$prev_data_source = $order->get_meta('bleumipay_data_source');
		$currentTime = strtotime(new \WC_DateTime()); //Server Unix time
		$minutes = BleumiPay_Helper::bpcp_getMinutesDiff($currentTime, $order_modified_date);
		if ($minutes < BleumiPay_Helper::$cron_collision_safe_minutes ) {
			// Skip orders-cron update if order was updated by payments-cron recently.
			if ( ($data_source === 'orders-cron') && ($prev_data_source === 'payments-cron')) {
				$msg = 'Skipping sync_order at this time as payments-cron updated this order recently, will be re-tried again';
				BleumiPay_Helper::bpcp_log( 'sync_order:'. $order_id . ' ' . $msg );
				BleumiPay_Exception_Handler::bpcp_log_transient_exception($order, 'sync_order', 'E200', $msg);
				return;
			}

		}

		$result = BleumiPay_APIHandler::bpcp_get_payment_token_balance(null, $order);
		if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
			//If balance of more than 1 token is found, mark the order as "multi_token".
			$msg = $result[0]['message'];
			if ($result[0]['code'] == -2) {
				$success = BleumiPay_Helper::bpcp_multi_token_order($order, $msg);
				if ($success) {
					BleumiPay_Helper::bpcp_log( "sync_order:". $order_id ." token balance error : ". $msg .", order status changed to 'Multi Token Payment' ");
				}
			} else {
				BleumiPay_Helper::bpcp_log( 'sync_order:'. $order_id .' token balance error : '. $msg );
			}
			return $order_modified_date;
		} 
		$payment_info = $result[1];

		//If no payment amount is found, return
		$amount = $payment_info['token_balances'][0]['balance'];
		if (is_null($amount)) {
			BleumiPay_Helper::bpcp_log('sync_order:'. $order_id .' payment is blank.');
			return;
		}

		$order_status = strtolower($order->get_status());
		$date_modified = null;	
		$valid_bp_statuses = array('pending', 'payment-received', 'awaitingconfirm');
		
		if (($bp_processing_completed == 'no') && (in_array($bp_payment_status, $valid_bp_statuses))){
			switch ($order_status) {
				case "on-hold":
				case "awaitingconfirm":	
				case "pending":
					break;
				case "completed":
					$msg = 'sync_order:'. $order_id .'  settling payment.';
					BleumiPay_Helper::bpcp_log( $msg );
					self::bpcp_settle_order($order, $payment_info, $data_source);
					break;
				case "processing":
					//Check if payment was received by Bleumi Pay, else mark 
					$amt = $order->get_meta('bleumipay_amount_paid');
					if (isset($amt) && !is_null($amt)){
						$bleumipay_amount_paid = (float)$amt;
						if ($bleumipay_amount_paid <= 0) {
							$code = 'E903';
							$msg = 'sync_order: Processed by external payment gateway';
							self::bpcp_log_sync_order_hard_exception($order, $data_source, $code, $msg);
						} 
					} 
					break;
				case "cancelled":
				case "refunded":
				case "failed":
					$msg = 'sync_order:'. $order_id .'  refunding payment.';
					BleumiPay_Helper::bpcp_log( $msg );
					self::bpcp_refund_order($order, $payment_info, $data_source);
					$date_modified = $order_modified_date;
					break;				
				default:
					BleumiPay_Helper::bpcp_log('sync_order:'. $order_id .' switch case : unhandled order status: ' . $order_status . ' Order ID: '. $order_id );
					break;
			}
		} else {
			$msg = 'sync_order:'. $order_id .'  bp_status:' . $bp_payment_status .' order_status:'. $order_status;
			BleumiPay_Helper::bpcp_log( $msg );
		}
		return $date_modified;
	}

	/**
	 * Settle orders and set to settle_in_progress Bleumi Pay status 
	*/
	public static function bpcp_settle_order($order, $payment_info, $data_source) {
		$msg = '';
		usleep(300000);  // rate limit delay.
		$order_id = (string) $order->get_id();
		$result = BleumiPay_APIHandler::bpcp_settle_payment($payment_info, $order);
		if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
			$msg = $result[0]['message'];
			BleumiPay_Exception_Handler::bpcp_log_transient_exception($order, 'sync_order', 'E203', $msg);
		} else {
			$operation = $result[1];
			if (!is_null($operation['txid'])) {
				$msg = 'settle_order:'. $order_id .' settle_payment invoked, tx-id is: '. $operation['txid'];
				$order->reduce_order_stock(); // Reduce stock levels
				$order->update_meta_data('bleumipay_txid',  $operation['txid']);
				$order->update_meta_data('bleumipay_payment_status',  'settle_in_progress');
				$order->update_meta_data('bleumipay_processing_completed',  'no');
				$order->update_meta_data('bleumipay_data_source',  $data_source);
				BleumiPay_Exception_Handler::bpcp_clear_transient_error($order);
				$order->save();
			} else {
				$msg = " settle_payment invoked, got back no tx-id ";
				BleumiPay_Exception_Handler::bpcp_log_transient_exception($order, 'sync_order', 'E204', $msg);
			}
		}
		BleumiPay_Helper::bpcp_log( $msg );
	}

	/**
	 * Refund Orders and set to refund_in_progress Bleumi Pay status 
	*/
	public static function bpcp_refund_order($order, $payment_info, $data_source) {
		$msg = '';
		usleep(300000);  // rate limit delay.
		$order_id = (string) $order->get_id();
		$result = BleumiPay_APIHandler::bpcp_refund_payment($payment_info, $order);
		if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
			$msg = $result[0]['message'];
			BleumiPay_Exception_Handler::bpcp_log_transient_exception($order, 'sync_order', 'E205', $msg);
		} else {
			$operation = $result[1];
			if (!is_null($operation['txid'])) {
				$order->update_meta_data('bleumipay_txid',  $operation['txid']);
				$order->update_meta_data('bleumipay_payment_status',  'refund_in_progress');
				$order->update_meta_data('bleumipay_processing_completed',  'no');
				BleumiPay_Exception_Handler::bpcp_clear_transient_error($order);
				$msg = 'refund_order:'. $order_id .' refund_payment invoked, tx-id is: '. $operation['txid'];
			} else {
				$msg = 'refund_payment invoked, got back no tx-id ';
				BleumiPay_Exception_Handler::bpcp_log_transient_exception($order, 'sync_order', 'E206', $msg);
			}
		}
		BleumiPay_Helper::bpcp_log( $msg );
		$order->update_meta_data('bleumipay_data_source',  $data_source);
		$order->save();
	}

	/**
	 * Find Orders which are in bp_payment_status = settle_in_progress  
	 * and check transaction status
	*/
	public static function bpcp_verify_settle_operation_statuses($data_source) {
		$args = array(
			'payment_method' => 'bleumipay',
			'bleumipay_payment_status' => 'settle_in_progress',
			'bleumipay_processing_completed' => 'no',
			'orderby' => 'date_modified'
		);
		$orders = wc_get_orders($args);
		if (count($orders) > 0) {
			$operation = "settle";
			BleumiPay_APIHandler::bpcp_verify_operation_completion($orders, $operation, $data_source);
		} 
	}
	
	/**
	 * Find Orders which are in refund_in_progress Bleumi Pay status 
	*/
	public static function bpcp_verify_refund_operation_statuses($data_source) {
		$args = array(
			'payment_method' => 'bleumipay',
			'bleumipay_payment_status' =>  'refund_in_progress',
			'bleumipay_processing_completed' => 'no',
			'orderby' => 'date_modified'
		);
		$orders = wc_get_orders($args);
		if (count($orders) > 0) {
			$operation = "refund";
			BleumiPay_APIHandler::bpcp_verify_operation_completion($orders, $operation, $data_source);
		} 
	}

	/**
	 * Fail the orders that are not confirmed even after cut-off time. (1 hour) 
	*/
	public static function bpcp_fail_unconfirmed_payment_orders($data_source) {
		$args = array(
			'payment_method' => 'bleumipay',
			'status' => 'awaitingconfirm',
			'bleumipay_processing_completed' => 'no'
		);
		$orders = wc_get_orders($args);
		if (count($orders) > 0) {
			foreach ($orders as $order) {
				$order_id = (string) $order->get_id();
				$currentTime = strtotime(new \WC_DateTime()); //Server UNIX time
				$order_created_date = strtotime( $order->get_date_created() ) ;
				$minutes = BleumiPay_Helper::bpcp_getMinutesDiff($currentTime , $order_created_date);
				if ($minutes > BleumiPay_Helper::$await_payment_minutes)  {
					$msg = 'Payment confirmation not received before cut-off time, elapsed minutes: '. round($minutes, 2);
					BleumiPay_Helper::bpcp_log('fail_unconfirmed_payment_orders: '. $order_id . $msg);
					BleumiPay_Helper::bpcp_fail_this_order($order, $msg);
				}
			}
		} 
	}

	/**
	 * Log Hard Exception
	*/
	public static function bpcp_log_sync_order_hard_exception($order, $data_source, $code, $msg) {
		$order->update_meta_data('bleumipay_data_source',  $data_source);
		BleumiPay_Exception_Handler::bpcp_log_hard_exception($order, 'sync_order', $code, $msg);
	}

	/**
	 * Verify that the refund is complete 
	*/
	public static function bpcp_verify_complete_refund($data_source) {
		$args = array(
			'payment_method' => 'bleumipay',
			'bleumipay_payment_status' =>  'refunded',
			'bleumipay_processing_completed' => 'no',
			'orderby' => 'date_modified'
		);
		$orders = wc_get_orders($args);
		foreach ( $orders as $order ) {
			$order_id = (string) $order->get_id();
			$result = BleumiPay_APIHandler::bpcp_get_payment_token_balance(null, $order);
			$payment_info = $result[1];
			$token_balances = $payment_info['token_balances'];
			$token_balances_modified = array();
			//All tokens are refunded, can mark the order as processing completed
			if (count($token_balances) == 0) {
				$order->update_meta_data('bleumipay_processing_completed',  'yes');
				$order->save();
				BleumiPay_Helper::bpcp_log('verify_complete_refund:'. $order_id .' processing completed.');
				return;
			}
			$next_token = '';
			do {
				$ops_result = BleumiPay_APIHandler::bpcp_list_payment_operations($order_id);
				$operations = $ops_result[1]['results'];
				$next_token = $operations['next_token'];
				if (is_null($next_token)) {
					$next_token = '';
				}
				$valid_operations = array('createAndRefundWallet', 'refundWallet');
				foreach( $token_balances as $token_balance ) {
					$token_balance['refunded'] = 'no';
					foreach( $operations as $operation ) {
						if (isset($operation['hash'])&&(!is_null($operation['hash']))) {
							if ( ($operation['inputs']['token'] === $token_balance['addr']) && ( $operation['status'] == 'yes' ) && ( $operation['chain'] == $token_balance['chain'] ) && ( $operation['inputs']['token'] == $token_balance['addr'] ) && (in_array($operation['func_name'], $valid_operations)) ) {
								$token_balance['refunded'] = 'yes';
								break;
							}
						}
					}
					array_push($token_balances_modified, $token_balance);
				}
			} while ($next_token !== '');
			
			$all_refunded = 'yes';
			foreach( $token_balances_modified as $token_balance ) {
				if ( $token_balance['refunded'] == 'no' ) {
					$amount = $token_balance['balance'];
					if (!is_null($amount)) {
						$payment_info['id'] = $order_id;
						$item = array (
							'chain' => $token_balance['chain'],
							'addr' => $token_balance['addr'],
							'balance' => $token_balance['balance']
						);
						$payment_info['token_balances'] = array($item);
						self::bpcp_refund_order($order, $payment_info, $data_source);
						$all_refunded = 'no';
						break;
					}
				}
			}
			if ($all_refunded == 'yes') {
				$order->update_meta_data('bleumipay_processing_completed',  'yes');
				$order->save();
				BleumiPay_Helper::bpcp_log('verify_complete_refund:'. $order_id .' processing completed.');
			}
		} 
	}
}