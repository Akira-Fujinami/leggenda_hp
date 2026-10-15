<?php

namespace Tests\Feature\ComparisonCommentary;

use App\Enums\AnalysisKind;
use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\ComparisonCommentary\EvidenceSnippetSelector;
use App\Services\ComparisonCommentary\StrengthWeaknessDetailComposer;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\Report\ComparisonStrengthWeaknessComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 依頼CUA: 強み・弱みを「項目」と「根拠の一節」で書く(AIを使わない)。材料は手で組んだ配列(24項目のうち必要な項目だけ)。
 * 根拠の文・企業名はすべて人工のもの。
 */
class StrengthWeaknessDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 依頼CV: 一節の文は既定で出さない(STRENGTH_WEAKNESS_DETAIL_ENABLEDの既定をfalseに戻した)。ここでは仕組みそのものを試す。
        config(['admin_comparison_pptx.strength_weakness_detail.enabled' => true]);
    }

    private function composer(): StrengthWeaknessDetailComposer
    {
        return app(StrengthWeaknessDetailComposer::class);
    }

    /**
     * @param  list<array{0: string, 1: bool, 2: string}>  $competitors  [label, ○か, 根拠の文]
     * @return array<string, mixed>
     */
    private function item(int $order, string $key, string $name, bool $self, string $selfEvidence, array $competitors): array
    {
        return [
            'order' => $order, 'key' => $key, 'axis' => '活動的魅力', 'name' => $name,
            'self_matched' => $self, 'self_evidence' => $self ? $selfEvidence : '',
            'competitor_matched_count' => count(array_filter($competitors, fn (array $c) => $c[1])),
            'competitors' => array_map(fn (array $c) => ['label' => $c[0], 'matched' => $c[1], 'evidence' => $c[1] ? $c[2] : ''], $competitors),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $ties
     * @return array<string, mixed>
     */
    private function material(array $items, array $ties = [], int $n = 3, bool $names = true): array
    {
        $labels = $names ? ['A社', 'B社', 'C社', 'D社'] : ['A', 'B', 'C', 'D'];

        return [
            'status' => 'ok', 'reason' => null, 'self_name' => '自社', 'competitor_count' => $n,
            'competitors' => array_map(fn (string $l) => ['label' => $l, 'name' => $names ? $l : $l.'社'], array_slice($labels, 0, $n)),
            'items' => $items, 'tie_axes' => $ties,
        ];
    }

    // ------------------------------------------------------------------ CUA-1 一節

    public function test_the_snippet_is_cut_only_at_punctuation_or_line_breaks_and_never_in_the_middle_of_a_clause(): void
    {
        $evidence = "私たちは、一人ひとりが挑戦できる環境を整えています。\nまず新しいことに取り組み、その経験を次の仕事に生かします。";

        $candidates = app(EvidenceSnippetSelector::class)->candidates($evidence);

        $this->assertSame(['一人ひとりが挑戦できる環境を整えています', 'まず新しいことに取り組み', 'その経験を次の仕事に生かします'], $candidates, '「私たちは」(4字)は8字未満なので使わない');
        foreach ($candidates as $candidate) {
            $this->assertStringContainsString($candidate, $evidence, '一節はそのまま含まれる');
        }
    }

    public function test_only_snippets_of_8_to_40_characters_are_candidates_and_the_bounds_come_from_the_config(): void
    {
        $selector = app(EvidenceSnippetSelector::class);
        $seven = str_repeat('あ', 7);
        $eight = str_repeat('あ', 8);
        $forty = str_repeat('い', 40);
        $fortyOne = str_repeat('う', 41);

        $this->assertSame([$eight, $forty], $selector->candidates("{$seven}。{$eight}。{$forty}。{$fortyOne}。"));

        config(['admin_comparison_pptx.strength_weakness_detail.snippet_min_chars' => 5, 'admin_comparison_pptx.strength_weakness_detail.snippet_max_chars' => 8]);
        $this->assertSame([$seven, $eight], $selector->candidates("{$seven}。{$eight}。{$forty}。"));
    }

    public function test_snippets_with_digits_katakana_words_or_latin_letters_come_first(): void
    {
        $selector = app(EvidenceSnippetSelector::class);

        $this->assertSame(['3年で管理職を目指せます', 'フレキシブルな勤務制度です', 'AIを活用しています', '普通の文章がここにあります'], $selector->candidates('普通の文章がここにあります。3年で管理職を目指せます。フレキシブルな勤務制度です。AIを活用しています。'));
        // 優先する節が無ければ、元の順(最初の候補が第一)。
        $this->assertSame(['最初の普通の文章です', '次の普通の文章です'], $selector->candidates('最初の普通の文章です。次の普通の文章です。'));
    }

    public function test_when_no_clause_is_usable_the_evidence_gives_no_candidate(): void
    {
        $this->assertSame([], app(EvidenceSnippetSelector::class)->candidates('短い。これも短い、です。'));
        $this->assertSame([], app(EvidenceSnippetSelector::class)->candidates(''));
    }

    // ------------------------------------------------------------------ CUA-2 強み

    public function test_the_strength_uses_the_item_only_this_company_has_with_the_snippet_as_is(): void
    {
        $material = $this->material([
            $this->item(1, 'purpose', 'パーパス', true, '私たちは社会の課題を解決します。', [['A社', false, ''], ['B社', true, '別の会社の文章がここにあります。'], ['C社', false, '']]),
        ]);

        $out = $this->composer()->compose($material);

        $this->assertSame('パーパスは、競合3社のうち1社からしか読み取れなかった内容で、自社は「私たちは社会の課題を解決します」と記載しています。', $out['strength']);
        $this->assertSame('purpose', $out['info']['strength_item']);
        $this->assertSame('only', $out['info']['strength_kind']);
    }

    public function test_when_no_competitor_has_the_item_the_wording_says_none_of_them_not_zero_of_them(): void
    {
        $out = $this->composer()->compose($this->material([
            $this->item(1, 'purpose', 'パーパス', true, '私たちは社会の課題を解決します。', [['A社', false, ''], ['B社', false, ''], ['C社', false, '']]),
        ]));

        $this->assertSame('パーパスは、競合3社のいずれからも読み取れなかった内容で、自社は「私たちは社会の課題を解決します」と記載しています。', $out['strength']);
    }

    public function test_among_several_the_item_with_the_fewest_competitors_comes_first_and_ties_follow_the_item_order(): void
    {
        $material = $this->material([
            $this->item(1, 'a', '項目甲', true, '甲の根拠の文章がここにあります。', [['A社', true, 'x'], ['B社', false, ''], ['C社', false, '']]),
            $this->item(2, 'b', '項目乙', true, '乙の根拠の文章がここにあります。', [['A社', false, ''], ['B社', false, ''], ['C社', false, '']]),
            $this->item(3, 'c', '項目丙', true, '丙の根拠の文章がここにあります。', [['A社', false, ''], ['B社', false, ''], ['C社', false, '']]),
        ]);

        $this->assertSame('b', $this->composer()->compose($material)['info']['strength_item'], '競合の○が0社の乙・丙のうち、並び順で先の乙');

        $material['items'][1]['self_matched'] = false;
        $this->assertSame('c', $this->composer()->compose($material)['info']['strength_item']);
    }

    public function test_an_item_where_exactly_half_of_the_competitors_have_it_is_used_in_the_same_form_after_the_only_strengths(): void
    {
        $material = $this->material([
            $this->item(1, 'a', '項目甲', true, '甲の根拠の文章がここにあります。', [['A社', true, 'x'], ['B社', true, 'x'], ['C社', false, ''], ['D社', false, '']]),
        ], [], 4);

        $out = $this->composer()->compose($material);

        $this->assertSame('項目甲は、競合4社のうち2社からしか読み取れなかった内容で、自社は「甲の根拠の文章がここにあります」と記載しています。', $out['strength']);
        $this->assertSame('half', $out['info']['strength_kind']);

        // 競合3社では、半数ちょうどは無い(2社は半数以上で、強みではない)。
        $three = $this->material([$this->item(1, 'a', '項目甲', true, '甲の根拠の文章がここにあります。', [['A社', true, 'x'], ['B社', true, 'x'], ['C社', false, '']])]);
        $this->assertNull($this->composer()->compose($three)['strength']);
    }

    public function test_an_only_strength_always_comes_before_an_item_with_exactly_half(): void
    {
        $material = $this->material([
            $this->item(1, 'half', '半数の項目', true, '半数の根拠の文章がここにあります。', [['A社', true, 'x'], ['B社', true, 'x'], ['C社', false, ''], ['D社', false, '']]),
            $this->item(2, 'only', '強みの項目', true, '強みの根拠の文章がここにあります。', [['A社', true, 'x'], ['B社', false, ''], ['C社', false, ''], ['D社', false, '']]),
        ], [], 4);

        $this->assertSame('only', $this->composer()->compose($material)['info']['strength_item']);
    }

    public function test_when_there_is_no_strength_item_a_tie_axis_is_described_and_zero_ties_are_not(): void
    {
        $tie = fn (string $axis, int $score) => ['axis' => $axis, 'score' => $score, 'denominator' => 4, 'company_count' => 4];

        $out = $this->composer()->compose($this->material([], [$tie('経営スタイル', 0), $tie('活動的魅力', 3)]));
        $this->assertSame('活動的魅力は全社が3/4で、この比較では差がつきにくい領域です。', $out['strength']);
        $this->assertSame('tie', $out['info']['strength_kind']);

        // 全社0点の領域だけのときは、強みとして出さない(依頼CT-1の一文に戻る)。
        $this->assertNull($this->composer()->compose($this->material([], [$tie('経営スタイル', 0)]))['strength']);
        // 何も無ければnull。
        $this->assertNull($this->composer()->compose($this->material([]))['strength']);
    }

    public function test_an_item_without_a_usable_snippet_is_skipped_and_counted(): void
    {
        $material = $this->material([
            $this->item(1, 'a', '項目甲', true, '短い。', [['A社', false, ''], ['B社', false, ''], ['C社', false, '']]),
            $this->item(2, 'b', '項目乙', true, '乙の根拠の文章がここにあります。', [['A社', false, ''], ['B社', false, ''], ['C社', false, '']]),
        ]);

        $out = $this->composer()->compose($material);

        $this->assertSame('b', $out['info']['strength_item']);
        $this->assertSame(1, $out['info']['strength_skipped']);
    }

    // ------------------------------------------------------------------ CUA-3 弱み

    public function test_the_weakness_says_the_company_could_not_be_read_and_quotes_the_competitor_snippet(): void
    {
        $material = $this->material([
            $this->item(1, 'office', 'オフィス・施設', false, '', [['A社', false, ''], ['B社', true, '無料のマッサージルームを完備しています。'], ['C社', true, '別の会社の文章がここにあります。']]),
        ]);

        $out = $this->composer()->compose($material);

        $this->assertSame('オフィス・施設は自社サイトから読み取れませんでしたが、B社は「無料のマッサージルームを完備しています」と記載しています。', $out['weakness']);
        foreach (['ありません', '無い', '書いていない', '記載がない'] as $word) {
            $this->assertStringNotContainsString($word, (string) $out['weakness'], '自社の×を無い・書いていないと断定しない');
        }
        $this->assertSame('office', $out['info']['weakness_item']);
    }

    public function test_the_hole_with_the_most_competitors_comes_first_and_ties_follow_the_item_order(): void
    {
        $three = [['A社', true, '甲乙丙の根拠の文章がここにあります。'], ['B社', true, '甲乙丙の根拠の文章がここにあります。'], ['C社', true, '甲乙丙の根拠の文章がここにあります。']];
        $two = [['A社', true, '甲乙丙の根拠の文章がここにあります。'], ['B社', true, '甲乙丙の根拠の文章がここにあります。'], ['C社', false, '']];
        $material = $this->material([
            $this->item(1, 'a', '項目甲', false, '', $two),
            $this->item(2, 'b', '項目乙', false, '', $three),
            $this->item(3, 'c', '項目丙', false, '', $three),
        ]);

        $this->assertSame('b', $this->composer()->compose($material)['info']['weakness_item']);
    }

    public function test_the_competitor_is_the_first_one_with_the_item_and_a_usable_snippet_in_table_order(): void
    {
        $material = $this->material([
            $this->item(1, 'a', '項目甲', false, '', [['A社', true, '短い。'], ['B社', true, 'B社の根拠の文章がここにあります。'], ['C社', true, 'C社の根拠の文章がここにあります。']]),
        ]);

        $this->assertStringContainsString('B社は「B社の根拠の文章がここにあります」', (string) $this->composer()->compose($material)['weakness']);
    }

    public function test_an_item_without_any_usable_competitor_snippet_is_skipped_and_without_holes_the_weakness_is_null(): void
    {
        $material = $this->material([
            $this->item(1, 'a', '項目甲', false, '', [['A社', true, '短い。'], ['B社', true, '短い。'], ['C社', false, '']]),
            $this->item(2, 'b', '項目乙', false, '', [['A社', true, '乙の根拠の文章がここにあります。'], ['B社', true, '乙の根拠の文章がここにあります。'], ['C社', false, '']]),
        ]);
        $out = $this->composer()->compose($material);
        $this->assertSame('b', $out['info']['weakness_item']);
        $this->assertSame(1, $out['info']['weakness_skipped']);

        // 競合の○が半数未満の項目、自社が○の項目は穴ではない。
        $none = $this->material([
            $this->item(1, 'a', '項目甲', false, '', [['A社', true, '甲の根拠の文章がここにあります。'], ['B社', false, ''], ['C社', false, '']]),
            $this->item(2, 'b', '項目乙', true, '乙の根拠の文章がここにあります。', [['A社', true, 'x'], ['B社', true, 'x'], ['C社', true, 'x']]),
        ]);
        $this->assertNull($this->composer()->compose($none)['weakness']);
    }

    public function test_when_competitors_are_shown_by_symbol_the_competitor_is_written_as_competitor_and_symbol(): void
    {
        $material = $this->material([
            $this->item(1, 'a', '項目甲', false, '', [['A', true, '甲の根拠の文章がここにあります。'], ['B', true, '甲の根拠の文章がここにあります。'], ['C', false, ''], ['D', false, '']]),
        ], [], 4, false);

        $this->assertStringContainsString('競合Aは「甲の根拠の文章がここにあります」', (string) $this->composer()->compose($material)['weakness']);
    }

    // ------------------------------------------------------------------ 文字数

    public function test_a_sentence_over_the_limit_uses_a_shorter_snippet_then_the_next_item_then_gives_up(): void
    {
        $long = str_repeat('長', 38).'です';        // 40字
        $short = '短くて足りる一節です、これで十分に足ります';
        $evidence = "{$long}。{$short}。";
        $name = str_repeat('項', 24);                // 項目名を長くして、長い一節だと100字を超えるようにする

        // 一節が長いと超える → 同じ根拠の文の短い候補に替える。
        $material = $this->material([$this->item(1, 'a', $name, true, $evidence, [['A社', false, ''], ['B社', false, ''], ['C社', false, '']])]);
        $out = $this->composer()->compose($material);
        $this->assertLessThanOrEqual(100, mb_strlen((string) $out['strength']));
        $this->assertStringContainsString('「短くて足りる一節です」', (string) $out['strength']);

        // どの候補でも超える項目は飛ばして、次の項目へ。
        $material['items'][0]['self_evidence'] = "{$long}。";
        $material['items'][] = $this->item(2, 'b', '短い名前', true, "{$short}。", [['A社', false, ''], ['B社', false, ''], ['C社', false, '']]);
        $out = $this->composer()->compose($material);
        $this->assertSame('b', $out['info']['strength_item']);
        $this->assertSame(1, $out['info']['strength_skipped']);

        // 最後まで収まらなければnull(依頼CT-1の一文)。
        $material['items'][1]['self_evidence'] = "{$long}。";
        $material['items'][1]['name'] = $name;
        $this->assertNull($this->composer()->compose($material)['strength']);

        // 上限はconfigから。
        config(['admin_comparison_pptx.strength_weakness_detail.sentence_limit_chars' => 1000]);
        $this->assertNotNull($this->composer()->compose($material)['strength']);
    }

    // ------------------------------------------------------------------ 既定・無効・材料が作れないとき

    public function test_when_disabled_nothing_is_written(): void
    {

        config(['admin_comparison_pptx.strength_weakness_detail.enabled' => false]);
        $out = $this->composer()->compose($this->material([$this->item(1, 'purpose', 'パーパス', true, '私たちは社会の課題を解決します。', [['A社', false, ''], ['B社', false, ''], ['C社', false, '']])]));
        $this->assertNull($out['strength']);
        $this->assertNull($out['weakness']);
    }

    public function test_a_material_that_could_not_be_built_gives_no_sentences(): void
    {
        $out = $this->composer()->compose(['status' => 'skipped', 'reason' => 'material_insufficient'] + $this->material([]));

        $this->assertNull($out['strength']);
        $this->assertNull($out['weakness']);
    }

    /** 比較(自社1 + 競合2)。自社と競合1の○の項目、根拠の文は人工。competitorThinは、競合1の起点由来の文字数を少なくする。 */
    private function makeAnalysis(bool $competitorThin = false): Analysis
    {
        $company = LeadCompany::factory()->create(['company_name' => '自社テスト']);
        $project = new Project(['name' => '比較']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_company_id = $company->id;
        $project->save();
        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Running, 'kind' => AnalysisKind::AdminComparison, 'skip_brand_wheel' => false]);

        $sets = [['自社', true, 0, ['purpose'], null], ['A社', false, 1, ['business_expansion', 'social_contribution'], $competitorThin ? 50 : null], ['B社', false, 2, ['social_contribution'], null]];
        foreach ($sets as $i => [$name, $primary, $order, $keys, $chars]) {
            $website = Website::factory()->create(['project_id' => $project->id, 'is_primary' => $primary, 'display_order' => $order, 'name' => $name, 'url' => "https://site{$i}.example.com/recruit/"]);
            $wa = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
            $axes = [];
            foreach ((array) config('brand_wheel.axes') as $axisKey => $axis) {
                $matched = [];
                foreach (array_keys((array) $axis['sub_elements']) as $subKey) {
                    if (in_array($subKey, $keys, true)) {
                        $matched[] = ['key' => $subKey, 'evidence' => "{$name}は{$subKey}について具体的に書いています。"];
                    }
                }
                $axes[] = ['axis_key' => $axisKey, 'matched_sub_elements' => $matched, 'discarded_sub_elements' => [], 'claimed_sub_element_count' => count($matched), 'state' => 'partial'];
            }
            BrandWheelAnalysisResult::factory()->create(['analysis_id' => $analysis->id, 'website_analysis_id' => $wa->id, 'status' => 'success', 'axes' => $axes, 'input_origin_chars' => $chars]);
        }

        return $analysis;
    }

    public function test_the_sentences_are_built_from_the_stored_judgments_without_the_ai_or_the_network(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $analysis = $this->makeAnalysis();

        $out = $this->composer()->composeForAnalysis($analysis);

        $this->assertStringContainsString('パーパスは、競合2社のいずれからも読み取れなかった内容で、自社は「自社はpurposeについて具体的に書いています」と記載しています。', (string) $out['strength']);
        // 社会貢献活動は競合2社とも○で、自社は×。企業名はサイトの名前、並び順はdisplay_order。
        $this->assertSame('社会貢献活動は自社サイトから読み取れませんでしたが、A社は「A社はsocial_contributionについて具体的に書いています」と記載しています。', $out['weakness']);
        Http::assertNothingSent();
    }

    public function test_a_company_with_thin_material_gives_no_sentences(): void
    {
        $out = $this->composer()->composeForAnalysis($this->makeAnalysis(true));

        $this->assertNull($out['strength']);
        $this->assertNull($out['weakness']);
    }

    // ------------------------------------------------------------------ CT-1・AIとの関係 / スライド(CUA-4・5)

    /** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} */
    private function scores(): array
    {
        return [
            [['name' => '自社', 'matched' => 5, 'total' => 24, 'is_self' => true, 'material_sufficient' => true], ['name' => 'A社', 'matched' => 3, 'total' => 24, 'is_self' => false, 'material_sufficient' => true]],
            [['name' => '活動的魅力', 'caption' => null, 'denominator' => 4, 'self_count' => 3, 'competitor_counts' => [2], 'self_gap' => false]],
        ];
    }

    public function test_the_detail_sentences_replace_ct1_and_the_ai_weakness_replaces_only_the_weakness_and_the_ai_strength_is_never_used(): void
    {
        [$companies, $axes] = $this->scores();
        $composer = new ComparisonStrengthWeaknessComposer;
        $ct1 = $composer->compose($companies, $axes, true);
        $detail = ['strength' => 'CUAの強みの一文です。', 'weakness' => 'CUAの弱みの一文です。'];
        $ai = ['strength' => ['sentence' => 'AIの強みの一文です。'], 'weakness' => ['sentence' => 'AIの弱みの一文です。']];

        // AIなし: 強み・弱みともCUAの文。注記はCUA。
        $only = $composer->compose($companies, $axes, true, null, $detail);
        $this->assertSame('CUAの強みの一文です。', $only['strength']);
        $this->assertSame('CUAの弱みの一文です。', $only['weakness']);
        $this->assertTrue($only['detail_used']);
        $this->assertFalse($only['ai_used']);

        // AIの文が通ったとき(依頼CV): その側はAIの文。AIの強みも使う(CUAの文より優先)。
        $both = $composer->compose($companies, $axes, true, $ai, $detail);
        $this->assertSame('AIの強みの一文です。', $both['strength']);
        $this->assertSame('AIの弱みの一文です。', $both['weakness']);
        $this->assertTrue($both['ai_used']);

        // AIの弱みが落ちた(null)とき: CT-1ではなくCUAの弱み。
        $fallen = $composer->compose($companies, $axes, true, ['strength' => null, 'weakness' => null], $detail);
        $this->assertSame('CUAの弱みの一文です。', $fallen['weakness']);
        $this->assertFalse($fallen['ai_used']);

        // CUAが作れなかった側はCT-1。
        $strengthOnly = $composer->compose($companies, $axes, true, null, ['strength' => 'CUAの強みの一文です。', 'weakness' => null]);
        $this->assertSame($ct1['weakness'], $strengthOnly['weakness']);

        // どちらも無ければCT-1(無効のときも同じ)。
        $none = $composer->compose($companies, $axes, true, null, null);
        $this->assertSame($ct1['strength'], $none['strength']);
        $this->assertFalse($none['detail_used']);
    }

    public function test_the_thin_material_notice_comes_before_the_detail_sentences(): void
    {
        [$companies, $axes] = $this->scores();
        $companies[1]['material_sufficient'] = false;

        $out = (new ComparisonStrengthWeaknessComposer)->compose($companies, $axes, true, null, ['strength' => 'CUAの強みの一文です。', 'weakness' => 'CUAの弱みの一文です。']);

        $this->assertNotNull($out['notice']);
        $this->assertNull($out['strength']);
        $this->assertFalse($out['detail_used']);
    }

    public function test_the_slide_note_is_the_detail_note_the_ai_note_or_none(): void
    {
        [$companies, $axes] = $this->scores();
        $composer = new ComparisonStrengthWeaknessComposer;
        $generator = app(AdminComparisonPptxGenerator::class);
        $base = ['self_company_name' => '自社', 'self_readable' => true, 'companies' => $companies, 'axes' => $axes, 'source_note' => 'n', 'page_number' => null];
        $detailNote = (string) config('admin_comparison_pptx.strength_weakness_detail.note');
        $aiNote = (string) config('strength_weakness_sentence.note');
        $detail = ['strength' => 'CUAの強みの一文です。', 'weakness' => 'CUAの弱みの一文です。'];

        $xml = fn (array $text) => $this->slideXml($generator->generate($base + ['strength_weakness' => $text]));

        $detailXml = $xml($composer->compose($companies, $axes, true, null, $detail));
        $this->assertStringContainsString($detailNote, $detailXml);
        $this->assertStringNotContainsString($aiNote, $detailXml);

        $aiXml = $xml($composer->compose($companies, $axes, true, ['weakness' => ['sentence' => 'AIの弱みの一文です。']], $detail));
        $this->assertStringContainsString($aiNote, $aiXml);
        $this->assertStringNotContainsString($detailNote, $aiXml);

        $ct1Xml = $xml($composer->compose($companies, $axes, true));
        $this->assertStringNotContainsString($detailNote, $ct1Xml);
        $this->assertStringNotContainsString($aiNote, $ct1Xml);
    }

    public function test_the_notes_say_nothing_that_claims_an_absence(): void
    {
        $words = (string) config('admin_comparison_pptx.strength_weakness_detail.note').(string) config('admin_comparison_pptx.strength_weakness_detail.weakness_template');

        foreach (['ありません', '無い', '書いていない', '発信していない'] as $word) {
            $this->assertStringNotContainsString($word, $words);
        }
    }

    private function slideXml(string $pptxBytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cua-slide');
        file_put_contents($path, $pptxBytes);
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = html_entity_decode((string) $zip->getFromName('ppt/slides/slide1.xml'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $zip->close();
        @unlink($path);

        return $xml;
    }
}
