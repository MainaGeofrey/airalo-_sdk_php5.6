<?php

namespace Airalo\Services;

use Airalo\Config;
use Airalo\Constants\ApiConstants;
use Airalo\Exceptions\AiraloException;
use Airalo\Helpers\EasyAccess;
use Airalo\Resources\CurlResource;
use Airalo\Helpers\Cached;
use Airalo\Resources\MultiCurlResource;

class SimService
{
    private $config;
    private $curl;
    private $multiCurl;
    private $baseUrl;
    private $accessToken;

    /**
     * @param Config $config
     * @param CurlResource $curl
     * @param MultiCurlResource $multiCurl
     * @param string $accessToken
     */
    public function __construct(
        Config $config,
        CurlResource $curl,
        MultiCurlResource $multiCurl,
        $accessToken
    ) {
        if (!$accessToken) {
            throw new AiraloException('Invalid access token please check your credentials');
        }

        $this->config = $config;
        $this->curl = $curl;
        $this->multiCurl = $multiCurl;
        $this->accessToken = $accessToken;
        $this->baseUrl = $this->config->getUrl();
    }

    /**
     * @param array $params An associative array of parameters
     * @return EasyAccess|null
     */
    public function simUsage(array $params = array())
    {
        $url = $this->buildUrl($params);

        $result = Cached::get(function () use ($url) {
            $response = $this->curl->setHeaders(array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->accessToken,
            ))->get($url);

            $result = json_decode($response, true);

            return new EasyAccess($result);
        }, $this->getKey($url, $params), 300);

        return (count($result['data']) ? $result : null);
    }

    /**
     * @param array $iccids
     * @return mixed
     */
    public function simUsageBulk(array $iccids = array())
    {
        foreach ($iccids as $iccid) {
            $this->multiCurl
                ->tag($iccid)
                ->setHeaders(array(
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->accessToken,
                ))->get($this->buildUrl(array('iccid' => $iccid)));
        }

        return Cached::get(function () {
            if (!$response = $this->multiCurl->exec()) {
                return null;
            }

            $result = array();
            foreach ($response as $iccid => $each) {
                $result[$iccid] = new EasyAccess($each);
            }

            return new EasyAccess($result);
        }, $this->getKey(implode('', $iccids), array()), 300);
    }

    /**
     * Builds a URL based on the provided parameters.
     *
     * @param array $params An associative array of parameters. Must include the 'iccid' key.
     * @return string The constructed URL.
     * @throws AiraloException if the 'iccid' parameter is not provided or is not a valid type.
     */
    private function buildUrl(array $params)
    {
        if (!isset($params['iccid'])) {
            throw new AiraloException('The parameter "iccid" is required.');
        }

        $iccid = (string) $params['iccid'];
        $url = sprintf(
            '%s%s/%s/%s',
            $this->baseUrl,
            ApiConstants::SIMS_SLUG,
            $iccid,
            ApiConstants::SIMS_USAGE
        );

        return $url;
    }

    /**
     * Generates a unique key based on the provided URL, parameters, HTTP headers, and access token.
     *
     * @param string $url The base URL.
     * @param array $params An associative array of parameters.
     * @return string The generated unique key.
     */
    private function getKey($url, array $params)
    {
        return md5($url . json_encode($params) . json_encode($this->config->getHttpHeaders()) . $this->accessToken);
    }
}
