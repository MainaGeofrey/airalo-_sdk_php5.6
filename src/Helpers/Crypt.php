<?php

namespace Airalo\Helpers;

use Airalo\Exceptions\AiraloException;

final class Crypt
{
    private static $cipher = 'AES-256-CBC';
    private static $keyLength = 32; // For AES-256

    /**
     * Encrypt the data using OpenSSL.
     *
     * @param string $data
     * @param string $key
     * @return string
     */
    public static function encrypt($data, $key)
    {
        self::validateKey($key);

        // Generate an initialization vector
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::$cipher));

        // Encrypt the data
        $encrypted = openssl_encrypt($data, self::$cipher, $key, 0, $iv);

        // Return the IV and the encrypted data
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt the data using OpenSSL.
     *
     * @param string $data
     * @param string $key
     * @return string
     */
    public static function decrypt($data, $key)
    {
        self::validateKey($key);

        // Decode the data
        $decodedData = base64_decode($data);

        // Extract the IV and the encrypted data
        $ivLength = openssl_cipher_iv_length(self::$cipher);
        $iv = substr($decodedData, 0, $ivLength);
        $encrypted = substr($decodedData, $ivLength);

        // Decrypt the data
        return openssl_decrypt($encrypted, self::$cipher, $key, 0, $iv);
    }

    /**
     * Validate the key length.
     *
     * @param string $key
     * @throws AiraloException
     * @return void
     */
    private static function validateKey($key)
    {
        if (strlen($key) < self::$keyLength) {
            throw new AiraloException('The key must be at least ' . self::$keyLength . ' characters long.');
        }
    }

    /**
     * Check if data is encrypted (basic check).
     *
     * @param mixed $data
     * @return bool
     */
    public static function isEncrypted($data)
    {
        // A simple check can be implemented here if needed
        return strpos($data, '==') !== false; // Base64 padding check
    }
}
