<?php

namespace CoffeeR\Digtrace\Encryption;

/**
 * ハイブリッド暗号の復号器（Encryptor の逆）。
 *
 * decryptTrace() で1つのトレース配列を受け取り、
 * *_encrypted フィールドを復号して *_raw フィールドとして展開する。
 */
class Decryptor
{
    /** @var resource|\OpenSSLAsymmetricKey */
    private $privateKey;

    /**
     * @param string $privateKeyPem  RSA 秘密鍵の PEM 文字列
     * @throws \InvalidArgumentException
     */
    public function __construct($privateKeyPem)
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new \InvalidArgumentException('Invalid RSA private key PEM');
        }
        $this->privateKey = $key;
    }

    /**
     * base64 エンコードされた RSA 暗号化済み AES キーを復号する。
     *
     * @param string $encryptedKeyB64  encryptAesKey() の戻り値
     * @return string  32バイトの AES キー
     * @throws \RuntimeException
     */
    public function decryptAesKey($encryptedKeyB64)
    {
        $encrypted = base64_decode($encryptedKeyB64, true);
        if ($encrypted === false) {
            throw new \RuntimeException('Invalid base64 in encrypted AES key');
        }
        $success = openssl_private_decrypt($encrypted, $decrypted, $this->privateKey, OPENSSL_PKCS1_OAEP_PADDING);
        if (!$success) {
            throw new \RuntimeException('RSA private key decryption failed: ' . openssl_error_string());
        }
        return $decrypted;
    }

    /**
     * Encryptor::encrypt() が返した JSON bundle を AES-256-GCM で復号する。
     *
     * @param string $bundleJson  {"iv":"...","c":"...","t":"..."} の JSON 文字列
     * @param string $aesKey      32バイトの AES キー
     * @return mixed  json_decode した元の値
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function decrypt($bundleJson, $aesKey)
    {
        $bundle = json_decode($bundleJson, true);
        if (!is_array($bundle) || !isset($bundle['iv'], $bundle['c'], $bundle['t'])) {
            throw new \InvalidArgumentException('Invalid encrypted bundle format');
        }

        $iv         = base64_decode($bundle['iv'], true);
        $ciphertext = base64_decode($bundle['c'], true);
        $tag        = base64_decode($bundle['t'], true);

        if ($iv === false || $ciphertext === false || $tag === false) {
            throw new \RuntimeException('Invalid base64 in bundle fields');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $aesKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('AES-256-GCM decryption failed (tag mismatch or corrupt data)');
        }

        return json_decode($plaintext, true);
    }

    /**
     * トレース配列の *_encrypted フィールドを復号し *_raw フィールドを展開して返す。
     * encryption_envelope がない場合はそのまま返す。
     *
     * @param array $trace  digtrace-v1 レコード
     * @return array
     */
    public function decryptTrace(array $trace)
    {
        if (!isset($trace['encryption_envelope']['k'])) {
            return $trace;
        }

        $aesKey = $this->decryptAesKey($trace['encryption_envelope']['k']);

        // HTTP フィールド
        if (isset($trace['http']) && is_array($trace['http'])) {
            $trace['http'] = $this->decryptHttpFields($trace['http'], $aesKey);
        }

        // timeline の SQL バインド値
        if (isset($trace['timeline']) && is_array($trace['timeline'])) {
            foreach ($trace['timeline'] as $i => $event) {
                if (isset($event['bind_encrypted'])) {
                    try {
                        $trace['timeline'][$i]['bind_raw'] = $this->decrypt($event['bind_encrypted'], $aesKey);
                    } catch (\Exception $e) {
                        $trace['timeline'][$i]['bind_decrypt_error'] = $e->getMessage();
                    }
                }
            }
        }

        return $trace;
    }

    /**
     * @param array  $http
     * @param string $aesKey
     * @return array
     */
    private function decryptHttpFields(array $http, $aesKey)
    {
        $fieldMap = [
            'query_encrypted'   => 'query_raw',
            'request_encrypted' => 'request_raw',
        ];

        foreach ($fieldMap as $encField => $rawField) {
            if (isset($http[$encField])) {
                try {
                    $http[$rawField] = $this->decrypt($http[$encField], $aesKey);
                } catch (\Exception $e) {
                    $http[$rawField . '_decrypt_error'] = $e->getMessage();
                }
            }
        }

        return $http;
    }
}
