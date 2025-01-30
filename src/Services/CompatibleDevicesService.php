<?php

namespace Airalo\Services;

use Airalo\Config;
use Airalo\Constants\ApiConstants;
use Airalo\Exceptions\AiraloException;
use Airalo\Helpers\Cached;
use Airalo\Helpers\EasyAccess;
use Airalo\Resources\CurlResource;

class CompatibleDevicesService
{
    private $accessToken;

    private $baseUrl;

    private $config;

    private $curl;

    /**
     * @param Config $config
     * @param CurlResource $curl
     * @param string $accessToken
     */
    public function __construct(Config $config, CurlResource $curl, $accessToken)
    {
        if (!$accessToken) {
            throw new AiraloException('Invalid access token please check your credentials');
        }

        $this->accessToken = $accessToken;
        $this->config = $config;
        $this->baseUrl = $this->config->getUrl();
        $this->curl = $curl;
    }

    /**
     * Retrieve compatible devices.
     *
     * @param array $params
     * @return EasyAccess|null
     */
    public function getCompatibleDevices($params = array())
    {
        $url = $this->baseUrl . ApiConstants::COMPATIBILITY_SLUG;

        // Use caching for the compatible devices response
        $result = Cached::get(function () use ($url, $params) {
            $response = $this->curl->setHeaders(array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->accessToken,
            ))->get($url . '?' . http_build_query($params));

            if (!$response) {
                return null;
            }

            $response = json_decode($response, true);
            if (empty($response['data'])) {
                return null;
            }

            return new EasyAccess($response);
        }, $this->getKey($url, $params), 3600);

        return $result;
    }

    /**
     * Generate a unique cache key based on the URL and parameters.
     *
     * @param string $url
     * @param array $params
     * @return string
     */
    private function getKey($url, $params)
    {
        return md5($url . json_encode($params) . json_encode($this->config->getHttpHeaders()) . $this->accessToken);
    }
}
