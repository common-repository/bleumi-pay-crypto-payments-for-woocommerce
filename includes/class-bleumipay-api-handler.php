<?php
namespace BleumiPay\PaymentGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BleumiPay_APIHandler {

	public static $api_key;
	/**
 	* Returns the Bleumi Pay Payment API Instance
 	*/
	 public static function bpcp_payment_api_instance() {
		$apiKey =  self::$api_key;
		$config = \Bleumi\Pay\Configuration::getDefaultConfiguration()->setApiKey('x-api-key',  $apiKey);
		$apiInstance = new \Bleumi\Pay\Api\PaymentsApi(
			new \GuzzleHttp\Client(),
			$config
		);
		return $apiInstance;
	}

	/**
 	* Returns the Bleumi Pay HostedCheckouts API Instance
 	*/	
	public static function bpcp_hostedcheckouts_api_instance() {
		$apiKey =  self::$api_key;
		$config = \Bleumi\Pay\Configuration::getDefaultConfiguration()->setApiKey('x-api-key',  $apiKey);
		$apiInstance = new \Bleumi\Pay\Api\HostedCheckoutsApi(
			new \GuzzleHttp\Client(),
			$config
		);
		return $apiInstance;
	}

    
    /**
 	* Retrieves the payment details for the order_id from Bleumi Pay
 	*/
	public static function bpcp_get_payment($order) {
		$result = null;
		$errorStatus = array();
		$id = (string) $order->get_id();
		$apiInstance = self::bpcp_payment_api_instance();
		try {
			$result = $apiInstance->getPayment($id);
		} catch (\Exception $e) {
			$msg = 'get_payment: failed order-id:' . $id . '; response: '. $e->getMessage() . '; response Body: '. $e->getResponseBody();
			BleumiPay_Helper::bpcp_log($msg);
			$errorStatus['code'] = -1;
			$errorStatus['message'] = $msg;
		}
		return array($errorStatus, $result);
	}

	/**
 	* Retrieves the payment details for the order_id from Bleumi Pay
 	*/
	 public static function bpcp_get_payments($updated_after_time, $next_token) {
		$result = null; 
		$errorStatus = array();
		$apiInstance = self::bpcp_payment_api_instance();
		$next_token = $next_token;  
		$sort_by = "updatedAt"; 
		$sort_order = "ascending";  
		$start_at = $updated_after_time;  
		try {
			$result = $apiInstance->listPayments($next_token, $sort_by, $sort_order, $start_at);
		}  catch (\Exception $e) {
			$msg = 'get_payments: failed, response: '. $e->getMessage() . '; response body: '. $e->getResponseBody();
			BleumiPay_Helper::bpcp_log($msg);
			$errorStatus['code'] = -1;
			$errorStatus['message'] = $msg;
		}
		return array($errorStatus, $result) ;
	}

	/**
 	* Create payment in Bleumi Pay for the given order_id 
	*/
	 
	public static function bpcp_create_payment($id =null, $currency=null, $amount=null, $success_url=null, $cancel_url=null) {
		$result = null;
		$errorStatus = array();
		$apiInstance = self::bpcp_hostedcheckouts_api_instance();
		$createCheckoutUrlRequest = new \Bleumi\Pay\Model\CreateCheckoutUrlRequest(); 
		$createCheckoutUrlRequest->setId($id);  
		$createCheckoutUrlRequest->setCurrency($currency);  
		$createCheckoutUrlRequest->setAmount($amount);  
		$createCheckoutUrlRequest->setsuccessUrl($success_url);  
		$createCheckoutUrlRequest->setCancelUrl($cancel_url);  
		$createCheckoutUrlRequest->setBase64Transform(true);  
		try {
			$result = $apiInstance->createCheckoutUrl($createCheckoutUrlRequest);
		}  catch (\Exception $e) {
			$msg = 'create_payment: failed response: '. $e->getMessage();
			if ($e->getResponseBody() !== null) {
				$msg = $msg . $e->getResponseBody();
			}
			BleumiPay_Helper::bpcp_log($msg);
			$errorStatus['code'] = -1;
			$errorStatus['message'] = $msg;
		}
		return array($errorStatus, $result);
	}

