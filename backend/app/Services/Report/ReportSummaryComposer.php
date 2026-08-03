<?php

namespace App\Services\Report;

use App\Services\Comparison\RankingCalculator;
use App\Services\Comparison\StrengthWeaknessExtractor;
use App\Services\Lead\LeadPerspectiveComposer;

/**
 * レポート「総評」ページの文面を組み立てる。
 *
 * 最高/最低カテゴリの選定は必ず「達成率」(score ÷ configured_max_score)で
 * 行い、生スコアの大小では判定しない ―― configured_max_scoreがカテゴリごとに
 * 異なる(例: technical_seo=20点、accessibility=10点)ため、生スコアで比較すると
 * 配点の大きいカテゴリが不当に有利になる。あわせて既存
 * StrengthWeaknessExtractorの配慮(カバー率50%未満のカテゴリは判定対象から
 * 除外)を同じ定数で再利用し、「実測できていないカテゴリを最低カテゴリと
 * 断定しない」という既存設計を壊さないようにする。
 */
class ReportSummaryComposer
{
    // 既存のDataQualityNotice(frontend: data-quality-notice.tsx の
    // COVERAGE_THRESHOLD)と同じ閾値。画面と同じ条件で「参考スコア」を判定する。
    private const REFERENCE_SCORE_COVERAGE_THRESHOLD = 70.0;

    // カバー率の差がこの値(pt)以上のときは、測定できた範囲そのものが
    // 異なるとみなし、スコアの単純比較を断定しない。
    private const COVERAGE_GAP_THRESHOLD = 20.0;

    // 総合スコアの差がこの値未満のときは「大きな差はない」と表現する。
    private const SCORE_DIFF_THRESHOLD = 1.0;

    /**
     * @param  list<array{key: string, name: string, score: float, configured_max_score: float, coverage_rate: float}>  $categoryScores
     * @return array{status: 'none'|'single'|'tie'|'normal', best: ?array{key: string, name: string, ratio: float}, worst: ?array{key: string, name: string, ratio: float}}
     */
    public function selectCategoryHighlights(array $categoryScores): array
    {
        $eligible = [];

        foreach ($categoryScores as $category) {
            if ((float) $category['configured_max_score'] <= 0) {
                continue;
            }

            if ((float) $category['coverage_rate'] < StrengthWeaknessExtractor::MIN_CATEGORY_COVERAGE) {
                continue;
            }

            $eligible[] = [
                'key' => $category['key'],
                'name' => $category['name'],
                'ratio' => (float) $category['score'] / (float) $category['configured_max_score'],
            ];
        }

        if ($eligible === []) {
            return ['status' => 'none', 'best' => null, 'worst' => null];
        }

        if (count($eligible) === 1) {
            return ['status' => 'single', 'best' => $eligible[0], 'worst' => $eligible[0]];
        }

        $best = array_reduce($eligible, fn ($carry, $c) => $carry === null || $c['ratio'] > $carry['ratio'] ? $c : $carry);
        $worst = array_reduce($eligible, fn ($carry, $c) => $carry === null || $c['ratio'] < $carry['ratio'] ? $c : $carry);

        if (abs($best['ratio'] - $worst['ratio']) < PHP_FLOAT_EPSILON) {
            return ['status' => 'tie', 'best' => $best, 'worst' => $worst];
        }

        return ['status' => 'normal', 'best' => $best, 'worst' => $worst];
    }

    /**
     * @param  array{display_score: int, coverage_rate: float, category_scores: list<array<string, mixed>>}  $selfScore
     */
    public function composeOverallSummary(array $selfScore, int $recommendationCount, string $displayCompanyName): string
    {
        $sentences = [$this->composeScoreSentence($selfScore, $displayCompanyName)];

        $highlight = $this->selectCategoryHighlights($selfScore['category_scores']);
        $sentences[] = $this->composeHighlightSentence($highlight);

        if ($recommendationCount > 0) {
            $sentences[] = '具体的な改善提案は次のページをご覧ください。';
        }

        return implode('', array_filter($sentences, fn ($s) => $s !== null && $s !== ''));
    }

