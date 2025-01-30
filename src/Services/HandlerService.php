<?php

namespace Airalo\Services;

use Airalo\Config;
use Airalo\Constants\ApiConstants;
use Airalo\Exceptions\AiraloException;
use Airalo\Helpers\Cached;
use Airalo\Helpers\EasyAccess;
use Airalo\Resources\CurlResource;
use Airalo\Helpers\Signature;
use Airalo\Resources\MultiCurlResource;
use Exception;

class HandlerService extends BaseService
{
    private $accessToken;
    private $airaloConfig;
    private $curl;
    private $signature;
    private $multiCurlResource;
    private $regionService;
    private $db_con;

    private $orderStatus = [];


    /**
     * @param Config $config
     * @param CurlResource $curl
     * @param string $accessToken
     */
    public function __construct()
    {
        parent::__construct();
        $this->initializeAiraloServices();
        $this->db_con = $this->CI->db;
        $this->orderStatus = $this->orderStatuses;
    }

    private function initializeAiraloServices()
    {
        try {
            $configData = $this->getAccessConfig();

            $this->airaloConfig = new Config($configData);
            $this->curl = new CurlResource($this->airaloConfig, false, $this->db);
            $this->multiCurlResource = new MultiCurlResource($this->airaloConfig);
            $this->signature = new Signature($configData["client_secret"]);

            $oauthService = new OAuthService($this->airaloConfig, $this->curl, $this->signature);

            $this->accessToken = $oauthService->getAccessToken();

            if (!$this->accessToken) {
                $this->logger->logInfo("Failed to obtain access token." . PHP_EOL);
            }
        } catch (Exception $e) {
            $this->logger->logError('Error during Airalo service initialization: ' . $e->getMessage());
            throw $e;
        }
    }

    public function handleMpesaPayment($paymnetIntent, $transaction)
    {
        try {
            $orderService = $this->createOrderService();
            $orderIntent = $orderService->getOrderIntentById($paymnetIntent["other_id"], $this->orderStatus[2], $paymnetIntent["account_id"]);
            $result = [];

            //   echo json_encode($orderIntent);

            if ($orderIntent) {
                $result =   $orderService->handleOrder($paymnetIntent, $orderIntent, $transaction);
            } else {
                throw new Exception("Error hanling esim payment. Order intent not found");
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->logError('Error handling Esim payment: ' . $e->getMessage());
            throw $e;
        }
    }

    public function handleDPOPayment($transaction, $payment)
    {
        try {


            $accountid = $transaction["account_id"];
            $orderService = $this->createOrderService();
            $orderIntent = $orderService->getOrderIntentById($transaction["intent"], $this->orderStatus[1], $accountid);
            $result = [];

            $payment["transaction_token"] = $transaction["TransactionToken"];
            $payment["amount"] = $payment["TransactionAmount"];
            $payment["account_id"] = $accountid;
            $type = $transaction["type"];

            if ($type === 'esim_topup') {
                $payment["type"]  = 'topup';
            } else if ($type === 'esim_purchase') {
                $payment["type"] = 'sim';
            } else {
                $this->logger->logCritical("Error processing DPO ESIM paymenbt. We could not get the correct type " . json_encode($transaction));
            }

            //  echo json_encode($payment);

            if ($orderIntent) {
                $result =   $orderService->handleOrder($payment, $orderIntent);
            } else {
                throw new Exception("Error hanling esim payment. Order intent not found");
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->logError('Error handling Esim payment: ' . $e->getMessage());
            throw $e;
        }
    }

    private function createOrderService()
    {
        try {
            $multiCurlResource = new MultiCurlResource($this->airaloConfig);
            $config = $this->airaloConfig->getConfig();
            $signature = new Signature($config["client_secret"]);

            $orderService = new OrderService($this->airaloConfig, $this->curl, $multiCurlResource, $signature, $this->accessToken);

            return $orderService;
        } catch (Exception $e) {
            throw $e;
        }
    }
}
