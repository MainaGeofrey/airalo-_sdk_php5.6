<?php

namespace Airalo\Services;

use Airalo;
use Airalo\Config;
use Airalo\Constants\ApiConstants;
use Airalo\Constants\SdkConstants;
use Airalo\Exceptions\AiraloException;
use Airalo\Helpers\EasyAccess;
use Airalo\Resources\CurlResource;
use Airalo\Helpers\Signature;
use Airalo\Resources\MultiCurlResource;
use Exception;

class OrderService extends BaseService
{
    private $config;

    private $curl;

    private $multiCurl;

    private $signature;

    private $accessToken;
    private $orderStatus = [];
    private $db_con;
    /**
     * @param Config $config
     * @param CurlResource $curl
     * @param MultiCurlResource $multiCurl
     * @param Signature $signature
     * @param string $accessToken
     */
    public function __construct(Config $config, CurlResource $curl, MultiCurlResource $multiCurl, Signature $signature, $accessToken)
    {
        if (!$accessToken) {
            throw new AiraloException('Invalid access token please check your credentials');
        }

        parent::__construct();
        $this->config = $config;
        $this->curl = $curl;
        $this->multiCurl = $multiCurl;
        $this->signature = $signature;
        $this->accessToken = $accessToken;
        $this->db_con = $this->CI->db;
        $this->orderStatus = $this->orderStatuses;
    }

    public function getOrder($orderId, array $params = [])
    {
        $url = $this->buildOrderUrl($orderId, $params);
        $response = $this->curl
            ->setHeaders([
                'Accept: application/json',
                'Authorization: Bearer ' . $this->accessToken,
            ])
            ->get($url);

        if ($this->curl->code != 200) {
            throw new AiraloException(
                'Failed to retrieve order, status code: ' . $this->curl->code . ', response: ' . $response
            );
        }

        return new EasyAccess($response);
    }

    public function getOrderList($filters = [])
    {
        $url = $this->buildUrlForOrderList($filters);
        $response = $this->curl
            ->setHeaders([
                'Accept: application/json',
                'Authorization: Bearer ' . $this->accessToken
            ])
            ->get($url);

        if ($this->curl->code != 200) {
            throw new AiraloException(
                'Failed to retrieve order list, status code: ' . $this->curl->code . ', response: ' . $response
            );
        }

        return new EasyAccess($response);
    }

    private function buildUrlForOrderList(array $filters)
    {
        $url = 'https://sandbox-partners-api.airalo.com/v2/orders';
        $queryParams = [];

        if (isset($filters['include'])) {
            $queryParams['include'] = implode(',', (array) $filters['include']);
        }

        if (isset($filters['filter']['created_at'])) {
            $queryParams['filter[created_at]'] = $filters['filter']['created_at'];
        }

        if (isset($filters['filter']['code'])) {
            $queryParams['filter[code]'] = $filters['filter']['code'];
        }

        if (isset($filters['filter']['order_status'])) {
            $queryParams['filter[order_status]'] = $filters['filter']['order_status'];
        }

        if (isset($filters['filter']['iccid'])) {
            $queryParams['filter[iccid]'] = $filters['filter']['iccid'];
        }

        if (isset($filters['filter']['description'])) {
            $queryParams['filter[description]'] = $filters['filter']['description'];
        }

        if (isset($filters['limit'])) {
            $queryParams['limit'] = $filters['limit'];
        }

        if (isset($filters['page'])) {
            $queryParams['page'] = $filters['page'];
        }

        return $url . '?' . http_build_query($queryParams);
    }


    private function buildOrderUrl($orderId, array $params)
    {
        $url = $this->config->getUrl() . ApiConstants::ORDERS_SLUG . '/' . $orderId;

        $queryParams = [];
        if (!empty($params)) {
            $queryParams['include'] = implode(',', $params);
        }

        return $queryParams ? $url . '?' . http_build_query($queryParams) : $url;
    }

