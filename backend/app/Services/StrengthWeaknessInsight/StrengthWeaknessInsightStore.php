<?php

namespace App\Services\StrengthWeaknessInsight;

use App\Services\Analysis\AnalysisStoragePaths;
use Illuminate\Support\Facades\Storage;

/**
 * 依頼DB-6: 強み・弱みの下書き(Claudeが書き、サーバー側の確認を通ったものだけ)のJSONを、分析の保存先(analysisディスク)の中に
 * 置く/読む。マイグレーションは足さない。置き場所は AnalysisStoragePaths::strengthWeaknessInsightPath()
 * (analyses/{aid}/strength_weakness_insight.json)で、analysisDir()ごとの削除(依頼CIのデータ削除)でいっしょに消える。
 *
 * ファイルの形:
 *  status: 'done'
 *  skipped: ?string        AIを呼ばなかった/書けなかった理由(ANTHROPIC_NOT_CONFIGURED / MODEL_NOT_CONFIGURED / self_unreadable /
 *                          material_insufficient / AI_TIMEOUT など)。nullならAIを呼んだ
 *  strength / weakness: ?{sentence, quotes}   確認を通ったものだけ。通らなければnull(依頼CT-1の文に戻る)
 *  rejected: {strength: list<string>, weakness: list<string>}   確認で落ちた理由の符号(文の中身は置かない)
 *  job_failed: ?string     ジョブ全体の失敗の記録
 *  prompt_version, provider, model, ai_calls, usage_input_tokens, usage_output_tokens, generated_at
 */
class StrengthWeaknessInsightStore
{
    public function __construct(private readonly AnalysisStoragePaths $paths) {}

    public function exists(int $analysisId): bool
    {
        return Storage::disk('analysis')->exists($this->paths->strengthWeaknessInsightPath($analysisId));
    }

    /**
     * 読めない/壊れているときはnull(「まだ無い」と同じ扱い)。
     *
     * @return ?array<string, mixed>
     */
    public function read(int $analysisId): ?array
    {
        $disk = Storage::disk('analysis');
        $path = $this->paths->strengthWeaknessInsightPath($analysisId);

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
            $this->paths->strengthWeaknessInsightPath($analysisId),
            (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );
    }

    public function delete(int $analysisId): void
    {
        Storage::disk('analysis')->delete($this->paths->strengthWeaknessInsightPath($analysisId));
    }
}
