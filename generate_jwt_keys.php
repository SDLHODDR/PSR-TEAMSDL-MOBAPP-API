<?php

declare(strict_types=1);

$directory = __DIR__ . '/storage/secure/jwt';

if (!extension_loaded('openssl')) {
    die("ERROR: PHP OpenSSL extension is not enabled.\n");
}

// Create directory if it doesn't exist
if (!is_dir($directory)) {
    mkdir($directory, 0700, true);
}

// Generate RSA 2048-bit key pair
$config = [
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
];

$privateKey = openssl_pkey_new($config);

if ($privateKey === false) {
    die("ERROR: RSA key generation failed.\n");
}

// Export private key
if (!openssl_pkey_export($privateKey, $privateKeyContent)) {
    die("ERROR: Private key export failed.\n");
}

// Extract public key
$details = openssl_pkey_get_details($privateKey);

if ($details === false) {
    die("ERROR: Public key extraction failed.\n");
}

$publicKeyContent = $details['key'];

// Save keys
$privatePath = $directory . '/jwt-private.pem';
$publicPath  = $directory . '/jwt-public.pem';

if (file_put_contents($privatePath, $privateKeyContent) === false) {
    die("ERROR: Cannot save private key.\n");
}

if (file_put_contents($publicPath, $publicKeyContent) === false) {
    die("ERROR: Cannot save public key.\n");
}

// Restrict permissions where supported
if (PHP_OS_FAMILY !== 'Windows') {
    chmod($privatePath, 0600);
    chmod($publicPath, 0644);
}

echo "SUCCESS: RSA key pair generated.\n";
echo "Private key: " . $privatePath . "\n";
echo "Public key : " . $publicPath . "\n";


// echo "PHP Version: " . PHP_VERSION . PHP_EOL;

// echo "OpenSSL Extension: ";
// var_dump(extension_loaded('openssl'));

// echo "OpenSSL Version: ";
// echo OPENSSL_VERSION_TEXT . PHP_EOL;

// echo PHP_EOL . "OpenSSL Config Locations:" . PHP_EOL;
// print_r(openssl_get_cert_locations());

// echo PHP_EOL . "Attempting RSA key generation..." . PHP_EOL;

// $config = [
//     'private_key_bits' => 2048,
//     'private_key_type' => OPENSSL_KEYTYPE_RSA,
// ];

// $key = openssl_pkey_new($config);

// if ($key === false) {
//     echo PHP_EOL . "RSA GENERATION FAILED" . PHP_EOL;

//     while ($error = openssl_error_string()) {
//         echo "OpenSSL Error: " . $error . PHP_EOL;
//     }

//     exit(1);
// }

// echo "SUCCESS: RSA key generation working." . PHP_EOL;