	/**
 	* Settle payment in Bleumi Pay for the given order_id 
 	*/
	
	public static function bpcp_settle_payment($payment_info, $order) {
		$result = null; 
		$errorStatus = array();
		$id = $payment_info['id'];
		$tokenBalance = $payment_info['token_balances'][0];
		$apiInstance = self::bpcp_payment_api_instance();
		$token = $tokenBalance['addr'];
		$paymentSettleRequest = new \Bleumi\Pay\Model\PaymentSettleRequest();
		$amount = (string) $order->get_total();
		$paymentSettleRequest->setAmount($amount); 
		$paymentSettleRequest->setToken($token);
		try {
			$result = $apiInstance->settlePayment($paymentSettleRequest, $id, $tokenBalance['chain']);
		}  catch (\Exception $e) {
			BleumiPay_Helper::bpcp_log( '-Settle Exception-' . $e->getMessage());
			$msg = 'settle_payment: failed : ' . $id . '; response: '. $e->getMessage();
			if ($e->getResponseBody() !== null) {
				$msg = $msg . $e->getResponseBody();
			}
			$errorStatus['code'] = -1;
			$errorStatus['message'] = $msg;
			BleumiPay_Helper::bpcp_log($msg);
		}
		return array($errorStatus, $result);
	}

	/**
 	* Refund payment in Bleumi Pay for the given order_id 
 	*/
	
	 public static function bpcp_refund_payment($payment_info, $order) {
		$result = null; 
		$errorStatus = array();
		$apiInstance = self::bpcp_payment_api_instance();
		$id = $payment_info['id'];
		try {
			$token_balance = $payment_info['token_balances'][0];
			$amount = (float) $token_balance['balance'];
			if ($amount > 0) {
				$paymentRefundRequest = new \Bleumi\Pay\Model\PaymentRefundRequest(); 
				$paymentRefundRequest->setToken($token_balance['addr']);
				$result = $apiInstance->refundPayment($paymentRefundRequest, $id, $token_balance['chain']);	
			}
			BleumiPay_Exception_Handler::bpcp_clear_order_error_meta_data($order);
		} catch (\Exception $e) {
			$msg = 'refund_payment: failed : ' . $id . '; response: '. $e->getMessage();
			if ($e->getResponseBody() !== null) {
				$msg = $msg . $e->getResponseBody();
			}
			$errorStatus['code'] = -1;
			$errorStatus['message'] = $msg;
		}
		$order->save();
		return array($errorStatus, $result);
	}		

	/**
 	* Retrieves the payment operation details for the payment_id, tx_id from Bleumi Pay
 	*/
	 public static function bpcp_get_payment_operation($id, $tx_id, $order) {
		$result = null;
		$errorStatus = array();
		$apiInstance = self::bpcp_payment_api_instance();
		try {
			$result = $apiInstance->getPaymentOperation( $id, $tx_id );
		} catch (\Exception $e) {
			$msg = 'get_payment_operation: failed : ' . $id . '; response: '. $e->getMessage() . '; response body: '. $e->getResponseBody();
			$errorStatus['code'] = -1;
			$errorStatus['message'] = $msg;
			BleumiPay_Helper::bpcp_log($msg);
			BleumiPay_Exception_Handler::bpcp_log_exception($order, 'get_payment_operation', $e->getCode(), $msg);
		}
		return array($errorStatus, $result);
	}

	/**
 	* List of Payment Operations
 	*/
	 public static function bpcp_list_payment_operations($id, $next_token = null) {
		$result = null;
		$errorStatus = array();
		$apiInstance = self::bpcp_payment_api_instance();
		try {
			$result = $apiInstance->listPaymentOperations( $id, $next_token );
		} catch (\Exception $e) {
			$msg = 'list_payment_operations: failed : ' . $id . '; response: '. $e->getMessage() . '; response body: '. $e->getResponseBody();
			$errorStatus['code'] = -1;
			$errorStatus['message'] = $msg;
			BleumiPay_Helper::bpcp_log($msg);
		}
		return array($errorStatus, $result);
	}

