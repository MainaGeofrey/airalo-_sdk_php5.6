<?php

namespace Airalo\Services;

use Airalo\Exceptions\AiraloException;
use Airalo\Services\HandlerService;

class PaymentService extends BaseService
{
    private $db_con;
    private $systemConfig;

    private $consumerKey;
    private $consumerSecret;
    private $businessShortCode;
    private $passKey;
    private $ambiaBaseUrl;
    private $accessTokenUrl;
    private $initiateUrl;
    private $registrationUrl;

    public function __construct()
    {
        parent::__construct();

        $this->CI->load->library('Mpesa_payments_lib');
        $this->db_con = $this->CI->db;

        $this->systemConfig = $this->CI->common_model::$global_config['system_config'];

        $this->consumerKey = $this->systemConfig['consumer_key'] ?? null;
        $this->consumerSecret = $this->systemConfig['consumer_secret'] ?? null;
        $this->businessShortCode = $this->systemConfig['business_short_code'] ?? null;
        $this->passKey = $this->systemConfig['pass_key'] ?? null;
        $this->accessTokenUrl = $this->systemConfig['access_token_url'] ?? null;
        $this->initiateUrl = $this->systemConfig['initiate_url'] ?? null;
        $this->registrationUrl = $this->systemConfig['registration_url'] ?? null;
        $this->ambiaBaseUrl = base_url();
    }


    private function getAccessToken()
    {



        $credentials = base64_encode($this->consumerKey  . ':' . $this->consumerSecret);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->accessTokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Basic ' . $credentials, 'Content-Type: application/json'));
        $response = curl_exec($ch);
        curl_close($ch);
        $response = json_decode($response);

        $access_token = $response->access_token;


        if (!$access_token) {
            $this->logger->logInfo('error', "Invalid access token generated::" . json_last_error_msg());
            return false;
        }
        return $access_token;
    }

    public function mpesa($data)
    {
        try {
            $response_data["shortcode"] = $this->businessShortCode;
            $data['transaction_type'] = $data["payment_method"];
            $data['request_type'] = 'initiate';

            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                throw new AiraloException("Failed to generated daraja access token");
            }

            $transactionToken = $this->createPaymentIntentRecord($data);

            if ($transactionToken === 0) {
                throw new AiraloException("Failed to create payment intent");
            }

            if ($data["payment_method"] === "paybill") {
                $response_data["transaction_token"] = $transactionToken;
                $response_data["amount"] =  $data['amount'];
                return $response_data;
            }

            $timestamp = date('YmdHis');
            $password = base64_encode($this->businessShortCode . $this->passKey . $timestamp);

            $curl_post_data = json_encode([
                'BusinessShortCode' => $this->businessShortCode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => $data['amount'],
                'PartyA' => $data['phone_number'],
                'PartyB' => $this->businessShortCode,
                'PhoneNumber' => $data['phone_number'],
                'CallBackURL' => $this->ambiaBaseUrl . "api/payments_processor/stk_callback",
                'AccountReference' => $transactionToken,
                'TransactionDesc' => $data["description"]
            ]);

            $this->logger->logInfo("STK Payload: " . $curl_post_data);

            $ch = curl_init($this->initiateUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $curl_post_data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

            $curl_response = curl_exec($ch);

            if (curl_errno($ch)) {
                $error = "cURL Error: " . curl_error($ch);
                $this->logger->logError($error);
                throw new AiraloException($error);
            }
            curl_close($ch);

            $response_data = json_decode($curl_response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $error = "JSON Decode Error: " . json_last_error_msg();
                $this->logger->logError($error);
                throw new AiraloException($error);
            }


            $this->logTransaction($response_data, $data, $transactionToken);

            if (isset($response_data['ResponseCode']) && $response_data['ResponseCode'] == "0") {
                $this->logger->logInfo("Payment initiated successfully: " . json_encode($response_data));
            } else {
                $error = $response_data["errorMessage"] ?? "Payment initiation failed";
                throw new AiraloException($error);
            }


            if (isset($response_data['ResponseCode']) && $response_data['ResponseCode'] == "0") {
                $response_data["transaction_token"] = $transactionToken;
                $response_data["amount"] =  $data['amount'];
                unset($response_data["MerchantRequestID"]);
                unset($response_data["CheckoutRequestID"]);
            } else {
                $response_data["transaction_token"] = $transactionToken;
                $response_data["amount"] =  $data['amount'];
            }
            return $response_data;
        } catch (\Exception $e) {
            $this->logger->logCritical("STK Process Failed: " . $e->getMessage());
            throw $e;
        }
    }


