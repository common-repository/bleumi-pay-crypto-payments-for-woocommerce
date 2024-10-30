<?php
namespace BleumiPay\PaymentGateway;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BleumiPay_Exception_Handler {

	public static function bpcp_log_exception($order, $retry_action, $code, $message) {
		if ($code == 400)  {
			self::bpcp_log_hard_exception($order, $retry_action, $code, $message) ;
		} else {
			self::bpcp_log_transient_exception($order, $retry_action, $code, $message) ;
		}
	}

	public static function bpcp_log_transient_exception($order, $retry_action, $code, $message) {
		$tries_count = 0;
		//Get previous transient errors for this order
		$prev_count = (int)$order->get_meta('bleumipay_transient_error_count');
		if (isset($prev_count) && !is_null($prev_count)) {	
			$tries_count = $prev_count;
		}
		$prev_code = $order->get_meta('bleumipay_transient_error_code');
		$prev_action = $order->get_meta('bleumipay_retry_action');
		//If the same error occurs with same retry_action, then inc the retry count
		if (isset($prev_code) && isset($prev_action) && ($prev_code === $code) && ($prev_action === $retry_action)) {
			$tries_count++; 
		} else {
			//Else restart count
			$tries_count = 0;
			$order->update_meta_data('bleumipay_transient_error', 'yes');
			$order->update_meta_data('bleumipay_transient_error_code', $code);
			$order->update_meta_data('bleumipay_transient_error_msg', $message);
			if (!is_null($retry_action)) {
				$order->update_meta_data('bleumipay_retry_action', $retry_action);
			}
		}
		$order->update_meta_data('bleumipay_transient_error_count', $tries_count);
		$order->save();
	}

	public static function bpcp_log_hard_exception($order, $retry_action, $code, $message) {
		$order->update_meta_data('bleumipay_hard_error',  'yes');
		$order->update_meta_data('bleumipay_hard_error_code', $code);
		$order->update_meta_data('bleumipay_hard_error_msg', $message);
		if (!is_null($retry_action)) {
			$order->update_meta_data('bleumipay_retry_action', $retry_action);
		}
	//	$order->add_order_note(__($error_message . '; error code :' . $code, 'bleumipay'));
		$order->save();
	}

	public static function bpcp_clear_order_error_meta_data($order) {
		self::bpcp_clear_transient_error($order);
	}

	public static function bpcp_clear_transient_error($order) {
		$order->delete_meta_data('bleumipay_transient_error');
		$order->delete_meta_data('bleumipay_transient_error_code');
		$order->delete_meta_data('bleumipay_transient_error_msg');
		$order->delete_meta_data('bleumipay_transient_error_count');
		$order->delete_meta_data('bleumipay_retry_action');
		$order->save();
	}

	public static function bpcp_check_retry_count($order) {
		$retry_count = (int)$order->get_meta('bleumipay_transient_error_count');
		$action = $order->get_meta('bleumipay_retry_action');
		if ($retry_count > BleumiPay_Helper::$max_retry_count) {
			$code = 'E907';
			$msg = 'Retry count exceeded.';
			BleumiPay_Exception_Handler::bpcp_log_hard_exception($order, $action, $code , $msg);
		}
		return $retry_count;
  	}

}