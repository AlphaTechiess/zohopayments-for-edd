<?php
defined('ABSPATH') || exit;

final class Zoho_EDD_Crypto {
    public static function encrypt(string $plain): string {
        if ($plain === '') return '';
        $key = hash('sha256', wp_salt('auth'), true);
        $iv  = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $enc = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $enc === false ? '' : 'zedd1:' . base64_encode($iv . $enc);
    }

    public static function decrypt(string $cipher): string {
        if ($cipher === '') return '';
        if (strpos($cipher, 'zedd1:') !== 0) return trim($cipher);
        $raw = base64_decode(substr($cipher, 6), true);
        if ($raw === false || strlen($raw) < 17) return '';
        $out = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return $out === false ? '' : trim((string)$out);
    }
}
