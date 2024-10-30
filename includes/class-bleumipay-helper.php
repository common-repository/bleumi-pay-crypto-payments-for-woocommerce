<?php
namespace BleumiPay\PaymentGateway;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BleumiPay_Helper {

	/** @var WC_Logger Logger instance */
	public static $log = false;

	/** @var integer The minutes to wait for payment before cancelling order */
	public static $await_payment_minutes = 24*60;
	public static $max_retry_count = 3;
	public static $cron_collision_safe_minutes = 10;
	
	public static function bpcp_log( $message, $level = 'info', $over_rider = true ) {
		return call_user_func( self::$log, $message, $level, $over_rider );
	}

	/**
 	* Database Helper function - Retrieves the last execution time of the cron job
 	*/
	 public static function bpcp_get_last_execution_time($field_name) {
		global $wpdb;
		$control_id_value = 1;  //key to locate the row
		$table_name = $wpdb->prefix.'bleumi_cron_schedule';
		$query = $wpdb->prepare("SELECT $field_name FROM $table_name WHERE control_id=%d", array($control_id_value));
		$last_exec = $wpdb->get_row($query)->$field_name;
		return $last_exec;
	}
	
	/**
 	* Database Helper function - Sets the last execution time of the cron job
 	*/
	public static function bpcp_update_last_execution_time($field_name, $last_exec_time) {
		global $wpdb;
		$control_id = 1;  //key to locate the row
		$table_name = $wpdb->prefix.'bleumi_cron_schedule';
		$query_where = ' WHERE control_id = ' . $control_id;
		
		$row_data = self::bpcp_get_last_execution_time('control_id'); //Retrieve value for control_id to check row exists 
		$query = '';
		if (!empty($row_data)) {
		 	$query = $wpdb->prepare("UPDATE $table_name SET $field_name = %d {$query_where}", array($last_exec_time));
		} else {
			$query = $wpdb->prepare("INSERT INTO $table_name (control_id, $field_name) VALUES (%d, %d)", array($control_id, $last_exec_time));
		}
		$wpdb->query($query);
	}

	public static function bpcp_getTransactionLink($txHash, $chain=null) {
        switch ($chain) {
		case 'alg_mainnet':
			return 'https://algoexplorer.io/tx/' . $txHash;
		case 'alg_testnet':
			return 'https://testnet.algoexplorer.io/tx/' . $txHash;
		case 'rsk':
			return 'https://explorer.rsk.co/tx/' . $txHash;
		case 'rsk_testnet':
			return 'https://explorer.testnet.rsk.co/tx/' . $txHash;
		case 'mainnet':
		case 'xdai':    
			return 'https://etherscan.io/tx/' . $txHash;
		case 'goerli':
		case 'xdai_testnet':     
			return 'https://goerli.etherscan.io/tx/' . $txHash;
		default:
			return;
		}
	}

	public static function bpcp_getMinutesDiff($dateTime1, $dateTime2) {
		$minutes = abs($dateTime1 - $dateTime2)/60;
		return $minutes;
	}

	public static function bpcp_update_order_status($order, $status, $message, $valid_statuses) {
		$order_status = strtolower($order->get_status());
		if (in_array($order_status, $valid_statuses)) {
			$order->update_meta_data('bleumipay_payment_status',  $status);
			$order->update_status($status, __($message, 'bleumipay'));
			$order->save();
			return true;
		}
		return false;
	}

	public static function bpcp_fail_this_order($order, $message) {
		$valid_statuses = array ("awaitingconfirm");
		return self::bpcp_update_order_status($order, 'failed', $message, $valid_statuses);
	}

	public static function bpcp_multi_token_order($order, $message) {
		$valid_statuses = array ("on-hold", "pending", "awaitingconfirm");
		return self::bpcp_update_order_status($order, 'multi-token', $message, $valid_statuses);
	}

}