<?php

namespace App\Jobs\Analysis\Concerns;

use App\Enums\AnalysisErrorCode;
use Illuminate\Database\QueryException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;

/**
 * Job::failed()(キュー基盤がJobを終了させた際に呼ばれる経路)で受け取った
 * 例外を、AnalysisErrorCode + 記録用メッセージへ分類する。BaseWebsiteAnalysisJob
 * とGenerateBrandWheelAnalysisJobの両方が同じ分類ロジックを必要とするため
 * ここへ括り出す(2026-08-24、8/16〜17の本番障害の再発防止 ―― この分類が
 * 無かったため、positive_impressionカラム欠落によるQueryExceptionが
 * JOB_TIMEOUTとして記録され、無関係な方向(AIタイムアウト設定)へ調査が
 * ミスリードされた)。
 *
 * QueryExceptionはSQLSTATEで区別する: undefined_column(42703)・
 * undefined_table(42P01)・datatype_mismatch(42804)は、マイグレーション
 * 未適用やカラム名の不一致など「リトライしても直らない」定義不一致
 * (SchemaMismatch)として扱う。それ以外のQueryException(デッドロック・
 * 接続断等、一過性の可能性がある)はDatabaseErrorとして扱う。
 */
trait ClassifiesJobFailureExceptions
{
    /**
     * @return array{0: AnalysisErrorCode, 1: string}
     */
    private function classifyJobFailureException(?\Throwable $exception): array
    {
        return match (true) {
            $exception instanceof TimeoutExceededException => [AnalysisErrorCode::JobTimeout, 'ジョブがタイムアウトしました。'],
            $exception instanceof MaxAttemptsExceededException => [AnalysisErrorCode::MaxAttemptsExceeded, 'リトライ回数の上限に達しました。'],
            // PDOがSQLSTATEを文字列として設定する経路とPHPの例外コンストラクタが
            // 数値文字列をintへ暗黙変換する経路の両方があり得るため、
            // (string)で正規化してから比較する(型の揺れによる誤分類防止)。
            $exception instanceof QueryException => match ((string) $exception->getCode()) {
                '42703', '42P01', '42804' => [AnalysisErrorCode::SchemaMismatch, 'データベース定義が想定と一致しません。'],
                default => [AnalysisErrorCode::DatabaseError, 'データベースエラーが発生しました。'],
            },
            default => [AnalysisErrorCode::UnknownError, 'ジョブがタイムアウトしたか、想定外のエラーで終了しました。'],
        };
    }
}
