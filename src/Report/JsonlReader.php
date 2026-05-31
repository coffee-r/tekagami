<?php

namespace CoffeeR\Tekagami\Report;

/**
 * JSONL ファイル（複数可）を読み込み、トレース配列を返す。
 */
class JsonlReader
{
    /** @var string[] */
    private $warnings = [];

    /**
     * 複数の JSONL ファイルを統合してトレース配列を返す。
     * malformed な行はスキップし warnings() に記録する。
     *
     * @param string[] $paths
     * @return array
     * @throws \RuntimeException  ファイルが開けない場合
     */
    public function read(array $paths)
    {
        $this->warnings = [];
        $traces = [];

        foreach ($paths as $path) {
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('Cannot open file: ' . $path);
            }

            $lineNum = 0;
            while (($line = fgets($handle)) !== false) {
                $lineNum++;
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (!is_array($decoded)) {
                    $this->warnings[] = sprintf('%s:%d: invalid JSON, skipped', $path, $lineNum);
                    continue;
                }
                $traces[] = $decoded;
            }

            fclose($handle);
        }

        return $traces;
    }

    /**
     * @return string[]
     */
    public function warnings()
    {
        return $this->warnings;
    }
}