    private function composeScoreSentence(array $selfScore, string $displayCompanyName): string
    {
        $displayScore = (int) $selfScore['display_score'];
        $coverageRate = (float) $selfScore['coverage_rate'];
        // リード向けスコア(LeadScoreCalculator)は4観点に表示している指標
        // だけを対象に算出するため、満点が常に100点とは限らない
        // ―― configured_max_scoreを固定値(100)にしないこと。
        $maxScore = (int) round((float) $selfScore['configured_max_score']);

        if ($coverageRate < self::REFERENCE_SCORE_COVERAGE_THRESHOLD) {
            return "{$displayCompanyName}の自社サイトは、測定できた範囲で総合スコア{$displayScore}点({$maxScore}点満点、参考スコア)という結果になりました。";
        }

        return "{$displayCompanyName}の自社サイトは、総合スコア{$displayScore}点({$maxScore}点満点)という結果になりました。";
    }

    /**
     * @param  array{status: string, best: ?array{key: string, name: string, ratio: float}, worst: ?array{key: string, name: string, ratio: float}}  $highlight
     */
    private function composeHighlightSentence(array $highlight): ?string
    {
        return match ($highlight['status']) {
            'none' => '各カテゴリの測定データが十分でないため、特に優れている点・改善が必要な点の特定は今回できませんでした。',
            'single' => $this->composeSingleCategorySentence($highlight['best']),
            'tie' => $this->composeTieSentence($highlight['best']),
            'normal' => $this->composeNormalHighlightSentence($highlight['best'], $highlight['worst']),
            default => null,
        };
    }

    /**
     * @param  array{key: string, name: string, ratio: float}  $category
     */
    private function composeSingleCategorySentence(array $category): string
    {
        $percent = $this->formatPercent($category['ratio']);

        if ($category['ratio'] >= StrengthWeaknessExtractor::HIGH_CATEGORY_RATIO) {
            return "測定できた「{$category['name']}」については達成率{$percent}%と高く評価されています。ただし、他のカテゴリは十分なデータが得られなかったため、今回は判定を保留しています。";
        }

        if ($category['ratio'] <= StrengthWeaknessExtractor::LOW_CATEGORY_RATIO) {
            return "測定できた「{$category['name']}」については達成率{$percent}%にとどまっており、改善の余地があります。ただし、他のカテゴリは十分なデータが得られなかったため、今回は判定を保留しています。";
        }

        return "測定できた「{$category['name']}」の達成率は{$percent}%でした。他のカテゴリは十分なデータが得られなかったため、今回は判定を保留しています。";
    }

    /**
     * @param  array{key: string, name: string, ratio: float}  $category
     */
    private function composeTieSentence(array $category): string
    {
        $percent = $this->formatPercent($category['ratio']);

        return "測定できたカテゴリはいずれも同水準(達成率{$percent}%前後)であり、特に優れている点・改善が必要な点への偏りは見られませんでした。";
    }

    /**
     * @param  array{key: string, name: string, ratio: float}  $best
     * @param  array{key: string, name: string, ratio: float}  $worst
     */
    private function composeNormalHighlightSentence(array $best, array $worst): string
    {
        $sentence = '';

        if ($best['ratio'] >= StrengthWeaknessExtractor::HIGH_CATEGORY_RATIO) {
            $bestPercent = $this->formatPercent($best['ratio']);
            $sentence .= "中でも「{$best['name']}」は達成率{$bestPercent}%と高く評価されています。";
        }

        if ($worst['ratio'] <= StrengthWeaknessExtractor::LOW_CATEGORY_RATIO) {
            $worstPercent = $this->formatPercent($worst['ratio']);
            $sentence .= "一方で「{$worst['name']}」は達成率{$worstPercent}%にとどまっており、改善の余地があります。";
        }

        return $sentence;
    }

