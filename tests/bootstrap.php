<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Paire de clés RSA dédiée aux tests
$keyDir = dirname(__DIR__).'/var/test-keys';
if (!is_file($keyDir.'/private.pem')) {
    @mkdir($keyDir, 0777, true);
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privatePem, $_SERVER['OAUTH_PASSPHRASE']);
    file_put_contents($keyDir.'/private.pem', $privatePem);
    file_put_contents($keyDir.'/public.pem', openssl_pkey_get_details($key)['key']);
    chmod($keyDir.'/private.pem', 0600);
}