    private function createPaymentIntentRecord($data)
    {
        try {
            $transactionToken =  $this->CI->mpesa_payments_lib->generateToken(null);
            if (!$transactionToken) {
                throw new AiraloException("An error occurred when generating token");
            }

            $paymentDetails = array(
                'transaction_token' => $transactionToken,
                'account_id' => isset($data['account_id']) ? $data['account_id'] : null,
                'did_id' => isset($data['did_id']) ? $data['did_id'] : null,
                'item_type' => isset($data['item_type']) ? $data['item_type'] : null,
                'amount' => $data['amount'],
                'phone_number' => $data['phone_number'],
                'transaction_type' => $data['transaction_type'],
                'mpesa_transaction_ref' => null,
                'currency_rate' => $data['currency_rate'],
                'status' => 'pending',
                'other_table' => isset($data['other_table']) ? $data['other_table'] : null,
                'other_id' => isset($data['intent_id']) ? $data['intent_id'] : null
            );


            $query = "
            INSERT INTO safaricom_mpesa_payments 
            (transaction_token, account_id, did_id, item_type, amount, phone_number, transaction_type, mpesa_transaction_ref, currency_rate, status, other_table,other_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,?)
        ";
            $result = $this->db_con->query($query, array_values($paymentDetails));

            $insertedId = $this->db_con->insert_id();

            $resultQuery = "SELECT transaction_token FROM safaricom_mpesa_payments WHERE id = ?";
            $result = $this->db_con->query($resultQuery, [$insertedId]);

            if ($result->num_rows() === 0) {
                return null;
            }

            $trasactionToken = $result->row_array()["transaction_token"];
            $this->updateTransactionToken($data['intent_id'], $transactionToken, $data["payment_method"]);

            return $trasactionToken;
        } catch (\Exception $e) {
            $this->logger->logError("Exception: " . $e->getMessage());
            throw $e;
        }
    }

    public function updateTransactionToken($intentId, $transactionToken, $paymentMethod)
    {
        try {
            if (empty($transactionToken) || !is_string($transactionToken)) {
                throw new \InvalidArgumentException("Please provide a valid transaction token.");
            }

            if (empty($paymentMethod) || !is_string($paymentMethod)) {
                throw new \InvalidArgumentException("Please provide a valid payment method.");
            }

            $intentQuery = "SELECT * FROM airalo_order_intent WHERE id = ?";
            $intentResult = $this->db_con->query($intentQuery, [$intentId]);

            if ($intentResult->num_rows() === 0) {
                throw new \Exception("Order intent not found.");
            }

            $updateQuery = "
            UPDATE airalo_order_intent 
            SET transaction_token = ?, payment_method = ? 
            WHERE id = ?
        ";
            $this->db_con->query($updateQuery, [$transactionToken, $paymentMethod, $intentId]);

            $updatedIntent = $this->db_con->query($intentQuery, [$intentId]);

            if ($updatedIntent->num_rows() === 0) {
                throw new \Exception("Error updating order intent.");
            }

            return $updatedIntent->row_array();
        } catch (\Exception $e) {
            throw $e;
        }
    }


