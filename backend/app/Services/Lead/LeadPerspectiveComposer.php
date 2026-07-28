<?php

namespace App\Services\Lead;

use App\Enums\MetricResultStatus;
use App\Models\MetricResult;
use App\Services\Comparison\StrengthWeaknessExtractor;
use App\Services\Scoring\MetricScorer;
use App\Support\Lead\LeadMetricCatalog;
use Illuminate\Support\Collection;

/**
 * 採用担当向けの4観点(①書くべきこと・②メッセージ・③導線・④見やすさ)へ
 * 指標を再構成する。内部の採点ロジック(MetricScorer/カテゴリ配点)は
 * 一切変更せず、既に記録済みのMetricResultをLeadMetricCatalogの
 * グルーピングに沿って読み替えるだけ。JSON API(LeadAnalysisController)と
 * レポート(Word/PDF、ReportViewModelBuilder経由)の両方から共有して使う。
 *
 * 誠実性の維持(Phase 2から継続):
 * - 未取得(Unavailable/Error)を「改善の余地があります」に丸めない ――
 *   「計測できませんでした」として明確に区別する。
 * - 採用ページが見つからない場合(①)は「計測対象外」、見つかったのに
 *   内容を確認できなかった場合は「評価不可」と、理由を書き分ける
 *   (Phase 2のCategoryAvailabilityClassifierと同じ考え方)。
 */
class LeadPerspectiveComposer
{
    public const STATUS_GOOD = 'good';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_NEEDS_IMPROVEMENT = 'needs_improvement';

    public const STATUS_NOT_MEASURED = 'not_measured';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    // ①観点専用の追加ステータス(採用ページの検出有無に基づく)。
    public const STATUS_NOT_DETECTED = 'not_detected';

    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @var array<string, string>
     */
    private const RECRUIT_CONTENT_KEYS = [
        'recruit_title_present', 'recruit_meta_description_present', 'recruit_h1_single',
        'recruit_heading_structure_present', 'recruit_word_count_sufficient',
    ];

    public function __construct(
        private readonly LeadMetricCatalog $catalog,
        private readonly MetricScorer $scorer,
    ) {}

    /**
     * @param  Collection<int, MetricResult>  $metricResults  metricDefinition読み込み済み
     * @return list<array<string, mixed>>
     */
    public function compose(Collection $metricResults): array
    {
        $resultsByKey = $metricResults
            ->filter(fn (MetricResult $r) => $r->metricDefinition !== null)
            ->keyBy(fn (MetricResult $r) => $r->metricDefinition->key);

        $perspectives = [];

        foreach (array_keys(LeadMetricCatalog::PERSPECTIVE_LABELS) as $perspectiveKey) {
            $perspectives[] = $perspectiveKey === LeadMetricCatalog::PERSPECTIVE_COMPLETENESS
                ? $this->composeCompleteness($resultsByKey)
                : $this->composeStandard($perspectiveKey, $resultsByKey);
        }

        return $perspectives;
    }

    /**
     * @param  Collection<string, MetricResult>  $resultsByKey
     */
    private function composeCompleteness(Collection $resultsByKey): array
    {
        $base = [
            'key' => LeadMetricCatalog::PERSPECTIVE_COMPLETENESS,
            'label' => LeadMetricCatalog::PERSPECTIVE_LABELS[LeadMetricCatalog::PERSPECTIVE_COMPLETENESS],
            'heading' => LeadMetricCatalog::PERSPECTIVE_HEADINGS[LeadMetricCatalog::PERSPECTIVE_COMPLETENESS],
            'note' => LeadMetricCatalog::COMPLETENESS_LEGAL_ITEMS_NOTE,
        ];

        $recruitLink = $resultsByKey->get('recruit_link_present');
        $recruitFound = $recruitLink !== null
            && $recruitLink->status === MetricResultStatus::Success
            && ($recruitLink->normalized_value['value'] ?? false) === true;

        if (! $recruitFound) {
            return $base + [
                'status' => self::STATUS_NOT_DETECTED,
                'summary' => '採用ページを検出できませんでした。トップページに採用に関する案内が見つからなかったため、この観点は今回「計測対象外」です。',
                'items' => [],
            ];
        }

        $recruitContentMeasured = collect(self::RECRUIT_CONTENT_KEYS)
            ->contains(fn (string $key) => $resultsByKey->get($key)?->status === MetricResultStatus::Success);

        if (! $recruitContentMeasured) {
            return $base + [
                'status' => self::STATUS_UNAVAILABLE,
                'summary' => '採用ページへのリンクは見つかりましたが、内容を確認できませんでした。',
                'items' => [],
            ];
        }

        $items = [];
        $items[] = $this->composeItem('recruit_link_present', $resultsByKey->get('recruit_link_present'));

        foreach (self::RECRUIT_CONTENT_KEYS as $key) {
            $items[] = $this->composeItem($key, $resultsByKey->get($key));
        }

        return $base + [
            'status' => $this->overallStatus($items),
            'summary' => '採用ページを確認できました。',
            'items' => $items,
        ];
    }