    public function createOrderIntent($accountId, $packagesService, $params, $status)
    {
        try {
            $packageId = $params["package_id"];
            if (!$packageId) {
                throw new AiraloException("Please provide a valid package id");
            }
            

            $resultQuery = "
            SELECT * FROM airalo_order_intent 
            WHERE account_id = ? AND package_id = ? AND status = ? AND created_by = ?
        ";
            $result = $this->db_con->query($resultQuery, [$accountId, $packageId, 'initiated', $accountId]);

            if ($result->num_rows() > 0) {
                //  echo json_encode($result->row_array());
                return $result->row_array();
            }

            $package = $packagesService->checkIfPackageExists($packageId);
            if (!$package) {
                throw new AiraloException("Package not found");
            }

            $iccid = null;
            $packageType = isset($package[0]["type"]) ? $package[0]["type"] : null;
            if ($packageType === "sim") {
                $isDuplicate = $this->isOrderDuplicate($accountId, $packageId);
                if ($isDuplicate) {

                    throw new AiraloException("The user already has this esim package associated with their account.");
                }
            } else {
                if (!isset($params['iccid']) || empty($params['iccid'])) {
                    throw new AiraloException("Esim iccid is required for top up.");
                }
                $packageType = "topup";
                $iccid = isset($params["iccid"]) ? $params["iccid"] : null;
            }

            $query = "
            INSERT INTO airalo_order_intent (account_id, package_id,iccid,type, status, created_by)
            VALUES (?, ?,?, ?,?,?)
        ";



            $this->db_con->query($query, [$accountId, $packageId, $iccid, $packageType, $status, $accountId]);

            $insertedId = $this->db_con->insert_id();

            $resultQuery = "SELECT * FROM airalo_order_intent WHERE id = ?";
            $result = $this->db_con->query($resultQuery, [$insertedId]);

            if ($result->num_rows() === 0) {
                return null;
            }

            return $result->row_array();
        } catch (AiraloException $e) {
            $this->logger->logException($e);
            throw new Exception("Failed to create order intent: " . $e->getMessage());
        } catch (Exception $e) {
            $this->logger->logException($e);
            throw new Exception("Failed to create order intent: " . $e->getMessage());
        }
    }



    public function updateOrderIntentStatus($id, $status, $iccid = null)
    {
        try {
            $checkQuery = "SELECT * FROM airalo_order_intent WHERE id = ?";
            $result = $this->db_con->query($checkQuery, [$id]);

            if ($result->num_rows() === 0) {
                throw new AiraloException("Order intent not found with ID: $id");
            }

            if (is_null($status)) {
                throw new AiraloException("No status provided for updating order intent with ID: $id");
            }

            $updateQuery = "
        UPDATE airalo_order_intent
        SET status = ?, updated_at = NOW()";

            $queryParams = [$status];

            if (!is_null($iccid) && !empty($iccid)) {
                $updateQuery .= ", iccid = ?";
                $queryParams[] = $iccid;
            }

            $updateQuery .= " WHERE id = ?";
            $queryParams[] = $id;

            $this->db_con->query($updateQuery, $queryParams);

            $resultQuery = "SELECT * FROM airalo_order_intent WHERE id = ?";
            $updatedResult = $this->db_con->query($resultQuery, [$id]);

            if ($updatedResult->num_rows() === 0) {
                throw new Exception("Failed to fetch the updated record for order intent with ID: $id");
            }

            return $updatedResult->row_array();
        } catch (AiraloException $e) {
            $this->logger->logException($e);
            throw new Exception("Failed to update order intent: " . $e->getMessage());
        } catch (Exception $e) {
            $this->logger->logException($e);
            throw new Exception("An error occurred while updating order intent: " . $e->getMessage());
        }
    }



