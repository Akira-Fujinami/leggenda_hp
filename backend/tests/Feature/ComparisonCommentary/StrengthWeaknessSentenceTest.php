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
use App\Services\StrengthWeaknessSentence\StrengthWeaknessSentenceAssembler;
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
 * 依頼CV・CW: 強み・弱みの一文。機械が選んだ「1項目・1社」から、AIは「引用する一節」を選ぶだけで、文はサーバーが
 * 決まった形で組み立てる。材料・サーバー側の確認(引用)・文の組み立て・流れ・測定コマンド。
 * 根拠の文・企業名はすべて人工のもの。AIは呼ばず、固定の出力を返す偽物を使う。
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

    /** 標準の比較で、確認をすべて通る出力(AIが選ぶのは引用だけ。依頼CW)。 */
    private function goodOutput(): array
    {
        return [
            'strength_quote' => 'purposeについて具体的に書いています',
            'weakness_quote' => 'social_contributionについて具体的に書いています',
        ];
    }

    /** 標準の比較で、サーバーが組み立てる文。 */
    private function expectedStrength(): string
    {
        return 'パーパスは、競合3社のいずれからも読み取れなかった内容で、自社は「purposeについて具体的に書いています」と記載しています。';
    }

    private function expectedWeakness(): string
    {
        return '社会貢献活動は自社サイトから読み取れませんでしたが、A社は「social_contributionについて具体的に書いています」と記載しています。';
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
                return 'v3';
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

    private function verify(string $side, ?string $quote, Analysis $analysis): array
    {
        return app(StrengthWeaknessSentenceVerifier::class)->verify($side, $quote, $this->material($analysis));
    }

    // ------------------------------------------------------------------ CV-1 材料

    public function test_the_material_has_the_one_item_and_the_one_company_the_ct_ua_rules_pick(): void
    {
        $material = $this->material($this->standard());

        $this->assertSame('ok', $material['status']);
        // 強み: 自社だけの強み(競合の○が半数未満)のうち、競合の○が最も少ないパーパス(0社)。自社の根拠の文は全文。
        $this->assertSame('purpose', $material['strength']['key']);
        $this->assertSame('unique', $material['strength']['basis']);
        $this->assertSame($this->evidence('自社', 'purpose'), $material['strength']['evidence']);
        $this->assertSame(3, $material['strength']['competitor_count']);
        $this->assertSame(0, $material['strength']['matched']);
        $this->assertNotSame('', $material['strength']['definition']);
        // 弱み: 自社の穴(自社×・競合の○が半数以上)のうち競合の○が最も多い社会貢献活動(A社・B社)。会社は、○の競合のうち表示順で最初のA社。
        $this->assertSame('social_contribution', $material['weakness']['key']);
        $this->assertSame('A社', $material['weakness']['company']);
        $this->assertSame($this->evidence('A社', 'social_contribution'), $material['weakness']['evidence']);
        $this->assertSame(2, $material['weakness']['matched']);
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
        $this->assertSame('half', $material['strength']['basis']);
        $this->assertSame(2, $material['strength']['matched']);
        $this->assertSame('social_contribution', $material['weakness']['key']);
        $this->assertSame('C', $material['weakness']['company_label']);
        $this->assertSame('競合C', $material['weakness']['company']);
        $this->assertTrue($material['symbol_mode']);
    }

    public function test_a_side_without_a_target_is_null_and_when_both_are_null_nothing_is_built_and_the_ai_is_not_called(): void
    {
        // 自社○の項目(社会貢献活動 = 調査項目に結び付かない)は競合も○(半数以上)で強みが無く、自社×の項目に競合の○が無い → 対象なし。
        $analysis = $this->makeComparison(['social_contribution'], [['social_contribution'], ['social_contribution']]);
        $fake = $this->fakeProvider($this->goodOutput());

        $this->assertSame('no_target', $this->material($analysis)['reason']);

        $result = app(StrengthWeaknessSentenceService::class)->generate($analysis);

        $this->assertSame(0, $fake->calls);
        $this->assertSame('no_target', $result['skipped']);
        $this->assertNull($result['strength']);
        $this->assertNull($result['weakness']);
    }

    public function test_when_only_one_side_has_a_target_only_that_side_is_given_to_the_ai_and_the_other_stays_ct1(): void
    {
        // 強みの対象は無く(自社○の項目は競合全員が○で、調査項目にも結び付かない)、弱みの対象はある(パーパス。取り上げる競合はA社)。
        $analysis = $this->makeComparison(['social_contribution'], [['social_contribution', 'purpose'], ['social_contribution', 'purpose']]);
        $fake = $this->fakeProvider(['strength_quote' => null, 'weakness_quote' => 'purposeについて具体的に書いています']);

        $material = $this->material($analysis);
        $this->assertNull($material['strength']);
        $this->assertNotNull($material['weakness']);

        $result = app(StrengthWeaknessSentenceService::class)->generate($analysis);

        $this->assertSame(1, $fake->calls);
        $this->assertNull($fake->materials[0]['strength']);
        $this->assertNull($result['strength']);
        $this->assertSame([], $result['rejected']['strength'], '対象が無い側は確認しない');
        $this->assertSame('パーパスは自社サイトから読み取れませんでしたが、A社は「purposeについて具体的に書いています」と記載しています。', $result['weakness']['sentence']);
    }

    // ------------------------------------------------------------------ CW-2 サーバーが組み立てる文

    public function test_the_sentence_is_assembled_by_the_server_in_the_fixed_form_for_each_way_the_strength_was_chosen(): void
    {
        $assembler = app(StrengthWeaknessSentenceAssembler::class);
        $quote = 'ある制度の内容を表す一節です';

        // 自社だけの強み: 競合のうち○がM社のとき。
        $this->assertSame(
            "給与水準は、競合3社のうち1社からしか読み取れなかった内容で、自社は「{$quote}」と記載しています。",
            $assembler->assemble('strength', ['name' => '給与水準', 'basis' => 'unique', 'competitor_count' => 3, 'matched' => 1, 'survey' => null], $quote),
        );
        // M=0 のときは「いずれからも」。
        $this->assertSame(
            "給与水準は、競合3社のいずれからも読み取れなかった内容で、自社は「{$quote}」と記載しています。",
            $assembler->assemble('strength', ['name' => '給与水準', 'basis' => 'unique', 'competitor_count' => 3, 'matched' => 0, 'survey' => null], $quote),
        );
        // 半数ちょうども同じ形。
        $this->assertSame(
            "給与水準は、競合4社のうち2社からしか読み取れなかった内容で、自社は「{$quote}」と記載しています。",
            $assembler->assemble('strength', ['name' => '給与水準', 'basis' => 'half', 'competitor_count' => 4, 'matched' => 2, 'survey' => null], $quote),
        );
        // 求職者調査の割合で選んだ強み: 割合は小数第1位(.0は付けない)。競合との差は書かない。
        $this->assertSame(
            "求職者の23.8%が知りたい実現できるキャリアパスについて、自社は「{$quote}」と記載しています。",
            $assembler->assemble('strength', ['name' => '成長機会', 'basis' => 'survey', 'competitor_count' => 3, 'matched' => 3, 'survey' => ['name' => '実現できるキャリアパス', 'percentage' => 23.8]], $quote),
        );
        $this->assertStringContainsString('求職者の17%が知りたい', $assembler->assemble('strength', ['name' => 'x', 'basis' => 'survey', 'competitor_count' => 3, 'matched' => 3, 'survey' => ['name' => '企業の事業・サービス', 'percentage' => 17.0]], $quote));
        // 弱み: 競合は、企業名(記号表示のときは「競合C」)。自社は「読み取れませんでした」。
        $this->assertSame(
            "福利厚生は自社サイトから読み取れませんでしたが、競合Cは「{$quote}」と記載しています。",
            $assembler->assemble('weakness', ['name' => '福利厚生', 'company' => '競合C'], $quote),
        );
    }

    public function test_the_assembled_sentence_has_no_evaluation_or_what_is_conveyed_and_no_claim_of_absence(): void
    {
        $assembler = app(StrengthWeaknessSentenceAssembler::class);
        $quote = 'ある制度の内容を表す一節です';
        $sentences = [
            $assembler->assemble('strength', ['name' => '項目', 'basis' => 'unique', 'competitor_count' => 3, 'matched' => 1, 'survey' => null], $quote),
            $assembler->assemble('strength', ['name' => '項目', 'basis' => 'survey', 'competitor_count' => 3, 'matched' => 1, 'survey' => ['name' => '調査項目', 'percentage' => 10.5]], $quote),
            $assembler->assemble('weakness', ['name' => '項目', 'company' => 'A社'], $quote),
        ];

        foreach ($sentences as $sentence) {
            foreach (array_merge((array) config('comparison_commentary.forbidden_words'), ['伝わります', '具体像']) as $word) {
                $this->assertStringNotContainsString((string) $word, $sentence);
            }
        }
    }

    public function test_a_sentence_over_the_limit_makes_that_side_ct1(): void
    {
        $analysis = $this->standard();
        config(['strength_weakness_sentence.sentence_limit_chars' => 50]);

        $strength = $this->verify('strength', $this->goodOutput()['strength_quote'], $analysis);

        $this->assertFalse($strength['ok']);
        $this->assertSame(['sentence_too_long'], $strength['reasons']);

        config(['strength_weakness_sentence.sentence_limit_chars' => 100]);
        $this->assertTrue($this->verify('strength', $this->goodOutput()['strength_quote'], $analysis)['ok']);
    }

    // ------------------------------------------------------------------ CW-3 サーバー側の確認(引用)

    public function test_a_quote_that_meets_every_check_is_used_in_the_assembled_sentence(): void
    {
        $analysis = $this->standard();

        $strength = $this->verify('strength', $this->goodOutput()['strength_quote'], $analysis);
        $weakness = $this->verify('weakness', $this->goodOutput()['weakness_quote'], $analysis);

        $this->assertSame([], $strength['reasons']);
        $this->assertSame($this->expectedStrength(), $strength['value']['sentence']);
        $this->assertSame($this->goodOutput()['strength_quote'], $strength['value']['quote']);
        $this->assertSame([], $weakness['reasons']);
        $this->assertSame($this->expectedWeakness(), $weakness['value']['sentence']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> [側, quote, 落ちる理由]
     */
    public static function brokenQuotes(): array
    {
        return [
            '根拠の文に無い(強み)' => ['strength', '材料に無い言葉をここに書きます', 'quote_not_in_evidence'],
            '根拠の文に無い(弱み・自社の根拠)' => ['weakness', '自社はpurposeについて具体的に書いています', 'quote_not_in_evidence'],
            '短い' => ['strength', '具体的に書いてい', 'quote_length'],
            '長い' => ['weakness', 'A社はsocial_contributionについて具体的に書いています', 'quote_length'],
            '会社名だけ(弱み)' => ['weakness', 'A社は', 'quote_company_name_only'],
            '会社名だけ(強み・自社名の言い換え)' => ['strength', '自社テストは', 'quote_company_name_only'],
            '会社名と助詞だけで8字に満たない残り' => ['weakness', 'A社は具体的に', 'quote_company_name_only'],
            '末尾が「は」' => ['strength', '自社はpurposeについては', 'quote_bad_ending'],
            '末尾が「では」' => ['weakness', 'social_contributionについて具体的に書いてはでは', 'quote_bad_ending'],
            '末尾が「の」' => ['strength', '自社はpurposeについて具体的の', 'quote_bad_ending'],
            '末尾が「が」' => ['strength', 'purposeについて具体的に書いていますが', 'quote_bad_ending'],
            '末尾が「を」' => ['strength', 'purposeについて具体的に書くことを', 'quote_bad_ending'],
            '末尾が「に」' => ['strength', 'purposeについて具体的に書くことに', 'quote_bad_ending'],
            '末尾が「も」' => ['strength', 'purposeについて具体的に書くことも', 'quote_bad_ending'],
            '末尾が「て」(文の途中で切れる)' => ['weakness', 'social_contributionについて具体的に書いて', 'quote_bad_ending'],
            '末尾が「よう」(文の途中で切れる)' => ['weakness', 'social_contributionについて具体的に書けるよう', 'quote_bad_ending'],
            '末尾が「ため」(文の途中で切れる)' => ['strength', 'purposeについて具体的に書くため', 'quote_bad_ending'],
            '末尾が「など」' => ['strength', 'purposeについて具体的に書くなど', 'quote_bad_ending'],
            '感想の言葉「感じ」' => ['weakness', 'social_contributionについて具体的に書いていると感じた', 'quote_feeling_word'],
            '感想の言葉「痛感」' => ['weakness', 'social_contributionの大切さを痛感しました', 'quote_feeling_word'],
            '感想の言葉「思った」' => ['strength', 'purposeについて具体的に書こうと思った', 'quote_feeling_word'],
            '感想の言葉「印象」' => ['strength', 'purposeについて具体的な印象が強い', 'quote_feeling_word'],
            '「」を含む' => ['weakness', 'social_contributionについて「具体的」に書いています', 'quote_has_bracket'],
            '『』を含む' => ['strength', 'purposeについて『具体的』に書いています', 'quote_has_bracket'],
            '禁止の言葉' => ['weakness', 'social_contributionについて具体的に懸念がある書き方です', 'forbidden_word'],
            '空' => ['strength', '', 'empty_quote'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenQuotes')]
    public function test_a_quote_that_fails_any_check_makes_that_side_ct1_and_only_that_side(string $side, string $quote, string $reason): void
    {
        $analysis = $this->standard();
        $good = $this->goodOutput();

        $checked = $this->verify($side, $quote, $analysis);
        $this->assertFalse($checked['ok']);
        $this->assertContains($reason, $checked['reasons']);
        $this->assertNull($checked['value']);

        // もう片方は影響されない。
        $other = $side === 'strength' ? 'weakness' : 'strength';
        $this->assertTrue($this->verify($other, $good[$other.'_quote'], $analysis)['ok']);

        // 保存される結果では、その側だけnull(依頼CT-1の文に戻る)。落ちた理由の符号は残り、引用の中身は残らない。
        $this->fakeProvider([$side.'_quote' => $quote] + $good);
        $result = app(StrengthWeaknessSentenceService::class)->generate($analysis, true);
        $this->assertNull($result[$side]);
        $this->assertNotNull($result[$other]);
        $this->assertContains($reason, $result['rejected'][$side]);
        $this->assertStringNotContainsString('について', json_encode($result['rejected'], JSON_UNESCAPED_UNICODE));
    }

    public function test_the_company_name_check_also_catches_the_name_without_the_legal_form_and_symbols_are_written_as_competitor_and_symbol(): void
    {
        // 自社名「株式会社自社テスト」から法人格を除いた「自社テスト」だけの引用も、会社名だけとして落とす。
        $this->assertContains('quote_company_name_only', $this->verify('strength', '自社テストの自社テスト', $this->standard())['reasons']);

        // 記号で見出しを出す比較(競合4社)では、文の競合は「競合C」。
        $four = $this->makeComparison(['project_initiative'], [['project_initiative'], ['project_initiative'], ['social_contribution'], ['social_contribution']]);
        $ok = $this->verify('weakness', 'social_contributionについて具体的に書いています', $four);
        $this->assertTrue($ok['ok']);
        $this->assertStringContainsString('競合Cは「social_contributionについて具体的に書いています」', $ok['value']['sentence']);
    }

    public function test_a_quote_may_be_taken_across_spaces_and_width_differences_of_the_evidence(): void
    {
        $material = [
            'strength' => ['key' => 'purpose', 'name' => 'パーパス', 'basis' => 'unique', 'survey' => null, 'evidence' => '年間 ２０日の 有給休暇を、全員が取得できます。', 'competitor_count' => 3, 'matched' => 0],
            'weakness' => null, 'self_names' => ['自社'], 'competitors' => [['label' => 'A社', 'name' => 'A社', 'variants' => ['A社']]], 'symbol_mode' => false,
        ];

        $checked = app(StrengthWeaknessSentenceVerifier::class)->verify('strength', '年間20日の有給休暇を、全員が取得できます', $material);

        $this->assertTrue($checked['ok'], implode(',', $checked['reasons']));
    }

    // ------------------------------------------------------------------ 流れ(材料 → AI → 確認 → 保存)

    public function test_the_ai_is_called_once_for_both_sides_and_the_server_assembles_the_sentences_from_the_verified_quotes(): void
    {
        $analysis = $this->standard();
        $fake = $this->fakeProvider($this->goodOutput());

        $result = app(StrengthWeaknessSentenceService::class)->generate($analysis);

        $this->assertSame(1, $fake->calls);
        $this->assertSame('purpose', $fake->materials[0]['strength']['key']);
        $this->assertSame($this->expectedStrength(), $result['strength']['sentence']);
        $this->assertSame($this->expectedWeakness(), $result['weakness']['sentence']);
        $this->assertSame($this->goodOutput()['weakness_quote'], $result['weakness']['quote']);
        $this->assertSame(['strength' => 'purpose', 'strength_basis' => 'unique', 'weakness' => ['item' => 'social_contribution', 'company' => 'A社']], $result['targets']);
        Storage::disk('analysis')->assertExists("analyses/{$analysis->id}/strength_weakness_sentence.json");

        // 結果のファイルがあれば、AIは呼び直さない。
        app(StrengthWeaknessSentenceService::class)->generate($analysis);
        $this->assertSame(1, $fake->calls);
    }

    public function test_the_ai_output_is_only_the_quotes_and_a_sentence_written_by_the_ai_is_ignored(): void
    {
        $analysis = $this->standard();
        // AIが文を書いてきても(スキーマ違反)、使わない。使うのは、確認を通った引用から組み立てた文だけ。
        $this->fakeProvider($this->goodOutput() + ['strength' => ['sentence' => 'AIが書いた文です。'], 'sentence' => 'AIが書いた文です。']);

        $result = app(StrengthWeaknessSentenceService::class)->generate($analysis);

        $this->assertSame($this->expectedStrength(), $result['strength']['sentence']);
        $this->assertStringNotContainsString('AIが書いた', json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    public function test_the_prompt_asks_for_quotes_only_with_the_rules_and_no_example_sentence(): void
    {
        $provider = new OpenAiStrengthWeaknessSentenceProvider;
        $builder = app(StrengthWeaknessSentenceMaterialBuilder::class);
        $prompt = $provider->buildPrompt($builder->visible($builder->fromBase(app(\App\Services\ComparisonCommentary\ComparisonCommentaryMaterialBuilder::class)->build($this->standard()))));

        // 材料: 項目名・定義・根拠の文(全文)。
        $this->assertStringContainsString('パーパス', $prompt);
        $this->assertStringContainsString((string) config('brand_wheel.axes.will_activity.sub_element_definitions.purpose'), $prompt);
        $this->assertStringContainsString($this->evidence('自社', 'purpose'), $prompt);
        $this->assertStringContainsString($this->evidence('A社', 'social_contribution'), $prompt);
        // 規則: 引用だけ選ぶ。文は書かせない。長さ・選ばないもの。
        $this->assertStringContainsString('文は書かないでください', $prompt);
        $this->assertStringContainsString('10〜35字', $prompt);
        $this->assertStringContainsString('会社名だけの部分', $prompt);
        $this->assertStringContainsString('個人の感想・心情', $prompt);
        $this->assertStringContainsString('文の途中で切れる部分', $prompt);
        foreach ((array) config('strength_weakness_sentence.quote_feeling_words') as $word) {
            $this->assertStringContainsString((string) $word, $prompt);
        }
        foreach ((array) config('strength_weakness_sentence.quote_bad_endings') as $ending) {
            $this->assertStringContainsString((string) $ending, $prompt);
        }
        foreach ((array) config('comparison_commentary.forbidden_words') as $word) {
            $this->assertStringContainsString((string) $word, $prompt, '禁止の言葉は依頼CUと同じ一覧をプロンプトにも載せる');
        }
        // 文の例は書かない(引用の良い例・悪い例だけ)。「求職者に何が伝わるか」の一言も、{QUOTE}の目印も無い。
        $this->assertStringNotContainsString('記載しています', $prompt);
        $this->assertStringNotContainsString('伝わります', $prompt);
        $this->assertStringNotContainsString('具体像', $prompt);
        $this->assertStringNotContainsString('{QUOTE}', $prompt);
        $this->assertStringContainsString('良い例', $prompt);
        $this->assertStringContainsString('悪い例', $prompt);
        // 出力は引用だけ(nullを許す)。
        $schema = $provider->buildResponseSchema();
        $this->assertSame(['strength_quote', 'weakness_quote'], $schema['required']);
        $this->assertSame(['string', 'null'], $schema['properties']['strength_quote']['type']);
        $this->assertArrayNotHasKey('sentence', $schema['properties']);
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

    public function test_the_log_has_only_counts_reason_codes_and_the_basis(): void
    {
        $analysis = $this->standard();
        $this->fakeProvider(['strength_quote' => 'purposeについて懸念がある書き方です'] + $this->goodOutput());
        Log::spy();

        app(StrengthWeaknessSentenceService::class)->generate($analysis);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) {
            if ($message !== 'Strength weakness sentence saved') {
                return false;
            }
            $json = json_encode($context, JSON_UNESCAPED_UNICODE);

            return str_contains($json, '"strength_basis":"unique"') && str_contains($json, 'forbidden_word') && str_contains($json, 'quote_not_in_evidence')
                && ! str_contains($json, '懸念') && ! str_contains($json, 'A社') && ! str_contains($json, '具体的に書いています');
        })->once();
    }

    // ------------------------------------------------------------------ CVA-2 強みの選び方(求職者調査の割合)

    public function test_the_unique_strength_and_exactly_half_come_before_the_survey_basis(): void
    {
        $unique = $this->material($this->standard());
        $this->assertSame('unique', $unique['strength']['basis']);
        $this->assertNull($unique['strength']['survey']);

        // 競合4社(記号)で、自社○の項目の競合の○が2社(半数ちょうど)。
        $half = $this->material($this->makeComparison(['project_initiative'], [['project_initiative'], ['project_initiative'], ['brand_recognition'], ['social_contribution']]));
        $this->assertSame('half', $half['strength']['basis']);
    }

    public function test_when_there_is_no_unique_or_half_item_the_item_tied_to_the_survey_item_with_the_highest_percentage_is_chosen(): void
    {
        // 自社○: purpose(調査: 企業のビジョンや理念 16.0%) と business_expansion(企業の事業・サービス 17.0%)。競合3社のうち purpose は3社、business_expansion は2社が○。
        $material = $this->material($this->makeComparison(['purpose', 'business_expansion'], [
            ['purpose', 'business_expansion'],
            ['purpose', 'business_expansion'],
            ['purpose'],
        ]));

        $this->assertSame('survey', $material['strength']['basis']);
        $this->assertSame('business_expansion', $material['strength']['key']);
        $this->assertSame('企業の事業・サービス', $material['strength']['survey']['name']);
        $this->assertSame(17.0, $material['strength']['survey']['percentage']);
        $this->assertSame($this->evidence('自社', 'business_expansion'), $material['strength']['evidence'], '根拠の文は自社のもの');
    }

    public function test_with_the_same_percentage_the_item_with_fewer_competitors_comes_first_then_the_table_order(): void
    {
        // business_expansion と competitiveness は、どちらも 企業の事業・サービス(17.0%)。
        $fewer = $this->material($this->makeComparison(['business_expansion', 'competitiveness'], [
            ['business_expansion', 'competitiveness'],
            ['business_expansion', 'competitiveness'],
            ['business_expansion'],
        ]));
        $this->assertSame('survey', $fewer['strength']['basis']);
        $this->assertSame('competitiveness', $fewer['strength']['key'], '○の競合が少ない項目(2社)が、3社の項目より先');

        $order = $this->material($this->makeComparison(['business_expansion', 'competitiveness'], [
            ['business_expansion', 'competitiveness'],
            ['business_expansion', 'competitiveness'],
            ['business_expansion', 'competitiveness'],
        ]));
        $this->assertSame('business_expansion', $order['strength']['key'], '同数なら、比較表の並び順で先の項目');
    }

    public function test_an_item_without_a_survey_item_is_never_chosen_for_the_survey_basis_and_then_the_strength_stays_ct1(): void
    {
        // 社会貢献活動は、調査項目に結び付かない(survey_option=null)。自社が○でも、強みには選ばれない。
        $material = $this->material($this->makeComparison(['social_contribution'], [['social_contribution', 'purpose'], ['social_contribution', 'purpose'], ['social_contribution', 'purpose']]));

        $this->assertNull($material['strength'], '選べないときは、AIに渡さずCT-1');
        $this->assertSame('purpose', $material['weakness']['key']);
    }

    public function test_the_survey_basis_sentence_is_assembled_with_the_percentage_and_the_survey_item(): void
    {
        $analysis = $this->makeComparison(['purpose', 'business_expansion'], [['purpose', 'business_expansion'], ['purpose', 'business_expansion'], ['purpose']]);
        $this->fakeProvider(['strength_quote' => 'business_expansionについて具体的に書いています', 'weakness_quote' => null]);

        $result = app(StrengthWeaknessSentenceService::class)->generate($analysis);

        $this->assertSame('求職者の17%が知りたい企業の事業・サービスについて、自社は「business_expansionについて具体的に書いています」と記載しています。', $result['strength']['sentence']);
        $this->assertSame('survey', $result['targets']['strength_basis']);
    }

    public function test_the_basis_is_stored_and_none_when_the_strength_could_not_be_chosen(): void
    {
        $fake = $this->fakeProvider($this->goodOutput());
        $service = app(StrengthWeaknessSentenceService::class);

        $survey = $service->generate($this->makeComparison(['purpose', 'business_expansion'], [['purpose', 'business_expansion'], ['purpose', 'business_expansion'], ['purpose']]));
        $this->assertSame('survey', $survey['targets']['strength_basis']);

        $none = $service->generate($this->makeComparison(['social_contribution'], [['social_contribution', 'purpose'], ['social_contribution', 'purpose'], ['social_contribution', 'purpose']]));
        $this->assertSame('none', $none['targets']['strength_basis']);
        $this->assertNull($none['strength']);
        $this->assertSame(2, $fake->calls);
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
