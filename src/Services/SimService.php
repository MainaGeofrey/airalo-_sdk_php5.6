<?php

namespace Airalo\Services;

use Airalo\Config;
use Airalo\Constants\ApiConstants;
use Airalo\Exceptions\AiraloException;
use Airalo\Helpers\EasyAccess;
use Airalo\Resources\CurlResource;
use Airalo\Helpers\Cached;
use Airalo\Resources\MultiCurlResource;

class SimService extends BaseService
{
    private $config;
    private $curl;
    private $multiCurl;
    private $baseUrl;
    private $accessToken;
    private $db_con;

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
        $this->db_con = $this->CI->db;
    }

    public function getEsimDetails(array $params = [])
    {
        $url = $this->buildSimUrl($params);

        $response = $this->curl->setHeaders([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->accessToken,
        ])->get($url);

        $result = json_decode($response, true);

        return new EasyAccess($result);
    }

    private function buildSimUrl(array $params)
    {
        if (!isset($params['iccid'])) {
            throw new AiraloException('The parameter "iccid" is required.');
        }

        $iccid = (string) $params['iccid'];
        $url = sprintf(
            '%s%s/%s',
            $this->baseUrl,
            ApiConstants::SIMS_SLUG,
            $iccid
        );

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
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
     * Fetches a list of eSIMs with customizable filters and options.
     *
     * @param array $params Request parameters to customize eSIM retrieval
     * @return EasyAccess|null Parsed response data or null if the request fails
     */
    public function getEsims(array $params = [])
    {
        $url = $this->buildEsimsUrl($params);

        return Cached::get(function () use ($url) {
            $response = $this->curl->setHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->accessToken,
            ])->get($url);

            if (!$response) {
                return null;
            }

            return new EasyAccess(json_decode($response, true));
        }, $this->getKey($url, $params), 3600);
    }

    /**
     * Builds the eSIM API request URL with provided parameters.
     *
     * @param array $params Request parameters for URL construction
     * @return string Constructed URL
     */
    private function buildEsimsUrl(array $params)
    {
        $queryParams = [];

        if (isset($params['include'])) {
            $queryParams['include'] = $params['include'];
        }

        if (isset($params['filter[iccid]'])) {
            $queryParams['filter[iccid]'] = $params['filter[iccid]'];
        }
        if (isset($params['filter[created_at]'])) {
            $queryParams['filter[created_at]'] = $params['filter[created_at]'];
        }

        if (isset($params['limit'])) {
            $queryParams['limit'] = $params['limit'];
        }
        if (isset($params['page'])) {
            $queryParams['page'] = $params['page'];
        }
        return $this->baseUrl . ApiConstants::SIMS_SLUG . '?' . http_build_query($queryParams);
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

    /**
     * Retrieves the eSIM data package history, including top-ups, for the specified ICCID.
     * 
     * @param string $iccid The ICCID of the eSIM.
     * @return EasyAccess|null Cached or fresh response data if available, null otherwise.
     * @throws AiraloException If ICCID is not provided or response encounters errors.
     */
    public function getEsimPackages($params)
    {
        $iccid = $params["iccid"];
        if (empty($iccid)) {
            throw new AiraloException('The parameter "iccid" is required.');
        }

        $url = sprintf('%s%s/%s/packages', $this->baseUrl, ApiConstants::SIMS_SLUG, $iccid);


        return Cached::get(function () use ($url) {
            $response = $this->curl->setHeaders([
                'Accept: application/json',
                'Authorization: Bearer ' . $this->accessToken,
            ])->get($url);

            $statusCode = $this->curl->getStatusCode();
            if ($statusCode === 429) {
                $retryAfter = $this->curl->getHeader('Retry-After');
                if ($retryAfter) {
                    sleep((int)$retryAfter);
                    $response = $this->curl->get($url);
                }
            }

            if (!$response || $this->curl->getStatusCode() >= 400) {
                return null;
            }

            return new EasyAccess(json_decode($response, true));
        }, $this->getKey($url, $params), 900);
    }

    public function getEsimTopUpPackages($params)
    {
        $iccid = $params["iccid"];
        if (empty($iccid)) {
            throw new AiraloException('The parameter "iccid" is required.');
        }

        $url = sprintf('%s%s/%s/topups', $this->baseUrl, ApiConstants::SIMS_SLUG, $iccid);


        return Cached::get(function () use ($url) {
            $response = $this->curl->setHeaders([
                'Accept: application/json',
                'Authorization: Bearer ' . $this->accessToken,
            ])->get($url);

            $statusCode = $this->curl->getStatusCode();
            if ($statusCode === 429) {
                $retryAfter = $this->curl->getHeader('Retry-After');
                if ($retryAfter) {
                    sleep((int)$retryAfter);
                    $response = $this->curl->get($url);
                }
            }

            if (!$response || $this->curl->getStatusCode() >= 400) {
                return null;
            }

            return new EasyAccess(json_decode($response, true));
        }, $this->getKey($url, $params), 900);
    }


    public function getCustomerSavedEsims($account_id)
    {

    }
}