    public function getOrderIntentById($intentId, $status, $accountId)
    {
        try {
            // echo 'intent:' . $intentId . PHP_EOL;
            //  echo 'acc: ' . $accountId . PHP_EOL;
            ///  echo 'status:' .  $status . PHP_EOL;

            // $this->logger->logInfo('intent: ' . $intentId);
            //  $this->logger->logInfo('acc: ' . $accountId);
            //  $this->logger->logInfo('status: ' . $status);


            $query = "
                SELECT 
                    airalo_order_intent.id AS intent_id,
                    airalo_order_intent.account_id,
                    airalo_order_intent.package_id,
                    airalo_order_intent.iccid,
                    airalo_order_intent.status,
                    airalo_order_intent.created_at,
                    airalo_packages.id AS ambia_package_id,
                    airalo_packages.package_id AS package_unique_id,
                    airalo_packages.operator_id,
                    airalo_packages.region_id,
                    airalo_packages.country_id,
                    airalo_packages.slug,
                    airalo_packages.type,
                    airalo_packages.airalo_price,
                    airalo_packages.airalo_net_price,
                    airalo_packages.elige_edge,
                    airalo_packages.amount,
                    airalo_packages.day,
                    airalo_packages.is_unlimited,
                    airalo_packages.title,
                    airalo_packages.data,
                    airalo_packages.short_info,
                    airalo_packages.qr_installation,
                    airalo_packages.manual_installation,
                    airalo_packages.voice,
                    airalo_packages.text,
                    airalo_packages.iso_country_code
                FROM airalo_order_intent
                LEFT JOIN airalo_packages 
                    ON airalo_order_intent.package_id = airalo_packages.package_id
                WHERE airalo_order_intent.id = ?
                AND airalo_order_intent.account_id = ?;

        ";

            $result = $this->db_con->query($query, [$intentId, $accountId]);

            if ($result->num_rows() === 0) {
                return null;
            }


            $orderIntent = $result->row_array();
            // echo json_encode($orderIntent);
            if ($orderIntent["status"] !== $status) {
                throw new Exception("Unable to process this order as it has already been processed with the status: " . $orderIntent["status"]);
            }
            $currencyInfo = $this->accountService->getCurrencyInfo($accountId);
            $currencyRate = 1;
            $currency = "USD";

            if ($currencyInfo && isset($currencyInfo['currencyrate'])) {
                $currencyRate = $currencyInfo['currencyrate'];
                $currency = $currencyInfo['currency'];
            }

            $packageConfig = [
                'package_id' => $orderIntent['package_id'],
                'elige_edge' => $orderIntent['elige_edge']
            ];
            $newPrice = $orderIntent['airalo_net_price'] * (1 + $packageConfig['elige_edge'] / 100);
            $newPrice *= $currencyRate;

            $orderIntent['price'] = number_format($newPrice, 2);
            $orderIntent["currency"] = $currency;
            $orderIntent["currency_rate"] = $currencyRate;
            unset($orderIntent['airalo_net_price']);
            unset($orderIntent['airalo_price']);
            return $orderIntent;
        } catch (Exception $e) {
            $this->logger->logException($e);
            throw new Exception("Failed to retrieve order intent: " . $e->getMessage());
        }
    }


    public function isOrderDuplicate($account_id, $package_id)
    {
        try {
            if (empty($account_id) || !is_numeric($account_id)) {
                throw new \InvalidArgumentException("Invalid account_id provided.");
            }
            if (empty($package_id) || !is_string($package_id)) {
                throw new \InvalidArgumentException("Invalid package_id provided.");
            }


            $query = "SELECT * FROM airalo_accounts WHERE account_id = ? AND package_id = ? AND status = 'active';";
            $result = $this->db_con->query($query, [$account_id, $package_id]);


            if ($result->num_rows() > 0) {
                return true;
            }

            return false;
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $th) {
            throw $th;
        }
    }


    public function handleOrder($paymnetIntent, $orderIntent)
    {
        $orderSuccess = false;
        $order = [];
        try {
            $accountId = $paymnetIntent["account_id"];
            $params = [];
            $params["type"] = "sim";
            $params["package_id"] = $orderIntent["package_id"];
            $params["description"] = $accountId;
            $orderPrice = $paymnetIntent['amount'];
            $orderType = $orderIntent["type"];
            $description = "";
            $intentid = $orderIntent["intent_id"];
            $newIccid = "";
            $trasactionToken = $paymnetIntent["transaction_token"];

            if ($orderType === "sim") {
                $params["quantity"] = 1;
                $description = "Esim purchase";
                $orderResponse = $this->createOrder($params);

                if (!isset($orderResponse['data']) || !isset($orderResponse['data']['id'])) {
                    $this->updateOrderIntentStatus($intentid, $this->orderStatus[4]);
                    throw new Exception("Invalid order response structure");
                }

                if (empty($orderResponse['data']['sims']) || !is_object($orderResponse['data']['sims'])) {
                    $this->logger->logCritical("Sims data is missing or invalid. Full response: " . json_encode($orderResponse));
                    throw new Exception("Invalid order response: No 'sims' data found.");
                }

                $sims = (array) $orderResponse['data']['sims'];

                $simCount = count($sims);

                if ($simCount === 0) {
                    throw new Exception("No eSIMs found in the order response.");
                }

                if (
                    $simCount > 1
                ) {
                    $this->logger->logCritical("Multiple eSIMs found in the order response. Unable to determine the correct eSIM. For order intent " . $intentid);
                }
                $firstSimKey = array_key_first($sims);
                if (isset($sims[$firstSimKey]["iccid"])) {
                    $newIccid = $sims[$firstSimKey]["iccid"];
                } else {
                    throw new Exception("The first eSIM in the response does not contain an ICCID.");
                }

                $orderSuccess = true;
                $order  = $orderResponse["data"];
                $order["price"] = intval(str_replace(',', '', $orderIntent["price"]));
                $order["currency"] = $orderIntent["currency"];

                $this->createEsimAccount($accountId, $order);
            } else {
                $orderType = "topup";
                $description = "Esim top up";
                $simService = new TopupService($this->config, $this->curl, $this->signature, $this->accessToken);
                $params["iccid"] = $orderIntent["iccid"];
                $topUpresponse = $simService->createTopup($params);


                if (!isset($topUpresponse['data']) || !isset($topUpresponse['data']['id'])) {

                    $this->updateOrderIntentStatus($intentid, $this->orderStatus[4]);
                    throw new Exception("Invalid order response structure");
                }

                $orderSuccess = true;
                $order  = $topUpresponse["data"];
                $order["price"] = intval(str_replace(',', '', $orderIntent["price"]));
                $order["currency"] = $orderIntent["currency"];

                $this->createEsimTopUpLogs($accountId, $order, $params["iccid"], $intentid);
            }

            $this->updateOrderIntentStatus($intentid, $this->orderStatus[3], $newIccid);
            $account = $this->accountService->getAccount($accountId);
            $this->updateAmbiaTransactionHistory($accountId, $trasactionToken, $orderPrice, $account, $description, $orderType);
            $this->updateInvoiceDetails($accountId, $account, $orderPrice, $description, $orderType, $orderType);


            return $order;
        } catch (Exception $e) {
            if ($orderSuccess) {
                $order["caution"] = "Airalo package created. An error occured while saving records in the database";
                $this->logger->logCritical($e);
                return $order;
            }
            throw $e;
        }
    }


