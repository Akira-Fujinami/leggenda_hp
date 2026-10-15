<?php

namespace App\Services\BranchSurveyItems;

use App\Services\Analysis\AnalysisStoragePaths;
use Illuminate\Support\Facades\Storage;

/**
 * 依頼CT: 枝ごと・TOPの判定結果のJSONを、分析の保存先(analysisディスク)の中に置く/読む。
 * マイグレーションは足さない。置き場所はAnalysisStoragePaths::branchSurveyItemsPath()で、
 * analysisDir()ごとの削除(依頼CIのデータ削除)でいっしょに消える。
 *
 * ファイルの形:
 *  status: 'done'
 *  top: ?{label, status: 'ok'|'failed', items: [{key, evidence}], discarded: int, reason: ?string}  TOPページ(無ければnull)
 *  branches: [{key, name, status, items, discarded, reason}]
 *  prompt_version, provider, model, generated_at, usage_input_tokens, usage_output_tokens, ai_calls
 */
class BranchSurveyItemsStore
{
    public function __construct(private readonly AnalysisStoragePaths $paths) {}

    public function exists(int $analysisId, int $websiteAnalysisId): bool
    {
        return Storage::disk('analysis')->exists($this->paths->branchSurveyItemsPath($analysisId, $websiteAnalysisId));
    }

    /**
     * 読めない/壊れているときはnull(「まだ無い」と同じ扱い)。
     *
     * @return ?array<string, mixed>
     */
    public function read(int $analysisId, int $websiteAnalysisId): ?array
    {
        $disk = Storage::disk('analysis');
        $path = $this->paths->branchSurveyItemsPath($analysisId, $websiteAnalysisId);

        if (! $disk->exists($path)) {
            return null;
        }

        $decoded = json_decode((string) $disk->get($path), true);

        return is_array($decoded) && ($decoded['status'] ?? null) === 'done' && is_array($decoded['branches'] ?? null) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function write(int $analysisId, int $websiteAnalysisId, array $result): void
    {
        Storage::disk('analysis')->put(
            $this->paths->branchSurveyItemsPath($analysisId, $websiteAnalysisId),
            (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );
    }

    public function delete(int $analysisId, int $websiteAnalysisId): void
    {
        Storage::disk('analysis')->delete($this->paths->branchSurveyItemsPath($analysisId, $websiteAnalysisId));
    }
}
