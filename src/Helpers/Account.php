<?php

namespace Airalo\Helpers;

use Airalo\Exceptions\AiraloException;

class Account
{
    private $db_con;

    public function __construct($db_con)
    {
        $this->db_con = $db_con;
    }

    public function getAccount($accountId)
    {
        $account_query = "SELECT * FROM accounts WHERE id = $accountId AND deleted = 0";
        $account_result = $this->db_con->query($account_query);

        if ($account_result->num_rows() === 0) {
            return null;
        }

        return $account_result->result_array()[0];
    }


    public function getCurrencyInfo($account_id)
    {
        try {
            $account_info = $this->getAccount($account_id);

            if (!isset($account_info['currency_id'])) {
                return null;
            }
            $currency_query = "SELECT * FROM currency WHERE id = " . $account_info['currency_id'];
            $currency_result = $this->db_con->query($currency_query);

            if ($currency_result->num_rows() === 0) {
                return null;
            }

            $currency_info = $currency_result->result_array()[0];

            if (!isset($currency_info['currencyrate'])) {
                return null;
            }

            return $currency_info;
        } catch (\Exception $e) {
            throw new AiraloException("Error retrieving currency information: " . $e->getMessage());
        }
    }


    public function convertToCustomerCurrency($account_id, $amount)
    {
        try {
            $currency_info = $this->getCurrencyInfo($account_id);

            echo json_encode($currency_info);
            if (!$currency_info || !isset($currency_info['currencyrate'])) {
                throw new AiraloException("Currency rate not available for the given account.");
            }

            $converted_amount = $amount * $currency_info['currencyrate'];
            return round($converted_amount, 2);
        } catch (\Exception $e) {
            throw new AiraloException("Error converting amount to customer currency: " . $e->getMessage());
        }
    }
}
