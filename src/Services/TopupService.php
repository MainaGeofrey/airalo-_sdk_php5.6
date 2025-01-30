<?php

namespace Airalo\Services;

use Airalo\Config;
use Airalo\Constants\ApiConstants;
use Airalo\Exceptions\AiraloException;
use Airalo\Helpers\EasyAccess;
use Airalo\Resources\CurlResource;
use Airalo\Helpers\Signature;

class TopupService
{
    private $config;

    private $curl;

    private $signature;

    private $accessToken;

    /**
     * @param Config $config
     * @param CurlResource $curl
     * @param Signature $signature
     * @param string $accessToken
     */
    public function __construct(
        Config $config,
        CurlResource $curl,
        Signature $signature,
        $accessToken
    ) {
        if (!$accessToken) {
            throw new AiraloException('Invalid access token please check your credentials');
        }

        $this->config = $config;
        $this->curl = $curl;
        $this->signature = $signature;
        $this->accessToken = $accessToken;
    }

    /**
     * @param array $payload
     * @return EasyAccess|null
     */
    public function createTopup(array $payload)
    {
        $this->validateTopup($payload);

        $response = $this->curl
            ->setHeaders(array(
                'Accept: application/json',
                'Authorization: Bearer ' . $this->accessToken,
                'airalo-signature: ' . $this->signature->getSignature($payload),
            ))
            ->post($this->config->getUrl() . ApiConstants::TOPUPS_SLUG, http_build_query($payload));

        if ($this->curl->code != 200) {
            throw new AiraloException(
                'Topup creation failed, status code: ' . $this->curl->code
            );
        }

        return new EasyAccess($response);
    }

    /**
     * @throws AiraloException
     * @param array $payload
     * @return void
     */
    private function validateTopup(array $payload)
    {
        if (!isset($payload['package_id']) || $payload['package_id'] == '') {
            throw new AiraloException('The package_id is required, payload: ');
        }

        if (!isset($payload['iccid']) || $payload['iccid'] == '') {
            throw new AiraloException('The iccid is required, payload: ');
        }
    }
}
