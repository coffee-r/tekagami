<?php

namespace CoffeeR\Digtrace\Redaction;

use CoffeeR\Digtrace\Config;

/**
 * shape生成・HMACトークン化・マスキングを担う内部クラス。
 * Collector が唯一のインスタンス化箇所。
 */
class Redactor
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * キーが keepKeys に完全一致するかを判定する（大小無視）。
     *
     * @param string $key
     * @return bool
     */
    public function isKept($key)
    {
        $lower = strtolower((string)$key);
        foreach ($this->config->keepKeys as $keep) {
            if (strtolower($keep) === $lower) {
                return true;
            }
        }
        return false;
    }

    /**
     * 値の shape（構造と型）を再帰的に生成する。
     *
     * インデックス配列は重複 shape を除去して圧縮する（[1,2,3] → ["number"]）。
     *
     * @param mixed       $value
     * @param string|null $key
     * @return mixed
     */
    public function shape($value, $key = null)
    {
        $nodesLeft = $this->config->maxShapeNodes;
        return $this->shapeInternal($value, $key, 0, $nodesLeft);
    }

    /**
     * @param mixed  $value
     * @param string|null $key
     * @param int    $depth
     * @param int    &$nodesLeft
     * @return mixed
     */
    private function shapeInternal($value, $key, $depth, &$nodesLeft)
    {
        if ($nodesLeft <= 0) {
            return '...';
        }
        $nodesLeft--;

        if ($depth >= $this->config->maxDepth) {
            return '...';
        }

        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value) || is_float($value)) {
            return 'number';
        }
        if (is_string($value)) {
            return 'string';
        }
        if (is_object($value)) {
            $value = (array)$value;
        }
        if (is_array($value)) {
            if (empty($value)) {
                return [];
            }
            $keys   = array_keys($value);
            $isList = $keys === range(0, count($value) - 1);

            if ($isList) {
                $shapes = [];
                foreach ($value as $item) {
                    $shapes[] = $this->shapeInternal($item, null, $depth + 1, $nodesLeft);
                }
                return $this->uniqueShapes($shapes);
            } else {
                $result = [];
                foreach ($value as $k => $v) {
                    $result[$k] = $this->shapeInternal($v, $k, $depth + 1, $nodesLeft);
                }
                return $result;
            }
        }

        return 'string';
    }

    /**
     * serialize を使った深い比較による重複排除。
     * array_unique はネストした配列を 'Array' に変換して比較するため使えない。
     *
     * @param array $shapes
     * @return array
     */
    private function uniqueShapes(array $shapes)
    {
        $unique = [];
        $seen   = [];
        foreach ($shapes as $shape) {
            $key = serialize($shape);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[]   = $shape;
            }
        }
        return array_values($unique);
    }

    /**
     * 値を HMAC トークンに変換する。同じ値 → 同じトークン。
     *
     * @param mixed $value
     * @return string  例: '{p-a1b2c3d4ef12}'
     */
    public function tokenize($value)
    {
        return '{p-' . substr(
            hash_hmac('sha256', (string)$value, $this->config->secret),
            0,
            $this->config->tokenHmacLength
        ) . '}';
    }

    /**
     * 配列（またはスカラー）の全リーフ値を HMAC トークンに変換する。
     *
     * @param mixed $data
     * @return mixed
     */
    public function buildTokens($data)
    {
        if (!is_array($data) && !is_object($data)) {
            return $this->tokenize($data);
        }

        $data   = (array)$data;
        $keys   = array_keys($data);
        $isList = !empty($data) && $keys === range(0, count($data) - 1);

        if ($isList) {
            return array_map([$this, 'buildTokens'], array_values($data));
        }

        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $result[$key] = $this->buildTokens($value);
            } else {
                $result[$key] = $this->tokenize($value);
            }
        }
        return $result;
    }

    /**
     * 配列から keepKeys（白リスト）に一致するキーの実値だけを抽出する（フラット）。
     *
     * @param array $data
     * @return array
     */
    public function buildValues(array $data)
    {
        $result = [];
        foreach ($data as $key => $value) {
            if ($this->isKept($key)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
