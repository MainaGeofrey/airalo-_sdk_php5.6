<?php

namespace Airalo\Helpers;

use Airalo\Exceptions\AiraloException;

final class Logger
{
    private $CI;
    private $currentdate;

    public function __construct()
    {
        $this->CI = &get_instance();
        $this->CI->load->library("timezone");
        $this->CI->load->model('db_model');
        $this->CI->load->library('common');
        $this->CI->load->model('common_model');
        $this->CI->load->library('ASTPP_Sms');
        $this->CI->load->library('astpp/common');
        $this->currentdate = gmdate("Y-m-d H:i:s");
    }

    /**
     * Initializes the logger for Airalo
     *
     * @return resource
     * @throws AiraloException
     */
    public function initializeLogger()
    {
        try {
            $logger_path = $this->CI->common_model::$global_config['system_config']['log_path'];
            $fp = fopen($logger_path . "airalo_" . gmdate('Y_m_d') . ".log", "a+");

            fwrite($fp, "====================  Airalo ===============================\n");

            return $fp;
        } catch (\Exception $e) {
            throw new AiraloException('Error initializing Airalo logger: ' . $e->getMessage());
        }
    }

    /**
     * Get current class and function name using debug_backtrace
     *
     * @return string
     */
    private function getCallerInfo()
    {
        $backtrace = debug_backtrace();
        $caller = $backtrace[2]; // Get the caller (index 2, as the last one is this method)
        $className = isset($caller['class']) ? $caller['class'] : 'UnknownClass';
        $functionName = isset($caller['function']) ? $caller['function'] : 'UnknownFunction';
        return $className . '::' . $functionName;
    }

    /**
     * Log general information with class and function info
     *
     * @param string $message
     */
    public function logInfo($message)
    {
        $fp = $this->initializeLogger();
        $callerInfo = $this->getCallerInfo(); // Get class and function name
        fwrite($fp, "[" . gmdate('Y-m-d H:i:s') . "] Info: " . $callerInfo . " - " . $message . "\n");
        fclose($fp);
    }

    /**
     * Log warnings with class and function info
     *
     * @param string $message
     */
    public function logWarning($message)
    {
        $fp = $this->initializeLogger();
        $callerInfo = $this->getCallerInfo(); // Get class and function name
        fwrite($fp, "[" . gmdate('Y-m-d H:i:s') . "] Warning: " . $callerInfo . " - " . $message . "\n");
        fclose($fp);
    }

    /**
     * Log errors with class and function info
     *
     * @param string $message
     */
    public function logError($message)
    {
        $fp = $this->initializeLogger();
        $callerInfo = $this->getCallerInfo(); // Get class and function name
        fwrite($fp, "[" . gmdate('Y-m-d H:i:s') . "] Error: " . $callerInfo . " - " . $message . "\n");
        fclose($fp);
    }

    /**
     * Log exception with class and function info
     *
     * @param \Exception $e
     * @throws AiraloException
     */
    public function logException(\Exception $e)
    {
        $fp = $this->initializeLogger();
        $callerInfo = $this->getCallerInfo(); // Get class and function name
        fwrite($fp, "[" . gmdate('Y-m-d H:i:s') . "] Error: " . $callerInfo . " - " . $e->getMessage() . "\n");
        fwrite($fp, "[" . gmdate('Y-m-d H:i:s') . "] Trace: " . $e->getTraceAsString() . "\n");

        fclose($fp);
    }


    /**
     * Log critical errors
     *
     * @param string $message
     */
    public function logCritical($message)
    {
        try {
            // Path to the critical log file (without date in the filename)
            $logger_path = $this->CI->common_model::$global_config['system_config']['log_path'];
            $critical_fp = fopen($logger_path . "airalo_criticals.log", "a+");

            // Add date header and separator if this is a new entry
            fwrite($critical_fp, "\n==================== " . gmdate('Y-m-d H:i:s') . " ====================\n");

            // Write the critical message
            fwrite($critical_fp, "[" . gmdate('Y-m-d H:i:s') . "] Critical: " . $message . "\n");

            fclose($critical_fp);
        } catch (\Exception $e) {
            throw new AiraloException('Error logging critical message: ' . $e->getMessage());
        }
    }
}
