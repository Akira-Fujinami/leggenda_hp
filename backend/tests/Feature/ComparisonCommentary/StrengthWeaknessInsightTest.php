<?php

namespace Tests\Feature\ComparisonCommentary;

use App\Enums\AnalysisKind;
use App\Enums\AnalysisStatus;
use App\Enums\PageType;
use App\Enums\WebsiteAnalysisStatus;
use App\Jobs\Analysis\FinalizeAnalysisJob;
use App\Jobs\GenerateStrengthWeaknessInsightJob;
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
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\Report\ComparisonStrengthWeaknessComposer;
use App\Services\StrengthWeaknessInsight\AnthropicStrengthWeaknessInsightProvider;
use App\Services\StrengthWeaknessInsight\StrengthWeaknessInsightDispatcher;
use App\Services\StrengthWeaknessInsight\StrengthWeaknessInsightMaterialBuilder;
use App\Services\StrengthWeaknessInsight\StrengthWeaknessInsightProvider;
use App\Services\StrengthWeaknessInsight\StrengthWeaknessInsightProviderFactory;
use App\Services\StrengthWeaknessInsight\StrengthWeaknessInsightService;
use App\Services\StrengthWeaknessInsight\StrengthWeaknessInsightStore;
use App\Services\StrengthWeaknessInsight\StrengthWeaknessInsightVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTopMessageFixtures;
use Tests\TestCase;

/**
 * 依頼DB: 強み・弱みを、Claudeが根拠つきの2行で書く(社内用・下書き)。材料・Anthropicの呼び出し口・サーバー側の確認(事実の部分だけ)・
 * 流れ・スライド・測定コマンド。根拠の文・企業名はすべて人工のもの。AIは呼ばず、固定の出力を返す偽物か、Http::fake を使う。
 */
class StrengthWeaknessInsightTest extends TestCase
{
    use MakesTopMessageFixtures;
    use RefreshDatabase;

