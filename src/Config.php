<?php

namespace Airalo;

use Airalo\Constants\ApiConstants;
use Airalo\Exceptions\AiraloException;

class Config
{
    const MANDATORY_CONFIG_KEYS = [
        'client_id',
        'client_secret',
    ];

    const ENVIRONMENTS = [
        'sandbox',
        'production',
    ];

    private $data = [];

    /**
     * @param mixed $data
     * @throws AiraloException
     */
    public function __construct($data)
    {
        if (!$data) {
            throw new AiraloException('Config data is not provided');
        }

        $this->data = !is_array($data)
            ? json_decode(json_encode($data), true)
            : $data;

        $jsonError = json_last_error();

        if ($jsonError !== JSON_ERROR_NONE && empty($this->data)) {
            throw new AiraloException("Invalid config data provided, error code: $jsonError");
        }

        $this->validate();
    }

    /**
     * @param string $key
     * @param null $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        return isset($this->data[$key]) ? $this->data[$key] : $default;
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        return $this->data;
    }

    /**
     * @param bool $asString
     * @return array|string
     */
    public function getCredentials($asString = false)
    {
        $credentials = [
            'client_id' => $this->data['client_id'],
            'client_secret' => $this->data['client_secret'],
        ];

        if ($asString) {
            return http_build_query($credentials);
        }

        return $credentials;
    }

    /**
     * @return string
     */
    public function getEnvironment()
    {
        return $this->data['env'];
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->getEnvironment() == 'sandbox'
            ? ApiConstants::SANBOX_URL
            : ApiConstants::PRODUCTION_URL;
    }

    /**
     * @return array
     */
    public function getHttpHeaders()
    {
        return isset($this->data['http_headers']) ? $this->data['http_headers'] : [];
    }

    /**
     * @return void
     * @throws AiraloException
     */
    private function validate()
    {
        $configKeys = array_keys($this->data);

        foreach (self::MANDATORY_CONFIG_KEYS as $key) {
            if (
                !in_array($key, $configKeys)
                || !isset($this->data[$key])
                || !$this->data[$key]
            ) {
                throw new AiraloException("Mandatory field `$key` is missing in the provided config data");
            }
        }

        if (!isset($this->data['env'])) {
            $this->data['env'] = 'production';
        }

        if (!in_array($this->data['env'], self::ENVIRONMENTS)) {
            throw new AiraloException(
                "Invalid environment provided: `{$this->data['env']}`, allowed: " . implode(', ', self::ENVIRONMENTS)
            );
        }
    }
}
