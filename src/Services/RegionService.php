<?php

namespace Airalo\Services;

use Airalo\Config;
use Airalo\Constants\ApiConstants;
use Airalo\Exceptions\AiraloException;
use Airalo\Helpers\Cached;
use Airalo\Helpers\EasyAccess;
use Airalo\Resources\CurlResource;
use Exception;

class RegionService extends BaseService
{
    private $db_con;

    /**
     * @param Config $config
     * @param CurlResource $curl
     * @param string $accessToken
     */
    public function __construct($db_con)
    {
        parent::__construct();
        $this->db_con = $this->CI->db;
    }

    /**
     * @param array $params
     * @return EasyAccess|null
     */
    public function getRegions()
    {
        try {
            $query = "SELECT id as region_id, slug, title, type, description, status FROM airalo_regions";

            $result = $this->db_con->query($query);

            if ($result->num_rows() === 0) {
                return null;
            }
            return $result->result_array();
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function getAllowedRegionsWithConfig()
    {
        try {
            // SQL query to join airalo_regions with airalo_region_configs
            $query = "
        SELECT 
            r.id AS region_id, 
            r.slug, 
            r.title, 
            r.type, 
            r.description, 
            r.status AS region_status,
            GROUP_CONCAT(c.iso_country_code) AS iso_country_code, 
            GROUP_CONCAT(c.packages) AS packages, 
            c.status AS config_status
        FROM 
            airalo_regions r
        LEFT JOIN 
            airalo_region_configs c 
        ON 
            r.id = c.region_id
        WHERE 
            r.status = 1
        GROUP BY 
            r.id
        ";

            $result = $this->db_con->query($query);

            // Check if there are rows in the result
            if ($result->num_rows() === 0) {
                return null;
            }

            // Fetch all rows as an array
            $regions = $result->result_array();

            // Decode the packages JSON strings
            foreach ($regions as &$region) {
                if (!empty($region['packages'])) {
                    // Correctly handle JSON decoding
                    $region['packages'] = json_decode(str_replace('],[', ',', $region['packages']), true);
                }
            }

            // echo json_encode($regions);

            return $regions;
        } catch (\Throwable $th) {
            throw $th;
        }
    }




    public function updateDbData($response)
    {
        try {
            $this->db_con->trans_start();
            $regionId = null;
            $countryId = null;

            foreach ($response as $data) {
                if (!empty($data["country_code"])) {

                    $country = $data;
                    $formattedCountry = [
                        'slug' => $country["slug"],
                        'title' => $country['title'],
                        'code' => $country['country_code'],
                        'status' => 1,
                        'image_url' => $country['image']['url'] ?? null
                    ];


                    $countryInsertQuery = "
                    INSERT INTO airalo_countries (slug, title, code, status, image_url, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW()) 
                    ON DUPLICATE KEY UPDATE 
                        title = VALUES(title),
                        code = VALUES(code),
                        image_url = VALUES(image_url),
                        updated_at = NOW()";

                    $this->db_con->query($countryInsertQuery, [
                        $formattedCountry['slug'],
                        $formattedCountry['title'],
                        $formattedCountry['code'],
                        $formattedCountry['status'],
                        $formattedCountry['image_url']
                    ]);

                    $countryId = $this->db_con->insert_id();
                } else {
                    $type = ($data['slug'] === 'world') ? 'Global' : (isset($data['type']) ? $data['type'] : 'Regional');
                    $description = (isset($data['description']) && $data['slug'] !== 'world') ? $data['description'] : $data['title'];

                    $regionInsertQuery = "
                        INSERT INTO airalo_regions (slug, title, type, description, status, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW()) 
                        ON DUPLICATE KEY UPDATE 
                            type = VALUES(type),
                            description = VALUES(description),
                            updated_at = NOW()";

                    $this->db_con->query($regionInsertQuery, [
                        $data['slug'],
                        $data['title'],
                        $type,
                        $description,
                        1
                    ]);

                    $regionId = $this->db_con->insert_id();


                    if (isset($data['operators'])) {
                        $this->handleRegionConfigs($data, $regionId);
                    }
                }

                if (isset($data['operators'])) {
                    $this->handleOperators($data['operators'], $regionId, $countryId, $data['country_code']);
                }
            }

            $this->db_con->trans_complete();

            if ($this->db_con->trans_status() === false) {
                throw new AiraloException("Failed to add region and configuration.");
            }

            return [
                'message' => 'Region and configurations added successfully.'
            ];
        } catch (\Throwable $th) {
            $this->db_con->trans_rollback();
            throw new AiraloException("Error adding region and configuration: " . $th->getMessage());
        }
    }



    private function handleRegionConfigs($data, $regionId)
    {
        $insertData = [];

        foreach ($data['operators'] as $operator) {
            if (is_array($operator['countries']) || is_object($operator['countries'])) {
                $countriesArray = json_decode(json_encode($operator['countries']), true);

                foreach ($countriesArray as $country) {
                    $countryCode = isset($country['country_code']) ? $country['country_code'] : null;

                    $packagesArray = json_decode(json_encode($operator['packages']), true);
                    $packageIds = [];
                    if (is_array($packagesArray)) {
                        foreach ($packagesArray as $package) {
                            if (isset($package['id'])) {
                                $packageIds[] = $package['id'];
                            }
                        }
                    }
                    $packagesJson = json_encode($packageIds);

                    $insertData[] = [
                        'region_id' => $regionId,
                        'iso_country_code' => $countryCode,
                        'packages' => $packagesJson,
                        'status' => 1
                    ];
                }
            } else {
                $this->logger->logError("Countries is not an array or object for region ID: {$regionId}");
            }
        }

        try {
            if (!empty($insertData)) {
                // Using ON DUPLICATE KEY UPDATE to avoid duplicates
                $query = "
                INSERT INTO airalo_region_configs (
                    iso_country_code, 
                    region_id, 
                    packages, 
                    status, 
                    updated_at
                ) 
                VALUES (?, ?, ?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE 
                    packages = VALUES(packages),
                    updated_at = NOW();
            ";

                foreach ($insertData as $data) {
                    $this->db_con->query($query, [
                        $data['iso_country_code'],
                        $data['region_id'],
                        $data['packages'],
                        $data['status']
                    ]);
                }

                $this->logger->logInfo("Inserted or updated " . count($insertData) . " configurations successfully for region ID: {$regionId}");
            } else {
                $this->logger->logInfo("No configurations to insert for region ID: {$regionId}");
            }
        } catch (Exception $e) {
            $this->logger->logError("Error inserting/updating batch: " . $e->getMessage());
        }
    }





    private function handleOperators($operatorData, $regionId, $countryId, $countryCode = "")
    {
        $operatorData = json_decode(json_encode($operatorData), true);

        if (!is_array($operatorData)) {
            throw new AiraloException("Invalid operator data provided.");
        }

        $formattedOperators = [];
        $operatorIdMapping = [];

        foreach ($operatorData as $operator) {
            $formattedOperators[] = array(
                'airalo_operator_id' => $operator['id'],
                'region_id' => $regionId,
                'country_id' => $countryId,
                'title' => $operator['title'],
                'style' => isset($operator['style']) ? $operator['style'] : null,
                'gradient_start' => isset($operator['gradient_start']) ? $operator['gradient_start'] : null,
                'gradient_end' => isset($operator['gradient_end']) ? $operator['gradient_end'] : null,
                'type' => isset($operator['type']) ? $operator['type'] : null,
                'is_prepaid' => isset($operator['is_prepaid']) ? (int) $operator['is_prepaid'] : 0,
                'esim_type' => isset($operator['esim_type']) ? $operator['esim_type'] : null,
                'apn_type' => isset($operator['apn_type']) ? $operator['apn_type'] : null,
                'apn_value' => isset($operator['apn_value']) ? $operator['apn_value'] : null,
                'is_roaming' => isset($operator['is_roaming']) ? (int) $operator['is_roaming'] : 0,
                'plan_type' => isset($operator['plan_type']) ? $operator['plan_type'] : null,
                'activation_policy' => isset($operator['activation_policy']) ? $operator['activation_policy'] : null,
                'is_kyc_verify' => isset($operator['is_kyc_verify']) ? (int) $operator['is_kyc_verify'] : 0,
                'rechargeability' => isset($operator['rechargeability']) ? (int) $operator['rechargeability'] : 0,
                'image_url' => isset($operator['image']['url']) ? $operator['image']['url'] : null,
                'image_width' => isset($operator['image']['width']) ? $operator['image']['width'] : null,
                'image_height' => isset($operator['image']['height']) ? $operator['image']['height'] : null,
                'info' => isset($operator['info']) ? (is_array($operator['info']) ? json_encode($operator['info']) : $operator['info']) : null,
                'other_info' => isset($operator['other_info']) ? (is_array($operator['other_info']) ? json_encode($operator['other_info']) : $operator['other_info']) : null,
            );
        }

        try {
            // Prepare the bulk insert or update query
            $query = "
            INSERT INTO airalo_operators (
                airalo_operator_id, region_id,country_id, title, style, gradient_start, gradient_end, 
                type, is_prepaid, esim_type, apn_type, apn_value, is_roaming, plan_type, 
                activation_policy, is_kyc_verify, rechargeability, image_url, image_width, 
                image_height, info, other_info, updated_at
            ) VALUES ";

            $queryValues = [];
            $queryParams = [];

            foreach ($formattedOperators as $operator) {
                $queryValues[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?,?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $queryParams = array_merge($queryParams, array_values($operator));
            }

            $query .= implode(", ", $queryValues);
            $query .= "
            ON DUPLICATE KEY UPDATE 
                region_id = VALUES(region_id),
                title = VALUES(title),
                style = VALUES(style),
                gradient_start = VALUES(gradient_start),
                gradient_end = VALUES(gradient_end),
                type = VALUES(type),
                is_prepaid = VALUES(is_prepaid),
                esim_type = VALUES(esim_type),
                apn_type = VALUES(apn_type),
                apn_value = VALUES(apn_value),
                is_roaming = VALUES(is_roaming),
                plan_type = VALUES(plan_type),
                activation_policy = VALUES(activation_policy),
                is_kyc_verify = VALUES(is_kyc_verify),
                rechargeability = VALUES(rechargeability),
                image_url = VALUES(image_url),
                image_width = VALUES(image_width),
                image_height = VALUES(image_height),
                info = VALUES(info),
                other_info = VALUES(other_info),
                updated_at = NOW();
        ";


            // Execute the query
            $this->db_con->query($query, $queryParams);

            $this->logger->logInfo("Successfully inserted or updated operators.");

            // Map inserted operator IDs for further processing
            $this->db_con->select('id, airalo_operator_id');
            $this->db_con->from('airalo_operators');
            $this->db_con->where_in('airalo_operator_id', array_column($operatorData, 'id'));
            $query = $this->db_con->get();

            $insertedOperators = $query->result_array();

            foreach ($insertedOperators as $operator) {
                $operatorIdMapping[$operator['airalo_operator_id']] = $operator['id'];
            }
        } catch (Exception $e) {
            $this->logger->logError("Error inserting or updating operators batch: " . $e->getMessage());
            $this->logger->logInfo("Failed Operators Data: " . json_encode($formattedOperators));
            throw new AiraloException("Failed to insert or update operators: " . $e->getMessage());
        }

        foreach ($operatorData as $operator) {
            $operatorId = $operatorIdMapping[$operator['id']] ?? null;
            if ($operatorId && isset($operator['coverages']) && !empty($operator['coverages'])) {
                $this->handleCoverages($operator['coverages'], $operatorId);
            }

            if ($operatorId && isset($operator['packages']) && !empty($operator['packages'])) {
                $this->handlePackages($operator['packages'], $regionId,$countryId, $operatorId, $countryCode);
            }
        }
    }




    private function handlePackages($packages, $regionId,$countryId, ?int $operatorId = null, ?string $countryCode = null, int $createdBy = 0)
    {
        $packages = json_decode(json_encode($packages), true);

        if (empty($packages)) {
            throw new AiraloException("No packages data provided.");
        }

        $formattedPackages = [];
        foreach ($packages as $package) {
            $formattedPackage = array(
                'package_id' => isset($package['id']) ? $package['id'] : null,
                'region_id' => $regionId,
                'country_id' => $countryId,
                'operator_id' => $operatorId,
                'slug' => isset($package['id']) ? strtolower(str_replace(' ', '-', $package['id'])) : null,
                'type' => isset($package['type']) ? $package['type'] : null,
                'airalo_price' => isset($package['price']) ? $package['price'] : 0.00,
                'airalo_net_price' => isset($package['net_price']) ? $package['net_price'] : 0.00,
                'elige_edge' => 25.00,
                'amount' => isset($package['amount']) ? $package['amount'] : 0,
                'day' => isset($package['day']) ? $package['day'] : 0,
                'is_unlimited' => isset($package['is_unlimited']) ? (int)$package['is_unlimited'] : 0,
                'title' => isset($package['title']) ? $package['title'] : null,
                'data' => isset($package['data']) ? $package['data'] : null,
                'short_info' => isset($package['short_info']) ? $package['short_info'] : null,
                'qr_installation' => isset($package['qr_installation']) ? $package['qr_installation'] : null,
                'manual_installation' => isset($package['manual_installation']) ? $package['manual_installation'] : null,
                'voice' => isset($package['voice']) ? $package['voice'] : null,
                'text' => isset($package['text']) ? $package['text'] : null,
                'iso_country_code' => isset($countryCode) ? $countryCode : (isset($package['iso_country_code']) ? $package['iso_country_code'] : null),
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
            );


            if (empty($formattedPackage['package_id']) || empty($formattedPackage['type']) || empty($formattedPackage['title'])) {
                throw new AiraloException("Missing required package fields: id, type, or title.");
            }

            $formattedPackages[] = $formattedPackage;
        }

        try {
            $insertQuery = "
            INSERT INTO airalo_packages (package_id, region_id,country_id, operator_id, slug, type, airalo_price, airalo_net_price, elige_edge, amount, day, is_unlimited, title, data, short_info, qr_installation, manual_installation, voice, text, iso_country_code, created_by, updated_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                region_id = VALUES(region_id),
                operator_id = VALUES(operator_id),
                slug = VALUES(slug),
                type = VALUES(type),
                airalo_price = VALUES(airalo_price),
                airalo_net_price = VALUES(airalo_net_price),
                amount = VALUES(amount),
                day = VALUES(day),
                is_unlimited = VALUES(is_unlimited),
                title = VALUES(title),
                data = VALUES(data),
                short_info = VALUES(short_info),
                qr_installation = VALUES(qr_installation),
                manual_installation = VALUES(manual_installation),
                voice = VALUES(voice),
                text = VALUES(text),
                iso_country_code = VALUES(iso_country_code),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ";

            foreach ($formattedPackages as $package) {
                $this->db_con->query($insertQuery, array_values($package));
            }

            $this->logger->logInfo("Inserted or updated " . count($formattedPackages) . " packages successfully.");
        } catch (Exception $e) {
            $this->logger->logError("Error inserting/updating packages batch: " . $e->getMessage());
            throw new AiraloException("Failed to insert/update packages: " . $e->getMessage());
        }
    }


    private function handleCoverages(array $coverages, int $operatorId)
    {
        if (empty($coverages)) {
            throw new Exception("No coverage data provided.");
        }

        $formattedValues = [];
        $placeholders = [];
        $currentTime = date('Y-m-d H:i:s');

        foreach ($coverages as $coverage) {
            if (!isset($coverage['name']) || !isset($coverage['networks'])) {
                throw new Exception("Invalid coverage data: Missing required fields.");
            }

            $countryCode = $coverage['name'];
            foreach ($coverage['networks'] as $network) {
                if (!isset($network['name'], $network['types'])) {
                    throw new Exception("Invalid network data: Missing required fields.");
                }

                $formattedValues[] = [
                    'operator_id' => $operatorId,
                    'country_code' => $countryCode,
                    'network_name' => $network['name'],
                    'network_type' => json_encode(array_values($network['types'])),
                    'created_at' => $currentTime,
                    'updated_at' => $currentTime,
                ];
                $placeholders[] = "(?, ?, ?, ?, ?, ?)";
            }
        }

        if (empty($formattedValues)) {
            throw new Exception("No valid coverage data to insert.");
        }

        $query = "
        INSERT INTO airalo_coverages (operator_id, country_code, network_name, network_type, created_at, updated_at)
        VALUES " . implode(', ', $placeholders) . "
        ON DUPLICATE KEY UPDATE 
            network_name = VALUES(network_name),
            network_type = VALUES(network_type),
            updated_at = VALUES(updated_at)";

        try {
            $flatValues = [];
            foreach ($formattedValues as $row) {
                $flatValues = array_merge($flatValues, array_values($row));
            }

            $this->db_con->query($query, $flatValues);

            $this->logger->logInfo("Inserted or updated " . count($formattedValues) . " coverage records successfully.");
        } catch (Exception $e) {
            $this->logger->logError("Error inserting coverage batch: " . $e->getMessage());
            throw new Exception("Failed to insert coverages: " . $e->getMessage());
        }
    }
}
