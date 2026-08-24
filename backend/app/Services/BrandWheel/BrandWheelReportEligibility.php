<?php

namespace App\Services\BrandWheel;

use App\Models\BrandWheelAnalysisResult;

/**
 * 自社サイトのブランド・ホイール分析結果が、リード向けレポート生成・
 * 診断回数消費の対象になる(=「白紙」ではない実質的な結果を持つ)かどうかの
 * 単一の判定。2026-08-24追加。
 *
 * 「status='success'」だけでは不十分 ―― 24項目すべてmatched=0でも
 * successとして記録される(BrandWheelLeadResponseComposer::resolveStatus()の
 * 'no_matched_content'と同じ状態)ため、実際にマッチした下位要素が
 * 1件以上あることまで確認する。error/insufficient_inputは、レポートの
 * 該当セクションが状態メッセージのみになり実質的な中身を持たない点で
 * 同列に扱う(依頼者指定 ―― 「白紙は出さない」)。
 *
 * この判定は2箇所から同じ意味で使われる。ロジックを1箇所に集約すること
 * 自体が目的(呼び出し箇所ごとに条件がずれることを防ぐ):
 * - GenerateBrandWheelAnalysisJob::finalizeBrandWheelResult()
 *   (リード診断回数=LeadSession.analyses_usedを消費してよいか)
 * - LeadAnalysisController::maybeDispatchReportGeneration()
 *   (GenerateLeadReportJobを起動してよいか)
 */
class BrandWheelReportEligibility
{
    public function isReportable(?BrandWheelAnalysisResult $result): bool
    {
        if ($result === null || $result->status !== 'success') {
            return false;
        }

        return $this->totalMatched($result) > 0;
    }

    private function totalMatched(BrandWheelAnalysisResult $result): int
    {
        return collect((array) ($result->axes ?? []))
            ->sum(fn (array $axis) => count($axis['matched_sub_elements'] ?? []));
    }
}
