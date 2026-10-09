<?php

namespace App\Services\TopMessageInsight;

use App\Services\Analysis\AnalysisStoragePaths;
use Illuminate\Support\Facades\Storage;

/**
 * 依頼CQ-3: 結果のJSONを、分析の保存先(analysisディスク)の中に置く/読む。
 * マイグレーションは足さない。置き場所はAnalysisStoragePaths::topMessageInsightPath()
 * (analyses/{analysisId}/websites/{websiteAnalysisId}/top_message_insight.json)で、
 * analysisDir()ごとの削除(依頼CIのデータ削除)でいっしょに消える。
 *
 * ファイルの形:
 *  status: 'created' | 'not_created'
 *  reason: not_createdのとき、作らなかった理由の識別子
 *  quote, keywords[{keyword, programs[{name, detail, source_url, source_title}]}]: createdのとき
 *  sources: [{url, title}] 使ったページ(メッセージのページ + 採用された制度の出どころ)
 *  discarded: 確認で捨てた件数の内訳(理由 => 件数)
 *  message_candidate_count, program_page_count, prompt_version, provider, model, generated_at
 */
class TopMessageInsightStore
{
    public function __construct(private readonly AnalysisStoragePaths $paths) {}

    public function exists(int $analysisId, int $websiteAnalysisId): bool
    {
        return Storage::disk('analysis')->exists($this->paths->topMessageInsightPath($analysisId, $websiteAnalysisId));
    }

    /**
     * 読めない/壊れているときはnull(「まだ作られていない」と同じ扱い)。
     *
     * @return ?array<string, mixed>
     */
    public function read(int $analysisId, int $websiteAnalysisId): ?array
    {
        $disk = Storage::disk('analysis');
        $path = $this->paths->topMessageInsightPath($analysisId, $websiteAnalysisId);

        if (! $disk->exists($path)) {
            return null;
        }

        $decoded = json_decode((string) $disk->get($path), true);

        return is_array($decoded) && in_array($decoded['status'] ?? null, ['created', 'not_created'], true) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function write(int $analysisId, int $websiteAnalysisId, array $result): void
    {
        Storage::disk('analysis')->put(
            $this->paths->topMessageInsightPath($analysisId, $websiteAnalysisId),
            (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );
    }

    public function delete(int $analysisId, int $websiteAnalysisId): void
    {
        Storage::disk('analysis')->delete($this->paths->topMessageInsightPath($analysisId, $websiteAnalysisId));
    }
}
