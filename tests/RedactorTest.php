<?php

namespace CoffeeR\Digtrace\Tests;

use CoffeeR\Digtrace\Config;
use CoffeeR\Digtrace\Redaction\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    private function makeRedactor(array $options = [])
    {
        $config = new Config('app', 'test', null, $options);
        return new Redactor($config);
    }

    private function makeRedactorWithSecret(array $options = [])
    {
        $config = new Config('app', 'test', 'test-secret', $options);
        return new Redactor($config);
    }

    // -------------------------------------------------------------------------
    // shape - スカラー
    // -------------------------------------------------------------------------

    public function testShapeNull()
    {
        $r = $this->makeRedactor();
        $this->assertNull($r->shape(null));
    }

    public function testShapeBool()
    {
        $r = $this->makeRedactor();
        $this->assertSame('boolean', $r->shape(true));
        $this->assertSame('boolean', $r->shape(false));
    }

    public function testShapeInt()
    {
        $r = $this->makeRedactor();
        $this->assertSame('number', $r->shape(42));
    }

    public function testShapeFloat()
    {
        $r = $this->makeRedactor();
        $this->assertSame('number', $r->shape(3.14));
    }

    public function testShapeString()
    {
        $r = $this->makeRedactor();
        $this->assertSame('string', $r->shape('hello'));
    }

    // -------------------------------------------------------------------------
    // shape - 配列
    // -------------------------------------------------------------------------

    public function testShapeAssocArray()
    {
        $r      = $this->makeRedactor();
        $result = $r->shape(['name' => 'Alice', 'age' => 30]);
        $this->assertSame(['name' => 'string', 'age' => 'number'], $result);
    }

    public function testShapeIndexedArray()
    {
        $r = $this->makeRedactor();
        $this->assertSame(['number'], $r->shape([1, 2, 3]));
    }

    public function testShapeIndexedArrayDeduplication()
    {
        $r = $this->makeRedactor();
        $this->assertSame(['number', 'string'], $r->shape([1, 'a', 2, 'b']));
    }

    public function testShapeEmptyArray()
    {
        $r = $this->makeRedactor();
        $this->assertSame([], $r->shape([]));
    }

    public function testShapeNestedArray()
    {
        $r      = $this->makeRedactor();
        $result = $r->shape(['user' => ['id' => 1, 'name' => 'Alice']]);
        $this->assertSame(['user' => ['id' => 'number', 'name' => 'string']], $result);
    }

    // -------------------------------------------------------------------------
    // shape - 機微キーも型のみ（白リスト一本: 値は出ない）
    // -------------------------------------------------------------------------

    public function testShapeSensitiveKeyStillTypeOnly()
    {
        $r      = $this->makeRedactor();
        $result = $r->shape(['user' => 'Alice', 'password' => 'secret']);
        // 値は一切出ず、機微キーも型 'string' になるだけ（漏洩なし）
        $this->assertSame(['user' => 'string', 'password' => 'string'], $result);
    }

    // -------------------------------------------------------------------------
    // shape - 深さ制限・ノード制限
    // -------------------------------------------------------------------------

    public function testShapeDepthLimit()
    {
        $r = $this->makeRedactor(['maxDepth' => 2]);
        // depth 0 → 1 → 2 → truncated
        $nested = ['a' => ['b' => ['c' => 'deep']]];
        $result = $r->shape($nested);
        $this->assertSame(['a' => ['b' => '...']], $result);
    }

    public function testShapeNodeLimit()
    {
        $r = $this->makeRedactor(['maxShapeNodes' => 3]);
        // 3ノードで打ち切り
        $data = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4];
        $result = $r->shape($data);
        // 3ノード消費後は '...' になるため d は '...' か存在しないはず
        $this->assertArrayHasKey('a', $result);
        // 4番目のキーが '...' であることを確認
        $this->assertSame('...', $result['d']);
    }

    // -------------------------------------------------------------------------
    // tokenize
    // -------------------------------------------------------------------------

    public function testTokenizeSameValueSameToken()
    {
        $r      = $this->makeRedactorWithSecret();
        $token1 = $r->tokenize('123');
        $token2 = $r->tokenize('123');
        $this->assertSame($token1, $token2);
    }

    public function testTokenizeDifferentValueDifferentToken()
    {
        $r = $this->makeRedactorWithSecret();
        $this->assertNotSame($r->tokenize('abc'), $r->tokenize('xyz'));
    }

    public function testTokenizeFormat()
    {
        $r     = $this->makeRedactorWithSecret();
        $token = $r->tokenize('hello');
        $this->assertMatchesRegularExpression('/^\{p-[0-9a-f]{12}\}$/', $token);
    }

    // -------------------------------------------------------------------------
    // buildValues
    // -------------------------------------------------------------------------

    public function testBuildValuesKeepsKeepKeys()
    {
        $config = new Config('app', 'test', null, ['keepKeys' => ['amount', 'status']]);
        $r      = new Redactor($config);
        $result = $r->buildValues(['amount' => 1000, 'status' => 'active', 'password' => 'secret']);
        $this->assertSame(['amount' => 1000, 'status' => 'active'], $result);
    }

    public function testBuildValuesKeepsWhitelistedKeyEvenIfSensitiveName()
    {
        // denyKeys 廃止後: 白リストに明示したキーは名前に関わらず保持される（順序ルールなし）
        $config = new Config('app', 'test', null, ['keepKeys' => ['secret']]);
        $r      = new Redactor($config);
        $result = $r->buildValues(['secret' => 'val']);
        $this->assertSame(['secret' => 'val'], $result);
    }
}
