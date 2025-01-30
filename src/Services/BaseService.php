<?php

namespace Airalo\Services;

use Airalo\Helpers\Logger;
use Airalo\Helpers\Account;
use Airalo\Exceptions\AiraloException;
use Exception;

class BaseService
{
    protected $CI;
    protected $logger;
    protected $accountService;
    protected $orderStatuses = [
        1 => "initiated",
        2 => "started",
        3 => "completed",
        4 => "failed"
    ];

    public function __construct()
    {
        $this->CI = &get_instance();
        $this->CI->load->library("timezone");
        $this->CI->load->model('db_model');
        $this->CI->load->library('common');
        $this->CI->load->model('common_model');
        $this->CI->load->library('ASTPP_Sms');
        $this->CI->load->library('astpp/common');




        //    $logger_path = $this->CI->common_model::$global_config['system_config']['consumer_secret'];

        //  echo $logger_path;
        $this->logger = new Logger();
        $this->accountService  = new Account($this->CI->db);
    }

    public function getAccessConfig()
    {
        try {
            $configData = [
                'client_id' => $this->CI->common_model::$global_config['system_config']['airalo_client_id'] ?? null,
                'client_secret' => $this->CI->common_model::$global_config['system_config']['airalo_client_secret'] ?? null,
                'env' => $this->CI->common_model::$global_config['system_config']['airalo_env'] ?? null, // 'sandbox' 
                'http_headers' => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json'
                ]
            ];

            if (empty($configData['client_id']) || empty($configData['client_secret']) || empty($configData['env'])) {
                throw new Exception("One or more configuration values are missing: client_id, client_secret, or env.");
            }

            return $configData;
        } catch (Exception $e) {
            $this->logger->logError('Error retrieving access configuration: ' . $e->getMessage());

            throw $e;
        }
    }
}
