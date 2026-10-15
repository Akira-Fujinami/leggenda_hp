<?php

namespace App\Services\SiteFirstImpression;

use App\Services\Analysis\AnalysisStoragePaths;
use Illuminate\Support\Facades\Storage;

/**
 * 依頼CX: 第一印象(AIが書き、サーバー側の確認を通った点だけ)のJSONを、分析の保存先(analysisディスク)の中に置く/読む。
 * マイグレーションは足さない。置き場所はAnalysisStoragePaths::siteFirstImpressionPath()で、analysisDir()ごとの削除
 * (依頼CIのデータ削除)でいっしょに消える(画像も同じ分析の保存先の中)。
 *
 * ファイルの形:
 *  status: 'done'
 *  skipped: ?string      ページを作らなかった理由(no_page / not_enough_points / PROVIDER_NOT_OPENAI / AI_* など)
 *  page: ?{company_name, good: [{title, body, evidence: [{id, text}]}], bad: [...], sources: [{label, url}]}
 *  kept / discarded(理由の符号 => 件数) / structure(ページごとの要素数) / images / image_count
 *  job_failed: ?string   ジョブ全体の失敗の記録
 *  prompt_version, provider, model, ai_calls, usage_input_tokens, usage_output_tokens, generated_at
 */
class SiteFirstImpressionStore
{
    public function __construct(private readonly AnalysisStoragePaths $paths) {}

    public function exists(int $analysisId): bool
    {
        return Storage::disk('analysis')->exists($this->paths->siteFirstImpressionPath($analysisId));
    }

    /**
     * 読めない/壊れているときはnull(「まだ無い」と同じ扱い)。
     *
     * @return ?array<string, mixed>
     */
    public function read(int $analysisId): ?array
    {
        $disk = Storage::disk('analysis');
        $path = $this->paths->siteFirstImpressionPath($analysisId);

        if (! $disk->exists($path)) {
            return null;
        }

        $decoded = json_decode((string) $disk->get($path), true);

        return is_array($decoded) && ($decoded['status'] ?? null) === 'done' ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function write(int $analysisId, array $result): void
    {
        Storage::disk('analysis')->put(
            $this->paths->siteFirstImpressionPath($analysisId),
            (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );
    }

    public function delete(int $analysisId): void
    {
        Storage::disk('analysis')->delete($this->paths->siteFirstImpressionPath($analysisId));
    }
}
