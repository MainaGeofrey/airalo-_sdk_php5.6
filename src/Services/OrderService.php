<?php

namespace Airalo\Services;

use Airalo\Config;
use Airalo\Constants\ApiConstants;
use Airalo\Constants\SdkConstants;
use Airalo\Exceptions\AiraloException;
use Airalo\Helpers\EasyAccess;
use Airalo\Resources\CurlResource;
use Airalo\Helpers\Signature;
use Airalo\Resources\MultiCurlResource;

class OrderService
{
    private $config;

    private $curl;

    private $multiCurl;

    private $signature;

    private $accessToken;

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

        $this->config = $config;
        $this->curl = $curl;
        $this->multiCurl = $multiCurl;
        $this->signature = $signature;
        $this->accessToken = $accessToken;
    }

    /**
     * @param array $payload
     * @return EasyAccess|null
     */
    public function createOrder($payload)
    {
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
