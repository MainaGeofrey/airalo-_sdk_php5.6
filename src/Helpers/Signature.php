<?php

namespace Airalo\Helpers;

class Signature
{
    private $secret;

    /**
     * @param string $secret
     */
    public function __construct($secret)
    {
        $this->secret = $secret;
    }

    /**
     * @param mixed $payload
     * @return string|null
     */
    public function getSignature($payload)
    {
        if (!$payload = $this->preparePayload($payload)) {
            return null;
        }

        return $this->signData($payload);
    }

    /**
     * @param string|null $hash
     * @param mixed $payload
     * @return boolean
     */
    public function checkSignature($hash = null, $payload = null)
    {
        if (!$hash || !$payload = $this->preparePayload($payload)) {
            return false;
        }

        return $this->secureCompare($this->signData($payload), $hash);
    }

    /**
     * @param mixed $payload
     * @return string|null
     */
    private function preparePayload($payload)
    {
        if (!$payload) {
            return null;
        }

        if (is_string($payload)) {
            // remove all whitespaces
            $payload = json_encode(json_decode($payload, true));
        }

        if (!is_string($payload)) {
            $payload = json_encode($payload);
        }

        return $payload;
    }

    /**
     * @param string $payload
     * @param string $algo
     * @return string
     */
    private function signData($payload, $algo = 'sha512')
    {
        return hash_hmac($algo, $payload, $this->secret);
    }

    /**
     * Securely compares two strings to prevent timing attacks
     * @param string $str1
     * @param string $str2
     * @return bool
     */
    private function secureCompare($str1, $str2)
    {
        if (strlen($str1) !== strlen($str2)) {
            return false;
        }

        $res = 0;
        for ($i = 0; $i < strlen($str1); $i++) {
            $res |= ord($str1[$i]) ^ ord($str2[$i]);
        }

        return $res === 0;
    }
}
