<?php

namespace Airalo\Services;

use Airalo\Config;
use Airalo\Constants\ApiConstants;
use Airalo\Exceptions\AiraloException;
use Airalo\Helpers\Cached;
use Airalo\Helpers\EasyAccess;
use Airalo\Resources\CurlResource;

class PackagesService
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
     * @param array $params
     * @return EasyAccess|null
     */
    public function getPackages(array $params = array())
    {
        $url = $this->buildUrl($params);

        $result = Cached::get(function () use ($url, $params) {
            $currentPage = isset($params['page']) ? $params['page'] : 1;
            $result = array('data' => array());

            while (true) {
                if ($currentPage) {
                    $pageUrl = $url . "&page=$currentPage";
                }

                $response = $this->curl->setHeaders(array(
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->accessToken,
                ))->get(isset($pageUrl) ? $pageUrl : $url);

                if (!$response) {
                    return null;
                }

                $response = json_decode($response, true);

                if (empty($response['data'])) {
                    break;
                }

                $result['data'] = array_merge($result['data'], $response['data']);

                if (isset($params['limit']) && count($result['data']) >= $params['limit']) {
                    break;
                }

                if (isset($response['meta']['last_page']) && $response['meta']['last_page'] == $currentPage) {
                    break;
                }

                $currentPage++;
            }

            return new EasyAccess(isset($params['flat']) && $params['flat'] ? $this->flatten($result) : $result);
        }, $this->getKey($url, $params), 3600);

        return count($result['data']) ? $result : null;
    }

    /**
     * @param array $params
     * @return string
     */
    private function buildUrl(array $params)
    {
        $url = $this->baseUrl . ApiConstants::PACKAGES_SLUG . '?';
        $queryParams = array('include' => 'topup');

        if (isset($params['simOnly']) && $params['simOnly'] === true) {
            unset($queryParams['include']);
        }

        if (isset($params['type'])) {
            $queryParams['filter[type]'] = $params['type'];
        }
        if (isset($params['country'])) {
            $queryParams['filter[country]'] = $params['country'];
        }
        if (isset($params['limit']) && $params['limit'] > 0) {
            $queryParams['limit'] = $params['limit'];
        }

        return $url . http_build_query($queryParams);
    }

    /**
     * @param array $data
     * @return array
     */
    private function flatten(array $data)
    {
        $flattened = array('data' => array());

        foreach ($data['data'] as $each) {
            foreach ($each['operators'] as $operator) {
                foreach ($operator['packages'] as $package) {
                    $countries = array();

                    foreach ($operator['countries'] as $country) {
                        $countries[] = $country['country_code'];
                    }

                    $flattened['data'][] = array(
                        'package_id' => $package['id'],
                        'slug' => $each['slug'],
                        'type' => $package['type'],
                        'price' => $package['price'],
                        'net_price' => $package['net_price'],
                        'amount' => $package['amount'],
                        'day' => $package['day'],
                        'is_unlimited' => $package['is_unlimited'],
                        'title' => $package['title'],
                        'data' => $package['data'],
                        'short_info' => $package['short_info'],
                        'voice' => $package['voice'],
                        'text' => $package['text'],
                        'plan_type' => $operator['plan_type'],
                        'activation_policy' => $operator['activation_policy'],
                        'operator' => array(
                            'title' => $operator['title'],
                            'is_roaming' => $operator['is_roaming'],
                            'info' => $operator['info'],
                        ),
                        'countries' => $countries,
                        'image' => isset($operator['image']['url']) ? $operator['image']['url'] : null,
                        'other_info' => $operator['other_info'],
                    );
                }
            }
        }

        return $flattened;
    }

    /**
     * @param string $url
     * @param array $params
     * @return string
     */
    private function getKey($url, array $params)
    {
        return md5($url . json_encode($params) . json_encode($this->config->getHttpHeaders())  . $this->accessToken);
    }
}
