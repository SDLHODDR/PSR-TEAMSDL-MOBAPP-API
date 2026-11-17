<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class SmsGatewayService
{
    public function sendConsentOtp(string $mobile, string $otp): void
    {
        $endpoint = $this->requiredConfig('SMS_GATEWAY_URL');
        if (strtolower((string) parse_url($endpoint, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('SMS_GATEWAY_URL must use HTTPS.');
        }

        $query = http_build_query([
            'user' => $this->requiredConfig('SMS_GATEWAY_USERNAME'),
            'pwd' => $this->requiredConfig('SMS_GATEWAY_PASSWORD'),
            'sender' => $this->requiredConfig('SMS_GATEWAY_SENDER'),
            'mobile' => trim($mobile),
            'msg' => "Namaste, please share OTP {$otp} to our field staff as consent to register your name on our portal - teamSDL",
            'mt' => '0',
            'tempId' => $this->requiredConfig('SMS_GATEWAY_CONSENT_TEMPLATE_ID'),
        ], '', '&', PHP_QUERY_RFC3986);

        $separator = strpos($endpoint, '?') === false ? '?' : '&';
        $handle = curl_init($endpoint . $separator . $query);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the SMS gateway request.');
        }

        try {
            $configured = curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if (!$configured) {
                throw new RuntimeException('Unable to configure the SMS gateway request.');
            }

            $response = curl_exec($handle);
            if ($response === false) {
                throw new RuntimeException(
                    'The SMS gateway request failed with cURL error ' . curl_errno($handle) . '.'
                );
            }

            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('The SMS gateway returned HTTP ' . $status . '.');
            }

            $response = trim($response);
            if ($response === '' || preg_match('/\b(error|fail(?:ed|ure)?|invalid|unauthorized|rejected)\b/i', $response)) {
                throw new RuntimeException('The SMS gateway rejected the consent OTP request.');
            }
        } finally {
            curl_close($handle);
        }
    }

    private function requiredConfig(string $name): string
    {
        $value = trim((string) getenv($name));
        if ($value === '') {
            throw new RuntimeException($name . ' is not configured.');
        }

        return $value;
    }
}
