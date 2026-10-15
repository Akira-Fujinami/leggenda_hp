<?php

namespace Tests\Feature\ComparisonCommentary;

use App\Enums\AnalysisKind;
use App\Enums\AnalysisStatus;
use App\Enums\WebsiteAnalysisStatus;
use App\Jobs\Analysis\FinalizeAnalysisJob;
use App\Jobs\GenerateStrengthWeaknessSentenceJob;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Admin\LeadCompanyDeletionService;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\ComparisonCommentary\ComparisonCommentaryException;
use App\Services\StrengthWeaknessSentence\OpenAiStrengthWeaknessSentenceProvider;
use App\Services\StrengthWeaknessSentence\StrengthWeaknessSentenceDispatcher;
use App\Services\StrengthWeaknessSentence\StrengthWeaknessSentenceMaterialBuilder;
use App\Services\StrengthWeaknessSentence\StrengthWeaknessSentenceProvider;
use App\Services\StrengthWeaknessSentence\StrengthWeaknessSentenceProviderFactory;
use App\Services\StrengthWeaknessSentence\StrengthWeaknessSentenceService;
use App\Services\StrengthWeaknessSentence\StrengthWeaknessSentenceStore;
use App\Services\StrengthWeaknessSentence\StrengthWeaknessSentenceVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼CV: 強み・弱みの一文を、機械が選んだ「1項目・1社」から AI で書く。材料(CV-1)・サーバー側の確認(CV-3)・流れ(CV-5)・
 * 測定コマンド。根拠の文・企業名はすべて人工のもの。AIは呼ばず、固定の出力を返す偽物を使う。
 */
class StrengthWeaknessSentenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
        config(['strength_weakness_sentence.enabled' => true]);
    }

    // ------------------------------------------------------------------ 部品

    /** 根拠の文(人工)。誰の・どの項目かが分かる一文。 */
    private function evidence(string $who, string $key): string
    {
        return "{$who}は{$key}について具体的に書いています。";
    }

    /** @return list<array<string, mixed>> */
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
     * 自社1 + 競合N社の比較(競合の名前は 'A社' 'B社' …。4社以上のときは記号になる)。
     *
     * @param  list<string>  $self  自社が○の項目
     * @param  list<list<string>>  $competitors  競合ごとの○の項目
     */
    private function makeComparison(array $self, array $competitors, array $originChars = []): Analysis
    {
        $company = LeadCompany::factory()->create(['company_name' => '株式会社自社テスト']);
        $project = new Project(['name' => '比較']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_company_id = $company->id;
        $project->save();
        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Running, 'kind' => AnalysisKind::AdminComparison, 'skip_brand_wheel' => false]);

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

    /** 3社の標準の比較: 強み = パーパス(競合0社)、弱み = 社会貢献活動(A社・B社が○。取り上げる競合はA社)。 */
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
                'quote' => 'purposeについて具体的に書いています',
                'sentence' => '自社は「purposeについて具体的に書いています」と記載しており、競合3社では読み取れなかった内容を示しています。',
            ],
            'weakness' => [
                'quote' => 'social_contributionについて具体的に書いています',
                'sentence' => '社会貢献活動は自社サイトから読み取れませんでしたが、A社は「social_contributionについて具体的に書いています」と記載しています。',
            ],
        ];
    }

    /**
     * AIの偽物。固定の出力(または材料から作る出力)を返す。呼ばれた回数を数える。
     *
     * @param  array<string, mixed>|\Closure  $output
     */
    private function fakeProvider(array|\Closure $output): object
    {
        $provider = new class($output) implements StrengthWeaknessSentenceProvider
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

        app()->instance(StrengthWeaknessSentenceProviderFactory::class, new class($provider) extends StrengthWeaknessSentenceProviderFactory
        {
            public function __construct(private readonly StrengthWeaknessSentenceProvider $fake) {}

            public function make(): StrengthWeaknessSentenceProvider
            {
                return $this->fake;
            }
        });

        return $provider;
    }

    private function material(Analysis $analysis): array
    {
        return app(StrengthWeaknessSentenceMaterialBuilder::class)->build($analysis);
    }

    private function verify(string $side, array $output, Analysis $analysis): array
    {
        return app(StrengthWeaknessSentenceVerifier::class)->verify($side, $output, $this->material($analysis));
    }

    // ------------------------------------------------------------------ CV-1 材料

    public function test_the_material_has_the_one_item_and_the_one_company_the_ct_ua_rules_pick(): void
    {
        $material = $this->material($this->standard());

        $this->assertSame('ok', $material['status']);
        // 強み: 自社だけの強み(競合の○が半数未満)のうち、競合の○が最も少ないパーパス(0社)。自社の根拠の文は全文。
        $this->assertSame('purpose', $material['strength']['key']);
        $this->assertSame($this->evidence('自社', 'purpose'), $material['strength']['evidence']);
        $this->assertSame(3, $material['strength']['competitor_count']);
        $this->assertSame(0, $material['strength']['matched']);
        $this->assertNotSame('', $material['strength']['definition']);
        // 弱み: 自社の穴(自社×・競合の○が半数以上)のうち競合の○が最も多い社会貢献活動(A社・B社)。会社は、○の競合のうち表示順で最初のA社。
        $this->assertSame('social_contribution', $material['weakness']['key']);
        $this->assertSame('A社', $material['weakness']['company']);
        $this->assertSame($this->evidence('A社', 'social_contribution'), $material['weakness']['evidence']);
        $this->assertSame(2, $material['weakness']['matched']);
        $this->assertSame(3, $material['weakness']['competitor_count']);
        // プロンプトに載せる部分には、検証用の会社名の一覧を含めない。
        $visible = app(StrengthWeaknessSentenceMaterialBuilder::class)->visible($material);
        $this->assertArrayNotHasKey('competitors', $visible);
        $this->assertArrayNotHasKey('self_names', $visible);
    }

    public function test_exactly_half_is_used_for_the_strength_when_there_is_no_only_strength_and_the_company_is_the_first_circle_in_table_order(): void
    {
        // 競合4社(記号)。自社○の project_initiative は競合2社(半数ちょうど)。自社×の social_contribution は競合C・Dが○ → 最初はC。
        $analysis = $this->makeComparison(['project_initiative'], [
            ['project_initiative'],
            ['project_initiative'],
            ['social_contribution'],
            ['social_contribution'],
        ]);

        $material = $this->material($analysis);

        $this->assertSame('project_initiative', $material['strength']['key']);
        $this->assertSame(2, $material['strength']['matched']);
        $this->assertSame('social_contribution', $material['weakness']['key']);
        $this->assertSame('C', $material['weakness']['company_label']);
        $this->assertSame('競合C', $material['weakness']['company']);
        $this->assertTrue($material['symbol_mode']);
    }

    public function test_a_side_without_a_target_is_null_and_when_both_are_null_nothing_is_built_and_the_ai_is_not_called(): void
    {
        // 自社○の項目はすべて競合も○(半数以上)で強みが無く、自社×の項目に競合の○が無い → 対象なし。
        $analysis = $this->makeComparison(['purpose'], [['purpose'], ['purpose']]);
        $fake = $this->fakeProvider($this->goodOutput());

        $this->assertSame('no_target', $this->material($analysis)['reason']);

        $result = app(StrengthWeaknessSentenceService::class)->generate($analysis);

        $this->assertSame(0, $fake->calls);
        $this->assertSame('no_target', $result['skipped']);
        $this->assertNull($result['strength']);
        $this->assertNull($result['weakness']);
    }

    public function test_when_only_one_side_has_a_target_only_that_side_is_asked_and_the_other_stays_ct1(): void
    {
        // 強みの対象は無く(自社○の項目は競合全員が○)、弱みの対象はある。
        $analysis = $this->makeComparison(['purpose'], [['purpose', 'social_contribution'], ['purpose', 'social_contribution']]);
        $fake = $this->fakeProvider($this->goodOutput());

        $material = $this->material($analysis);
        $this->assertNull($material['strength']);
        $this->assertNotNull($material['weakness']);

        $result = app(StrengthWeaknessSentenceService::class)->generate($analysis);

        $this->assertSame(1, $fake->calls);
        $this->assertNull($fake->materials[0]['strength']);
        $this->assertNull($result['strength']);
        $this->assertSame([], $result['rejected']['strength'], '対象が無い側は確認しない');
        $this->assertNotNull($result['weakness']);
    }

    // ------------------------------------------------------------------ CV-3 サーバー側の確認

    public function test_a_sentence_that_meets_every_check_is_used_with_the_quote(): void
    {
        $analysis = $this->standard();

        foreach (['strength', 'weakness'] as $side) {
            $checked = $this->verify($side, $this->goodOutput()[$side], $analysis);
            $this->assertSame([], $checked['reasons'], $side);
            $this->assertTrue($checked['ok']);
            $this->assertSame($this->goodOutput()[$side]['quote'], $checked['value']['quote']);
        }
    }

    /**
     * @return array<string, array{0: string, 1: callable(array): array, 2: string}>
     */
    public static function brokenOutputs(): array
    {
        $replace = fn (array $changes) => fn (array $o) => array_replace($o, $changes);

        return [
            '会社名だけの引用(弱み)' => ['weakness', $replace(['quote' => 'A社は', 'sentence' => '社会貢献活動は自社サイトから読み取れませんでしたが、A社は「A社は」と記載しています。']), 'quote_company_name_only'],
            '会社名だけの引用(強み・自社名の言い換え)' => ['strength', $replace(['quote' => '自社テストは', 'sentence' => '自社は「自社テストは」と記載しています。']), 'quote_company_name_only'],
            '会社名と助詞だけで8字に満たない残り' => ['weakness', $replace(['quote' => 'A社は具体的に', 'sentence' => '社会貢献活動は自社サイトから読み取れませんでしたが、A社は「A社は具体的に」と記載しています。']), 'quote_company_name_only'],
            'quoteが根拠の文に無い(強み)' => ['strength', $replace(['quote' => '材料に無い言葉をここに書きます', 'sentence' => '自社は「材料に無い言葉をここに書きます」と記載しています。']), 'quote_not_in_evidence'],
            'quoteが根拠の文に無い(弱み・競合でなく自社の根拠)' => ['weakness', $replace(['quote' => '自社はpurposeについて具体的に書いています', 'sentence' => 'A社は「自社はpurposeについて具体的に書いています」と記載していますが、自社サイトから読み取れませんでした。']), 'quote_not_in_evidence'],
            'quoteが短い' => ['strength', $replace(['quote' => '具体的に書いてい', 'sentence' => '自社は「具体的に書いてい」と記載しています。']), 'quote_length'],
            'quoteが長い' => ['weakness', $replace(['quote' => 'A社はsocial_contributionについて具体的に書いています', 'sentence' => 'A社は「A社はsocial_contributionについて具体的に書いています」と記載していますが、自社サイトから読み取れませんでした。']), 'quote_length'],
            'quoteが「は」で終わる' => ['strength', $replace(['quote' => '自社はpurposeについては', 'sentence' => '自社は「自社はpurposeについては」と記載しています。']), 'quote_bad_ending'],
            'quoteが「では」で終わる' => ['weakness', $replace(['quote' => 'social_contributionについて具体的に書いています。では', 'sentence' => '自社サイトから読み取れませんでしたが、A社は「social_contributionについて具体的に書いています。では」と記載しています。']), 'quote_bad_ending'],
            'quoteが「の」で終わる' => ['strength', $replace(['quote' => '自社はpurposeについて具体的の', 'sentence' => '自社は「自社はpurposeについて具体的の」と記載しています。']), 'quote_bad_ending'],
            '「」の中がquoteと違う' => ['strength', $replace(['sentence' => '自社は「purposeについて具体的な」内容を記載しています。']), 'bracketed_word_not_quote'],
            '「」が無い' => ['weakness', $replace(['sentence' => '社会貢献活動は自社サイトから読み取れませんでしたが、A社は記載しています。']), 'bracketed_word_not_quote'],
            '「」が2組ある' => ['strength', $replace(['sentence' => '自社は「purposeについて具体的に書いています」と「purposeについて具体的に書いています」を記載しています。']), 'bracketed_word_not_quote'],
            '渡していない企業名(強みに競合の名前)' => ['strength', $replace(['sentence' => 'A社と違い、自社は「purposeについて具体的に書いています」と記載しています。']), 'company_not_allowed'],
            '渡していない企業名(弱みに別の競合)' => ['weakness', $replace(['sentence' => '社会貢献活動は自社サイトから読み取れませんでしたが、A社とB社は「social_contributionについて具体的に書いています」と記載しています。']), 'company_not_allowed'],
            '材料に無い数字(強み)' => ['strength', $replace(['sentence' => '自社は「purposeについて具体的に書いています」と記載しており、競合777社では読み取れなかった内容です。']), 'number_not_in_material'],
            '材料に無い数字(弱み・全角)' => ['weakness', $replace(['sentence' => '社会貢献活動は自社サイトから読み取れませんでしたが、競合９９９社のA社は「social_contributionについて具体的に書いています」と記載しています。']), 'number_not_in_material'],
            '禁止の言葉(弱み・無い断定)' => ['weakness', $replace(['sentence' => '社会貢献活動は自社サイトには記載がありません。A社は「social_contributionについて具体的に書いています」と記載しています。']), 'forbidden_word'],
            '禁止の言葉(強み・評価)' => ['strength', $replace(['sentence' => '自社は「purposeについて具体的に書いています」と記載しており、優れた内容です。']), 'forbidden_word'],
            '禁止の言葉(強み・懸念)' => ['strength', $replace(['sentence' => '自社は「purposeについて具体的に書いています」と記載していますが、懸念が残ります。']), 'forbidden_word'],
            '100字超え' => ['strength', fn (array $o) => array_replace($o, ['sentence' => '自社は「purposeについて具体的に書いています」と記載しており、'.str_repeat('競合3社では読み取れなかった内容を示しています。', 4)]), 'too_long'],
            '文が空' => ['weakness', $replace(['sentence' => '']), 'empty_sentence'],
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

        // 保存される結果では、その側だけnull(依頼CT-1の文に戻る)。落ちた理由の符号は残り、文の中身は残らない。
        $this->fakeProvider([$side => $broken] + $good);
        $result = app(StrengthWeaknessSentenceService::class)->generate($analysis, true);
        $this->assertNull($result[$side]);
        $this->assertNotNull($result[$other]);
        $this->assertContains($reason, $result['rejected'][$side]);
        $this->assertStringNotContainsString('について具体的に書いています', json_encode($result['rejected'], JSON_UNESCAPED_UNICODE));
    }

    public function test_the_company_name_check_also_catches_the_name_without_the_legal_form_and_a_wrong_symbol(): void
    {
        $analysis = $this->standard();
        // 自社名「株式会社自社テスト」から法人格を除いた「自社テスト」だけの引用も、会社名だけとして落とす。
        $output = ['quote' => '自社テストの自社テスト', 'sentence' => '自社は「自社テストの自社テスト」と記載しています。'];
        $this->assertContains('quote_company_name_only', $this->verify('strength', $output, $analysis)['reasons']);

        // 記号で見出しを出す比較(競合4社)では、「競合X」のXが取り上げた競合の記号でなければ落とす。
        $four = $this->makeComparison(['project_initiative'], [['project_initiative'], ['project_initiative'], ['social_contribution'], ['social_contribution']]);
        $good = ['quote' => 'social_contributionについて具体的に書いています', 'sentence' => '社会貢献活動は自社サイトから読み取れませんでしたが、競合Cは「social_contributionについて具体的に書いています」と記載しています。'];
        $this->assertTrue($this->verify('weakness', $good, $four)['ok']);
        $wrong = ['sentence' => str_replace('競合C', '競合D', $good['sentence'])] + $good;
        $this->assertContains('company_not_allowed', $this->verify('weakness', $wrong, $four)['reasons']);
    }

    public function test_a_bracket_inside_the_quote_is_part_of_the_quote(): void
    {
        $material = [
            'strength' => ['key' => 'purpose', 'evidence' => '私たちは『挑戦』を大切にする文化があります。', 'competitor_count' => 3, 'matched' => 0], 'weakness' => null,
            'self_names' => ['自社'], 'competitors' => [['label' => 'A社', 'name' => 'A社', 'variants' => ['A社']]], 'symbol_mode' => false,
        ];
        $verifier = app(StrengthWeaknessSentenceVerifier::class);
        $quote = '私たちは『挑戦』を大切にする文化があります';

        $this->assertTrue($verifier->verify('strength', ['quote' => $quote, 'sentence' => "自社は「{$quote}」と記載しています。"], $material)['ok']);
        $this->assertContains('bracketed_word_not_quote', $verifier->verify('strength', ['quote' => $quote, 'sentence' => "自社は「私たちは」と『挑戦』を記載しています。"], $material)['reasons']);
    }

    // ------------------------------------------------------------------ 流れ(材料 → AI → 確認 → 保存)

    public function test_the_ai_is_called_once_for_both_sides_and_the_verified_result_is_stored(): void
    {
        $analysis = $this->standard();
        $fake = $this->fakeProvider($this->goodOutput());

        $result = app(StrengthWeaknessSentenceService::class)->generate($analysis);

        $this->assertSame(1, $fake->calls);
        $this->assertSame('purpose', $fake->materials[0]['strength']['key']);
        $this->assertSame($this->goodOutput()['strength']['sentence'], $result['strength']['sentence']);
        $this->assertSame($this->goodOutput()['weakness']['quote'], $result['weakness']['quote']);
        $this->assertSame(['strength' => 'purpose', 'weakness' => ['item' => 'social_contribution', 'company' => 'A社']], $result['targets']);
        Storage::disk('analysis')->assertExists("analyses/{$analysis->id}/strength_weakness_sentence.json");

        // 結果のファイルがあれば、AIは呼び直さない。
        app(StrengthWeaknessSentenceService::class)->generate($analysis);
        $this->assertSame(1, $fake->calls);
    }

    public function test_the_prompt_has_the_material_the_rules_and_the_quote_first_schema(): void
    {
        $provider = new OpenAiStrengthWeaknessSentenceProvider;
        $builder = app(StrengthWeaknessSentenceMaterialBuilder::class);
        $prompt = $provider->buildPrompt($builder->visible($builder->fromBase(app(\App\Services\ComparisonCommentary\ComparisonCommentaryMaterialBuilder::class)->build($this->standard()))));

        // 材料: 項目名・定義・社数・会社・根拠の文(全文)。
        $this->assertStringContainsString('パーパス', $prompt);
        $this->assertStringContainsString((string) config('brand_wheel.axes.will_activity.sub_element_definitions.purpose'), $prompt);
        $this->assertStringContainsString('競合3社のうち、この内容を読み取れたのは0社', $prompt);
        $this->assertStringContainsString($this->evidence('自社', 'purpose'), $prompt);
        $this->assertStringContainsString($this->evidence('A社', 'social_contribution'), $prompt);
        // 規則。
        $this->assertStringContainsString('100字以内', $prompt);
        $this->assertStringContainsString('10〜35字', $prompt);
        $this->assertStringContainsString('「マネーフォワードでは」', $prompt);
        $this->assertStringContainsString('読み取れませんでした', $prompt);
        foreach ((array) config('comparison_commentary.forbidden_words') as $word) {
            $this->assertStringContainsString((string) $word, $prompt, '禁止の言葉は依頼CUと同じ一覧をプロンプトにも載せる');
        }
        // 出力は quote → sentence の順(先に引用を決めさせる)。
        $schema = $provider->buildResponseSchema();
        $this->assertSame(['strength', 'weakness'], $schema['required']);
        $this->assertSame(['quote', 'sentence'], array_keys($schema['properties']['strength']['properties']));
        $this->assertFalse($schema['additionalProperties']);
    }

    public function test_a_company_with_thin_material_or_an_unreadable_judgment_is_skipped_without_the_ai(): void
    {
        $fake = $this->fakeProvider($this->goodOutput());

        $thin = $this->makeComparison(['purpose'], [['business_expansion'], ['project_initiative']], [2 => 100]);
        $this->assertSame('material_insufficient', app(StrengthWeaknessSentenceService::class)->generate($thin)['skipped']);

        $unreadable = $this->standard();
        BrandWheelAnalysisResult::query()->where('website_analysis_id', $unreadable->websiteAnalyses()->orderByDesc('id')->value('id'))->update(['status' => 'error']);
        $this->assertSame('competitor_unreadable', app(StrengthWeaknessSentenceService::class)->generate($unreadable)['skipped']);

        $this->assertSame(0, $fake->calls);
    }

    public function test_the_log_has_only_counts_and_reason_codes(): void
    {
        $analysis = $this->standard();
        $this->fakeProvider(['strength' => ['quote' => 'purposeについて具体的に書いています', 'sentence' => '自社は「purposeについて具体的に書いています」と記載していますが、懸念があります。']] + $this->goodOutput());
        Log::spy();

        app(StrengthWeaknessSentenceService::class)->generate($analysis);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) {
            if ($message !== 'Strength weakness sentence saved') {
                return false;
            }
            $json = json_encode($context, JSON_UNESCAPED_UNICODE);

            return str_contains($json, 'forbidden_word') && ! str_contains($json, '懸念') && ! str_contains($json, 'A社') && ! str_contains($json, '具体的に書いています');
        })->once();
    }

    // ------------------------------------------------------------------ CV-5 いつ実行するか

    public function test_the_default_of_enabled_is_false_for_the_sentence_and_for_the_machine_snippets(): void
    {
        $this->assertFalse((require base_path('config/strength_weakness_sentence.php'))['enabled']);
        $this->assertStringContainsString("env('STRENGTH_WEAKNESS_SENTENCE_ENABLED', false)", (string) file_get_contents(base_path('config/strength_weakness_sentence.php')));
        // 依頼CUAの一節の文は、既定で出さない(STRENGTH_WEAKNESS_DETAIL_ENABLEDの既定をfalseに戻した)。
        $this->assertFalse((require base_path('config/admin_comparison_pptx.php'))['strength_weakness_detail']['enabled']);
        $this->assertStringContainsString("env('STRENGTH_WEAKNESS_DETAIL_ENABLED', false)", (string) file_get_contents(base_path('config/admin_comparison_pptx.php')));
    }

    public function test_when_disabled_nothing_is_dispatched_nothing_is_waited_for_and_no_ai_is_called(): void
    {
        config(['strength_weakness_sentence.enabled' => false]);
        Queue::fake();
        $analysis = $this->standard();
        $fake = $this->fakeProvider($this->goodOutput());

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);

        Queue::assertNotPushed(GenerateStrengthWeaknessSentenceJob::class);
        Queue::assertPushed(FinalizeAnalysisJob::class);
        $this->assertSame(0, $fake->calls);
        $this->assertFalse(app(StrengthWeaknessSentenceDispatcher::class)->holdFinalization($analysis));
    }

    public function test_when_enabled_the_comparison_waits_for_the_job_and_finishes_after_it(): void
    {
        Queue::fake();
        $analysis = $this->standard();
        $fake = $this->fakeProvider($this->goodOutput());

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);

        Queue::assertPushed(GenerateStrengthWeaknessSentenceJob::class, 1);
        Queue::assertNotPushed(FinalizeAnalysisJob::class);

        (new GenerateStrengthWeaknessSentenceJob($analysis->id))->handle(app(StrengthWeaknessSentenceService::class), app(StrengthWeaknessSentenceDispatcher::class));

        $this->assertSame(1, $fake->calls);
        Queue::assertPushed(FinalizeAnalysisJob::class, 1);
        $this->assertTrue(app(StrengthWeaknessSentenceStore::class)->exists($analysis->id));
    }

    public function test_the_job_is_dispatched_only_after_every_company_has_finished(): void
    {
        Queue::fake();
        $analysis = $this->standard();
        $analysis->websiteAnalyses()->first()->update(['status' => WebsiteAnalysisStatus::Running]);

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);

        Queue::assertNotPushed(GenerateStrengthWeaknessSentenceJob::class);
        Queue::assertNotPushed(FinalizeAnalysisJob::class);
    }

    public function test_the_old_commentary_job_is_no_longer_started_by_the_comparison_flow(): void
    {
        Queue::fake();
        config(['comparison_commentary.enabled' => true, 'strength_weakness_sentence.enabled' => false]);
        $analysis = $this->standard();

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);

        Queue::assertNotPushed(\App\Jobs\GenerateComparisonCommentaryJob::class);
        Queue::assertPushed(FinalizeAnalysisJob::class, 1);
    }

    public function test_a_failing_job_leaves_the_ct1_sentence_and_the_comparison_still_finishes(): void
    {
        Queue::fake();
        $analysis = $this->standard();
        $this->fakeProvider(fn () => throw new \RuntimeException('想定外の失敗'));

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);
        Queue::assertNotPushed(FinalizeAnalysisJob::class);

        (new GenerateStrengthWeaknessSentenceJob($analysis->id))->handle(app(StrengthWeaknessSentenceService::class), app(StrengthWeaknessSentenceDispatcher::class));

        $stored = app(StrengthWeaknessSentenceStore::class)->read($analysis->id);
        $this->assertSame('RuntimeException', $stored['job_failed']);
        $this->assertNull($stored['strength']);
        $this->assertNull($stored['weakness']);
        Queue::assertPushed(FinalizeAnalysisJob::class, 1);
    }

    public function test_an_ai_error_is_recorded_and_the_comparison_still_finishes(): void
    {
        Queue::fake();
        $analysis = $this->standard();
        $this->fakeProvider(fn () => throw new ComparisonCommentaryException('AI_RATE_LIMITED', 'レート制限'));

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);
        (new GenerateStrengthWeaknessSentenceJob($analysis->id))->handle(app(StrengthWeaknessSentenceService::class), app(StrengthWeaknessSentenceDispatcher::class));

        $stored = app(StrengthWeaknessSentenceStore::class)->read($analysis->id);
        $this->assertSame('AI_RATE_LIMITED', $stored['skipped']);
        $this->assertNull($stored['strength']);
        Queue::assertPushed(FinalizeAnalysisJob::class, 1);
    }

    public function test_a_job_that_is_killed_by_the_worker_also_unblocks_the_comparison(): void
    {
        Queue::fake();
        $analysis = $this->standard();
        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);
        Queue::assertNotPushed(FinalizeAnalysisJob::class);

        (new GenerateStrengthWeaknessSentenceJob($analysis->id))->failed(new \RuntimeException('timeout'));

        $this->assertSame('RuntimeException', app(StrengthWeaknessSentenceStore::class)->read($analysis->id)['job_failed']);
        Queue::assertPushed(FinalizeAnalysisJob::class, 1);
    }

    public function test_the_wait_ends_after_the_configured_minutes_even_if_the_job_never_comes(): void
    {
        Queue::fake();
        $analysis = $this->standard();
        $analysis->websiteAnalyses()->toBase()->update(['updated_at' => now()->subMinutes(30)]);

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);

        Queue::assertNotPushed(GenerateStrengthWeaknessSentenceJob::class);
        Queue::assertPushed(FinalizeAnalysisJob::class, 1);
    }

    public function test_a_comparison_without_brand_wheel_and_a_regular_diagnosis_are_not_touched(): void
    {
        Queue::fake();
        $analysis = $this->standard();
        $analysis->update(['skip_brand_wheel' => true]);

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);
        Queue::assertNotPushed(GenerateStrengthWeaknessSentenceJob::class);

        $analysis->update(['skip_brand_wheel' => false, 'kind' => AnalysisKind::LeadDiagnosis]);
        $this->assertFalse(app(StrengthWeaknessSentenceDispatcher::class)->holdFinalization($analysis->fresh()));
    }

    public function test_the_result_file_is_deleted_together_with_the_company_data(): void
    {
        $analysis = $this->standard();
        $this->fakeProvider($this->goodOutput());
        app(StrengthWeaknessSentenceService::class)->generate($analysis);
        $path = "analyses/{$analysis->id}/strength_weakness_sentence.json";
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
        $this->artisan('strength-weakness-sentence:measure', $args + ['--live' => true])->assertFailed();
        $this->assertSame(0, $fake->calls);

        // --repeat は同じ材料でもう1回呼ぶので、1回の承認では足りない。
        $this->artisan('strength-weakness-sentence:measure', $args + ['--live' => true, '--repeat' => true, '--max-ai-calls' => 1])->assertFailed();
        $this->assertSame(0, $fake->calls);

        // --live を付けなければ、承認があってもAIは呼ばない(材料と見込み回数だけ)。
        $this->artisan('strength-weakness-sentence:measure', $args + ['--max-ai-calls' => 5])->assertSuccessful();
        $this->assertSame(0, $fake->calls);

        // 承認された回数ぶんだけは呼ぶ。測定は結果のファイルを作らない。
        $this->artisan('strength-weakness-sentence:measure', $args + ['--live' => true, '--repeat' => true, '--max-ai-calls' => 2])->assertSuccessful();
        $this->assertSame(2, $fake->calls);
        Storage::disk('analysis')->assertMissing("analyses/{$analysis->id}/strength_weakness_sentence.json");

        // 判定し直し(--rejudge)は全社×1回。承認が足りないときは、1回も呼ばずに止まる。
        Http::preventStrayRequests();
        $this->artisan('strength-weakness-sentence:measure', $args + ['--rejudge' => true, '--live' => true, '--max-ai-calls' => 4])->assertFailed();
        $this->assertSame(2, $fake->calls);
        Http::assertNothingSent();
    }
}
