<?php

namespace CoffeeR\Digtrace\Tests\Encryption;

use CoffeeR\Digtrace\Encryption\Decryptor;
use CoffeeR\Digtrace\Encryption\Encryptor;
use PHPUnit\Framework\TestCase;

class EncryptorTest extends TestCase
{
    /** @var string */
    private static $privatePem;

    /** @var string */
    private static $publicPem;

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, self::$privatePem);
        self::$publicPem = openssl_pkey_get_details($key)['key'];
    }

    private function makeEncryptor()
    {
        return new Encryptor(self::$publicPem);
    }

    private function makeDecryptor()
    {
        return new Decryptor(self::$privatePem);
    }

    public function testGenerateAesKeyIs32Bytes()
    {
        $key = Encryptor::generateAesKey();
        $this->assertSame(32, strlen($key));
    }

    public function testGenerateAesKeyIsRandom()
    {
        $a = Encryptor::generateAesKey();
        $b = Encryptor::generateAesKey();
        $this->assertNotSame($a, $b);
    }

    public function testEncryptReturnsJsonBundle()
    {
        $enc    = $this->makeEncryptor();
        $aesKey = Encryptor::generateAesKey();
        $bundle = $enc->encrypt(['hello' => 'world'], $aesKey);

        $decoded = json_decode($bundle, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('iv', $decoded);
        $this->assertArrayHasKey('c', $decoded);
        $this->assertArrayHasKey('t', $decoded);
    }

    public function testRoundTripArray()
    {
        $enc    = $this->makeEncryptor();
        $dec    = $this->makeDecryptor();
        $aesKey = Encryptor::generateAesKey();

        $original = ['user_id' => 42, 'product' => 'coffee', 'nested' => ['a', 'b']];
        $bundle   = $enc->encrypt($original, $aesKey);
        $restored = $dec->decrypt($bundle, $aesKey);

        $this->assertSame($original, $restored);
    }

    public function testRoundTripNull()
    {
        $enc    = $this->makeEncryptor();
        $dec    = $this->makeDecryptor();
        $aesKey = Encryptor::generateAesKey();

        $bundle   = $enc->encrypt(null, $aesKey);
        $restored = $dec->decrypt($bundle, $aesKey);

        $this->assertNull($restored);
    }

    public function testRoundTripString()
    {
        $enc    = $this->makeEncryptor();
        $dec    = $this->makeDecryptor();
        $aesKey = Encryptor::generateAesKey();

        $bundle   = $enc->encrypt('hello world', $aesKey);
        $restored = $dec->decrypt($bundle, $aesKey);

        $this->assertSame('hello world', $restored);
    }

    public function testEncryptAesKeyRoundTrip()
    {
        $enc    = $this->makeEncryptor();
        $dec    = $this->makeDecryptor();
        $aesKey = Encryptor::generateAesKey();

        $encryptedB64    = $enc->encryptAesKey($aesKey);
        $restoredAesKey  = $dec->decryptAesKey($encryptedB64);

        $this->assertSame($aesKey, $restoredAesKey);
    }

    public function testDecryptWithWrongKeyFails()
    {
        $enc     = $this->makeEncryptor();
        $aesKey  = Encryptor::generateAesKey();
        $wrongKey = str_repeat('x', 32);

        $bundle = $enc->encrypt(['data' => 'secret'], $aesKey);

        $dec = $this->makeDecryptor();
        $this->expectException(\RuntimeException::class);
        $dec->decrypt($bundle, $wrongKey);
    }

    public function testDecryptInvalidBundleThrows()
    {
        $dec = $this->makeDecryptor();
        $this->expectException(\InvalidArgumentException::class);
        $dec->decrypt('not a json bundle', Encryptor::generateAesKey());
    }

    public function testDecryptTraceExpandsHttpFields()
    {
        $enc    = $this->makeEncryptor();
        $dec    = $this->makeDecryptor();
        $aesKey = Encryptor::generateAesKey();

        $queryData   = ['page' => '1', 'sort' => 'price'];
        $requestData = ['product_id' => 99, 'qty' => 2];

        $trace = [
            'encryption_envelope' => [
                'alg' => 'A256GCM+RSA-OAEP',
                'k'   => $enc->encryptAesKey($aesKey),
            ],
            'http' => [
                'method'           => 'POST',
                'path'             => '/orders',
                'query_encrypted'  => $enc->encrypt($queryData, $aesKey),
                'request_encrypted' => $enc->encrypt($requestData, $aesKey),
            ],
            'timeline' => [],
        ];

        $result = $dec->decryptTrace($trace);

        $this->assertSame($queryData, $result['http']['query_raw']);
        $this->assertSame($requestData, $result['http']['request_raw']);
        // encrypted フィールドは残る
        $this->assertArrayHasKey('query_encrypted', $result['http']);
    }

    public function testDecryptTraceExpandsBindEncrypted()
    {
        $enc    = $this->makeEncryptor();
        $dec    = $this->makeDecryptor();
        $aesKey = Encryptor::generateAesKey();

        $binds = [12345, 'pending'];

        $trace = [
            'encryption_envelope' => [
                'alg' => 'A256GCM+RSA-OAEP',
                'k'   => $enc->encryptAesKey($aesKey),
            ],
            'http'     => ['method' => 'GET', 'path' => '/orders'],
            'timeline' => [
                [
                    'seq'             => 1,
                    'type'            => 'sql',
                    'operation'       => 'SELECT',
                    'tables'          => ['ORDERS'],
                    'bind_encrypted'  => $enc->encrypt($binds, $aesKey),
                ],
            ],
        ];

        $result = $dec->decryptTrace($trace);

        $this->assertSame($binds, $result['timeline'][0]['bind_raw']);
        $this->assertArrayHasKey('bind_encrypted', $result['timeline'][0]);
    }

    public function testDecryptTraceWithoutEnvelopeReturnsUnchanged()
    {
        $dec   = $this->makeDecryptor();
        $trace = ['http' => ['method' => 'GET'], 'timeline' => []];

        $result = $dec->decryptTrace($trace);
        $this->assertSame($trace, $result);
    }

    public function testInvalidPublicKeyThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        new Encryptor('not a pem');
    }

    public function testInvalidPrivateKeyThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        new Decryptor('not a pem');
    }
}