    private function updateAmbiaTransactionHistory($accountId, $package_id, $price, $account, $description, $orderType)
    {

        try {
            $transactionData = [
                'credit' => '-',
                'debit' => $price,
                'accountid' => $accountId,
                'reseller_id' => $account->reseller_id > 0 ? $account->reseller_id : 0,
                'charge_description' => $description,
                'description' => $description,
                'item_type' => $orderType,
                'transaction_ref' => $package_id,
                'creation_date' => gmdate('Y-m-d H:i:s')
            ];
            $this->db_con->insert('ambia_transaction_history', $transactionData);

            return $this->db_con->insert_id();
        } catch (Exception $e) {
            throw $e;
        }
    }

    private function updateInvoiceDetails($accountId, $account, $orderPrice, $description, $orderType)
    {
        try {
            $this->db_con->select('invoiceid');
            $this->db_con->order_by('id', 'desc');
            $this->db_con->limit(1);
            $last_invoice_result = (array) $this->db_con->get('invoice_details')->first_row();

            $last_invoice_ID = isset($last_invoice_result['invoiceid']) ? (int) str_replace('INV-', '', $last_invoice_result['invoiceid']) : 0;

            $new_invoice_id = $last_invoice_ID + 1;

            $invoiceData = [
                'created_date' => gmdate('Y-m-d H:i:s'),
                'credit' => '-',
                'debit' => $orderPrice,
                'accountid' => $account['id'],
                'reseller_id' => $account['reseller_id'] > 0 ? $account['reseller_id'] : 0,
                'invoiceid' => $new_invoice_id,
                'description' => $description,
                'item_type' => $orderType,
                'before_balance' => $account['balance'],
                'after_balance' => $account['balance'] - $orderPrice
            ];

            $this->db_con->insert('invoice_details', $invoiceData);
            return $this->db_con->insert_id();
        } catch (Exception $e) {
            throw $e;
        }
    }

    private function createEsimAccount($id, $orderData)
    {
        try {
            $insertData = [
                'order_id' => (int)$orderData['id'],
                'account_id' => (int)$id,
                'iccid' => (int)$orderData["sims"][0]['iccid'],
                'package_id' => $orderData['package_id'],
                'status' => 'active'
            ];

            $inserted = $this->db_con->insert('airalo_accounts', $insertData);

            if (!$inserted) {
                throw new Exception("Failed to insert airalo account record");
            }

            return $this->db_con->insert_id();
        } catch (Exception $e) {
            throw $e;
        }
    }

    private function createEsimTopUpLogs($accountid, $data, $iccid, $intentid)
    {
        try {
            $insertData = [
                'account_id' => $accountid,
                'order_id' => $data['id'],
                'iccid' => $iccid,
                'intent_id' => $intentid,
                'code' => $data['code'],
                'package_id' => $data['package_id'],
            ];

            $inserted = $this->db_con->insert('airalo_topup_logs', $insertData);

            if (!$inserted) {
                throw new Exception("Failed to insert airalo top up log record");
            }

            return $this->db_con->insert_id();
        } catch (Exception $e) {
            throw $e;
        }
    }



