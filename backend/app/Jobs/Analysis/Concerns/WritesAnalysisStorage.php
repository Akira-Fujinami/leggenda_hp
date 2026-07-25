<?php

namespace App\Jobs\Analysis\Concerns;

use App\Enums\AnalysisErrorCode;
use App\Exceptions\Analysis\AnalysisException;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

/**
 * `analysis` disk(共有Volume)への書き込みを、権限不足・ディスクフル等の
 * 失敗が発生してもUNKNOWN_ERRORで握りつぶさず、専用のAnalysisErrorCodeとして
 * 分類するための共通処理。
 *
 * config/filesystems.phpの`analysis` diskは`throw=true`のため、書き込み失敗は
 * League\Flysystem\FilesystemException(の具象クラス)として飛んでくる。
 * これをAnalysisExceptionへ変換することで、BaseWebsiteAnalysisJob::handle()の
 * 既存のリトライ/エラーコード記録ロジックにそのまま乗せられるようにする。
 */
trait WritesAnalysisStorage
{
    private function putToAnalysisStorage(string $path, string $contents): void
    {
        try {
            Storage::disk('analysis')->put($path, $contents);
        } catch (FilesystemException $e) {
            throw new AnalysisException(
                AnalysisErrorCode::StorageWriteFailed,
                '分析結果の保存に失敗しました。',
                $e,
            );
        }
    }
}
