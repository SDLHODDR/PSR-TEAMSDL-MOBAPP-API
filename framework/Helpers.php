<?php
declare(strict_types=1);

namespace SDL\Framework;

final class Helpers
{
    /**
     * Get client IP address.
     */
    public static function getUserIpAddress(): string
    {
        // Default remote IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        /*
         * If the application is behind a trusted proxy,
         * handle forwarded IP headers here.
         *
         * Do not blindly trust HTTP_X_FORWARDED_FOR
         * from arbitrary clients.
         */

        return filter_var($ip, FILTER_VALIDATE_IP)
            ? $ip
            : '0.0.0.0';
    }

    /**
     * Get current date and time.
     */
    public static function getCurrentDateTime(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Get current date.
     */
    public static function getCurrentDate(): string
    {
        return date('Y-m-d');
    }

    /**
     * Check whether a value is empty.
     */
    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

     /**
     * Print Array.
     */

    public static function decodeMe(string $str, string $pwdStr)
    {
        for($i=0;$i<5;$i++)
        {		
            $decodePwd = base64_decode(strrev($pwdStr));
        }

        print '---------------';
        print $str;
        print '<br/>---------------';
        print '<pre/>';
        print_r($decodePwd);
        exit;
    }

    /**
     * Print Array.
     */
    
    public static function printArr(string $str, array $arr)
    {
        print '---------------';
        print $str;
        print '<br/>---------------';
        print '<pre/>';
        print_r($arr);
        exit;
    }

    /**
     * Generate a random reference ID.
     */
    public static function generateReferenceId(int $length = 16): string
    {
        $length = max(8, min($length, 64));

        return bin2hex(random_bytes((int) ceil($length / 2)));
    }

    public static function column(array $rows, string $column): array
    {
        return array_values(array_filter(
            array_column($rows, $column),
            static fn($value) => $value !== null
        ));
    }

    public static function singDymention(array $arrParam): array
    {
        $newArray = [];

        foreach ($arrParam as $key => $value) {

            if (is_array($value)) {

                foreach ($value as $keys => $vals) {
                    if (is_numeric($keys)) {
                        $newArray[] = $vals;
                    }
                }

            } else {

                if (is_numeric($key)) {
                    $newArray[] = $value;
                }
            }
        }

        return $newArray;
    }

    /**
     * Safely get a value from an array.
     */
    public static function arrayGet(
        array $data,
        string $key,
        mixed $default = null
    ): mixed {
        return $data[$key] ?? $default;
    }

    /**
     * Get a sanitized string.
     */
    public static function sanitizeString(?string $value): string
    {
        return trim($value ?? '');
    }
}