	/**
 	* List Tokens
 	*/
	 public static function bpcp_list_tokens($store_currency) {
		$result = array();
		$errorStatus = array();
		$apiInstance = self::bpcp_hostedcheckouts_api_instance();
		try {
			$tokens = $apiInstance->listTokens();
			foreach ($tokens as $item) {
                if ($item['currency'] === $store_currency) {
                    array_push($result, $item);
                }
            } 
		} catch (\Exception $e) {
			BleumiPay_Helper::bpcp_log('list_tokens: failed, response: '. $e->getMessage());
			$errorStatus['code'] = -1;
			$errorStatus['message'] = $e->getResponseBody();
		}
		return array($errorStatus, $result);
	}	

	/**
	 * Validate Payment Completion Parameters.
	 */ 
	 public static function bpcp_validate_payment( $hmac_alg, $hmac_keyId, $hmac_input, $hmac_value ) {
		$result = null;
		$errorStatus = array();
		$apiInstance = self::bpcp_hostedcheckouts_api_instance();
		try {
			$validateCheckoutRequest = new \Bleumi\Pay\Model\ValidateCheckoutRequest();  
			$validateCheckoutRequest->setHmacAlg($hmac_alg);  
			$validateCheckoutRequest->setHmacInput($hmac_input);  
			$validateCheckoutRequest->setHmacKeyId($hmac_keyId);  
			$validateCheckoutRequest->setHmacValue($hmac_value);  
			$result = $apiInstance->validateCheckoutPayment($validateCheckoutRequest);
		} catch (\Exception $e) {

			$msg = 'validate_payment: failed, response: '. $e->getMessage();
			if ($e->getResponseBody() !== null) {
				$msg = $msg . $e->getResponseBody();
			}

			$errorStatus['code'] = -1;
			$errorStatus['message'] = $msg;
			BleumiPay_Helper::bpcp_log( $msg );
		}
		return array($errorStatus, $result);
	}	

	//To ignore ALGO for algo token
	public static function bpcp_ignore_ALGO($token_balances) {
		$algo_token_found = false;
		$ret_token_balances = array();
		foreach ( $token_balances as $item ) {
			if (($item['network'] === 'algorand') && ($item['addr'] !== 'ALGO')) {
				$algo_token_found = true;
			}
		}
		foreach ( $token_balances as $item ) {
			if ($item['network'] === 'algorand') {
				if (($algo_token_found) && ($item['addr'] !== 'ALGO')) {
					array_push($ret_token_balances, $item);
				} 
			} else {
				array_push($ret_token_balances, $item);
			}
		}
		return $ret_token_balances;
	}

    /**
     * To check whether payment is made using multiple ERC-20 tokens
     * It is possible that user could have made payment to the wallet address using a different token
     * Returns false if balance>0 is found for more than 1 token when network='ethereum', chain=['mainnet', 'goerli']
     */

    public static function bpcp_is_multi_token_payment($payment)
    {
		$networks = array('ethereum', 'algorand', 'rsk');
		$token_balances = array();
		$chain_token_balances = null;
		foreach ($networks as $network) {
			$chains = array();
			if ($network === 'ethereum') {
				$chains = array('mainnet', 'goerli', 'xdai_testnet', 'xdai');
			} else if ($network === 'algorand') {
				$chains = array('alg_mainnet', 'alg_testnet');
			} else if ($network === 'rsk') {
				$chains = array('rsk', 'rsk_testnet');
			}
			foreach ($chains as $chain) {
				$chain_token_balances = $payment['balances'][$network][$chain];
				if (!is_null($chain_token_balances)) {
					foreach ($chain_token_balances as $addr => $token_balance) {
						$balance = (float) $token_balance['balance'];
						if ($balance > 0) {
							$item = array();
							$item['network'] = $network;
							$item['chain'] = $chain;
							$item['addr'] = $addr;
							$item['balance'] = $token_balance['balance'];
							$item['token_decimals'] = $token_balance['token_decimals'];
							$item['blockNum'] = $token_balance['blockNum'];
							$item['token_balance'] = $token_balance['token_balance'];
							array_push($token_balances, $item);
						}
					}
				}
			}
		}
		$ret_token_balances = self::bpcp_ignore_ALGO($token_balances);
		return (count($ret_token_balances)>1);
    }	