    /**
     * @param array $payload
     * @return EasyAccess|null
     */
    public function createOrder($payload)
    {
               // $payload['brand_settings_name'] = 'ambia';
        $this->validateOrder($payload);

        $response = $this->curl
            ->setHeaders($this->getHeaders($payload))
            ->post($this->config->getUrl() . ApiConstants::ORDERS_SLUG, $payload);

        if ($this->curl->code != 200) {
            throw new AiraloException(
                'Order creation failed, status code: ' . $this->curl->code . ', response: ' . $response
            );
        }

        return new EasyAccess($response);
    }

    /**
     * @param array $payload
     * @return EasyAccess|null
     */
    public function createOrderAsync($payload)
    {
        $this->validateOrder($payload);

        $response = $this->curl
            ->setHeaders($this->getHeaders($payload))
            ->post($this->config->getUrl() . ApiConstants::ASYNC_ORDERS_SLUG, $payload);

        if ($this->curl->code != 202) {
            throw new AiraloException(
                'Order creation failed, status code: ' . $this->curl->code . ', response: ' . $response
            );
        }

        return new EasyAccess($response);
    }

    /**
     * @param array $params
     * @param string|null $description
     * @return EasyAccess|null
     */
    public function createOrderBulk($params, $description = null)
    {
        $this->validateBulkOrder($params);

        foreach ($params as $packageId => $quantity) {
            $payload = array(
                'package_id' => $packageId,
                'quantity' => $quantity,
                'type' => 'sim',
                'description' => $description ? $description : 'Bulk order placed via Airalo PHP SDK',
            );

            $this->validateOrder($payload);

            $this->multiCurl
                ->tag($packageId)
                ->setHeaders($this->getHeaders($payload))
                ->post($this->config->getUrl() . ApiConstants::ORDERS_SLUG, $payload);
        }

        if (!$response = $this->multiCurl->exec()) {
            return null;
        }

        $result = array();

        foreach ($response as $key => $response) {
            $result[$key] = new EasyAccess($response);
        }

        return new EasyAccess($result);
    }

    /**
     * @param array $params
     * @param string|null $webhookUrl
     * @param string|null $description
     * @return EasyAccess|null
     */
    public function createOrderAsyncBulk($params, $webhookUrl = null, $description = null)
    {
        $this->validateBulkOrder($params);

        foreach ($params as $packageId => $quantity) {
            $payload = array(
                'package_id' => $packageId,
                'quantity' => $quantity,
                'type' => 'sim',
                'description' => $description ? $description : 'Bulk order placed via Airalo PHP SDK',
                'webhook_url' => $webhookUrl,
            );

            $this->validateOrder($payload);

            $this->multiCurl
                ->tag($packageId)
                ->setHeaders($this->getHeaders($payload))
                ->post($this->config->getUrl() . ApiConstants::ASYNC_ORDERS_SLUG, $payload);
        }

        if (!$response = $this->multiCurl->exec()) {
            return null;
        }

        $result = array();

        foreach ($response as $key => $response) {
            $result[$key] = new EasyAccess($response);
        }

        return new EasyAccess($result);
    }

    /**
     * @param array $payload
     * @return array
     */
    private function getHeaders($payload)
    {
        return array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->accessToken,
            'airalo-signature: ' . $this->signature->getSignature($payload),
        );
    }

    /**
     * @throws AiraloException
     * @param array $payload
     * @return void
     */
    private function validateOrder($payload)
    {
        if (!isset($payload['package_id']) || $payload['package_id'] == '') {
            throw new AiraloException('The package_id is required, payload: ' . json_encode($payload));
        }

        if ($payload['quantity'] < 1) {
            throw new AiraloException('The quantity is required, payload: ' . json_encode($payload));
        }

        if ($payload['quantity'] > SdkConstants::ORDER_LIMIT) {
            throw new AiraloException('The quantity may not be greater than ' . SdkConstants::BULK_ORDER_LIMIT);
        }
    }

    /**
     * @throws AiraloException
     * @param array $payload
     * @return void
     */
    private function validateBulkOrder($payload)
    {
        if (count($payload) > SdkConstants::BULK_ORDER_LIMIT) {
            throw new AiraloException('The packages count may not be greater than ' . SdkConstants::BULK_ORDER_LIMIT);
        }
    }
}
