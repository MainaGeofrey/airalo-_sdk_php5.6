<?php

namespace Airalo\Services;

use Airalo\Config;
use Airalo\Constants\ApiConstants;
use Airalo\Exceptions\AiraloException;
use Airalo\Helpers\Cached;
use Airalo\Helpers\EasyAccess;
use Airalo\Resources\CurlResource;
use Airalo\Services\RegionService;
use Exception;

class PackagesService extends BaseService
{
    private $accessToken;
    private $baseUrl;
    private $config;
    private $curl;
    private $db_con;
    private $regionService;

    /**
     * @param Config $config
     * @param CurlResource $curl
     * @param string $accessToken
     */
    public function __construct(Config $config, CurlResource $curl, $accessToken, RegionService $regionService, $db_con = null)
    {
        if (!$accessToken) {
            throw new AiraloException('Invalid access token please check your credentials');
        }
        parent::__construct();
        $this->accessToken = $accessToken;
        $this->config = $config;
        $this->baseUrl = $this->config->getUrl();
        $this->curl = $curl;

        $this->db_con = $this->CI->db;
        $this->regionService = $regionService;
    }

    public function checkIfPackageExists($package_id)
    {
        try {
            if (empty($package_id) || !is_string($package_id)) {
                throw new \InvalidArgumentException("Invalid package_id provided.");
            }

            $query = "SELECT * FROM airalo_packages WHERE package_id = ?";
            $result = $this->db_con->query($query, $package_id);

            if ($result->num_rows() === 0) {
                return null;
            }

            return $result->result_array();
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $th) {
            throw $th;
        }
    }
    



    /**
     * @param array $params
     * @return EasyAccess|null
     */
    public function getPackages(array $params = array())
    {
        // $params["limit"] = 200;
        $url = $this->buildUrl($params);
        // echo $url . "\n";

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

                //  echo json_encode($response);
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

    public function getCustomFilteredPackages($postdata)
    {
        $params = $postdata["params"];
        $params["include"] = "topup";
        //$params["limit"] = 20;
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

            $formattedResponse = new EasyAccess(isset($params['flat']) && $params['flat'] ? $this->flatten($result) : $result);

            if (!is_null($formattedResponse["data"])) {
                $this->regionService->updateDbData($formattedResponse["data"]);
            }

            return $formattedResponse;
        }, $this->getKey($url, $params), 3600);

        if (!is_null($result["data"])) {
            $accountid = $postdata["id"];
            $result["data"] = $this->processPackageResponse($result["data"], $accountid);
            return $result["data"];
        } else {
            return null;
        }
    }


    private function processPackageResponse($data, $accountid)
    {
        try {
            $allowedData = $this->allowedData($data);

            $packageIds = [];

            foreach ($allowedData as $entry) {
                foreach ($entry->operators as $operator) {
                    foreach ($operator->packages as $package) {
                        $packageIds[] = $package->id;
                    }
                }
            }

            $packageIds = array_unique($packageIds);

            //  echo json_encode($packageIds);

            $query = "SELECT package_id, elige_edge FROM airalo_packages WHERE package_id IN ('" . implode("','", $packageIds) . "')";
            $result = $this->db_con->query($query);

            if ($result->num_rows() === 0) {
                return null;
            }

            $packageConfig = $result->result_array();
            $currencyInfo = $this->accountService->getCurrencyInfo($accountid);
            $currencyRate = 1;
            $currency = "USD";

            if ($currencyInfo && isset($currencyInfo['currencyrate'])) {
                $currencyRate = $currencyInfo['currencyrate'];
                $currency = $currencyInfo['currency'];
            }

            foreach ($allowedData as $entry) {
                $entry->currency = $currency;
                foreach ($entry->operators as $operator) {
                    foreach ($operator->packages as &$package) {
                        if ($package->id) {
                            $config = array_filter($packageConfig, function ($config) use ($package) {
                                return $config["package_id"] === $package->id;
                            });

                            if (!empty($config)) {
                                $configKey = key($config);
                                $increasePercent = $config[$configKey]["elige_edge"];
                                $newPrice = $package->net_price * (1 + $increasePercent / 100);
                                $newPrice *= $currencyRate;

                                $package->price = number_format($newPrice, 2);
                                unset($package->net_price);
                                unset($package->voice);
                                unset($package->text);
                            }
                        }
                    }
                }
            }

            return $allowedData;
        } catch (Exception $e) {
            $this->logger->logException($e);
            throw new Exception("" . $e->getMessage());
        }
    }

    private function getAllowedCountries()
    {
        try {
            $query = "SELECT * FROM airalo_countries WHERE status = 1";
            $result = $this->db_con->query($query);

            if (!$result) {
                throw new Exception("Database query failed: " . $this->db_con->error);
            }

            if ($result->num_rows === 0) {
                return null;
            }

            return $result->result_array();
        } catch (Exception $e) {
            throw $e;
        }
    }

    private function filterAllowedPackages($packageIds, $allowedRegions)
    {
        $allowedPackageIds = [];
        foreach ($allowedRegions as $region) {
            $allowedPackageIds = array_merge($allowedPackageIds, $region['packages']);
        }

        $allowedPackageIds = array_unique($allowedPackageIds);

        $filteredPackageIds = array_filter($packageIds, function ($packageId) use ($allowedPackageIds) {
            return in_array($packageId, $allowedPackageIds);
        });

        return $filteredPackageIds;
    }


    private function allowedData($data)
    {
        $country_code = $data[0]["country_code"];

        if (empty($country_code)) {
            $allowedRecords = $this->regionService->getAllowedRegionsWithConfig();
        } else {
            $allowedRecords  = $this->getAllowedCountries();
        }

        $allowedSlugs = array_map(function ($allowedRecord) {
            return $allowedRecord['slug'];
        }, $allowedRecords);

        // echo json_encode($allowedSlugs);

        $filteredData = [];
        foreach ($data as $entry) {
            if (isset($entry['slug']) && in_array($entry['slug'], $allowedSlugs)) {
                $filteredData[] = $entry;
            }
        }

        return array_values($filteredData);
    }




    public function getPackageById($params)
    {
        $packageId = $params["package_id"];
        $packages = $this->getPackages($params);



        foreach ($packages['data'] as $package) {

            foreach ($package['operators'] as $operator) {
                foreach ($operator['packages'] as $pkg) {

                    if ($pkg['id'] == $packageId) {
                        return $pkg;
                    }
                }
            }
        }

        return null;
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
