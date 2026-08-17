<?php

use App\Services\BrandWheel\BrandWheelComparisonSummaryComposer;
use App\Services\BrandWheel\BrandWheelImprovementFocusComposer;
use App\Services\BrandWheel\BrandWheelSubElementComparisonComposer;
use App\Services\Report\PdfReportGenerator;
use App\Support\Report\ReportViewModel;

// UI最終整形ラウンド用の実PDF検証スクリプト。DBに依存せず、
// ReportViewModelを直接組み立てて worst-case を再現する。

function wheel(array $overrides = []): array
{
    return array_merge([
        'status' => 'success',
        'status_message' => null,
        'analyzed_url' => 'https://example.com/careers',
        'axes' => [],
        'key_message' => null,
        'impression' => null,
        'impression_items' => [],
        'positive_impression' => null,
        'negative_impression' => null,
        'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
    ], $overrides);
}

$axisDefs = [
    ['key' => 'will_activity', 'group' => 'company_appeal', 'name' => '活動的魅力', 'subs' => ['purpose' => 'パーパス', 'business_expansion' => '展開事業・商品', 'project_initiative' => 'PJ・新たな取組', 'social_contribution' => '社会貢献活動']],
    ['key' => 'asset', 'group' => 'company_appeal', 'name' => '資産的魅力', 'subs' => ['brand_recognition' => '知名度・評判', 'competitiveness' => '競争力・独自性', 'scale_influence' => '規模・影響力', 'office_facility' => 'オフィス・施設']],
    ['key' => 'personality', 'group' => 'company_distance', 'name' => '経営スタイル', 'subs' => ['leadership' => 'リーダーシップ', 'org_structure' => '組織構造', 'company_character' => '会社の性格', 'core_values' => '重視する価値']],
    ['key' => 'relationship', 'group' => 'company_distance', 'name' => '就業環境', 'subs' => ['colleagues' => '同僚・先輩像', 'atmosphere' => '職場の雰囲気', 'physical_freedom' => '物理的自由度', 'mental_freedom' => '精神的自由度']],
    ['key' => 'emotional_benefit', 'group' => 'job_appeal', 'name' => '情緒的便益', 'subs' => ['pride' => '誇りに思える', 'talkable' => '話したくなる', 'satisfaction' => '満足感', 'superiority' => '優越感']],
    ['key' => 'financial_benefit', 'group' => 'job_appeal', 'name' => '金銭的便益', 'subs' => ['salary_level' => '給与水準', 'benefits' => '福利厚生', 'growth_opportunity' => '成長機会', 'employment_stability' => '雇用の安定性']],
];

function buildAxes(array $axisDefs, array $countByKey): array
{
    $out = [];
    foreach ($axisDefs as $def) {
        $count = $countByKey[$def['key']] ?? 0;
        $subKeys = array_slice(array_keys($def['subs']), 0, $count);
        $matched = [];
        foreach ($subKeys as $k) {
            $matched[] = ['key' => $k, 'name' => $def['subs'][$k]];
        }
        $out[] = [
            'key' => $def['key'],
            'group' => $def['group'],
            'name' => $def['name'],
            'matched_count' => $count,
            'max_count' => 4,
            'matched_sub_elements' => $matched,
            'label_only_sub_elements' => [],
        ];
    }

    return $out;
}

$mode = getenv('UI_TEST_MODE') ?: 'extreme';

