<?php

namespace App\Services\ComparisonCommentary;

use App\Services\Analysis\AnalysisStoragePaths;
use Illuminate\Support\Facades\Storage;

/**
 * 依頼CU: 強み・弱みの一文(AIが書き、サーバー側の確認を通ったものだけ)のJSONを、分析の保存先(analysisディスク)の中に
 * 置く/読む。マイグレーションは足さない。置き場所はAnalysisStoragePaths::comparisonCommentaryPath()で、
 * analysisDir()ごとの削除(依頼CIのデータ削除)でいっしょに消える。
 *
 * ファイルの形:
 *  status: 'done'
 *  skipped: ?string        材料を作らなかった/AIを呼ばなかった理由(self_unreadable / no_competitors / material_insufficient /
 *                          competitor_unreadable / PROVIDER_NOT_OPENAI など)。nullならAIを呼んだ
 *  strength / weakness: ?{sentence, items, quotes, companies}   確認を通ったものだけ。通らなければnull(依頼CT-1の一文に戻る)
 *  rejected: {strength: list<string>, weakness: list<string>}   確認で落ちた理由の符号(文の中身は置かない)
 *  job_failed: ?string     ジョブ全体の失敗の記録
 *  prompt_version, provider, model, ai_calls, usage_input_tokens, usage_output_tokens, generated_at
 */
class ComparisonCommentaryStore
{
    public function __construct(private readonly AnalysisStoragePaths $paths) {}

    public function exists(int $analysisId): bool
    {
        return Storage::disk('analysis')->exists($this->paths->comparisonCommentaryPath($analysisId));
    }

    /**
     * 読めない/壊れているときはnull(「まだ無い」と同じ扱い)。
     *
     * @return ?array<string, mixed>
     */
    public function read(int $analysisId): ?array
    {
        $disk = Storage::disk('analysis');
        $path = $this->paths->comparisonCommentaryPath($analysisId);

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
            $this->paths->comparisonCommentaryPath($analysisId),
            (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );
    }

    public function delete(int $analysisId): void
    {
        Storage::disk('analysis')->delete($this->paths->comparisonCommentaryPath($analysisId));
    }
}