	/**
	 * Get Payment Token Balance - from payment object
	 * Parses the payment object which uses dictionaries
	 * 
     * {
	 *  "id": "535",
	 *  "addresses": {
	 *    "ethereum": {
	 *      "goerli": {
	 *        "addr": "0xbead07d152c64159190842ec1d6144f1a4a6cae9"
	 *      }
	 *    }
	 *  },
	 *  "balances": {
	 *    "ethereum": {
	 *      "goerli": {
	 *        "0x115615dbd0f835344725146fa6343219315f15e5": {
	 *          "blockNum": "1871014",
	 *          "token_balance": "10000000",
	 *          "balance": "10",
	 *          "token_decimals": 6
	 *        }
	 *      }
	 *    }
	 *  },
	 *	"createdAt": 1577086517,
	 *	"updatedAt": 1577086771
	 * }
	 * @return object
	 */ 
	public static function bpcp_get_payment_token_balance( $payment = null, $order ) {

		$order_id = (string) $order->get_id();
		$chain = '';
		$addr = '';
		$token_balances = array();
		$payment_info = array();
		$errorStatus = array();

		//Call get_payment API to set $payment if found null.
		if (is_null($payment)) {
			$result = self::bpcp_get_payment($order);
			if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
				BleumiPay_Helper::bpcp_log('get_payment_token_balance: '. $order_id . ' get_payment api failed : '. $result[0]['message'] );
				$errorStatus = array(
					'code' => -1, 
					'message' => 'get payment details failed '
				);
				return array($errorStatus, $payment_info) ;
			} 
			$payment = $result[1];
		}

		//If still not payment data is found, return error 
		if (is_null($payment)) {
			$errorStatus = array(
				'code' => -1, 
				'message' => 'no payment details found '
			);
			return array($errorStatus, $payment_info) ;
		}

		$payment_info['id'] = $payment['id'];
        $payment_info['addresses'] = $payment['addresses'];
        $payment_info['balances'] = $payment['balances'];
        $payment_info['created_at'] = $payment['created_at'];
        $payment_info['updated_at'] = $payment['updated_at'];
		
        if (self::bpcp_is_multi_token_payment($payment)) {
            $msg = 'More than one token balance found';
            $errorStatus['code'] = -2;
            $errorStatus['message'] = $msg;
            return array($errorStatus, $payment_info);
		}
		
		$store_currency = get_woocommerce_currency();
		$bp_order_currency = $order->get_meta('bleumipay_order_currency');
		if (strlen($bp_order_currency) > 0) {
			$store_currency = $bp_order_currency;
		}

		$result = self::bpcp_list_tokens($store_currency);
		BleumiPay_Helper::bpcp_log('list balances in currency: '. $store_currency);

