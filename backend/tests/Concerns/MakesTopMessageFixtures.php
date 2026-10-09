<?php

namespace Tests\Concerns;

use App\Enums\AnalysisKind;
use App\Enums\AnalysisStatus;
use App\Enums\PageType;
use App\Models\Analysis;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisPage;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\TopMessageInsight\TopMessageInsightProvider;
use App\Services\TopMessageInsight\TopMessageInsightProviderFactory;
use App\Services\TopMessageInsight\TopMessagePage;
use Illuminate\Support\Facades\Storage;

/**
 * 依頼CQ: トップメッセージ × 人事制度のテストが共通で使う部品。
 * 本文はすべて人工のもの(実在のサイトの文章ではない)。AIは呼ばず、固定の出力を返す偽物を使う。
 */
trait MakesTopMessageFixtures
{
    protected const MSG_QUOTE = 'ものづくりは人づくりだと信じています。';

    protected function makeComparisonWebsiteAnalysis(bool $primary = true, int $displayOrder = 0, string $name = 'self', ?Analysis $analysis = null): WebsiteAnalysis
    {
        if ($analysis === null) {
            $company = LeadCompany::factory()->create();
            $project = new Project(['name' => '比較']);
            $project->user_id = User::factory()->create()->id;
            $project->lead_company_id = $company->id;
            $project->save();
            $analysis = Analysis::factory()->create([
                'project_id' => $project->id,
                'status' => AnalysisStatus::Completed,
                'kind' => AnalysisKind::AdminComparison,
                'crawl_site' => true,
                'skip_brand_wheel' => false,
            ]);
        }

        $website = Website::factory()->create([
            'project_id' => $analysis->project_id,
            'is_primary' => $primary,
            'display_order' => $displayOrder,
            'name' => $name,
            'url' => "https://{$name}.example.com/recruit/",
        ]);

        return WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
    }

    protected function htmlPage(string $title, string $bodyText, string $extraBody = ''): string
    {
        $paragraphs = implode('', array_map(fn (string $line) => '<p>'.htmlspecialchars($line).'</p>', explode("\n", $bodyText)));

        return '<html><head><title>'.htmlspecialchars($title).'</title></head><body><nav><a href="/x">NAVIGATION</a></nav><main>'.$paragraphs.$extraBody.'</main><footer>FOOTER</footer></body></html>';
    }

    protected function addHomepage(WebsiteAnalysis $wa, string $url, string $html, PageType $type = PageType::Homepage): AnalysisPage
    {
        $path = "analyses/{$wa->analysis_id}/websites/{$wa->id}/raw/".$type->value.'.html';
        Storage::disk('analysis')->put($path, $html);

        return AnalysisPage::factory()->create([
            'website_analysis_id' => $wa->id,
            'page_type' => $type,
            'url' => $url,
            'final_url' => $url,
            'raw_html_path' => $path,
        ]);
    }

    protected function addCrawledPage(WebsiteAnalysis $wa, string $url, ?string $title, string $html, int $depth = 1): AnalysisCrawledPage
    {
        static $n = 0;
        $n++;
        $path = "analyses/{$wa->analysis_id}/websites/{$wa->id}/raw/crawl-{$n}.html";
        Storage::disk('analysis')->put($path, $html);

        return AnalysisCrawledPage::factory()->create([
            'website_analysis_id' => $wa->id,
            'url' => $url,
            'final_url' => $url,
            'title' => $title,
            'depth' => $depth,
            'status' => AnalysisCrawledPage::STATUS_FETCHED,
            'raw_html_path' => $path,
        ]);
    }

    /**
     * 標準の会社: メッセージ1・制度2のページ(トップページは採用トップ)。
     */
    protected function seedStandardCompany(WebsiteAnalysis $wa, string $base = 'https://self.example.com/recruit'): void
    {
        $this->addHomepage($wa, "{$base}/", $this->htmlPage('採用トップ', '採用情報です。', '<a href="'.$base.'/ceo-message/">代表からのご挨拶</a>'));
        $this->addCrawledPage($wa, "{$base}/ceo-message/", '代表メッセージ | 例社', $this->htmlPage('代表メッセージ | 例社', "代表取締役社長 山田太郎\n私たちは、\n".self::MSG_QUOTE."\n一社如一家の精神で、チーム力を高めます。"));
        $this->addCrawledPage($wa, "{$base}/training/", '研修制度 | 例社', $this->htmlPage('研修制度 | 例社', '新入社員は1カ月の新人研修を受けます。先輩がつくサポーター制度があります。'));
        $this->addCrawledPage($wa, "{$base}/welfare/", '福利厚生 | 例社', $this->htmlPage('福利厚生 | 例社', '家賃補助 最大80%を支給します。年間休日は120日です。資格取得費用を支給します。'));
    }

    /**
     * 標準の会社に対する、全て確認を通る出力。
     *
     * @return array<string, mixed>
     */
    protected function validAiOutput(string $base = 'https://self.example.com/recruit'): array
    {
        return [
            'quote' => self::MSG_QUOTE,
            'keywords' => [
                [
                    'keyword' => 'ものづくりは人づくり',
                    'programs' => [
                        ['name' => '新人研修', 'category' => '研修・育成', 'detail' => '入社後1カ月', 'source_url' => "{$base}/training/", 'evidence' => '1カ月の新人研修'],
                        ['name' => 'サポーター制度', 'category' => '研修・育成', 'detail' => '先輩がつく', 'source_url' => "{$base}/training/", 'evidence' => 'サポーター制度があります'],
                    ],
                ],
                [
                    'keyword' => '一社如一家',
                    'programs' => [
                        ['name' => '家賃補助', 'category' => '福利厚生・手当', 'detail' => '最大80%', 'source_url' => "{$base}/welfare/", 'evidence' => '家賃補助 最大80%を支給'],
                    ],
                ],
            ],
        ];
    }

    /**
     * AIの代わりに、固定の出力を返す偽物をコンテナへ差し込む。戻り値のオブジェクトで呼び出し回数と
     * 渡された入力を確認できる。
     *
     * @param  array<string, mixed>|\Closure  $output
     */
    protected function fakeTopMessageProvider(array|\Closure $output): object
    {
        $provider = new class($output) implements TopMessageInsightProvider
        {
            public int $calls = 0;

            /** @var list<array{message: TopMessagePage, programs: list<TopMessagePage>}> */
            public array $inputs = [];

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

            public function analyze(TopMessagePage $messagePage, array $programPages): array
            {
                $this->calls++;
                $this->inputs[] = ['message' => $messagePage, 'programs' => $programPages];
                $output = $this->output instanceof \Closure ? ($this->output)() : $this->output;

                return ['decoded' => $output, 'usage_input_tokens' => 100, 'usage_output_tokens' => 50];
            }
        };

        app()->instance(TopMessageInsightProviderFactory::class, new class($provider) extends TopMessageInsightProviderFactory
        {
            public function __construct(private readonly TopMessageInsightProvider $fake) {}

            public function make(): TopMessageInsightProvider
            {
                return $this->fake;
            }
        });

        return $provider;
    }
}