    /**
     * 競合との差分は、測定できた範囲(カバー率)が近いときのみ断定する。
     * 既存の比較画面(RankingCalculator::LOW_COVERAGE_THRESHOLD=50%未満で
     * 「データ不足」バッジ)の安全装置を、レポートの一文だけが迂回しない
     * ようにする。
     *
     * @param  array{overall_score: float, display_score: int, coverage_rate: float}  $selfScore
     * @param  array{overall_score: float, display_score: int, coverage_rate: float}  $competitorScore
     */
    public function composeComparisonSentence(array $selfScore, array $competitorScore): string
    {
        $selfCoverage = (float) $selfScore['coverage_rate'];
        $competitorCoverage = (float) $competitorScore['coverage_rate'];
        $coverageGap = abs($selfCoverage - $competitorCoverage);

        $eitherLowData = $selfCoverage < RankingCalculator::LOW_COVERAGE_THRESHOLD
            || $competitorCoverage < RankingCalculator::LOW_COVERAGE_THRESHOLD;

        if ($eitherLowData || $coverageGap >= self::COVERAGE_GAP_THRESHOLD) {
            return '測定できた範囲が異なるため、スコアの単純比較は参考程度にとどめてください。';
        }

        $scoreDiff = (float) $selfScore['overall_score'] - (float) $competitorScore['overall_score'];

        if (abs($scoreDiff) < self::SCORE_DIFF_THRESHOLD) {
            return '競合サイトとの間に、大きな差はありませんでした。';
        }

        $selfDisplay = (int) $selfScore['display_score'];
        $competitorDisplay = (int) $competitorScore['display_score'];

        if ($scoreDiff > 0) {
            return "自社サイトは総合スコア{$selfDisplay}点で、比較サイト({$competitorDisplay}点)を上回りました。";
        }

        return "自社サイトは総合スコア{$selfDisplay}点で、比較サイト({$competitorDisplay}点)を下回りました。";
    }

    /**
     * 達成率(score÷configured_max_score)は既存画面には存在しない
     * レポート独自の表示値のため、小数第1位で統一する
     * (カバー率のような既存表示桁との整合を取る対象がない)。
     */
    private function formatPercent(float $ratio): string
    {
        return number_format($ratio * 100, 1);
    }

    /**
     * 4観点(②③④、①はcomposeCompleteness()が既に持つsummaryをそのまま使う)の
     * 「理由1文」。個別指標名(items[*].label)は一切出さず、件数のみから
     * 機械的に組み立てる(2026-08-04のユーザー指摘: 社内の指標名がそのまま
     * 社外に出ている状態を解消するため。AIには書かせない、
     * BrandWheelComparisonSummaryComposerと同じ設計方針)。
     *
     * @param  array{status: string, items: list<array{label: string, status: string, detail: ?string}>, summary?: string}  $perspective  LeadPerspectiveComposer::compose()の要素1件分
     */
    public function composePerspectiveOneLiner(array $perspective): string
    {
        if (! empty($perspective['summary'])) {
            return (string) $perspective['summary'];
        }

        $determinableStatuses = [
            LeadPerspectiveComposer::STATUS_GOOD,
            LeadPerspectiveComposer::STATUS_NEEDS_REVIEW,
            LeadPerspectiveComposer::STATUS_NEEDS_IMPROVEMENT,
        ];
        $determinable = array_filter(
            $perspective['items'] ?? [],
            fn (array $item) => in_array($item['status'], $determinableStatuses, true),
        );
        $n = count($determinable);

        if ($n === 0) {
            return 'この観点は計測できませんでした。';
        }

        $status = $perspective['status'];

        if ($status === LeadPerspectiveComposer::STATUS_GOOD) {
            return "確認した{$n}項目に大きな問題は見つかりませんでした。";
        }

        $k = count(array_filter($determinable, fn (array $item) => $item['status'] === $status));

        return match ($status) {
            LeadPerspectiveComposer::STATUS_NEEDS_IMPROVEMENT => "確認した{$n}項目のうち{$k}項目で改善の余地がありました。",
            LeadPerspectiveComposer::STATUS_NEEDS_REVIEW => "確認した{$n}項目のうち{$k}項目で確認をおすすめします。",
            default => 'この観点は計測できませんでした。',
        };
    }
}