    private const FAKE_KEY = 'sk-ant-test-key-not-real';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
        config([
            'strength_weakness_insight.enabled' => true,
            'strength_weakness_insight.model' => 'test-model',
            'services.anthropic.api_key' => self::FAKE_KEY,
        ]);
    }

    // ------------------------------------------------------------------ 部品

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
     * 自社1 + 競合N社の比較(競合の名前は 'A社' 'B社' …)。
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

    /** 3社の標準の比較: 自社だけの項目 = purpose、自社に無くA社・B社が○の項目 = social_contribution。 */
    private function standard(): Analysis
    {
        return $this->makeComparison(['purpose', 'business_expansion', 'project_initiative'], [
            ['business_expansion', 'project_initiative', 'social_contribution'],
            ['project_initiative', 'social_contribution'],
            ['project_initiative'],
        ]);
    }

    /** 標準の比較で、確認をすべて通るClaudeの出力。 */
    private function goodOutput(): array
    {
        return [
            'strength' => [
                'text' => '自社は「purposeについて具体的に書いています」と明記しており、求職者には判断材料になりそうです。',
                'quotes' => [['company' => '自社サイト', 'text' => 'purposeについて具体的に書いています']],
            ],
            'weakness' => [
                'text' => '競合のA社は「social_contributionについて具体的に書いています」と載せていますが、自社からは同様の記述が読み取れません。',
                'quotes' => [['company' => 'A社', 'text' => 'social_contributionについて具体的に書いています']],
            ],
        ];
    }

    /**
     * Claudeの偽物。固定の出力(または材料から作る出力)を返す。呼ばれた回数と渡された材料を持つ。
     *
     * @param  array<string, mixed>|\Closure  $output
     */
    private function fakeProvider(array|\Closure $output): object
    {
        $provider = new class($output) implements StrengthWeaknessInsightProvider
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

        app()->instance(StrengthWeaknessInsightProviderFactory::class, new class($provider) extends StrengthWeaknessInsightProviderFactory
        {
            public function __construct(private readonly StrengthWeaknessInsightProvider $fake) {}

            public function make(): StrengthWeaknessInsightProvider
            {
                return $this->fake;
            }
        });

        return $provider;
    }

    private function material(Analysis $analysis): array
    {
        return app(StrengthWeaknessInsightMaterialBuilder::class)->build($analysis);
    }

    private function verify(string $side, mixed $candidate, ?Analysis $analysis = null): array
    {
        return app(StrengthWeaknessInsightVerifier::class)->verify($side, $candidate, $this->material($analysis ?? $this->standard()));
    }

    private function slideXml(string $pptxBytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'db-slide');
        file_put_contents($path, $pptxBytes);
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = html_entity_decode((string) $zip->getFromName('ppt/slides/slide1.xml'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $zip->close();
        @unlink($path);

        return $xml;
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} */
    private function wheelData(): array
    {
        $companies = [
            ['name' => '自社', 'matched' => 5, 'total' => 24, 'is_self' => true, 'material_sufficient' => true],
            ['name' => 'A社', 'matched' => 3, 'total' => 24, 'is_self' => false, 'material_sufficient' => true],
        ];
        $axes = [['name' => '活動的魅力', 'caption' => null, 'denominator' => 4, 'self_count' => 3, 'competitor_counts' => [2], 'self_gap' => false]];

        return [$companies, $axes];
    }

    // ------------------------------------------------------------------ DB-1 Claudeを呼ぶ口

    public function test_the_default_of_enabled_is_false_and_the_model_has_no_default_in_the_code(): void
    {
        $source = (string) file_get_contents(base_path('config/strength_weakness_insight.php'));

        $this->assertFalse((require base_path('config/strength_weakness_insight.php'))['enabled']);
        $this->assertStringContainsString("env('STRENGTH_WEAKNESS_INSIGHT_ENABLED', false)", $source);
        $this->assertStringContainsString("env('STRENGTH_WEAKNESS_INSIGHT_MODEL')", $source, 'モデル名は env で渡す(既定値をコードに書かない)');
        $this->assertStringNotContainsString("STRENGTH_WEAKNESS_INSIGHT_MODEL',", $source);
        $this->assertStringContainsString("env('ANTHROPIC_API_KEY')", (string) file_get_contents(base_path('config/services.php')));
    }

    public function test_the_request_has_the_model_the_output_limit_and_the_key_header_from_config_and_no_temperature_unless_configured(): void
    {
        config(['strength_weakness_insight.max_output_tokens' => 1234, 'strength_weakness_insight.temperature' => null]);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'thinking', 'thinking' => '…'], ['type' => 'text', 'text' => json_encode($this->goodOutput(), JSON_UNESCAPED_UNICODE)]],
            'usage' => ['input_tokens' => 321, 'output_tokens' => 45],
        ])]);

        $outcome = (new AnthropicStrengthWeaknessInsightProvider)->write($this->material($this->standard()));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->header('x-api-key') === [self::FAKE_KEY]
                && $request->header('anthropic-version') === [(string) config('services.anthropic.version')]
                && $body['model'] === 'test-model'
                && $body['max_tokens'] === 1234
                && ! array_key_exists('temperature', $body)
                && $body['messages'][0]['role'] === 'user';
        });
        $this->assertSame($this->goodOutput(), $outcome['decoded'], '考える分のブロックは読まず、textだけを読む');
        $this->assertSame(321, $outcome['usage_input_tokens']);
        $this->assertSame(45, $outcome['usage_output_tokens']);
    }

    public function test_a_configured_temperature_is_sent(): void
    {
        config(['strength_weakness_insight.temperature' => 0.0]);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => "```json\n".json_encode($this->goodOutput(), JSON_UNESCAPED_UNICODE)."\n```"]], 'usage' => []])]);

        $outcome = (new AnthropicStrengthWeaknessInsightProvider)->write($this->material($this->standard()));

        Http::assertSent(fn (Request $request) => $request->data()['temperature'] === 0.0);
        $this->assertSame($this->goodOutput(), $outcome['decoded'], 'コードフェンスつきでも読める');
    }

    public function test_without_the_api_key_or_the_model_there_is_no_exception_no_request_and_the_sentence_stays_ct1(): void
    {
        Http::fake();
        $analysis = $this->standard();

        config(['services.anthropic.api_key' => null]);
        $result = app(StrengthWeaknessInsightService::class)->generate($analysis);
        $this->assertSame('ANTHROPIC_NOT_CONFIGURED', $result['skipped']);
        $this->assertNull($result['strength']);
        $this->assertNull($result['weakness']);
        $this->assertSame(0, $result['ai_calls']);

        app(StrengthWeaknessInsightStore::class)->delete($analysis->id);
        config(['services.anthropic.api_key' => self::FAKE_KEY, 'strength_weakness_insight.model' => null]);
        $result = app(StrengthWeaknessInsightService::class)->generate($analysis);
        $this->assertSame('MODEL_NOT_CONFIGURED', $result['skipped']);

        Http::assertNothingSent();
    }

    public function test_a_timeout_or_an_error_response_is_recorded_without_an_exception_and_the_key_is_never_in_the_result_or_the_log(): void
    {
        $analysis = $this->standard();
        config(['strength_weakness_insight.max_retries' => 1]);
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('timeout');
        });
        $logged = [];
        Log::shouldReceive('info')->andReturnUsing(function ($message, $context = []) use (&$logged) {
            $logged[] = [$message, $context];
        });

        $result = app(StrengthWeaknessInsightService::class)->generate($analysis);

        $this->assertSame('AI_UNAVAILABLE', $result['skipped']);
        $this->assertNull($result['strength']);
        $this->assertSame(2, $attempts, '接続失敗は、設定の再試行の回数まで');

        $stored = (string) Storage::disk('analysis')->get("analyses/{$analysis->id}/strength_weakness_insight.json");
        $this->assertStringNotContainsString(self::FAKE_KEY, $stored);
        $this->assertStringNotContainsString(self::FAKE_KEY, json_encode($logged, JSON_UNESCAPED_UNICODE));
    }

    public function test_an_authentication_error_is_recorded_without_an_exception_and_the_key_is_not_in_the_result(): void
    {
        $analysis = $this->standard();
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'x']], 401)]);

        $result = app(StrengthWeaknessInsightService::class)->generate($analysis);

        $this->assertSame('AI_AUTH_FAILED', $result['skipped']);
        $this->assertNull($result['strength']);
        $this->assertStringNotContainsString(self::FAKE_KEY, (string) Storage::disk('analysis')->get("analyses/{$analysis->id}/strength_weakness_insight.json"));
    }

    // ------------------------------------------------------------------ DB-2 材料

    public function test_the_material_has_the_judgments_the_evidence_of_the_right_side_the_scores_and_the_names(): void
    {
        $material = $this->material($this->standard());

        $this->assertSame('ok', $material['status']);
        $this->assertCount(24, $material['items'], '24項目の判定');
        $names = array_column($material['competitors'], 'name');
        $this->assertSame(['A社', 'B社', 'C社'], $names);
        $selfTexts = array_column($material['self_evidence'], 'text');
        $this->assertContains($this->evidence('自社', 'purpose'), $selfTexts, '自社が○の項目の自社の根拠の文');
        $competitorRows = array_filter($material['competitor_evidence'], fn (array $r) => $r['name'] !== '' );
        $companies = array_unique(array_column($competitorRows, 'company'));
        sort($companies);
        $this->assertSame(['A社', 'B社'], $companies, '自社が×で競合が○の項目の、競合の根拠の文(会社名つき)');
        $this->assertNotContains($this->evidence('自社', 'project_initiative'), array_column($material['competitor_evidence'], 'text'));
        $this->assertNotEmpty($material['axis_scores'], '領域別の点数');
        $this->assertContains('自社サイト', $material['self_names']);
        $this->assertNotNull($material['items'][0]['definition'] ?? null);
    }

    public function test_the_material_over_the_limit_drops_the_evidence_from_the_back_and_the_dropped_text_is_not_quotable(): void
    {
        $analysis = $this->standard();
        $full = $this->material($analysis);
        $total = array_sum(array_map(fn (array $r) => mb_strlen($r['text']), array_merge($full['self_evidence'], $full['competitor_evidence'])));
        config(['strength_weakness_insight.material_max_chars' => $total - 1]);

        $trimmed = $this->material($analysis);

        $this->assertSame(1, $trimmed['evidence_trimmed']);
        $this->assertCount(count($full['competitor_evidence']) - 1, $trimmed['competitor_evidence'], '競合の根拠の文を後ろから削る');
        $this->assertSame(count($full['self_evidence']), count($trimmed['self_evidence']));
        $last = end($full['competitor_evidence']);
        $this->assertNotContains($last['text'], array_merge(...array_values($trimmed['corpus']['competitors'])), '削った文は、引用してよい文字からも外す');
    }

    public function test_the_structure_of_the_own_recruit_top_is_in_the_material_and_the_navigation_text_is_quotable(): void
    {
        $analysis = $this->standard();
        $selfWa = $analysis->websiteAnalyses()->with('website')->get()->first(fn ($wa) => $wa->website->is_primary);
        $html = '<html><head><title>採用</title></head><body><header><nav><a href="/a">会社を知る</a><a href="/b">仕事を知る</a><a href="/c">エントリー受付中</a></nav></header><main><h2>働く人の声</h2><a href="/v">先輩社員のインタビュー</a></main></body></html>';
        $this->addHomepage($selfWa, 'https://site0.example.com/recruit/', $html, PageType::Recruit);

        $material = $this->material($analysis);

        $this->assertNotNull($material['structure']);
        $this->assertSame('会社を知る', $material['structure']['nav'][0]['text']);
        $this->assertContains('エントリー受付中', $material['corpus']['self']);
        $this->assertContains('3', $material['numbers'], 'ナビの項目数・順番は、文に出してよい数字');

        $candidate = [
            'text' => '採用情報の入口は「エントリー受付中」と3番目に出ており、求職者には見つけやすく見えます。',
            'quotes' => [['company' => '自社サイト', 'text' => 'エントリー受付中']],
        ];
        $this->assertTrue(app(StrengthWeaknessInsightVerifier::class)->verify('strength', $candidate, $material)['ok']);
    }

    public function test_a_company_with_thin_material_or_an_unreadable_judgment_is_skipped_without_the_ai(): void
    {
        $fake = $this->fakeProvider($this->goodOutput());
        $thin = $this->makeComparison(['purpose'], [['social_contribution']], [0 => 10, 1 => 100000]);

        $result = app(StrengthWeaknessInsightService::class)->generate($thin);

        $this->assertNotNull($result['skipped']);
        $this->assertNull($result['strength']);
        $this->assertSame(0, $fake->calls);
    }

    // ------------------------------------------------------------------ DB-3 プロンプト

    public function test_the_prompt_has_the_viewpoint_the_rules_and_the_material_and_no_example_sentence(): void
    {
        $material = $this->material($this->standard());
        $provider = new AnthropicStrengthWeaknessInsightProvider;

        $prompt = $provider->buildPrompt($material);

        $this->assertStringContainsString('求職者', $prompt);
        $this->assertStringContainsString('強み1つと弱み1つ', $prompt);
        $this->assertStringContainsString('読み取れない', $prompt, '「無い」と断定しない');
        $this->assertStringContainsString('全角'.config('strength_weakness_insight.text_max_chars').'字以内', $prompt);
        $this->assertStringContainsString('quotes', $prompt);
        $this->assertStringContainsString($this->evidence('自社', 'purpose'), $prompt, '材料(根拠の文)');
        $this->assertStringContainsString('A社', $prompt);
        foreach (['例:', '例）', '(例', '例えば'] as $marker) {
            $this->assertStringNotContainsString($marker, $prompt, '文の例は書かない');
        }
        $this->assertSame(['strength', 'weakness'], array_keys($provider->outputShape()));
    }

    // ------------------------------------------------------------------ DB-4 確認

    public function test_a_text_that_meets_every_check_is_used_with_its_quotes(): void
    {
        $strength = $this->verify('strength', $this->goodOutput()['strength']);
        $weakness = $this->verify('weakness', $this->goodOutput()['weakness']);

        $this->assertTrue($strength['ok']);
        $this->assertSame($this->goodOutput()['strength']['text'], $strength['value']['sentence']);
        $this->assertSame([['company' => '自社サイト', 'text' => 'purposeについて具体的に書いています']], $strength['value']['quotes']);
        $this->assertTrue($weakness['ok']);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: string}> */
    public static function badCandidates(): array
    {
        $quote = fn (string $company, string $text) => ['company' => $company, 'text' => $text];
        $self = $quote('自社サイト', 'purposeについて具体的に書いています');
        $a = $quote('A社', 'social_contributionについて具体的に書いています');

        return [
            '「」の言葉が quotes に無い' => ['strength', ['text' => '自社は「purposeについて具体的に書いています」と「別の言葉を引いています」と明記しています。', 'quotes' => [$self]], 'bracket_not_in_quotes'],
            '「」の数が合わない' => ['strength', ['text' => '自社は「purposeについて具体的に書いています」と明記し、「途中までです。', 'quotes' => [$self]], 'bracket_not_in_quotes'],
            '「」が1つも無い' => ['strength', ['text' => '自社は目的について具体的に書いているように見えます。', 'quotes' => [$self]], 'no_quote'],
            'quotes が材料に無い' => ['strength', ['text' => '自社は「存在しない言葉を引いてしまいました」と明記しています。', 'quotes' => [$quote('自社サイト', '存在しない言葉を引いてしまいました')]], 'quote_not_in_material'],
            'quotes が別の会社の材料にある' => ['weakness', ['text' => '競合のA社は「purposeについて具体的に書いています」と載せています。', 'quotes' => [$quote('A社', 'purposeについて具体的に書いています')]], 'quote_not_in_material'],
            'quotes の会社が比較に入っていない' => ['weakness', ['text' => '競合のZ社は「social_contributionについて具体的に書いています」と載せています。', 'quotes' => [$quote('Z社', 'social_contributionについて具体的に書いています')]], 'quote_company_unknown'],
            'quotes が短すぎる' => ['strength', ['text' => '自社は「pur」と明記しています。', 'quotes' => [$quote('自社サイト', 'pur')]], 'quote_too_short'],
            '文に出る会社名が比較に入っていない' => ['weakness', ['text' => '株式会社未知企業は「social_contributionについて具体的に書いています」と載せています。', 'quotes' => [$a]], 'unknown_company_name'],
            '材料に無い数字' => ['strength', ['text' => '自社は「purposeについて具体的に書いています」と明記し、他に57件あります。', 'quotes' => [$self]], 'number_not_in_material'],
            '長さの上限を超える' => ['strength', ['text' => '自社は「purposeについて具体的に書いています」と明記しており、'.str_repeat('求職者に伝わります。', 12), 'quotes' => [$self]], 'text_too_long'],
            '強すぎる評価の言葉' => ['weakness', ['text' => '競合のA社は「social_contributionについて具体的に書いています」と載せており、自社は最悪です。', 'quotes' => [$a]], 'strong_evaluation_word'],
            '形が違う' => ['strength', ['text' => 123], 'malformed'],
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badCandidates')]
    public function test_a_text_that_fails_a_fact_check_is_rejected_with_the_reason(string $side, array $candidate, string $reason): void
    {
        $result = $this->verify($side, $candidate);

        $this->assertFalse($result['ok']);
        $this->assertNull($result['value']);
        $this->assertContains($reason, $result['reasons']);
    }

    public function test_numbers_found_in_the_material_the_scores_and_the_company_count_are_allowed(): void
    {
        $self = ['company' => '自社サイト', 'text' => 'purposeについて具体的に書いています'];
        $text = fn (string $body) => ['text' => $body, 'quotes' => [$self]];

        $this->assertTrue($this->verify('strength', $text('自社は「purposeについて具体的に書いています」と明記し、競合3社のどこにも出てきません。'))['ok'], '競合の社数');
        $this->assertTrue($this->verify('strength', $text('自社は「purposeについて具体的に書いています」と明記し、判定の全24項目のうち読み取れた項目です。'))['ok'], '項目数');
        $this->assertFalse($this->verify('strength', $text('自社は「purposeについて具体的に書いています」と明記し、5000人が見ています。'))['ok']);
    }

    public function test_the_ct1_forbidden_word_list_is_not_used_but_the_strong_word_list_is(): void
    {
        config(['comparison_commentary.forbidden_words' => ['求職者']]);
        $this->assertTrue($this->verify('strength', $this->goodOutput()['strength'])['ok'], '依頼CUの禁止の言葉の一覧では落とさない');

        config(['strength_weakness_insight.strong_evaluation_words' => ['判断材料']]);
        $this->assertContains('strong_evaluation_word', $this->verify('strength', $this->goodOutput()['strength'])['reasons']);
    }

    public function test_the_interpretation_and_the_rewording_are_not_checked(): void
    {
        $candidate = [
            'text' => '「purposeについて具体的に書いています」という書き方から、目的を大切にする会社だと求職者には伝わりそうに見えます。',
            'quotes' => [['company' => '自社サイト', 'text' => 'purposeについて具体的に書いています']],
        ];

        $this->assertTrue($this->verify('strength', $candidate)['ok'], '言い換え・解釈は、確認の対象にしない');
    }

    public function test_a_quote_matches_across_spaces_and_width_differences(): void
    {
        $candidate = [
            'text' => '自社は「ｐｕｒｐｏｓｅについて 具体的に書いています」と明記しています。',
            'quotes' => [['company' => '自社サイト', 'text' => 'ｐｕｒｐｏｓｅについて 具体的に書いています']],
        ];

        $this->assertTrue($this->verify('strength', $candidate)['ok']);
    }

    // ------------------------------------------------------------------ 流れ(サービス・ジョブ)

    public function test_the_ai_is_called_once_with_the_material_and_only_the_verified_sides_are_kept(): void
    {
        $analysis = $this->standard();
        $output = $this->goodOutput();
        $output['weakness']['quotes'][0]['text'] = '材料にない言葉をここに置きます';
        $output['weakness']['text'] = '競合のA社は「材料にない言葉をここに置きます」と載せています。';
        $fake = $this->fakeProvider($output);

        $result = app(StrengthWeaknessInsightService::class)->generate($analysis);

        $this->assertSame(1, $fake->calls);
        $this->assertSame(1, $result['ai_calls']);
        $this->assertSame($this->goodOutput()['strength']['text'], $result['strength']['sentence']);
        $this->assertNull($result['weakness'], '落ちた側だけ null(CT-1の文)');
        $this->assertContains('quote_not_in_material', $result['rejected']['weakness']);
        $this->assertSame([], $result['rejected']['strength']);
        $this->assertSame('fake-model', $result['model']);
        $this->assertSame('v1', $result['prompt_version']);
        $this->assertArrayNotHasKey('candidates', $result, '確認前のAIの文は、ファイルに置かない');
        $stored = app(StrengthWeaknessInsightStore::class)->read($analysis->id);
        $this->assertSame($result['strength'], $stored['strength']);
    }

    public function test_an_existing_result_is_not_asked_again(): void
    {
        $analysis = $this->standard();
        $fake = $this->fakeProvider($this->goodOutput());
        $service = app(StrengthWeaknessInsightService::class);

        $service->generate($analysis);
        $service->generate($analysis);

        $this->assertSame(1, $fake->calls);
    }

    public function test_the_log_has_only_counts_and_reason_codes(): void
    {
        $analysis = $this->standard();
        $this->fakeProvider($this->goodOutput());
        $logged = [];
        Log::shouldReceive('info')->andReturnUsing(function ($message, $context = []) use (&$logged) {
            $logged[] = [$message, $context];
        });

        app(StrengthWeaknessInsightService::class)->generate($analysis);

        $json = json_encode($logged, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Strength weakness insight saved', $json);
        $this->assertStringNotContainsString('purpose', $json, '文・根拠の文はログに出さない');
        $this->assertStringNotContainsString('A社', $json);
    }

    public function test_when_disabled_nothing_is_dispatched_nothing_is_waited_for_and_no_ai_is_called(): void
    {
        config(['strength_weakness_insight.enabled' => false]);
        Queue::fake();
        $analysis = $this->standard();
        $fake = $this->fakeProvider($this->goodOutput());

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);

        Queue::assertNotPushed(GenerateStrengthWeaknessInsightJob::class);
        Queue::assertPushed(FinalizeAnalysisJob::class);
        $this->assertSame(0, $fake->calls);
        $this->assertFalse(app(StrengthWeaknessInsightDispatcher::class)->holdFinalization($analysis));
    }

    public function test_when_enabled_the_comparison_waits_for_the_job_and_finishes_after_it(): void
    {
        Queue::fake();
        $analysis = $this->standard();
        $fake = $this->fakeProvider($this->goodOutput());

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);

        Queue::assertPushed(GenerateStrengthWeaknessInsightJob::class, 1);
        Queue::assertNotPushed(FinalizeAnalysisJob::class);

        (new GenerateStrengthWeaknessInsightJob($analysis->id))->handle(app(StrengthWeaknessInsightService::class), app(StrengthWeaknessInsightDispatcher::class));

        $this->assertSame(1, $fake->calls);
        Queue::assertPushed(FinalizeAnalysisJob::class, 1);
        $this->assertTrue(app(StrengthWeaknessInsightStore::class)->exists($analysis->id));
    }

    public function test_the_job_is_dispatched_only_after_every_company_has_finished(): void
    {
        Queue::fake();
        $analysis = $this->standard();
        $analysis->websiteAnalyses()->first()->update(['status' => WebsiteAnalysisStatus::Running]);

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);

        Queue::assertNotPushed(GenerateStrengthWeaknessInsightJob::class);
        Queue::assertNotPushed(FinalizeAnalysisJob::class);
    }

    public function test_a_failing_job_leaves_the_ct1_sentence_and_the_comparison_still_finishes(): void
    {
        Queue::fake();
        $analysis = $this->standard();
        $this->fakeProvider(fn () => throw new \RuntimeException('想定外の失敗'));

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);
        Queue::assertNotPushed(FinalizeAnalysisJob::class);

        (new GenerateStrengthWeaknessInsightJob($analysis->id))->handle(app(StrengthWeaknessInsightService::class), app(StrengthWeaknessInsightDispatcher::class));

        $stored = app(StrengthWeaknessInsightStore::class)->read($analysis->id);
        $this->assertSame('RuntimeException', $stored['job_failed']);
        $this->assertNull($stored['strength']);
        $this->assertNull($stored['weakness']);
        Queue::assertPushed(FinalizeAnalysisJob::class, 1);
    }

    public function test_a_job_that_is_killed_by_the_worker_also_unblocks_the_comparison(): void
    {
        Queue::fake();
        $analysis = $this->standard();
        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);
        Queue::assertNotPushed(FinalizeAnalysisJob::class);

        (new GenerateStrengthWeaknessInsightJob($analysis->id))->failed(new \RuntimeException('timeout'));

        $this->assertSame('RuntimeException', app(StrengthWeaknessInsightStore::class)->read($analysis->id)['job_failed']);
        Queue::assertPushed(FinalizeAnalysisJob::class, 1);
    }

    public function test_the_wait_ends_after_the_configured_minutes_even_if_the_job_never_comes(): void
    {
        Queue::fake();
        $analysis = $this->standard();
        $analysis->websiteAnalyses()->toBase()->update(['updated_at' => now()->subMinutes(30)]);

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);

        Queue::assertNotPushed(GenerateStrengthWeaknessInsightJob::class);
        Queue::assertPushed(FinalizeAnalysisJob::class, 1);
    }

    public function test_a_comparison_without_brand_wheel_and_a_regular_diagnosis_are_not_touched(): void
    {
        Queue::fake();
        $analysis = $this->standard();
        $analysis->update(['skip_brand_wheel' => true]);

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);
        Queue::assertNotPushed(GenerateStrengthWeaknessInsightJob::class);

        $analysis->update(['skip_brand_wheel' => false, 'kind' => AnalysisKind::LeadDiagnosis]);
        $this->assertFalse(app(StrengthWeaknessInsightDispatcher::class)->holdFinalization($analysis->fresh()));
    }

    public function test_the_result_file_is_deleted_together_with_the_company_data(): void
    {
        $analysis = $this->standard();
        $this->fakeProvider($this->goodOutput());
        app(StrengthWeaknessInsightService::class)->generate($analysis);
        $path = "analyses/{$analysis->id}/strength_weakness_insight.json";
        Storage::disk('analysis')->assertExists($path);

        $analysis->update(['status' => AnalysisStatus::Completed]);
        $company = LeadCompany::query()->findOrFail($analysis->project->lead_company_id);
        app(LeadCompanyDeletionService::class)->destroy($company, $company->company_name);

        Storage::disk('analysis')->assertMissing($path);
    }

    // ------------------------------------------------------------------ DB-5 スライド・優先順位

    public function test_both_sides_from_the_ai_get_the_red_label_and_the_note_and_ct1_has_neither(): void
    {
        [$companies, $axes] = $this->wheelData();
        $composer = new ComparisonStrengthWeaknessComposer;
        $generator = app(AdminComparisonPptxGenerator::class);
        $base = ['self_company_name' => '自社', 'self_readable' => true, 'companies' => $companies, 'axes' => $axes, 'source_note' => 'n', 'page_number' => null];
        $label = (string) config('strength_weakness_insight.label');
        $note = (string) config('strength_weakness_insight.note');
        $insight = ['strength' => ['sentence' => 'Claudeの強みの下書きです。', 'quotes' => []], 'weakness' => ['sentence' => 'Claudeの弱みの下書きです。', 'quotes' => []]];

        $both = $composer->compose($companies, $axes, true, null, null, $insight);
        $xml = $this->slideXml($generator->generate($base + ['strength_weakness' => $both]));
        $this->assertSame('Claudeの強みの下書きです。', $both['strength']);
        $this->assertSame(2, substr_count($xml, $label), '強み・弱みの両方に、行の頭のラベル');
        $this->assertStringContainsString($note, $xml);

        $ct1 = $composer->compose($companies, $axes, true);
        $xml = $this->slideXml($generator->generate($base + ['strength_weakness' => $ct1]));
        $this->assertStringNotContainsString($label, $xml);
        $this->assertStringNotContainsString($note, $xml);
        $this->assertFalse($ct1['insight_used']);
    }

    public function test_when_only_one_side_passes_the_other_side_is_ct1_and_only_the_ai_side_has_the_label(): void
    {
        [$companies, $axes] = $this->wheelData();
        $composer = new ComparisonStrengthWeaknessComposer;
        $generator = app(AdminComparisonPptxGenerator::class);
        $base = ['self_company_name' => '自社', 'self_readable' => true, 'companies' => $companies, 'axes' => $axes, 'source_note' => 'n', 'page_number' => null];
        $label = (string) config('strength_weakness_insight.label');
        $ct1 = $composer->compose($companies, $axes, true);

        $weakOnly = $composer->compose($companies, $axes, true, null, null, ['strength' => null, 'weakness' => ['sentence' => 'Claudeの弱みの下書きです。', 'quotes' => []]]);

        $this->assertSame($ct1['strength'], $weakOnly['strength'], '落ちた側はCT-1の文');
        $this->assertSame('Claudeの弱みの下書きです。', $weakOnly['weakness']);
        $this->assertFalse($weakOnly['insight_strength']);
        $this->assertTrue($weakOnly['insight_weakness']);
        $xml = $this->slideXml($generator->generate($base + ['strength_weakness' => $weakOnly]));
        $this->assertSame(1, substr_count($xml, $label), 'ラベルはAIの側だけ');
        $this->assertStringContainsString((string) config('strength_weakness_insight.note'), $xml);
    }

    public function test_the_insight_comes_before_the_sentence_of_the_other_ai_feature_when_both_are_enabled(): void
    {
        [$companies, $axes] = $this->wheelData();
        $composer = new ComparisonStrengthWeaknessComposer;
        $cw = ['strength' => ['sentence' => 'CWの強みの一文です。'], 'weakness' => ['sentence' => 'CWの弱みの一文です。']];

        $both = $composer->compose($companies, $axes, true, $cw, null, ['strength' => ['sentence' => 'この依頼の強みです。'], 'weakness' => ['sentence' => 'この依頼の弱みです。']]);
        $this->assertSame('この依頼の強みです。', $both['strength']);
        $this->assertSame('この依頼の弱みです。', $both['weakness']);
        $this->assertFalse($both['ai_used'], 'CWの文は使っていない');

        $weakFailed = $composer->compose($companies, $axes, true, $cw, null, ['strength' => ['sentence' => 'この依頼の強みです。'], 'weakness' => null]);
        $this->assertSame('この依頼の強みです。', $weakFailed['strength']);
        $this->assertSame('CWの弱みの一文です。', $weakFailed['weakness'], '落ちた側は、CWの文があればそれに戻る');
        $this->assertTrue($weakFailed['ai_used']);
        $this->assertTrue($weakFailed['insight_used']);
    }

    public function test_longer_text_gets_a_smaller_font_with_a_floor(): void
    {
        [$companies, $axes] = $this->wheelData();
        $composer = new ComparisonStrengthWeaknessComposer;
        $generator = app(AdminComparisonPptxGenerator::class);
        $fontSize = new \ReflectionMethod($generator, 'strengthWeaknessFontSize');
        $labels = (array) config('admin_comparison_pptx.strength_weakness_labels');
        $size = function (string $text) use ($composer, $companies, $axes, $fontSize, $generator, $labels): float {
            $data = $composer->compose($companies, $axes, true, null, null, ['strength' => ['sentence' => $text, 'quotes' => []], 'weakness' => ['sentence' => $text, 'quotes' => []]]);

            return $fontSize->invoke($generator, $data, $labels);
        };

        $this->assertSame((float) config('strength_weakness_insight.font_size'), $size(str_repeat('あ', 100)), '100字は、基準の大きさのまま');
        $this->assertSame((float) config('strength_weakness_insight.font_size'), $size(str_repeat('あ', 2 * (int) config('strength_weakness_insight.line_chars') - 8)), '2行ぶんに収まる間は、基準の大きさ');
        $this->assertLessThan((float) config('strength_weakness_insight.font_size'), $size(str_repeat('あ', 300)), '2行を超えるときは小さくする');
        $this->assertSame((float) config('strength_weakness_insight.min_font_size'), $size(str_repeat('あ', 900)), '下限より小さくしない');
    }

    public function test_the_columns_move_down_only_when_the_two_sides_need_more_than_two_lines(): void
    {
        [$companies, $axes] = $this->wheelData();
        $composer = new ComparisonStrengthWeaknessComposer;
        $generator = app(AdminComparisonPptxGenerator::class);
        $layout = new \ReflectionMethod($generator, 'insightLayout');
        $data = fn (string $strength, string $weakness, bool $insight = true) => $composer->compose($companies, $axes, true, null, null, $insight ? ['strength' => ['sentence' => $strength, 'quotes' => []], 'weakness' => ['sentence' => $weakness, 'quotes' => []]] : null);
        $one = str_repeat('あ', 85);
        $two = str_repeat('あ', 120);

        $this->assertSame([0.0, 0.0], $layout->invoke($generator, $data($one, $one)), '各1行(ラベル込み102字以内)に収まるときは、従来の位置');

        [$shift, $raise] = $layout->invoke($generator, $data($one, $two));
        $this->assertSame((float) config('strength_weakness_insight.layout_raise_in'), $raise, '3行になるときは、箱の上端を上げる');
        $size = (float) config('strength_weakness_insight.font_size');
        $available = 1.8 - (float) config('strength_weakness_insight.layout_gap_in') - (1.42 - $raise);
        $this->assertEqualsWithDelta(max(0.0, 3 * $size * 1.25 / 72 - $available), $shift, 0.002, '足りない高さの分だけ、列を下へずらす');

        [$shift4] = $layout->invoke($generator, $data($two, $two));
        $this->assertGreaterThanOrEqual($shift, $shift4, '行が増えれば、ずらす量も増える');
        $this->assertSame([0.0, 0.0], $layout->invoke($generator, $data('', '', false)), 'CT-1の文のときは、従来の位置');
        $this->assertSame([0.0, 0.0], $layout->invoke($generator, null));
    }

    // ------------------------------------------------------------------ ダウンロード時は呼ばない

    public function test_reading_the_stored_result_to_build_the_slide_never_calls_the_ai_or_the_network(): void
    {
        $analysis = $this->standard();
        $fake = $this->fakeProvider($this->goodOutput());
        app(StrengthWeaknessInsightService::class)->generate($analysis);
        $this->assertSame(1, $fake->calls);

        Http::preventStrayRequests();
        Http::fake();
        $stored = app(StrengthWeaknessInsightStore::class)->read($analysis->id);
        [$companies, $axes] = $this->wheelData();
        $data = (new ComparisonStrengthWeaknessComposer)->compose($companies, $axes, true, null, null, $stored);
        app(AdminComparisonPptxGenerator::class)->generate(['self_company_name' => '自社', 'self_readable' => true, 'companies' => $companies, 'axes' => $axes, 'source_note' => 'n', 'page_number' => null, 'strength_weakness' => $data]);

        $this->assertSame(1, $fake->calls);
        Http::assertNothingSent();
        $this->assertTrue($data['insight_used']);
    }

    public function test_the_download_controller_reads_the_stored_file_only_when_the_switch_is_on(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/Admin/AnalysisController.php'));

        $this->assertStringContainsString("config('strength_weakness_insight.enabled')", $source);
        $this->assertStringContainsString('StrengthWeaknessInsightStore::class)->read(', $source);
        $this->assertStringNotContainsString('StrengthWeaknessInsightService', $source, 'ダウンロードの経路にはサービス(AIを呼ぶ側)を使わない');
    }

    // ------------------------------------------------------------------ 測定コマンド

    public function test_the_measure_command_never_calls_the_ai_beyond_the_approved_number_of_calls(): void
    {
        $analysis = $this->standard();
        $fake = $this->fakeProvider($this->goodOutput());
        $ids = $analysis->websiteAnalyses()->with('website')->get()->sortBy(fn ($wa) => $wa->website->display_order)->pluck('id')->all();
        $args = ['--self' => "自社サイト:{$ids[0]}", '--competitors' => "A社:{$ids[1]},B社:{$ids[2]},C社:{$ids[3]}"];

        $this->artisan('strength-weakness-insight:measure', $args + ['--live' => true])->assertFailed();
        $this->assertSame(0, $fake->calls);

        $this->artisan('strength-weakness-insight:measure', $args + ['--live' => true, '--repeat' => true, '--max-ai-calls' => 1])->assertFailed();
        $this->assertSame(0, $fake->calls);

        $this->artisan('strength-weakness-insight:measure', $args + ['--max-ai-calls' => 5])->assertSuccessful();
        $this->assertSame(0, $fake->calls);

        $this->artisan('strength-weakness-insight:measure', $args + ['--live' => true, '--repeat' => true, '--max-ai-calls' => 2])->assertSuccessful();
        $this->assertSame(2, $fake->calls);
        Storage::disk('analysis')->assertMissing("analyses/{$analysis->id}/strength_weakness_insight.json");
    }
}