if ($mode === 'extreme') {
    // BrandWheelComparisonSummaryComposer::pointsForReport()が実際に返しうる
    // 最大4行(最充足軸+根拠1行・0件軸まとめ1行・sparse_group最大2行)を
    // 実際に発生させる組み合わせ ―― 1軸だけ4/4で他5軸すべて0/4だと、
    // 最充足軸の根拠(4項目)+0件軸まとめ(5軸分)+2グループがsparseになり、
    // 手書きの文言よりも実際のロジックが生成しうる最大値に近い。
    // 「理論上の最大」(1軸4/4+他全軸0/4)は6〜8行に折り返す極端すぎる組合せの
    // ため、実際に起こりうる範囲での最大(サマリー4件、うち評価軸の根拠が
    // 3項目・0件軸が3軸・sparse_groupが2件)で検証する。
    $selfAxes = buildAxes($axisDefs, ['will_activity' => 3, 'relationship' => 1, 'financial_benefit' => 1]);
    $competitorAxes = buildAxes($axisDefs, ['relationship' => 3, 'will_activity' => 1, 'financial_benefit' => 1]);
} elseif ($mode === 'card4') {
    // カード単体のworst-case: 1軸が4/4(4項目該当、下位要素名4行)。
    $selfAxes = buildAxes($axisDefs, ['will_activity' => 4]);
    $competitorAxes = buildAxes($axisDefs, []);
    $keyMessage = '技術で社会基盤を支える、という主題が置かれています。';
    $positive = '事業内容への取り組み姿勢が伝わり、良い印象を与える可能性があります。';
    $negative = '働く環境の具体像がイメージしづらい可能性があります。';
    $summaryComposer = app(BrandWheelComparisonSummaryComposer::class);
    $selfPoints = $summaryComposer->pointsForReport($selfAxes);
    $competitorPoints = $summaryComposer->pointsForReport($competitorAxes);

    $keyMessage = str_repeat('あ', 89).'ん'; // 90字(cap値ぴったり)
    $positive = str_repeat('い', 64).'ん'; // 65字
    $negative = str_repeat('う', 64).'ん'; // 65字

    $summaryComposer = app(BrandWheelComparisonSummaryComposer::class);
    $selfPoints = $summaryComposer->pointsForReport($selfAxes);
    $competitorPoints = $summaryComposer->pointsForReport($competitorAxes);
} else {
    // moderate: 一般的な実データに近い分量。
    $selfAxes = buildAxes($axisDefs, [
        'will_activity' => 2, 'asset' => 1, 'personality' => 2,
        'relationship' => 0, 'emotional_benefit' => 1, 'financial_benefit' => 2,
    ]);
    $competitorAxes = buildAxes($axisDefs, [
        'will_activity' => 1, 'asset' => 2, 'personality' => 1,
        'relationship' => 1, 'emotional_benefit' => 0, 'financial_benefit' => 1,
    ]);

    $keyMessage = '技術で社会基盤を支える、という主題が置かれています。';
    $positive = '事業内容への取り組み姿勢が伝わり、良い印象を与える可能性があります。';
    $negative = '働く環境の具体像がイメージしづらい可能性があります。';

    $selfPoints = ['活動的魅力が最も内容として充足しています。', '就業環境に関する記述は確認できませんでした。'];
    $competitorPoints = ['資産的魅力が最も内容として充足しています。'];
}

$comparisonComposer = app(BrandWheelSubElementComparisonComposer::class);
$subElementComparison = $comparisonComposer->compose($selfAxes, $competitorAxes);
$groupTotals = $comparisonComposer->groupTotals($subElementComparison);

$selfTotalMatched = array_sum(array_column($selfAxes, 'matched_count'));
$competitorTotalMatched = array_sum(array_column($competitorAxes, 'matched_count'));

$improvementFocus = app(BrandWheelImprovementFocusComposer::class)->compose($subElementComparison, [
    'relationship' => [
        'colleagues' => '入社3年目の先輩が、日々どんな判断をしているかを紹介しています。',
    ],
]);

$viewModel = new ReportViewModel(
    companyDisplayName: '株式会社サンプル様',
    generatedAtLabel: '2026年8月17日',
    selfWebsiteUrl: 'https://example.com',
    competitorWebsiteUrl: 'https://competitor.example.com',
    isPartial: false,
    brandWheelSelf: wheel([
        'axes' => $selfAxes,
        'key_message' => $keyMessage,
        'positive_impression' => $positive,
        'negative_impression' => $negative,
    ]),
    brandWheelCompetitor: wheel([
        'analyzed_url' => 'https://competitor.example.com/careers',
        'axes' => $competitorAxes,
        'key_message' => $keyMessage,
        'positive_impression' => $positive,
        'negative_impression' => $negative,
    ]),
    brandWheelComparison: [
        'self_points' => $selfPoints,
        'competitor_points' => $competitorPoints,
        'one_point' => ['key' => 'well_covered', 'text' => '6つの項目それぞれについて、内容が読み取れています。'],
    ],
    brandWheelRadarPngSelf: file_get_contents(resource_path('images/leggenda-logo.png')),
    brandWheelRadarPngCompetitor: file_get_contents(resource_path('images/leggenda-logo.png')),
    brandWheelRadarPngComparison: file_get_contents(resource_path('images/leggenda-logo.png')),
    selfTotalMatched: $selfTotalMatched,
    selfTotalMax: 24,
    competitorTotalMatched: $competitorTotalMatched,
    competitorTotalMax: 24,
    selfTotalLabelOnly: 0,
    competitorTotalLabelOnly: 0,
    subElementComparison: $subElementComparison,
    groupTotals: $groupTotals,
    comparisonOverview: app(BrandWheelComparisonSummaryComposer::class)
        ->comparisonOverview($selfTotalMatched, 24, $competitorTotalMatched, 24, $groupTotals),
    improvementFocus: $improvementFocus,
    improvementFocusSelfOnly: null,
    improvementOnePoint: '6つの項目それぞれについて、内容が読み取れています。',
    improvementRecommendation: 'まずは会社との距離に関する情報を拡充することを推奨します。',
    improvementReason: '就業環境は競合が読み取れているのに対し自社は情報が薄く、候補者が働くイメージを持ちにくい状態です。',
    improvementRecommendedContents: ['入社数年目の社員の1日の過ごし方', '部署間の関わり方が分かるエピソード'],
    improvementMidTermAction: null,
);

$pdf = app(PdfReportGenerator::class)->generate($viewModel);
file_put_contents("/tmp/ui-{$mode}.pdf", $pdf);
echo "generated /tmp/ui-{$mode}.pdf: ".strlen($pdf)." bytes\n";
