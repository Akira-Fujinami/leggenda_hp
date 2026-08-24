<?php

namespace App\Services\BrandWheel;

use App\Models\BrandWheelAnalysisResult;

/**
 * 自社サイトのブランド・ホイール分析結果が、リード向けレポート生成・
 * 診断回数消費の対象になる(=顧客に提出できる品質の結果を持つ)かどうかの
 * 単一の判定。2026-08-24追加、2026-08-25に閾値を引き上げ。
 *
 * 「status='success'」だけでは不十分 ―― 24項目すべてmatched=0でも
 * successとして記録される(BrandWheelLeadResponseComposer::resolveStatus()の
 * 'no_matched_content'と同じ状態)ため、実際にマッチした下位要素の件数が
 * config('brand_wheel.report_eligibility_min_matched')(既定6)以上あることまで
 * 確認する。error/insufficient_inputは、レポートの該当セクションが状態
 * メッセージのみになり実質的な中身を持たない点で同列に扱う(依頼者指定 ――
 * 「白紙は出さない」)。
 *
 * 2026-08-24発行のレポート33(自社1/24)は、旧基準(matched>0)では通過して
 * いたが顧客提出可能な品質ではなかった(依頼者指摘)。1件以上ではなく
 * 「営業が客先に持っていける品質」を基準にするため、閾値を6へ引き上げた。
 * この閾値はconfig('brand_wheel.comparison_sufficiency_threshold')
 * (BrandWheelComparisonSufficiency、レポート内で比較に基づく主張をして
 * よいかの判定)と値は同じだが関心事が別のため、統合しない
 * (BrandWheelComparisonSufficiencyのdocblock参照)。
 *
 * この判定は2箇所から同じ意味で使われる。ロジックを1箇所に集約すること
 * 自体が目的(呼び出し箇所ごとに条件がずれることを防ぐ) ―― 閾値を
 * このクラス以外にハードコードしないこと:
 * - GenerateBrandWheelAnalysisJob::maybeConsumeLeadQuota()
 *   (リード診断回数=LeadSession.analyses_usedを消費してよいか)
 * - LeadAnalysisController::maybeDispatchReportGeneration()
 *   (GenerateLeadReportJobを起動してよいか)
 *
 * 競合サイト側にはこの判定を適用しない(自社の結果のみを見る) ――
 * 競合の表示可否はBlade/WordReportGenerator側の$competitorReadable
 * (status==='success' && !empty($axes)のみ、件数は見ない)が別途判定する。
 */
class BrandWheelReportEligibility
{
    public function isReportable(?BrandWheelAnalysisResult $result): bool
    {
        if ($result === null || $result->status !== 'success') {
            return false;
        }

        return $this->totalMatched($result) >= (int) config('brand_wheel.report_eligibility_min_matched', 6);
    }

    private function totalMatched(BrandWheelAnalysisResult $result): int
    {
        return collect((array) ($result->axes ?? []))
            ->sum(fn (array $axis) => count($axis['matched_sub_elements'] ?? []));
    }
}
