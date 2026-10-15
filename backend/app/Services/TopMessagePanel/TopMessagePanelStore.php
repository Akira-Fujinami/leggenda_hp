<?php

namespace App\Services\TopMessagePanel;

use App\Services\Analysis\AnalysisStoragePaths;
use Illuminate\Support\Facades\Storage;

/**
 * 依頼CZ-5: トップメッセージと制度のパネル(AIが選び、サーバー側の確認を通ったものだけ)のJSONを、分析の保存先
 * (analysisディスク)の中に置く/読む。マイグレーションは足さない。置き場所はAnalysisStoragePaths::topMessagePanelPath()
 * (analyses/{aid}/top_message_panel.json)で、analysisDir()ごとの削除(依頼CIのデータ削除)でいっしょに消える。
 *
 * ファイルの形:
 *  status: 'done'
 *  companies: { "<website_analysis_id>": {
 *     status: 'panel' | 'fallback'      panel=確認を通ったパネルがある / fallback=パネルは使わず依頼CSの素材ページのまま
 *     reason: ?string                  fallbackの理由(no_message_page / no_sentences / no_programs / keywords_below_min / core_* / AI_* など)
 *     panel: ?{core, body, keywords}   TopMessagePanelVerifier::verify()の形
 *     speaker: ?string, message_title: ?string
 *     rejected: {符号: 件数}           確認で落ちた理由(文の中身は置かない)
 *     dropped: {message: {…}, programs: {…}}   材料の整理で除いた行の数(理由別)
 *     sentence_count, program_count, ai_calls, usage_input_tokens, usage_output_tokens
 *  } }
 *  job_failed: ?string   ジョブ全体の失敗の記録
 *  prompt_version, provider, model, generated_at
 */
class TopMessagePanelStore
{
    public function __construct(private readonly AnalysisStoragePaths $paths) {}

    public function exists(int $analysisId): bool
    {
        return Storage::disk('analysis')->exists($this->paths->topMessagePanelPath($analysisId));
    }

    /**
     * 読めない/壊れているときはnull(「まだ無い」と同じ扱い)。
     *
     * @return ?array<string, mixed>
     */
    public function read(int $analysisId): ?array
    {
        $disk = Storage::disk('analysis');
        $path = $this->paths->topMessagePanelPath($analysisId);

        if (! $disk->exists($path)) {
            return null;
        }

        $decoded = json_decode((string) $disk->get($path), true);

        return is_array($decoded) && ($decoded['status'] ?? null) === 'done' ? $decoded : null;
    }

    /**
     * 1社のパネル(status=panel)だけを返す。無い・fallbackのときはnull。
     *
     * @return ?array<string, mixed>
     */
    public function panelFor(int $analysisId, int $websiteAnalysisId): ?array
    {
        $company = $this->read($analysisId)['companies'][(string) $websiteAnalysisId] ?? null;

        return is_array($company) && ($company['status'] ?? null) === 'panel' && is_array($company['panel'] ?? null) ? $company : null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function write(int $analysisId, array $result): void
    {
        Storage::disk('analysis')->put(
            $this->paths->topMessagePanelPath($analysisId),
            (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );
    }

    public function delete(int $analysisId): void
    {
        Storage::disk('analysis')->delete($this->paths->topMessagePanelPath($analysisId));
    }
}
