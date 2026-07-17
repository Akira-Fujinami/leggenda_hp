<?php

namespace App\Exceptions\Analysis;

/**
 * 同一プロジェクトで実行中(pending/queued/running)のAnalysisが既にある状態で
 * 新規analysisの開始が要求された場合に投げる。
 */
class AnalysisAlreadyRunningException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('このプロジェクトでは既に分析が実行中です。完了までお待ちください。');
    }
}
