<?php

namespace Tests\Feature\ComparisonCommentary;

use App\Enums\AnalysisKind;
use App\Enums\AnalysisStatus;
use App\Enums\WebsiteAnalysisStatus;
use App\Jobs\Analysis\FinalizeAnalysisJob;
use App\Jobs\GenerateComparisonCommentaryJob;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Admin\LeadCompanyDeletionService;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\ComparisonCommentary\ComparisonCommentaryDispatcher;
use App\Services\ComparisonCommentary\ComparisonCommentaryException;
use App\Services\ComparisonCommentary\ComparisonCommentaryMaterialBuilder;
use App\Services\ComparisonCommentary\ComparisonCommentaryProvider;
use App\Services\ComparisonCommentary\ComparisonCommentaryProviderFactory;
use App\Services\ComparisonCommentary\ComparisonCommentaryService;
use App\Services\ComparisonCommentary\ComparisonCommentaryStore;
use App\Services\ComparisonCommentary\ComparisonCommentaryVerifier;
use App\Services\Report\ComparisonStrengthWeaknessComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼CU: 強み・弱みの一文をAIで書く。材料(CU-1)・サーバー側の確認(CU-3)・流れ(CU-4)・スライド(CU-5)・測定コマンド。
 * 本文・根拠の文はすべて人工のもの。AIは呼ばず、固定の出力を返す偽物を使う。
 *
 * 項目の記号: 活動的魅力 = purpose(p) business_expansion(b) project_initiative(j) social_contribution(s)。
 * 資産的魅力 = brand_recognition ほか。
 */
class ComparisonCommentaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
        config(['comparison_commentary.enabled' => true]);
    }

    // ------------------------------------------------------------------ 部品

    /** @return array<string, string> sub_key => axis_key */
    private function axisOf(): array
    {
        $map = [];
        foreach ((array) config('brand_wheel.axes') as $axisKey => $axis) {
            foreach (array_keys((array) $axis['sub_elements']) as $subKey) {
                $map[$subKey] = $axisKey;
            }
        }

        return $map;
    }

    /** 根拠の文(人工)。誰の・どの項目かが分かる一文。 */
    private function evidence(string $who, string $key): string
    {
        return "{$who}は{$key}について具体的に書いています。";
    }

    /**
     * @param  list<string>  $keys  ○の項目(sub_key)
     * @return list<array<string, mixed>>
     */
    private function axesFor(string $who, array $keys): array
    {
        $axes = [];
        foreach ((array) config('brand_wheel.axes') as $axisKey => $axis) {
            $matched = [];
            foreach (array_keys((array) $axis['sub_elements']) as $subKey) {
                if (in_array($subKey, $keys, true)) {
                    $matched[] = ['key' => $subKey, 'evidence' => $this->evidence($who, $subKey)];
                }
            }
            $axes[] = ['axis_key' => $axisKey, 'matched_sub_elements' => $matched, 'discarded_sub_elements' => [], 'claimed_sub_element_count' => count($matched), 'state' => $matched === [] ? 'unread' : 'partial'];
        }

        return $axes;
    }

    /**
     * 自社1 + 競合N社の比較。競合の名前は 'A社' 'B社' …(競合が4社以上のときは記号になる)。
     *
     * @param  list<string>  $self  自社が○の項目
     * @param  list<list<string>>  $competitors  競合ごとの○の項目
     * @param  array<int, ?int>  $originChars  競合ごと(添字0=自社、1〜=競合)の起点由来の文字数(未指定はnull=十分)
     */
    private function makeComparison(array $self, array $competitors, array $originChars = []): Analysis
    {
        $company = LeadCompany::factory()->create(['company_name' => '自社テスト']);
        $project = new Project(['name' => '比較']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_company_id = $company->id;
        $project->save();
        $analysis = Analysis::factory()->create([
            'project_id' => $project->id,
            'status' => AnalysisStatus::Running,
            'kind' => AnalysisKind::AdminComparison,
            'skip_brand_wheel' => false,
        ]);

        $sites = array_merge([['self', true, 0, $self]], array_map(fn (array $keys, int $i) => [chr(ord('A') + $i).'社', false, $i + 1, $keys], $competitors, array_keys($competitors)));
        foreach ($sites as $index => [$name, $primary, $order, $keys]) {
            $website = Website::factory()->create(['project_id' => $project->id, 'is_primary' => $primary, 'display_order' => $order, 'name' => $name === 'self' ? '自社サイト' : $name, 'url' => "https://site{$index}.example.com/recruit/"]);
            $wa = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
            BrandWheelAnalysisResult::factory()->create([
                'analysis_id' => $analysis->id,
                'website_analysis_id' => $wa->id,
                'status' => 'success',
                'axes' => $this->axesFor($name === 'self' ? '自社' : $name, $keys),
                'input_origin_chars' => $originChars[$index] ?? null,
            ]);
        }

        return $analysis;
    }

    /**
     * AIの偽物。固定の出力(または材料から作る出力)を返す。呼ばれた回数を数える。
     *
     * @param  array<string, mixed>|\Closure  $output
     */
    private function fakeProvider(array|\Closure $output): object
    {
        $provider = new class($output) implements ComparisonCommentaryProvider
        {
            public int $calls = 0;

            /** @var list<array<string, mixed>> */
            public array $materials = [];

            public function __construct(public readonly array|\Closure $output) {}

            public function name(): string
            {
                return 'fake';
            }

            public function model(): ?string
            {
                return 'fake-model';
            }

            public function promptVersion(): string
            {
                return 'v1';
            }

            public function write(array $material): array
            {
                $this->calls++;
                $this->materials[] = $material;
                $decoded = $this->output instanceof \Closure ? ($this->output)($material) : $this->output;

                return ['decoded' => $decoded, 'usage_input_tokens' => 10, 'usage_output_tokens' => 5];
            }
        };

        app()->instance(ComparisonCommentaryProviderFactory::class, new class($provider) extends ComparisonCommentaryProviderFactory
        {
            public function __construct(private readonly ComparisonCommentaryProvider $fake) {}

            public function make(): ComparisonCommentaryProvider
            {
                return $this->fake;
            }
        });

        return $provider;
    }

    /** 3社の標準の比較: 自社は p,b,j が○(p は競合全員が×、b は競合1社が○、j は競合全員が○)、s は自社×で競合2社が○。 */
    private function standard(): Analysis
    {
        return $this->makeComparison(['purpose', 'business_expansion', 'project_initiative'], [
            ['business_expansion', 'project_initiative', 'social_contribution'],
            ['project_initiative', 'social_contribution'],
            ['project_initiative'],
        ]);
    }

    /** 標準の比較で、確認をすべて通る出力。 */
    private function goodOutput(): array
    {
        return [
            'strength' => [
                'sentence' => '「'.$this->evidence('自社', 'purpose').'」と、パーパスを自社のサイトで具体的に示している点が、競合3社には見られない特徴です。',
                'items' => ['purpose'],
                'quotes' => [$this->evidence('自社', 'purpose')],
            ],
            'weakness' => [
                'sentence' => 'A社とB社は「'.$this->evidence('A社', 'social_contribution').'」と社会貢献活動を伝えていますが、自社のサイトからは読み取れませんでした。',
                'items' => ['social_contribution'],
                'quotes' => [$this->evidence('A社', 'social_contribution')],
                'companies' => ['A社', 'B社'],
            ],
        ];
    }

    private function verify(string $side, array $output, Analysis $analysis): array
    {
        $material = app(ComparisonCommentaryMaterialBuilder::class)->build($analysis);

        return app(ComparisonCommentaryVerifier::class)->verify($side, $output, $material);
    }

    // ------------------------------------------------------------------ CU-1 材料

    public function test_material_lists_only_strengths_holes_and_tie_axes_as_defined(): void
    {
        $material = app(ComparisonCommentaryMaterialBuilder::class)->build($this->standard());

        $this->assertSame('ok', $material['status']);
        // 自社が○で、競合の○が半数(1.5社)未満 = 0〜1社: purpose(0社), business_expansion(1社)。project_initiative(3社)は含まない。
        $this->assertSame(['purpose', 'business_expansion'], array_column($material['only_strengths'], 'key'));
        // 自社が×で、競合の○が半数以上 = 2社以上: social_contribution(2社)。
        $this->assertSame(['social_contribution'], array_column($material['holes'], 'key'));
        $this->assertSame(['A社', 'B社'], $material['holes'][0]['matched_companies']);
        $this->assertCount(2, $material['holes'][0]['competitor_evidence']);
        // 差がつかない領域: 全社が同じ点数の領域。標準の比較では、全員0点の領域が複数ある。
        $this->assertNotEmpty($material['tie_axes']);
        foreach ($material['tie_axes'] as $tie) {
            $this->assertSame(4, $material['competitor_count'] + 1);
            $this->assertSame($tie['company_count'], 4);
        }
        // 根拠の文はそのまま(80字以内)入っている。
        $this->assertSame($this->evidence('自社', 'purpose'), $material['only_strengths'][0]['self_evidence']);
    }

    public function test_the_half_boundary_is_applied_for_even_and_odd_company_counts(): void
    {
        $builder = app(ComparisonCommentaryMaterialBuilder::class);

        // 競合2社: 半数 = 1社。自社○で競合の○が0社 → 強み、1社 → 強みではない。自社×で競合の○が1社以上 → 穴。
        $two = $builder->build($this->makeComparison(['purpose', 'business_expansion'], [['business_expansion', 'social_contribution'], ['project_initiative']]));
        $this->assertSame(['purpose'], array_column($two['only_strengths'], 'key'));
        $this->assertEqualsCanonicalizing(['social_contribution', 'project_initiative'], array_column($two['holes'], 'key'));

        // 競合4社(記号): 半数 = 2社。自社○で競合の○が1社 → 強み、2社 → 強みではない。自社×で2社 → 穴、1社 → 穴ではない。
        $four = $builder->build($this->makeComparison(['purpose', 'business_expansion', 'project_initiative'], [
            ['business_expansion', 'project_initiative', 'social_contribution', 'brand_recognition'],
            ['project_initiative', 'social_contribution'],
            ['brand_recognition'],
            ['office_facility'],
        ]));
        $this->assertSame(['purpose', 'business_expansion'], array_column($four['only_strengths'], 'key'));
        $this->assertEqualsCanonicalizing(['social_contribution', 'brand_recognition'], array_column($four['holes'], 'key'));
        // 競合が4社のときの呼び名は記号(比較表の見出しと同じ)。
        $this->assertSame(['A', 'B', 'C', 'D'], array_column($four['competitors'], 'label'));
    }

    public function test_quote_candidates_are_short_verbatim_pieces_of_the_evidence(): void
    {
        $builder = app(ComparisonCommentaryMaterialBuilder::class);
        $method = new \ReflectionMethod($builder, 'quoteCandidates');
        $evidence = '私たちは、一人ひとりが挑戦できる環境を整えています。まず新しいことに取り組み、その経験を次の仕事に生かします。';

        $candidates = $method->invoke($builder, $evidence);

        $this->assertNotEmpty($candidates);
        foreach ($candidates as $candidate) {
            $this->assertLessThanOrEqual((int) config('comparison_commentary.quote_hint_max_chars'), mb_strlen($candidate));
            $this->assertStringContainsString($candidate, $evidence, '候補は根拠の文にそのまま含まれる');
            $this->assertDoesNotMatchRegularExpression('/^[、。]|[、。]$/u', $candidate, '前後の読点・句点は除く');
        }
        // 区切りのない長い文でも、決まった長さで切って、どれも根拠の文に含まれる。
        $long = str_repeat('あ', 75);
        $pieces = $method->invoke($builder, $long);
        $this->assertSame($long, implode('', $pieces));
        $this->assertCount(2, $pieces, '区切りの無い長い部分だけ、hard_max(50字)で切る');
        // 区切りのある長い部分は、途中で切らない(30字を超えても1つの候補のまま)。
        $clause = '適切なステップでキャリアを描ける環境を設けており、ご自身に適したスピードで成長できます。';
        $this->assertSame(['適切なステップでキャリアを描ける環境を設けており', 'ご自身に適したスピードで成長できます'], $method->invoke($builder, $clause));

        // 材料にも付く(強みは自社の根拠、穴は競合の根拠)。
        $material = $builder->build($this->standard());
        $this->assertNotEmpty($material['only_strengths'][0]['quote_candidates']);
        $this->assertNotEmpty($material['holes'][0]['competitor_evidence'][0]['quote_candidates']);
    }

    public function test_the_material_limits_and_the_evidence_length_come_from_the_config(): void
    {
        config(['comparison_commentary.evidence_max_chars' => 10, 'comparison_commentary.only_strengths_max' => 1]);
        $material = app(ComparisonCommentaryMaterialBuilder::class)->build($this->standard());

        $this->assertCount(1, $material['only_strengths']);
        $this->assertSame(10, mb_strlen($material['only_strengths'][0]['self_evidence']));
        $this->assertStringEndsWith('…', $material['only_strengths'][0]['self_evidence']);
    }

    // ------------------------------------------------------------------ CU-3 サーバー側の確認

    public function test_a_sentence_that_meets_every_check_is_used(): void
    {
        $analysis = $this->standard();

        foreach (['strength', 'weakness'] as $side) {
            $checked = $this->verify($side, $this->goodOutput()[$side], $analysis);
            $this->assertSame([], $checked['reasons'], $side);
            $this->assertTrue($checked['ok']);
        }
    }

    /**
     * @return array<string, array{0: string, 1: callable(array): array, 2: string}>
     */
    public static function brokenOutputs(): array
    {
        return [
            '強みの項目に自社が×の項目' => ['strength', fn (array $o) => array_replace($o, ['items' => ['social_contribution']]), 'items_invalid'],
            '強みの項目が24項目のキーでない' => ['strength', fn (array $o) => array_replace($o, ['items' => ['no_such_item']]), 'items_invalid'],
            '弱みの項目に自社が○の項目' => ['weakness', fn (array $o) => array_replace($o, ['items' => ['purpose']]), 'items_invalid'],
            '弱みの項目が空' => ['weakness', fn (array $o) => array_replace($o, ['items' => []]), 'items_invalid'],
            '「」を閉じていない' => ['strength', fn (array $o) => array_replace($o, ['sentence' => '「'.'自社のパーパスを示している点が、競合には見られない特徴です。']), 'bracketed_word_not_in_quotes'],
            '強みのquotesが根拠の文に無い' => ['strength', fn (array $o) => array_replace($o, ['quotes' => ['材料に無い言葉です']]), 'quote_not_in_material'],
            '弱みのquotesが根拠の文に無い' => ['weakness', fn (array $o) => array_replace($o, ['quotes' => ['材料に無い言葉です']]), 'quote_not_in_material'],
            '強みのquotesが自社の根拠でなく競合の根拠' => ['strength', fn (array $o) => array_replace($o, ['quotes' => ['A社は business_expansion について']]), 'quote_not_in_material'],
            '弱みのquotesが自社の根拠' => ['weakness', fn (array $o) => array_replace($o, ['quotes' => ['自社はpurposeについて具体的に書いています。']]), 'quote_not_in_material'],
            '「」の中の言葉がquotesに無い(強み)' => ['strength', fn (array $o) => array_replace($o, ['sentence' => '「自社のパーパス」を示している点が、競合3社には見られない特徴です。']), 'bracketed_word_not_in_quotes'],
            '「」の中の言葉がquotesに無い(弱み)' => ['weakness', fn (array $o) => array_replace($o, ['sentence' => 'A社とB社は「社会に貢献する」と伝えていますが、自社のサイトからは読み取れませんでした。']), 'bracketed_word_not_in_quotes'],
            '比較に無い会社名' => ['weakness', fn (array $o) => array_replace($o, ['companies' => ['Z社']]), 'company_invalid'],
            'その項目で×の会社名' => ['weakness', fn (array $o) => array_replace($o, ['companies' => ['A社', 'C社']]), 'company_invalid'],
            'companiesに無い競合の名前を文に出している' => ['weakness', fn (array $o) => array_replace($o, ['sentence' => 'A社とB社とC社が社会貢献活動を伝えていますが、自社のサイトからは読み取れませんでした。']), 'company_invalid'],
            '材料に無い数字(強み)' => ['strength', fn (array $o) => array_replace($o, ['sentence' => 'パーパスを示している点が、競合777社には見られない特徴です。']), 'number_not_in_material'],
            '材料に無い数字(弱み・全角)' => ['weakness', fn (array $o) => array_replace($o, ['sentence' => '競合９９９社のうちA社とB社が社会貢献活動を伝えていますが、自社のサイトからは読み取れませんでした。']), 'number_not_in_material'],
            '禁止の言葉(強み)' => ['strength', fn (array $o) => array_replace($o, ['sentence' => 'パーパスを示しており、競合には発信していない特徴です。']), 'forbidden_word'],
            '禁止の言葉(弱み・懸念)' => ['weakness', fn (array $o) => array_replace($o, ['sentence' => 'A社とB社が社会貢献活動を伝えており、自社のサイトでは懸念されます。']), 'forbidden_word'],
            '禁止の言葉(弱み・無い断定)' => ['weakness', fn (array $o) => array_replace($o, ['sentence' => '自社のサイトには社会貢献活動の記載がありません。']), 'forbidden_word'],
            '長さ超過' => ['strength', fn (array $o) => array_replace($o, ['sentence' => str_repeat('パーパスを示している特徴です。', 12)]), 'too_long'],
            '文が空' => ['weakness', fn (array $o) => array_replace($o, ['sentence' => '']), 'empty_sentence'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenOutputs')]
    public function test_a_sentence_that_fails_any_check_is_rejected_for_that_side_only(string $side, callable $break, string $reason): void
    {
        $analysis = $this->standard();
        $good = $this->goodOutput();
        $broken = $break($good[$side]);

        $checked = $this->verify($side, $broken, $analysis);
        $this->assertFalse($checked['ok']);
        $this->assertContains($reason, $checked['reasons']);
        $this->assertNull($checked['value']);

        // もう片方は影響されない。
        $other = $side === 'strength' ? 'weakness' : 'strength';
        $this->assertTrue($this->verify($other, $good[$other], $analysis)['ok']);

        // 保存される結果。依頼CUA-4: AIの強みは使わない(出力は無視され、常にnull・確認もしない)。弱みは、確認で落ちたらnull
        // (依頼CUAの文、無ければ依頼CT-1の一文に戻る)。落ちた理由の符号は残り、文の中身は残らない。
        $this->fakeProvider([$side => $broken] + $good);
        $result = app(ComparisonCommentaryService::class)->generate($analysis, true);
        $this->assertNull($result['strength']);
        $this->assertSame([], $result['rejected']['strength']);
        if ($side === 'weakness') {
            $this->assertNull($result['weakness']);
            $this->assertContains($reason, $result['rejected']['weakness']);
        } else {
            $this->assertNotNull($result['weakness']);
        }
        $this->assertStringNotContainsString($this->evidence('自社', 'purpose'), json_encode($result['rejected'], JSON_UNESCAPED_UNICODE));
    }

    public function test_a_bracket_inside_a_quoted_phrase_is_part_of_the_quote_but_a_separate_bracketed_word_must_be_a_quote(): void
    {
        $material = [
            'self_name' => '自社', 'competitors' => [['label' => 'A社', 'name' => 'A社']], 'competitor_count' => 1, 'tie_axes' => [], 'holes' => [],
            'only_strengths' => [['key' => 'purpose', 'axis' => '活動的魅力', 'name' => 'パーパス', 'self_evidence' => '私たちは『挑戦』を大切にします。', 'competitor_matched_count' => 0]],
            'self_matched_by_key' => ['purpose' => true], 'matched_labels_by_key' => ['purpose' => []],
        ];
        $verifier = app(ComparisonCommentaryVerifier::class);
        $output = fn (string $sentence, array $quotes) => ['sentence' => $sentence, 'items' => ['purpose'], 'quotes' => $quotes];

        // 外側の「」の中に『』がある引用は、外側の言葉が quotes と一致していれば通る(中の『』は引用の一部)。
        $this->assertTrue($verifier->verify('strength', $output('「私たちは『挑戦』を大切にします。」と記しています。', ['私たちは『挑戦』を大切にします。']), $material)['ok']);
        // 外側の外にある『』は、それ自体が quotes と一致しなければならない。
        $this->assertContains('bracketed_word_not_in_quotes', $verifier->verify('strength', $output('「私たちは」と『挑戦』を記しています。', ['私たちは']), $material)['reasons']);
    }

    public function test_a_company_that_is_marked_for_every_listed_item_is_accepted_and_one_that_is_not_is_rejected(): void
    {
        // 弱みの項目が2つ(social_contribution と project_initiative は自社○のため使えない)。自社×の項目は social_contribution のみ。
        $analysis = $this->makeComparison(['purpose'], [['social_contribution', 'brand_recognition'], ['social_contribution'], ['brand_recognition']]);
        $weak = fn (array $companies) => [
            'sentence' => '競合の一部は社会貢献活動やブランドの認知を伝えていますが、自社のサイトからは読み取れませんでした。',
            'items' => ['social_contribution', 'brand_recognition'],
            'quotes' => [],
            'companies' => $companies,
        ];

        // A社だけが両方の項目で○。
        $this->assertTrue($this->verify('weakness', $weak(['A社']), $analysis)['ok']);
        $this->assertContains('company_invalid', $this->verify('weakness', $weak(['A社', 'B社']), $analysis)['reasons']);
    }

    // ------------------------------------------------------------------ 流れ(材料 → AI → 確認 → 保存)

    public function test_the_ai_is_called_once_with_the_material_and_the_verified_result_is_stored(): void
    {
        $analysis = $this->standard();
        $fake = $this->fakeProvider($this->goodOutput());

        $result = app(ComparisonCommentaryService::class)->generate($analysis);

        $this->assertSame(1, $fake->calls);
        $this->assertSame(['purpose', 'business_expansion'], array_column($fake->materials[0]['only_strengths'], 'key'));
        // 検証用の対応表(24項目の○×)はプロンプトに載せない。
        $this->assertArrayNotHasKey('self_matched_by_key', $fake->materials[0]);
        // 依頼CUA-4: AIの強みは使わない。弱みだけが確認を通って残る。
        $this->assertNull($result['strength']);
        $this->assertSame($this->goodOutput()['weakness']['sentence'], $result['weakness']['sentence']);
        Storage::disk('analysis')->assertExists("analyses/{$analysis->id}/comparison_commentary.json");

        // 結果のファイルがあれば、AIは呼び直さない。
        app(ComparisonCommentaryService::class)->generate($analysis);
        $this->assertSame(1, $fake->calls);
    }

    public function test_the_prompt_contains_the_definitions_and_the_rules_and_the_schema_is_strict(): void
    {
        $provider = new \App\Services\ComparisonCommentary\OpenAiComparisonCommentaryProvider;
        $material = app(ComparisonCommentaryMaterialBuilder::class)->visible(app(ComparisonCommentaryMaterialBuilder::class)->build($this->standard()));
        $prompt = $provider->buildPrompt($material);

        // 24項目の定義(判定プロンプトと同じもの)。
        $this->assertSame(24, substr_count($prompt, '- key: '));
        $this->assertStringContainsString((string) config('brand_wheel.axes.will_activity.sub_element_definitions.purpose'), $prompt);
        // 規則。
        $this->assertStringContainsString('読み取れませんでした', $prompt);
        $this->assertStringContainsString('quotes', $prompt);
        $this->assertStringContainsString('60〜90文字', $prompt);
        $schema = $provider->buildResponseSchema();
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['strength', 'weakness'], $schema['required']);
        $this->assertContains('social_contribution', $schema['properties']['strength']['properties']['items']['items']['enum']);
        // 先に項目と短い引用を決めさせてから、文を書かせる順。
        $this->assertSame(['items', 'quotes', 'companies', 'sentence'], $schema['properties']['weakness']['required']);
        $this->assertSame(['items', 'quotes', 'companies', 'sentence'], array_keys($schema['properties']['weakness']['properties']));
    }

    public function test_when_a_company_has_thin_material_nothing_is_written_and_the_ai_is_not_called(): void
    {
        $analysis = $this->makeComparison(['purpose'], [['business_expansion'], ['project_initiative']], [2 => 100]);
        $fake = $this->fakeProvider($this->goodOutput());

        $result = app(ComparisonCommentaryService::class)->generate($analysis);

        $this->assertSame(0, $fake->calls);
        $this->assertSame('material_insufficient', $result['skipped']);
        $this->assertNull($result['strength']);
        $this->assertNull($result['weakness']);
        $this->assertSame(0, $result['ai_calls']);

        // 点数のほうは、CT-1と同じく「少ない」と1行だけ出して強み・弱みは出さない。
        $companies = [
            ['name' => '自社', 'matched' => 1, 'total' => 24, 'is_self' => true, 'material_sufficient' => true],
            ['name' => 'A社', 'matched' => 1, 'total' => 24, 'is_self' => false, 'material_sufficient' => true],
            ['name' => 'B社', 'matched' => 1, 'total' => 24, 'is_self' => false, 'material_sufficient' => false],
        ];
        $axes = [['name' => '活動的魅力', 'caption' => null, 'denominator' => 4, 'self_count' => 1, 'competitor_counts' => [1, 1], 'self_gap' => false]];
        $out = (new ComparisonStrengthWeaknessComposer)->compose($companies, $axes, true, $this->goodOutput());
        $this->assertNotNull($out['notice']);
        $this->assertNull($out['strength']);
        $this->assertFalse($out['ai_used']);
    }

    public function test_a_company_whose_judgment_is_not_readable_is_also_skipped_without_the_ai(): void
    {
        $analysis = $this->standard();
        BrandWheelAnalysisResult::query()->where('analysis_id', $analysis->id)->whereIn('website_analysis_id', $analysis->websiteAnalyses()->orderByDesc('id')->limit(1)->pluck('id'))->update(['status' => 'error']);
        $fake = $this->fakeProvider($this->goodOutput());

        $result = app(ComparisonCommentaryService::class)->generate($analysis);

        $this->assertSame(0, $fake->calls);
        $this->assertSame('competitor_unreadable', $result['skipped']);
    }

    public function test_the_log_has_only_counts_and_reason_codes(): void
    {
        $analysis = $this->standard();
        $this->fakeProvider(['weakness' => ['sentence' => '懸念があります。', 'items' => ['social_contribution'], 'quotes' => [], 'companies' => []]] + $this->goodOutput());
        \Illuminate\Support\Facades\Log::spy();

        app(ComparisonCommentaryService::class)->generate($analysis);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) {
            if ($message !== 'Comparison commentary saved') {
                return false;
            }
            $json = json_encode($context, JSON_UNESCAPED_UNICODE);

            return str_contains($json, 'forbidden_word') && ! str_contains($json, '懸念') && ! str_contains($json, 'A社') && ! str_contains($json, '具体的に書いています');
        })->once();
    }

    // ------------------------------------------------------------------ CT-1への戻り方 / スライド(CU-5)

    public function test_each_side_falls_back_to_the_ct1_sentence_and_the_note_appears_only_when_the_ai_sentence_is_used(): void
    {
        $companies = [
            ['name' => '自社', 'matched' => 5, 'total' => 24, 'is_self' => true, 'material_sufficient' => true],
            ['name' => 'A社', 'matched' => 3, 'total' => 24, 'is_self' => false, 'material_sufficient' => true],
        ];
        $axes = [['name' => '活動的魅力', 'caption' => null, 'denominator' => 4, 'self_count' => 3, 'competitor_counts' => [2], 'self_gap' => false]];
        $composer = new ComparisonStrengthWeaknessComposer;
        $ct1 = $composer->compose($companies, $axes, true);
        $this->assertFalse($ct1['ai_used']);

        // 依頼CV: 確認を通ったAIの強みも使う(依頼CUAの「強みは使わない」は、依頼CVで置き換えた)。
        $both = $composer->compose($companies, $axes, true, ['strength' => ['sentence' => 'AIの強みの一文です。'], 'weakness' => ['sentence' => 'AIの弱みの一文です。']]);
        $this->assertSame('AIの強みの一文です。', $both['strength']);
        $this->assertSame('AIの弱みの一文です。', $both['weakness']);
        $this->assertTrue($both['ai_used']);

        $weakOnly = $composer->compose($companies, $axes, true, ['strength' => null, 'weakness' => ['sentence' => 'AIの弱みの一文です。']]);
        $this->assertSame($ct1['strength'], $weakOnly['strength']);
        $this->assertSame('AIの弱みの一文です。', $weakOnly['weakness']);
        $this->assertTrue($weakOnly['ai_used']);

        $none = $composer->compose($companies, $axes, true, ['strength' => null, 'weakness' => null, 'job_failed' => 'x']);
        $this->assertSame($ct1['strength'], $none['strength']);
        $this->assertSame($ct1['weakness'], $none['weakness']);
        $this->assertFalse($none['ai_used']);

        // スライド: AIの文を使ったときだけ注記が1行出る。
        $generator = app(\App\Services\Report\AdminComparisonPptxGenerator::class);
        $base = ['self_company_name' => '自社', 'self_readable' => true, 'companies' => $companies, 'axes' => $axes, 'source_note' => 'n', 'page_number' => null];
        $note = (string) config('strength_weakness_sentence.note');
        $this->assertStringContainsString($note, $this->slideXml($generator->generate($base + ['strength_weakness' => $weakOnly])));
        $this->assertStringNotContainsString($note, $this->slideXml($generator->generate($base + ['strength_weakness' => $none])));
    }

    private function slideXml(string $pptxBytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cu-slide');
        file_put_contents($path, $pptxBytes);
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = html_entity_decode((string) $zip->getFromName('ppt/slides/slide1.xml'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $zip->close();
        @unlink($path);

        return $xml;
    }

    // ------------------------------------------------------------------ データ削除・ダウンロード

    public function test_the_result_file_is_deleted_together_with_the_company_data(): void
    {
        $analysis = $this->standard();
        $this->fakeProvider($this->goodOutput());
        app(ComparisonCommentaryService::class)->generate($analysis);
        $path = "analyses/{$analysis->id}/comparison_commentary.json";
        Storage::disk('analysis')->assertExists($path);

        $analysis->update(['status' => AnalysisStatus::Completed]);
        $company = LeadCompany::query()->findOrFail($analysis->project->lead_company_id);
        app(LeadCompanyDeletionService::class)->destroy($company, $company->company_name);

        Storage::disk('analysis')->assertMissing($path);
    }

    // ------------------------------------------------------------------ 測定コマンド

    public function test_the_measure_command_never_calls_the_ai_beyond_the_approved_number_of_calls(): void
    {
        $analysis = $this->standard();
        $fake = $this->fakeProvider($this->goodOutput());
        $ids = $analysis->websiteAnalyses()->with('website')->get()->sortBy(fn ($wa) => $wa->website->display_order)->pluck('id')->all();
        $args = ['--self' => "自社:{$ids[0]}", '--competitors' => "A社:{$ids[1]},B社:{$ids[2]},C社:{$ids[3]}"];

        // 既定(0)は何も呼ばない。--live を付けても、承認が無ければ呼ばずに止まる。
        $this->artisan('comparison-commentary:measure', $args + ['--live' => true])->assertFailed();
        $this->assertSame(0, $fake->calls);

        // --repeat は同じ材料でもう1回呼ぶので、1回の承認では足りない。
        $this->artisan('comparison-commentary:measure', $args + ['--live' => true, '--repeat' => true, '--max-ai-calls' => 1])->assertFailed();
        $this->assertSame(0, $fake->calls);

        // --live を付けなければ、承認があってもAIは呼ばない(材料と見込み回数だけ)。
        $this->artisan('comparison-commentary:measure', $args + ['--max-ai-calls' => 5])->assertSuccessful();
        $this->assertSame(0, $fake->calls);

        // 承認された回数ぶんだけは呼ぶ。
        $this->artisan('comparison-commentary:measure', $args + ['--live' => true, '--repeat' => true, '--max-ai-calls' => 2])->assertSuccessful();
        $this->assertSame(2, $fake->calls);
        // 測定は結果のファイルを作らない。
        Storage::disk('analysis')->assertMissing("analyses/{$analysis->id}/comparison_commentary.json");

        // 判定(--judge)が必要な会社があり、承認が足りないときも、1回も呼ばない。
        BrandWheelAnalysisResult::query()->where('website_analysis_id', $ids[1])->update(['status' => 'pending']);
        Http::preventStrayRequests();
        $this->artisan('comparison-commentary:measure', $args + ['--judge' => true, '--live' => true, '--max-ai-calls' => 1])->assertFailed();
        $this->assertSame(2, $fake->calls);
        Http::assertNothingSent();
    }
}
