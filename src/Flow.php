<?php

namespace CoffeeR\Digtrace;

/**
 * フロー相関情報の値オブジェクト。
 * 複数の HTTP リクエストを「1つのユーザー操作の流れ」として紐づける。
 * Collector::start() に渡す。
 */
class Flow
{
    /**
     * @var string|null  シナリオ識別子。フロー追跡が設定・検出されていない場合は null。
     *                   例: セッションIDのHMAC、ヘッダ由来のトークン
     */
    public $flowId = null;

    /**
     * @var int|null  フロー内のステップ番号 (1-based)。未設定の場合は null。
     *                例: browse=1, add-to-cart=2, order=3
     */
    public $seq = null;

    /**
     * @param string|null $flowId
     * @param int|null    $seq
     */
    public function __construct($flowId = null, $seq = null)
    {
        $this->flowId = $flowId;
        $this->seq    = $seq;
    }
}
