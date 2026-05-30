<?php

namespace CoffeeR\Digtrace\Encryption;

/**
 * ハイブリッド暗号化（RSA+AES-256-GCM）。
 *
 * 1トレースにつき1つの AES キーを generateAesKey() で生成し、
 * encryptAesKey() で RSA 公開鍵ラップしてトレース上位に保存する。
 * 各フィールドの encrypt() は同じ AES キーを使い、RSA は1回で済む。
 */
class Encryptor
{
    /** @var resource|\OpenSSLAsymmetricKey */
    private $publicKey;

    /**
     * @param string $publicKeyPem  RSA 公開鍵の PEM 文字列
     * @throws \InvalidArgumentException
     */
    public function __construct($publicKeyPem)
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            throw new \InvalidArgumentException('Invalid RSA public key PEM');
        }
        $this->publicKey = $key;
    }

    /**
     * AES-256 キー（32 bytes）をランダム生成する。
     *
     * @return string  32バイトのバイナリ文字列
     */
    public static function generateAesKey()
    {
        return random_bytes(32);
    }

    /**
     * AES キーを RSA 公開鍵で暗号化し、base64 文字列を返す。
     * トレース上位の encryption_envelope.k に格納する。
     *
     * @param string $aesKey  32バイトの AES キー
     * @return string  base64 エンコードされた暗号化済み AES キー
     * @throws \RuntimeException
     */
    public function encryptAesKey($aesKey)
    {
        $success = openssl_public_encrypt($aesKey, $encrypted, $this->publicKey, OPENSSL_PKCS1_OAEP_PADDING);
        if (!$success) {
            throw new \RuntimeException('RSA public key encryption failed: ' . openssl_error_string());
        }
        return base64_encode($encrypted);
    }

    /**
     * データを AES-256-GCM で暗号化し、JSON bundle 文字列を返す。
     *
     * bundle 形式: {"iv":"base64","c":"base64","t":"base64"}
     *
     * @param mixed  $data    暗号化するデータ（json_encode 可能な任意の型）
     * @param string $aesKey  32バイトの AES キー（同一トレース内で共有）
     * @return string  JSON bundle 文字列
     * @throws \RuntimeException
     */
    public function encrypt($data, $aesKey)
    {
        $iv        = random_bytes(12);
        $tag       = '';
        $plaintext = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $aesKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('AES-256-GCM encryption failed: ' . openssl_error_string());
        }

        return json_encode([
            'iv' => base64_encode($iv),
            'c'  => base64_encode($ciphertext),
            't'  => base64_encode($tag),
        ]);
    }
}
