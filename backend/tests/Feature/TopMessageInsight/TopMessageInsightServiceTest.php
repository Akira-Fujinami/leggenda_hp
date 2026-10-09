<?php

namespace Tests\Feature\TopMessageInsight;

use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\TopMessageInsight\TopMessageInsightException;
use App\Services\TopMessageInsight\TopMessageInsightService;
use App\Services\TopMessageInsight\TopMessageInsightStore;
use App\Services\TopMessageInsight\TopMessagePage;
use App\Services\TopMessageInsight\TopMessagePageFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTopMessageFixtures;
use Tests\TestCase;

/**
 * 依頼CQ-1〜CQ-3。AIは呼ばない(固定の出力を返す偽物を使う)。
 */
class TopMessageInsightServiceTest extends TestCase
{
    use MakesTopMessageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
    }

    // ---------------------------------------------------------------- CQ-1 / CQ-2

    public function test_the_message_page_is_found_by_url_title_or_link_text(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $base = 'https://self.example.com/recruit';
        // 1) URLに"message" 2) タイトルに"トップメッセージ" 3) URLにもタイトルにも無いが、トップページのリンクの文字が"代表挨拶"
        $this->addHomepage($wa, "{$base}/", $this->htmlPage('採用', '採用です。', '<a href="'.$base.'/greeting/">代表挨拶</a>'));
        $this->addCrawledPage($wa, "{$base}/a/", null, $this->htmlPage('トップメッセージ', '代表の二番目の候補です。'));
        $this->addCrawledPage($wa, "{$base}/greeting/", null, $this->htmlPage('ご挨拶', '代表の三番目の候補です。'));
        $this->addCrawledPage($wa, "{$base}/other/", 'その他', $this->htmlPage('その他', '候補ではありません。'));
        $this->addCrawledPage($wa, "{$base}/message/", null, $this->htmlPage('x', '代表の一番目の候補です。'));

        $selection = app(TopMessagePageFinder::class)->find($wa);

        $this->assertSame(3, $selection->messageCandidateCount);
        $this->assertNotNull($selection->message);
    }

    public function test_no_message_page_gives_zero_candidates_and_no_message(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, 'https://self.example.com/recruit/', $this->htmlPage('採用', '採用です。'));
        $this->addCrawledPage($wa, 'https://self.example.com/recruit/training/', '研修制度', $this->htmlPage('研修制度', '新人研修があります。'));

        $selection = app(TopMessagePageFinder::class)->find($wa);

        $this->assertNull($selection->message);
        $this->assertSame(0, $selection->messageCandidateCount);
        $this->assertCount(1, $selection->programPages);
    }

    public function test_among_several_candidates_the_one_inside_the_origin_and_with_more_text_is_chosen(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, 'https://self.example.com/recruit/', $this->htmlPage('採用', '採用です。'));
        // 起点(/recruit/)の外だが、本文は最も長い。
        $this->addCrawledPage($wa, 'https://self.example.com/about/message/', 'メッセージ', $this->htmlPage('メッセージ', '代表より。'.str_repeat('外の長い本文です。', 30)));
        // 起点配下で短い。
        $this->addCrawledPage($wa, 'https://self.example.com/recruit/message-short/', 'メッセージ', $this->htmlPage('メッセージ', '代表の短い本文です。'));
        // 起点配下で、より長い。
        $this->addCrawledPage($wa, 'https://self.example.com/recruit/ceo/', 'CEO', $this->htmlPage('CEO', '代表より。'.str_repeat('配下の長い本文です。', 10)));

        $selection = app(TopMessagePageFinder::class)->find($wa);

        $this->assertSame('https://self.example.com/recruit/ceo/', $selection->message->url);
        $this->assertSame(3, $selection->messageCandidateCount);
    }

    public function test_program_pages_are_capped_and_exclude_the_message_page(): void
    {
        config(['top_message_insight.program_pages_max' => 2]);
        $wa = $this->makeComparisonWebsiteAnalysis();
        $base = 'https://self.example.com/recruit';
        $this->addHomepage($wa, "{$base}/", $this->htmlPage('採用', '採用です。'));
        $this->addCrawledPage($wa, "{$base}/message/", 'メッセージ 制度', $this->htmlPage('メッセージ 制度', '代表の制度の話もするメッセージです。'));
        foreach (['training', 'welfare', 'benefit', 'workstyle'] as $i => $slug) {
            $this->addCrawledPage($wa, "{$base}/{$slug}/", $slug, $this->htmlPage($slug, str_repeat('本文', 10 + $i)));
        }

        $selection = app(TopMessagePageFinder::class)->find($wa);

        $this->assertSame("{$base}/message/", $selection->message->url);
        $this->assertCount(2, $selection->programPages);
        $this->assertSame(4, $selection->programCandidateCount, 'メッセージのページ自身は制度のページに数えない');
        $urls = array_map(fn (TopMessagePage $p) => $p->url, $selection->programPages);
        $this->assertNotContains("{$base}/message/", $urls);
        // 文字数の多い順: workstyle(本文26文字相当) → benefit。
        $this->assertSame(["{$base}/workstyle/", "{$base}/benefit/"], $urls);
    }

    public function test_navigation_text_is_not_part_of_the_page_text(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedStandardCompany($wa);

        $selection = app(TopMessagePageFinder::class)->find($wa);

        $this->assertStringNotContainsString('NAVIGATION', $selection->message->text);
        $this->assertStringNotContainsString('FOOTER', $selection->message->text);
    }

    // ---------------------------------------------------------------- CQ-3

    public function test_generate_saves_a_verified_result_and_a_second_call_does_not_call_the_ai_again(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedStandardCompany($wa);
        $fake = $this->fakeTopMessageProvider($this->validAiOutput());

        $first = app(TopMessageInsightService::class)->generate($wa);
        $second = app(TopMessageInsightService::class)->generate($wa);

        $this->assertSame(1, $fake->calls, '同じ会社で2回実行しても、AIは1回しか呼ばれない');
        $this->assertSame('created', $first['status']);
        $this->assertSame($first['quote'], $second['quote']);
        $this->assertSame(self::MSG_QUOTE, $first['quote']);
        $this->assertCount(2, $first['keywords']);
        $this->assertSame('v1', $first['prompt_version']);
        $this->assertCount(3, $first['sources'], 'メッセージのページ + 採用された制度の出どころ2ページ');
        // ページ名は区切り(|)より前だけ。
        $this->assertSame(['代表メッセージ', '研修制度', '福利厚生'], array_column($first['sources'], 'title'));

        $path = app(AnalysisStoragePaths::class)->topMessageInsightPath($wa->analysis_id, $wa->id);
        $this->assertSame("analyses/{$wa->analysis_id}/websites/{$wa->id}/top_message_insight.json", $path);
        Storage::disk('analysis')->assertExists($path);
    }

    public function test_the_ai_receives_the_message_page_and_program_pages_within_the_total_budget(): void
    {
        config(['top_message_insight.input_max_chars' => 120, 'top_message_insight.message_page_max_chars' => 60]);
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedStandardCompany($wa);
        $fake = $this->fakeTopMessageProvider($this->validAiOutput());

        app(TopMessageInsightService::class)->generate($wa);

        $input = $fake->inputs[0];
        $total = mb_strlen($input['message']->text) + array_sum(array_map(fn (TopMessagePage $p) => mb_strlen($p->text), $input['programs']));
        $this->assertLessThanOrEqual(120, $total);
        $this->assertLessThanOrEqual(60, mb_strlen($input['message']->text));
    }

    public function test_budget_gives_the_unused_share_of_short_pages_to_longer_pages(): void
    {
        config(['top_message_insight.input_max_chars' => 100, 'top_message_insight.message_page_max_chars' => 20]);
        $message = new TopMessagePage('https://e.com/m', null, str_repeat('あ', 50));
        $pages = [
            new TopMessagePage('https://e.com/long', null, str_repeat('い', 200)),
            new TopMessagePage('https://e.com/short', null, str_repeat('う', 10)),
        ];

        [$m, $p] = app(TopMessageInsightService::class)->applyBudget($message, $pages);

        $this->assertSame(20, mb_strlen($m->text));
        // 残り80: 短い方が10だけ使い、長い方に70が回る。並びは渡した順のまま。
        $this->assertSame([70, 10], array_map(fn (TopMessagePage $x) => mb_strlen($x->text), $p));
    }

    public function test_no_message_page_saves_not_created_without_calling_the_ai(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, 'https://self.example.com/recruit/', $this->htmlPage('採用', '採用です。'));
        $fake = $this->fakeTopMessageProvider($this->validAiOutput());

        $result = app(TopMessageInsightService::class)->generate($wa);

        $this->assertSame('not_created', $result['status']);
        $this->assertSame('no_message_page', $result['reason']);
        $this->assertSame(0, $fake->calls);
        $this->assertTrue(app(TopMessageInsightStore::class)->exists($wa->analysis_id, $wa->id));
    }

    public function test_a_quote_not_in_the_page_saves_not_created(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedStandardCompany($wa);
        $output = $this->validAiOutput();
        $output['quote'] = 'ものづくりは人づくりだと私は強く信じています。';
        $this->fakeTopMessageProvider($output);

        $result = app(TopMessageInsightService::class)->generate($wa);

        $this->assertSame('not_created', $result['status']);
        $this->assertSame('quote_invalid', $result['reason']);
        $this->assertSame(['quote_not_in_page' => 1], $result['discarded']);
    }

    public function test_too_few_items_after_verification_saves_not_created(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedStandardCompany($wa);
        $output = $this->validAiOutput();
        // 本文は「最大80%」、出力は「最大90%」 → その制度を捨てる → キーワードが1つに。
        $output['keywords'][1]['programs'][0]['detail'] = '最大90%';
        $this->fakeTopMessageProvider($output);

        $result = app(TopMessageInsightService::class)->generate($wa);

        $this->assertSame('not_created', $result['status']);
        $this->assertSame('too_few_keywords', $result['reason']);
        $this->assertSame(1, $result['discarded']['number_not_in_evidence']);
    }

    public function test_an_ai_failure_saves_nothing_and_rethrows_so_it_can_be_retried(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedStandardCompany($wa);
        $this->fakeTopMessageProvider(fn () => throw new TopMessageInsightException('AI_TIMEOUT', 'timeout'));

        try {
            app(TopMessageInsightService::class)->generate($wa);
            $this->fail('例外が投げられるはず');
        } catch (TopMessageInsightException $e) {
            $this->assertSame('AI_TIMEOUT', $e->errorCode);
        }

        $this->assertFalse(app(TopMessageInsightStore::class)->exists($wa->analysis_id, $wa->id));
    }

    public function test_the_provider_factory_refuses_anything_but_openai(): void
    {
        config(['services.brand_wheel_ai.provider' => 'mock']);

        $this->expectException(TopMessageInsightException::class);
        app(\App\Services\TopMessageInsight\TopMessageInsightProviderFactory::class)->make();
    }

    public function test_no_image_is_read_or_stored(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedStandardCompany($wa);
        $this->addCrawledPage($wa, 'https://self.example.com/recruit/benefit/', '福利厚生', $this->htmlPage('福利厚生', '写真付きの制度です。', '<img src="https://self.example.com/p.jpg" alt="ALTTEXT">'));
        $fake = $this->fakeTopMessageProvider($this->validAiOutput());

        $result = app(TopMessageInsightService::class)->generate($wa);

        foreach ($fake->inputs[0]['programs'] as $page) {
            $this->assertStringNotContainsString('p.jpg', $page->text);
        }
        $this->assertStringNotContainsString('p.jpg', (string) json_encode($result));
    }

    // ---------------------------------------------------------------- 依頼CQ追補 CQA-1 / CQA-2

    private function seedForSelection(WebsiteAnalysis $wa): string
    {
        $base = 'https://self.example.com/recruit';
        $this->addHomepage($wa, "{$base}/", $this->htmlPage('採用', '採用です。'));

        return $base;
    }

    public function test_a_page_without_a_representative_word_in_its_body_is_not_a_message_candidate(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $base = $this->seedForSelection($wa);
        $this->addCrawledPage($wa, "{$base}/message/", 'メッセージ', $this->htmlPage('メッセージ', '採用担当からのメッセージです。皆さんと働けることを楽しみにしています。'));

        $selection = app(TopMessagePageFinder::class)->find($wa);

        $this->assertNull($selection->message);
        $this->assertSame(1, $selection->messageKeywordMatchCount, '語には当たっているが、');
        $this->assertSame(0, $selection->messageCandidateCount, '代表者の語が無いため候補にならない');
    }

    public function test_an_interview_page_is_not_a_candidate_but_a_president_interview_titled_page_is(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $base = $this->seedForSelection($wa);
        $this->addCrawledPage($wa, "{$base}/interview/message/", '営業リーダーのインタビュー', $this->htmlPage('営業リーダーのインタビュー', '代表の考えを聞きました。'.str_repeat('長い。', 50)));
        $this->addCrawledPage($wa, "{$base}/message/president/", '社長インタビュー', $this->htmlPage('社長インタビュー', '代表取締役社長が語ります。'));

        $selection = app(TopMessagePageFinder::class)->find($wa);

        $this->assertSame("{$base}/message/president/", $selection->message->url);
        $this->assertSame(1, $selection->messageCandidateCount);
        $this->assertSame(2, $selection->messageKeywordMatchCount);
    }

    public function test_each_interview_word_excludes_a_page_unless_the_title_names_the_representative(): void
    {
        foreach (['社員' => 'x', 'story' => 'x', 'stories' => 'x', 'voice' => 'x', '対談' => 'x', 'interview' => 'x'] as $word => $_) {
            $wa = $this->makeComparisonWebsiteAnalysis(true, 0, 'self'.md5($word));
            $base = $this->seedForSelection($wa);
            $this->addCrawledPage($wa, "{$base}/message/", "{$word}のページ", $this->htmlPage('x', '代表の言葉。'));

            $this->assertNull(app(TopMessagePageFinder::class)->find($wa)->message, $word);
        }
    }

    public function test_pagination_job_list_and_english_pages_are_not_program_candidates(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $base = $this->seedForSelection($wa);
        $this->addCrawledPage($wa, "{$base}/message/", '代表メッセージ', $this->htmlPage('代表メッセージ', '代表取締役社長の言葉です。'));
        $jp = fn (string $t) => $this->htmlPage($t, '制度についての日本語の説明がここにあります。研修や福利厚生の話です。');
        $this->addCrawledPage($wa, "{$base}/training/", '研修制度', $jp('研修制度'));
        $this->addCrawledPage($wa, "{$base}/training/?page=2", '研修制度 2', $jp('研修制度2'));
        $this->addCrawledPage($wa, "{$base}/training/page/3/", '研修制度 3', $jp('研修制度3'));
        $this->addCrawledPage($wa, "{$base}/training/?p=4", '研修制度 4', $jp('研修制度4'));
        $this->addCrawledPage($wa, "{$base}/jobs/", '制度 求人一覧', $jp('求人'));
        $this->addCrawledPage($wa, "{$base}/job", '制度 求人一覧', $jp('求人'));
        $this->addCrawledPage($wa, "{$base}/en/benefits/", 'Benefits 福利厚生', $jp('x'));
        $this->addCrawledPage($wa, "{$base}/welfare-english/", '福利厚生', $this->htmlPage('Benefits', 'We offer many benefits to all employees. Training and welfare programs are available. Please read more.'));

        $selection = app(TopMessagePageFinder::class)->find($wa);

        $this->assertSame(["{$base}/training/"], array_map(fn (TopMessagePage $p) => $p->url, $selection->programPages));
    }

    public function test_program_pages_with_the_main_words_come_before_those_with_only_secondary_words(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $base = $this->seedForSelection($wa);
        $this->addCrawledPage($wa, "{$base}/message/", '代表メッセージ', $this->htmlPage('代表メッセージ', '代表取締役社長の言葉です。'));
        // 募集要項(第2)のほうが本文は長いが、研修(第1)が先に並ぶ。
        $this->addCrawledPage($wa, "{$base}/requirements/", '募集要項', $this->htmlPage('募集要項', str_repeat('募集要項の長い本文です。', 20)));
        $this->addCrawledPage($wa, "{$base}/training/", '研修', $this->htmlPage('研修', '研修の短い本文です。'));

        $urls = array_map(fn (TopMessagePage $p) => $p->url, app(TopMessagePageFinder::class)->find($wa)->programPages);

        $this->assertSame(["{$base}/training/", "{$base}/requirements/"], $urls);
    }

    public function test_without_program_pages_the_ai_is_not_called_and_nothing_is_created(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $base = $this->seedForSelection($wa);
        $this->addCrawledPage($wa, "{$base}/message/", '代表メッセージ', $this->htmlPage('代表メッセージ', '代表取締役社長の言葉です。'));
        $fake = $this->fakeTopMessageProvider($this->validAiOutput());

        $result = app(TopMessageInsightService::class)->generate($wa);

        $this->assertSame('no_program_pages', $result['reason']);
        $this->assertSame(0, $fake->calls);
    }
}