    private function logTransaction($response_data, $data, $transactionToken)
    {
        try {
            $payment_details = array(
                'account_id' => isset($data['account_id']) ? $data['account_id'] : null,
                'item_type' => isset($data['item_type']) ? $data['item_type'] : null,
                'amount' => $data['amount'],
                'charge_description' => isset($response_data['ChargeDescription']) ? $response_data['ChargeDescription'] : null,
                'description' => isset($response_data['Description']) ? $response_data['Description'] : null,
                'merchant_request_id' => isset($response_data['MerchantRequestID']) ? $response_data['MerchantRequestID'] : null,
                'checkout_request_id' => isset($response_data['CheckoutRequestID']) ? $response_data['CheckoutRequestID'] : (isset($response_data['requestId']) ? $response_data['requestId'] : null),
                'result_desc' => isset($response_data['ResponseDescription']) ? $response_data['ResponseDescription'] : null,
                'response_code' => isset($response_data['ResponseCode']) ? $response_data['ResponseCode'] : (isset($response_data['errorCode']) ? $response_data['errorCode'] : null),
                'response_description' => isset($response_data['ResponseDescription']) ? $response_data['ResponseDescription'] : null,
                'customer_message' => isset($response_data['CustomerMessage']) ? $response_data['CustomerMessage'] : (isset($response_data['errorMessage']) ? $response_data['errorMessage'] : null),
                'mpesa_receipt_number' => isset($response_data['MpesaReceiptNumber']) ? $response_data['MpesaReceiptNumber'] : null,
                'transaction_date' => gmdate('Y-m-d H:i:s'),
                'phone_number' => isset($data['phone_number']) ? $data['phone_number'] : null,
                'transaction_token' => $transactionToken,
                'transaction_ref' => isset($response_data['MpesaReceiptNumber']) ? $response_data['MpesaReceiptNumber'] : null,
                'transaction_type' => $data['transaction_type'],
                'request_type' => $data['request_type']
            );


            $query = "
            INSERT INTO safaricom_mpesa_c2b_transaction_logs 
            (account_id, item_type, amount, charge_description, description, 
             merchant_request_id, checkout_request_id, result_desc, response_code, 
             response_description, customer_message, mpesa_receipt_number, transaction_date, 
             phone_number, transaction_token, transaction_ref, transaction_type, request_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,?)
        ";

            $this->db_con->query($query, array_values($payment_details));

            $insertedId = $this->db_con->insert_id();
            return;
        } catch (\Exception $e) {
            $this->logger->logError("Error logging transaction: " . $e->getMessage());
            throw $e;
        }
    }

    public function prcoessMpesaPayment($paymentintent, $transaction)
    {
        // echo json_encode($paymentintent);
        //  echo json_encode($transaction);

        try {
            $result = [];
            if ($intent["other_table"] = "airalo_order_intent") {
                $airaloServices = new HandlerService();
                $result =  $airaloServices->handleMpesaPayment($paymentintent, $transaction);
            }

            // return $result;
            //TODO remove
            echo json_encode([
                'status' => true,
                'message' => 'Temporary response. In place of Daraja payments process in staging environment',
                'data' => $result
            ], 200);
        } catch (\Exception $e) {
            $this->logger->logError("Error logging transaction: " . $e->getMessage());
            //TODO remove
            echo json_encode([
                'message' => 'Temporary response. In place of Daraja payments process in staging environment',
                'status' => false,
                'error' => "An error occurred: " . $e->getMessage()
            ], 500);
        }
    }


    public function prcoessDPOPayment($transaction, $payment)
    {
        /// echo json_encode($transaction);
        // echo json_encode($payment);

        $this->logger->logInfo("DPO transaction data:....... " . json_encode($transaction));
        $this->logger->logInfo("DPO transaction payment data:....... " . json_encode($payment));

        try {
            $result = [];
            $airaloServices = new HandlerService();
            $result =  $airaloServices->handleDPOPayment($transaction, $payment);

            return $result;
        } catch (\Exception $e) {
            $this->logger->logError("Error logging transaction: " . $e->getMessage());
        }
    }
}