		if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
			BleumiPay_Helper::bpcp_log('get_payment_token_balance: '. $order_id . ' list_tokens api failed : '. $result[0]['message']);
			return array($result[0], $payment_info) ;
		} 

		$tokens = $result[1];
		if (count($tokens) > 0) {
			foreach ( $tokens as $token ) {
				$network = $token['network'];
				$chain = $token['chain'];
				$addr = $token['addr'];
				$token_balance = $payment['balances'][$network][$chain][$addr];
				BleumiPay_Helper::bpcp_log('token: '. $network . $chain . $addr . ' balance : '. print_r($token_balance, true));
				/*{
					"balance": "0",
					"token_decimals": 6,
					"blockNum": "1896563",
					"token_balance": "0"
				  }*/
				if (!is_null($token_balance['balance'])) {
					$balance = (float)$token_balance['balance'];
					if ($balance > 0) {
						$item = array();
						$item['network'] = $network;
						$item['chain'] = $chain;
						$item['addr'] = $addr;
						$item['balance'] = $token_balance['balance'];
						$item['token_decimals'] = $token_balance['token_decimals'];
						$item['blockNum'] = $token_balance['blockNum'];
						$item['token_balance'] = $token_balance['token_balance'];
						array_push($token_balances, $item);
					}
				}
			}
		}

		if ($store_currency != "ALGO") {
			$ret_token_balances = self::bpcp_ignore_ALGO($token_balances);
		} else {
			$ret_token_balances = $token_balances;
		}

            BleumiPay_Helper::bpcp_log( 'token balances: ' . print_r($ret_token_balances, true));


		$balance_count = count($ret_token_balances);

		if ($balance_count > 0) {
			$payment_info['token_balances'] = $ret_token_balances;
			if ($balance_count > 1) {
				BleumiPay_Helper::bpcp_log('get_payment_token_balance: '. $order_id . ' $balance_count: ' . $balance_count );
				$errorStatus['code'] = -2;
				$errorStatus['message'] = 'More than one token balance found';
			}
		} else {
            BleumiPay_Helper::bpcp_log( 'get_payment_token_balance: order-id :' . $order_id . ', no token balance found ');
        }
		return array($errorStatus, $payment_info) ;
	}

	/**
	 * Verify Payment Operation completion status.
	 */ 
	public static function bpcp_verify_operation_completion($orders, $operation, $data_source) {
		
		$completion_status = '';
		$op_failed_status = '';
		if ($operation == 'settle') {
			$completion_status = 'settled';
			$op_failed_status = 'settle_failed';
		} else {
			$completion_status = 'refunded';
			$op_failed_status = 'refund_failed';
		}

		foreach ( $orders as $order ) {
			$order_id = (string) $order->get_id();
			$tx_id = $order->get_meta('bleumipay_txid');
			if (is_null($tx_id)) {
				BleumiPay_Helper::bpcp_log('verify_operation_completion: '. $order_id . ' tx-id is not set for order-id :'. $order_id);
				continue;
			}
			//For such orders perform get operation & check whether status has become 'true'
			$result = self::bpcp_get_payment_operation($order_id, $tx_id, $order);
			if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
				$msg = $result[0]['message'];
				BleumiPay_Helper::bpcp_log('verify_operation_completion: '. $order_id . ' get_payment_operation api request failed: '. $msg );
				continue;
			} 
			$status = $result[1]['status'];
			$txHash = $result[1]['hash'];
			$chain = $result[1]['chain'];
			if (!is_null($status)) {
				if ( $status == 'yes' )  {
					$order->add_order_note(__('Tx hash for Bleumi Pay transfer '. $txHash . ' Transaction Link : ' . BleumiPay_Helper::bpcp_getTransactionLink($txHash, $chain), 'bleumipay'));
					$order->update_meta_data( 'bleumipay_payment_status', $completion_status );
					if ($operation == 'settle') {
						$order->update_meta_data( 'bleumipay_processing_completed', 'yes' );
					}
				} else {
					$msg = 'payment operation failed';
					$order->update_meta_data( 'bleumipay_payment_status', $op_failed_status );
					if ($operation == 'settle') {
						//Settle failure will be retried again & again
						BleumiPay_Exception_Handler::bpcp_log_transient_exception($order, $operation, 'E908', $msg);
					} else {
						//Refund failure will not be processed again
						BleumiPay_Exception_Handler::bpcp_log_hard_exception($order, $operation, 'E909', $msg);
					}
					BleumiPay_Helper::bpcp_log( 'verify_operation_completion: order-id '. $order_id .' '. $operation.' '.$msg );
				}
				$order->update_meta_data( 'bleumipay_data_source', $data_source );
				$order->save();
			}
		}
	}

}
