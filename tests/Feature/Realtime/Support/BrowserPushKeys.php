<?php

namespace Tests\Feature\Realtime\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Un «navegador» de mentira para los tests de Web Push: genera sus claves (p256dh y auth) como lo
 * hace PushManager.subscribe() y descifra los avisos que le llegan (RFC 8291, aes128gcm). Así el
 * test comprueba el contenido REAL que recibiría el navegador, no solo que se envió algo.
 */
final class BrowserPushKeys
{
    private const string P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    private function __construct(
        private readonly OpenSSLAsymmetricKey $private,
        public readonly string $rawPublic,
        public readonly string $rawAuth,
    ) {}

    public static function generate(): self
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new RuntimeException('OpenSSL no puede generar claves P-256');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('Clave P-256 sin coordenadas');
        }

        $public = "\x04".str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        return new self($key, $public, random_bytes(16));
    }

    public function publicKey(): string
    {
        return self::base64url($this->rawPublic);
    }

    public function authToken(): string
    {
        return self::base64url($this->rawAuth);
    }

    /**
     * Descifra el cuerpo de un aviso aes128gcm (un solo registro) y quita el relleno.
     */
    public function decrypt(string $body): string
    {
        $salt = substr($body, 0, 16);
        $idLength = ord($body[20]);
        $serverPublic = substr($body, 21, $idLength);
        $cipherText = substr($body, 21 + $idLength);

        $serverKey = openssl_pkey_get_public(self::pem($serverPublic));
        if ($serverKey === false) {
            throw new RuntimeException('Clave pública del servidor de push no válida');
        }

        $shared = openssl_pkey_derive($serverKey, $this->private, 32);
        if ($shared === false) {
            throw new RuntimeException('ECDH fallido');
        }

        // RFC 8291 §3.3-3.4: HKDF con el secreto «auth» y después con el salt del mensaje.
        $prkKey = hash_hmac('sha256', $shared, $this->rawAuth, true);
        $ikm = hash_hmac('sha256', "WebPush: info\0".$this->rawPublic.$serverPublic."\x01", $prkKey, true);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

        $plain = openssl_decrypt(substr($cipherText, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipherText, -16));
        if ($plain === false) {
            throw new RuntimeException('No se ha podido descifrar el aviso');
        }

        // Último registro: datos + 0x02 + relleno de ceros.
        $plain = rtrim($plain, "\0");

        return substr($plain, 0, -1);
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function pem(string $rawPublic): string
    {
        $der = (string) hex2bin(self::P256_SPKI_PREFIX).$rawPublic;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }
}