    /**
     * @param  Collection<string, MetricResult>  $resultsByKey
     */
    private function composeStandard(string $perspectiveKey, Collection $resultsByKey): array
    {
        $keys = $this->catalog->keysForPerspective($perspectiveKey);
        // ①専用キー(採用ページ本体の指標)はcomposeCompleteness()側で扱うため、
        // 他の観点(③④)には既に含まれていない ―― keysForPerspective()は
        // LeadMetricCatalogのマッピングをそのまま使うので、ここでの除外は不要。
        $items = collect($keys)
            ->map(fn (string $key) => $this->composeItem($key, $resultsByKey->get($key)))
            ->values()
            ->all();

        return [
            'key' => $perspectiveKey,
            'label' => LeadMetricCatalog::PERSPECTIVE_LABELS[$perspectiveKey],
            'heading' => LeadMetricCatalog::PERSPECTIVE_HEADINGS[$perspectiveKey],
            'note' => $perspectiveKey === LeadMetricCatalog::PERSPECTIVE_USABILITY ? LeadMetricCatalog::USABILITY_SECTION_NOTE : null,
            'status' => $this->overallStatus($items),
            'items' => $items,
        ];
    }

    /**
     * @return array{label: string, status: string, detail: ?string}
     */
    private function composeItem(string $key, ?MetricResult $result): array
    {
        $label = $this->catalog->label($key);

        if ($result === null) {
            return ['label' => $label, 'status' => self::STATUS_NOT_MEASURED, 'detail' => null];
        }

        return match ($result->status) {
            MetricResultStatus::Unavailable, MetricResultStatus::Error => ['label' => $label, 'status' => self::STATUS_NOT_MEASURED, 'detail' => null],
            MetricResultStatus::NotApplicable => ['label' => $label, 'status' => self::STATUS_NOT_APPLICABLE, 'detail' => null],
            MetricResultStatus::NotFound => ['label' => $label, 'status' => self::STATUS_NEEDS_IMPROVEMENT, 'detail' => null],
            MetricResultStatus::Success => $this->composeSuccessfulItem($label, $result),
        };
    }

    /**
     * @return array{label: string, status: string, detail: ?string}
     */
    private function composeSuccessfulItem(string $label, MetricResult $result): array
    {
        $definition = $result->metricDefinition;
        $outcome = $this->scorer->score($definition, $result);

        if ($outcome->countsTowardScore && $outcome->maxScore > 0) {
            $ratio = $outcome->score / $outcome->maxScore;
            $status = match (true) {
                $ratio >= StrengthWeaknessExtractor::HIGH_CATEGORY_RATIO => self::STATUS_GOOD,
                $ratio <= StrengthWeaknessExtractor::LOW_CATEGORY_RATIO => self::STATUS_NEEDS_IMPROVEMENT,
                default => self::STATUS_NEEDS_REVIEW,
            };

            return ['label' => $label, 'status' => $status, 'detail' => null];
        }

        // 採点対象外(not_scored)の項目は達成率を持たないため、
        // normalized_value(真偽値)そのものを定性判定に用いる
        // ―― 採点(0点扱い)には一切影響しない、表示レイヤー限定の判断。
        $value = $result->normalized_value['value'] ?? null;

        if (is_bool($value)) {
            return ['label' => $label, 'status' => $value ? self::STATUS_GOOD : self::STATUS_NEEDS_IMPROVEMENT, 'detail' => null];
        }

        return ['label' => $label, 'status' => self::STATUS_NOT_MEASURED, 'detail' => null];
    }

    /**
     * 画面・レポート双方で共有する、定性ステータスの日本語表示。
     * ここを唯一の変換元にすることで、JSON APIとWord/PDFの文言が
     * 食い違わないようにする。
     */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_GOOD => '良好',
            self::STATUS_NEEDS_REVIEW => '確認をおすすめします',
            self::STATUS_NEEDS_IMPROVEMENT => '改善の余地があります',
            self::STATUS_NOT_APPLICABLE => '該当なし',
            self::STATUS_NOT_DETECTED => '計測対象外',
            self::STATUS_UNAVAILABLE => '評価不可',
            self::STATUS_NOT_MEASURED => '計測できませんでした',
            default => $status,
        };
    }

    /**
     * @param  list<array{label: string, status: string, detail: ?string}>  $items
     */
    private function overallStatus(array $items): string
    {
        if ($items === []) {
            return self::STATUS_NOT_MEASURED;
        }

        $statuses = array_column($items, 'status');

        if (in_array(self::STATUS_NEEDS_IMPROVEMENT, $statuses, true)) {
            return self::STATUS_NEEDS_IMPROVEMENT;
        }

        if (in_array(self::STATUS_NEEDS_REVIEW, $statuses, true)) {
            return self::STATUS_NEEDS_REVIEW;
        }

        if (in_array(self::STATUS_GOOD, $statuses, true)) {
            return self::STATUS_GOOD;
        }

        return self::STATUS_NOT_MEASURED;
    }
}
