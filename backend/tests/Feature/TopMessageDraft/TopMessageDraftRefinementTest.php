<?php

namespace Tests\Feature\TopMessageDraft;

use App\Models\WebsiteAnalysis;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\TopMessageDraft\TopMessageDraftBuilder;
use App\Services\TopMessageDraft\TopMessageDraftExtractor;
use App\Services\TopMessageInsight\TopMessagePage;
use App\Services\TopMessageInsight\TopMessagePageFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTopMessageFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * 依頼CS追補(CSA-1〜CSA-5): 素材ページの「使えない行」を、言葉の一覧ではなくページの構造で減らす。
 * 本文はすべて人工のもの(実在のサイトの文章ではない)。AIは呼ばない。
 */
class TopMessageDraftRefinementTest extends TestCase
{
    use MakesTopMessageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
    }

    private function page(string $html, string $title = 'ページ', string $url = 'https://e.example.com/a'): TopMessagePage
    {
        return new TopMessagePage($url, $title, '本文', true, 2, $html);
    }

    /** @return list<array{kind: string, name: string, excerpt: string}> */
    private function items(string $html, ?string $title = null): array
    {
        $result = (new TopMessageDraftExtractor)->programItemsByPage([$this->page($html, $title ?? '')]);

        return array_map(fn (array $i) => ['kind' => $i['kind'], 'name' => $i['name'], 'excerpt' => $i['excerpt']], $result[0]['items']);
    }

    /** @return list<string> */
    private function programNames(string $html, ?string $title = null): array
    {
        return array_values(array_map(fn (array $i) => $i['name'], array_filter($this->items($html, $title), fn (array $i) => $i['kind'] === 'program')));
    }

    // ---- CSA-1: 末尾が「：」の見出し ----

    public function test_headings_ending_with_a_colon_are_not_program_candidates_even_with_spaces_around_it(): void
    {
        $html = '<html><body><main><h2>事業カテゴリー：</h2><p>広告 ゲーム</p><h2>表示順:</h2><p>新着順</p><h2>入社年度 ：</h2><p>1年目</p><h2>コース :  </h2><p>A</p><h2>新人研修</h2><p>説明です。</p></main></body></html>';

        $this->assertSame(['新人研修'], $this->programNames($html));

        $found = (new TopMessageDraftExtractor)->programItemsByPage([$this->page($html)]);
        $this->assertSame(4, $found[0]['dropped']['colon'], '外した数は理由別に数える');
    }

    public function test_a_colon_in_the_middle_of_a_heading_is_kept(): void
    {
        $this->assertSame(['福利厚生：家賃補助'], $this->programNames('<html><body><main><h2>福利厚生：家賃補助</h2><p>説明です。</p></main></body></html>'));
    }

    // ---- CSA-2: ページの題名と同じ見出し ----

    public function test_a_heading_equal_to_the_title_or_to_a_part_of_it_is_not_a_program_candidate(): void
    {
        $html = '<html><head><title>中途採用 ｜ 面白法人例社</title></head><body><main><h2>中途採用</h2><p>a</p><h2>面白法人例社</h2><p>b</p><h2>中途採用 ｜ 面白法人例社</h2><p>c</p><h2>家賃補助</h2><p>説明です。</p></main></body></html>';

        $this->assertSame(['家賃補助'], $this->programNames($html));
        $found = (new TopMessageDraftExtractor)->programItemsByPage([$this->page($html)]);
        $this->assertSame(3, $found[0]['dropped']['title']);
    }

    public function test_the_title_comparison_ignores_spaces_and_full_width_half_width_differences(): void
    {
        $html = '<html><head><title>ＭＩＸＩ　採用 | 例社</title></head><body><main><h2>MIXI採用</h2><p>a</p><h2>例 社</h2><p>b</p><h2>家賃補助</h2><p>説明です。</p></main></body></html>';

        $this->assertSame(['家賃補助'], $this->programNames($html));
    }

    public function test_the_title_given_by_the_page_record_is_used_too(): void
    {
        $html = '<html><body><main><h2>研修制度</h2><p>a</p><h2>新人研修</h2><p>説明です。</p></main></body></html>';

        $this->assertSame(['新人研修'], $this->programNames($html, '研修制度 | 例社'));
    }

    public function test_message_candidates_also_drop_the_title_and_its_parts(): void
    {
        $sentence = '私たちは、お客様と社員の成長を第一に考え、ものづくりを通じて社会に貢献します。';
        $html = '<html><head><title>代表メッセージ | 面白法人例社</title></head><body><main><h1>代表メッセージ</h1><h2>面白法人例社</h2><h2>私たちの約束</h2><p>'.$sentence.'</p></main></body></html>';

        $found = (new TopMessageDraftExtractor)->messageCandidates($html);

        $this->assertNotContains('代表メッセージ', $found['lines']);
        $this->assertNotContains('面白法人例社', $found['lines']);
        $this->assertContains('私たちの約束', $found['lines']);
        $this->assertContains($sentence, $found['lines']);
        $this->assertSame(2, $found['dropped_title']);
    }

    // ---- CSA-4: 分類の見出し ----

    public function test_a_heading_followed_by_a_deeper_heading_is_a_category_and_is_not_counted(): void
    {
        $html = '<html><body><main><h2>教育・研修</h2><h3>新人研修</h3><p>説明A</p><h3>資格取得支援</h3><p>説明B</p><h3>メンター制度</h3><p>説明C</p></main></body></html>';

        $items = $this->items($html);

        $this->assertSame(['category', 'program', 'program', 'program'], array_column($items, 'kind'));
        $this->assertSame(['新人研修', '資格取得支援', 'メンター制度'], $this->programNames($html), '分類の見出しは候補の件数に入らない');
        $this->assertSame('', $items[0]['excerpt'], '分類の見出しに抜粋は付けない');

        $found = (new TopMessageDraftExtractor)->programItemsByPage([$this->page($html)]);
        $this->assertSame(1, $found[0]['dropped']['category']);
    }

    public function test_a_heading_with_exactly_one_deeper_heading_is_a_program_and_that_heading_is_its_excerpt(): void
    {
        $html = '<html><body><main><h3>360度フルオープンフィードバック</h3><h4>相互のフィードバックを全社員に公開</h4><p>この本文は抜粋に使わない。</p><h3>ライバル指名制度</h3><h4>'.str_repeat('あ', 80).'</h4></main></body></html>';

        $items = $this->items($html);

        $this->assertSame(['program', 'program'], array_column($items, 'kind'), '深い見出しは1つだけなので、どちらも制度の名前(深い見出しは別の候補にしない)');
        $this->assertSame(['360度フルオープンフィードバック', 'ライバル指名制度'], array_column($items, 'name'));
        $this->assertSame('相互のフィードバックを全社員に公開', $items[0]['excerpt'], '深い見出しの文字が抜粋。その下の本文は使わない');
        $this->assertSame(str_repeat('あ', (int) config('top_message_draft.program_excerpt_max_chars')).'…', $items[1]['excerpt'], '抜粋の上限・切り詰めはいままでと同じ');
    }

    public function test_the_deeper_headings_are_counted_only_until_a_heading_of_the_same_or_shallower_depth(): void
    {
        // 教育・研修の下はh3が3つ(分類)。福利厚生の下はh3が1つだけ(制度。次のh2で数え終わる)。
        $html = '<html><body><main><h2>教育・研修</h2><h3>研修A</h3><h3>研修B</h3><h3>研修C</h3><h2>福利厚生</h2><h3>家賃補助</h3><h2>働き方</h2></main></body></html>';

        $items = $this->items($html);

        $this->assertSame([['category', '教育・研修'], ['program', '研修A'], ['program', '研修B'], ['program', '研修C'], ['program', '福利厚生'], ['program', '働き方']], array_map(fn (array $i) => [$i['kind'], $i['name']], $items));
        $this->assertSame('家賃補助', $items[4]['excerpt']);
    }

    public function test_headings_of_the_same_depth_stay_program_candidates(): void
    {
        $html = '<html><body><main><h2>健康経営</h2><h2>健康管理室</h2><h2>子育て支援</h2></main></body></html>';

        $this->assertSame(['program', 'program', 'program'], array_column($this->items($html), 'kind'), '同じ深さの見出しが並ぶだけ(抜粋が無くても)なら、いまのまま');
    }

    public function test_dt_and_th_are_never_categories_and_the_last_heading_is_a_candidate(): void
    {
        $html = '<html><body><main><h3>制度の一覧</h3><dl><dt>年間休日</dt><dd>120日</dd></dl><table><tr><th>資格取得</th><td>月額15,000円</td></tr></table><h2>最後の見出し</h2></main></body></html>';

        $kinds = array_column($this->items($html), 'kind', 'name');

        $this->assertSame('program', $kinds['年間休日']);
        $this->assertSame('program', $kinds['資格取得']);
        $this->assertSame('program', $kinds['最後の見出し'], '次の見出しが無いときは分類にしない');
    }

    public function test_a_heading_inside_the_header_footer_or_nav_does_not_make_the_one_above_a_category(): void
    {
        $html = '<html><body><main><h2>家賃補助</h2><p>説明です。</p></main><footer><h3>FOOTER-HEADING</h3></footer></body></html>';

        $this->assertSame(['program'], array_column($this->items($html), 'kind'));
    }

    // ---- CSA-3: ページごとに順番に取る / ページごとにまとめて表示 ----

    private function programPage(WebsiteAnalysis $wa, string $slug, string $title, string $itemsHtml, int $pad): void
    {
        $this->addCrawledPage(
            $wa,
            "https://self.example.com/recruit/{$slug}/",
            "{$title} | 例社",
            '<html><head><title>'.$title.' | 例社</title></head><body><main>'.$itemsHtml.str_repeat('<p>余白の文章です。</p>', $pad).'</main></body></html>',
        );
    }

    private function simpleItems(string $prefix, int $count): string
    {
        $html = '';
        for ($i = 1; $i <= $count; $i++) {
            $html .= "<h2>{$prefix}{$i}の制度</h2><p>{$prefix}{$i}の説明です。</p>";
        }

        return $html;
    }

    public function test_programs_are_taken_one_per_page_in_turn_and_shown_grouped_by_page(): void
    {
        config(['top_message_draft.program_total_max' => 6]);
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, 'https://self.example.com/recruit/', $this->htmlPage('採用', '採用です。'));
        // 本文の長い順に training → welfare → benefit(同じ優先の段)。
        $this->programPage($wa, 'training', '研修制度', $this->simpleItems('ア', 5), 30);
        $this->programPage($wa, 'welfare', '福利厚生', $this->simpleItems('イ', 5), 20);
        $this->programPage($wa, 'benefit', '福利制度', $this->simpleItems('ウ', 5), 10);

        $page = app(TopMessageDraftBuilder::class)->build($wa, '自社');

        $names = array_column($page['programs']['rows'], 'name');
        $this->assertSame(['ア1の制度', 'ア2の制度', 'イ1の制度', 'イ2の制度', 'ウ1の制度', 'ウ2の制度'], $names, '各ページから1件ずつ(1・2巡目)取り、表示はページごと・ページ内は出現順');
        $this->assertSame(['研修制度', '研修制度', '福利厚生', '福利厚生', '福利制度', '福利制度'], array_column($page['programs']['rows'], 'source'));
        $this->assertSame(15, $page['programs']['candidate_total']);
        $this->assertSame(9, $page['programs']['hidden']);
    }

    public function test_a_page_with_fewer_candidates_does_not_hold_back_the_others(): void
    {
        config(['top_message_draft.program_total_max' => 5]);
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, 'https://self.example.com/recruit/', $this->htmlPage('採用', '採用です。'));
        $this->programPage($wa, 'training', '研修制度', $this->simpleItems('ア', 1), 30);
        $this->programPage($wa, 'welfare', '福利厚生', $this->simpleItems('イ', 6), 20);

        $page = app(TopMessageDraftBuilder::class)->build($wa, '自社');

        $this->assertSame(['ア1の制度', 'イ1の制度', 'イ2の制度', 'イ3の制度', 'イ4の制度'], array_column($page['programs']['rows'], 'name'));
    }

    public function test_a_category_row_is_shown_only_above_chosen_candidates_and_is_not_counted(): void
    {
        config(['top_message_draft.program_total_max' => 2]);
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, 'https://self.example.com/recruit/', $this->htmlPage('採用', '採用です。'));
        $this->programPage($wa, 'training', '研修制度', '<h2>分類ア</h2><h3>制度ア1</h3><p>説明です。</p><h3>制度ア2</h3><p>説明です。</p><h2>分類イ</h2><h3>制度イ1</h3><p>説明です。</p><h3>制度イ2</h3><p>説明です。</p>', 10);

        $page = app(TopMessageDraftBuilder::class)->build($wa, '自社');

        $this->assertSame(
            [['category', '分類ア'], ['program', '制度ア1'], ['program', '制度ア2']],
            array_map(fn (array $r) => [$r['kind'], $r['name']], $page['programs']['rows']),
            '2件を選ぶ。下に候補が1件も残らなかった「分類イ」は出さない',
        );
        $this->assertSame(4, $page['programs']['candidate_total'], '分類の見出しは件数に数えない');
        $this->assertSame(2, $page['programs']['hidden']);
        $this->assertSame(2, $page['programs']['dropped']['category']);
    }

    public function test_dropped_rows_are_reported_by_reason(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, 'https://self.example.com/recruit/', $this->htmlPage('採用', '採用です。'));
        $this->programPage($wa, 'training', '研修制度', '<h2>研修制度</h2><p>a</p><h2>事業カテゴリー：</h2><p>b</p><h2>分類</h2><h3>家賃補助</h3><p>説明です。</p><h3>退職金制度</h3><p>説明です。</p>', 10);

        $page = app(TopMessageDraftBuilder::class)->build($wa, '自社');

        $this->assertSame(['colon' => 1, 'title' => 1, 'category' => 1, 'title_in_message' => 0], $page['programs']['dropped']);
    }

    // ---- CSA-5: メッセージのページの選び方 ----

    private function messageCandidateUrls(WebsiteAnalysis $wa): array
    {
        $selection = app(TopMessagePageFinder::class)->find($wa);

        return [$selection->message?->url, $selection->messageCandidateCount];
    }

    public function test_a_representative_word_only_in_the_footer_does_not_make_a_message_candidate(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $base = 'https://self.example.com/recruit';
        $this->addHomepage($wa, "{$base}/", $this->htmlPage('採用', '採用です。'));
        $this->addCrawledPage($wa, "{$base}/message/", 'メッセージ', '<html><head><title>メッセージ</title></head><body><main><p>本文です。</p></main><footer><p>代表取締役 山田太郎</p></footer></body></html>');
        $this->addCrawledPage($wa, "{$base}/ceo/", 'CEO', '<html><head><title>CEO</title></head><body><main><p>本文です。</p></main><div class="site-footer account"><p>代表取締役 山田太郎</p></div></body></html>');
        $this->addCrawledPage($wa, "{$base}/president/", 'President', '<html><head><title>President</title></head><body><main><p>本文です。</p></main><div role="contentinfo"><p>代表取締役 山田太郎</p></div></body></html>');

        [$url, $count] = $this->messageCandidateUrls($wa);

        $this->assertNull($url, 'フッター(タグ・class・role)にしか代表者の語が無いページは候補にならない');
        $this->assertSame(0, $count);
    }

    public function test_a_representative_word_in_the_body_is_enough(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $base = 'https://self.example.com/recruit';
        $this->addHomepage($wa, "{$base}/", $this->htmlPage('採用', '採用です。'));
        $this->addCrawledPage($wa, "{$base}/message/", 'メッセージ', '<html><head><title>メッセージ</title></head><body><main><p>代表取締役 山田太郎</p><small>注: 代表取締役の署名</small></main><footer><p>FOOTER</p></footer></body></html>');

        [$url] = $this->messageCandidateUrls($wa);

        $this->assertSame("{$base}/message/", $url);
    }

    public function test_a_message_word_only_in_the_link_text_does_not_make_a_candidate_but_the_h1_does(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $base = 'https://self.example.com/recruit';
        $this->addHomepage($wa, "{$base}/", $this->htmlPage('採用', '採用です。', '<a href="'.$base.'/career/">メッセージ</a><a href="'.$base.'/greeting/">メッセージ</a>'));
        $this->addCrawledPage($wa, "{$base}/career/", '中途採用', $this->htmlPage('中途採用', '代表取締役のコメントがあります。'));
        $this->addCrawledPage($wa, "{$base}/greeting/", 'ご挨拶', $this->htmlPage('ご挨拶', '代表取締役の挨拶です。', '<h1>トップメッセージ</h1>'));

        [$url, $count] = $this->messageCandidateUrls($wa);

        $this->assertSame("{$base}/greeting/", $url, '<h1>にメッセージ系の語があるページだけが候補');
        $this->assertSame(1, $count);
    }

    public function test_a_dena_type_page_with_message_in_the_url_and_the_word_in_the_body_is_chosen(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, 'https://self.example.com/recruit/', $this->htmlPage('採用', '採用です。'));
        $this->addCrawledPage(
            $wa,
            'https://self.example.com/jp/company/message.html',
            '代表メッセージ | 例社',
            '<html><head><title>代表メッセージ | 例社</title></head><body><header><nav><a href="/">HOME</a></nav></header><main><h1>代表メッセージ</h1><p>代表取締役会長</p><p>私がこれまで重視してきたのは、すべての社員が自立的に動き、各事業が独自のビジョンを持って運営される組織の土台づくりです。</p></main><footer><p>FOOTER</p></footer></body></html>',
        );
        // 採用の中途のページは、メッセージ系の語がURL・題名・<h1>のどこにも無いので候補にならない。
        $this->addCrawledPage($wa, 'https://self.example.com/recruit/career', '中途採用 | 例社', $this->htmlPage('中途採用', '代表取締役の言葉です。'));

        $page = app(TopMessageDraftBuilder::class)->build($wa, '自社');

        $this->assertSame('https://self.example.com/jp/company/message.html', $page['message']['url']);
        $this->assertContains('代表取締役会長', $page['message']['lines']);
        $this->assertNotContains('代表メッセージ', $page['message']['lines'], 'ページの題名と同じ見出しは候補にしない');
    }

    public function test_a_career_page_is_not_chosen_as_a_program_page_but_the_other_secondary_words_still_are(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $base = 'https://self.example.com/recruit';
        $this->addHomepage($wa, "{$base}/", $this->htmlPage('採用', '採用です。'));
        $this->addCrawledPage($wa, "{$base}/career", '中途採用', $this->htmlPage('中途採用', '求人の案内です。'));
        $this->addCrawledPage($wa, "{$base}/requirements/", '募集要項', $this->htmlPage('募集要項', '勤務地の案内です。'));

        $urls = array_map(fn (TopMessagePage $p) => $p->url, app(TopMessagePageFinder::class)->find($wa)->programPages);

        $this->assertSame(["{$base}/requirements/"], $urls);
        $this->assertNotContains('career', (array) config('top_message_insight.program_page_keywords_secondary'));
        $this->assertSame(['募集要項', '待遇'], config('top_message_insight.program_page_keywords_secondary'), '他の言葉は変えない');
    }

    // ---- スライド(区切りの行・外す順) ----

    private function slideXml(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sx');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $zip->open($tmp);
        $xml = html_entity_decode((string) $zip->getFromName('ppt/slides/slide1.xml'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $zip->close();
        @unlink($tmp);

        return $xml;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function pageData(array $rows, int $hidden = 0): array
    {
        return [
            'company_name' => 'テスト株式会社',
            'message' => null,
            'programs' => ['rows' => $rows, 'hidden' => $hidden, 'page_urls' => []],
        ];
    }

    public function test_a_category_row_is_drawn_bold_on_a_pale_band_without_an_excerpt(): void
    {
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($this->pageData([
            ['kind' => 'category', 'name' => '分類の見出し', 'excerpt' => '', 'source' => '研修制度', 'level' => 2],
            ['kind' => 'program', 'name' => '新人研修', 'excerpt' => '入社後1カ月', 'source' => '研修制度', 'order' => 0],
        ])));

        $this->assertStringContainsString(strtoupper((string) config('admin_comparison_pptx.top_message_draft_category_fill')), strtoupper($xml), '薄い背景(config)');
        $this->assertMatchesRegularExpression('/<a:rPr[^>]*\bb="1"[^>]*>(?:(?!<\/a:r>).)*分類の見出し/s', $xml, '太字');
        $this->assertSame(1, substr_count($xml, '研修制度'), '区切りの行に出典は出さない(各候補の出典だけ)');
    }

    private function overflowRows(): array
    {
        // 3ページ×各4件。表示順はページごと、選んだ順(order)は1巡目 → 2巡目 …。収まらない大きさにする。
        $rows = [];
        foreach (['ア' => 0, 'イ' => 1, 'ウ' => 2] as $prefix => $page) {
            for ($i = 0; $i < 4; $i++) {
                $rows[] = ['kind' => 'program', 'name' => str_repeat('長い名前', 4)."{$prefix}{$i}", 'excerpt' => str_repeat('抜粋です', 12), 'source' => "出典{$prefix}", 'order' => $i * 3 + $page];
            }
        }

        return $rows;
    }

    public function test_when_the_rows_do_not_fit_the_last_chosen_candidates_are_dropped_not_the_last_page(): void
    {
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($this->pageData($this->overflowRows(), 5)));

        preg_match('/ほか(\d+)件/', $xml, $m);
        $this->assertArrayHasKey(1, $m, '収まらない分は「ほか N件」');
        $shownFirstRound = 0;
        foreach (['ア', 'イ', 'ウ'] as $prefix) {
            $shownFirstRound += substr_count($xml, str_repeat('長い名前', 4).$prefix.'0') > 0 ? 1 : 0;
        }
        $this->assertSame(3, $shownFirstRound, '1巡目の候補は、どのページも残る');
        $this->assertStringNotContainsString(str_repeat('長い名前', 4).'ウ3', $xml, '選んだ順の最後(3巡目のウ)から外れる');
        $visible = preg_match_all('/長い名前長い名前長い名前長い名前[アイウ]\d/u', $xml);
        $this->assertSame(17, $visible + (int) $m[1], '見えている件数 + ほかの件数(元の5件を含む) = 12 + 5');
    }

    public function test_a_category_row_without_a_candidate_below_it_is_not_drawn_after_the_drop(): void
    {
        $rows = $this->overflowRows();
        // 最後に選んだ候補(order 99)だけを持つ分類。収まらずに外れると、分類の行も出さない。
        $rows[] = ['kind' => 'category', 'name' => '空になる分類', 'excerpt' => '', 'source' => '出典エ', 'level' => 2];
        $rows[] = ['kind' => 'program', 'name' => '最後の候補', 'excerpt' => '説明', 'source' => '出典エ', 'order' => 99];

        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($this->pageData($rows)));

        $this->assertStringNotContainsString('最後の候補', $xml);
        $this->assertStringNotContainsString('空になる分類', $xml);
    }
}
