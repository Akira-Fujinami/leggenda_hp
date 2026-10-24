<?php

namespace Tests\Feature\TopMessageDraft;

use App\Services\Report\JobListingPathFinder;
use App\Services\TopMessageDraft\TopMessageDraftExtractor;
use App\Services\TopMessageInsight\TopMessagePageFinder;
use App\Services\TopMessagePanel\TopMessagePanelMaterialBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTopMessageFixtures;
use Tests\TestCase;

/**
 * 依頼DI: 本番の乃村工藝社・日建設計で見えた、ページの選び方と文の拾い方の直し。
 *  - A: 「働く環境」のページを制度のページとして読む
 *  - B: 日本語と英語のメッセージのページがあるときは、日本語のページを選ぶ
 *  - C: 「Top Message トップメッセージ」のような見出しの語だけの文を本文にしない
 *  - E: 募集要項の箱は、ニュース記事へのリンクを後回しにする
 */
class TopMessagePageSelectionFixesTest extends TestCase
{
    use MakesTopMessageFixtures;
    use RefreshDatabase;

    private const BASE = 'https://self.example.com/recruit';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
    }

    public function test_a_working_environment_page_is_read_as_a_program_page(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, self::BASE.'/', $this->htmlPage('採用トップ', '採用情報です。'));
        $this->addCrawledPage($wa, self::BASE.'/environment/', '働く環境｜採用情報｜例社',
            '<html><head><title>働く環境｜採用情報｜例社</title></head><body><main>'
            .'<h2>社内制度・福利厚生</h2>'
            .'<h3>フレックスタイム・時短勤務制度</h3><p>コアタイムなしのマンスリーフレックスタイムを導入しています。</p>'
            .'<h3>テレワーク勤務の推進</h3><p>150カ所以上のサテライトオフィスを契約し、在宅勤務も推進しています。</p>'
            .'</main></body></html>');

        $selection = app(TopMessagePageFinder::class)->find($wa->fresh());

        $this->assertSame([self::BASE.'/environment/'], array_map(fn ($p) => $p->url, $selection->programPages));
        $names = array_map(fn (array $p) => $p['name'], app(TopMessagePanelMaterialBuilder::class)->build($wa->fresh())['programs']);
        $this->assertContains('フレックスタイム・時短勤務制度', $names);
        $this->assertContains('テレワーク勤務の推進', $names);
    }

    public function test_a_japanese_message_page_is_chosen_over_a_longer_english_one(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, self::BASE.'/', $this->htmlPage('採用トップ', '採用情報です。'));
        $english = '<html><head><title>CEO Message | Example</title></head><body><main><h1>CEO Message</h1>'
            .'<p>President and CEO Taro Yamada</p>'
            .str_repeat('<p>We contribute to society through work that offers true value to all of our clients and partners.</p>', 8)
            .'</main></body></html>';
        $japanese = '<html><head><title>社長メッセージ | 例社</title></head><body><main><h1>社長メッセージ</h1>'
            .'<p>代表取締役社長 山田太郎</p><p>私たちは、社会の課題に設計の力で向き合い続けます。</p>'
            .'</main></body></html>';
        $this->addCrawledPage($wa, self::BASE.'/en/message/', 'CEO Message | Example', $english);
        $this->addCrawledPage($wa, self::BASE.'/about/topmessage.html', '社長メッセージ | 例社', $japanese);

        $selection = app(TopMessagePageFinder::class)->find($wa->fresh());

        $this->assertSame(self::BASE.'/about/topmessage.html', $selection->message?->url);
        $this->assertSame(2, $selection->messageCandidateCount, '候補の数は変えない');
    }

    public function test_an_english_message_page_is_still_used_when_it_is_the_only_one(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, self::BASE.'/', $this->htmlPage('採用トップ', '採用情報です。'));
        $this->addCrawledPage($wa, self::BASE.'/en/message/', 'CEO Message | Example',
            '<html><head><title>CEO Message | Example</title></head><body><main><h1>CEO Message</h1><p>President and CEO Taro Yamada</p>'
            .'<p>We contribute to society through work that offers true value.</p></main></body></html>');

        $this->assertSame(self::BASE.'/en/message/', app(TopMessagePageFinder::class)->find($wa->fresh())->message?->url);
    }

    public function test_lines_made_only_of_message_label_words_are_not_used_as_the_message(): void
    {
        $html = '<html><head><title>トップメッセージ | 例社</title></head><body><main>'
            .'<h2>人々に「歓びと感動」を届ける</h2>'
            .'<p>Top Message トップメッセージ</p>'
            .'<h3>MESSAGE / ごあいさつ</h3>'
            .'<p>例社は、社会に必要とされるあらゆる空間で、歓びと感動を創造する集団です。</p>'
            .'<p>代表メッセージの最後に、皆さんと働ける日を楽しみにしています。</p>'
            .'</main></body></html>';
        $extractor = app(TopMessageDraftExtractor::class);

        $panel = array_map(fn (array $s) => $s['text'], $extractor->panelMessage($html, 'トップメッセージ | 例社')['sentences']);
        $draft = $extractor->messageCandidates($html, 'トップメッセージ | 例社')['lines'];

        foreach ([$panel, $draft] as $lines) {
            $this->assertNotContains('Top Message トップメッセージ', $lines);
            $this->assertNotContains('MESSAGE / ごあいさつ', $lines);
            $this->assertContains('例社は、社会に必要とされるあらゆる空間で、歓びと感動を創造する集団です。', $lines);
            $this->assertContains('代表メッセージの最後に、皆さんと働ける日を楽しみにしています。', $lines, '語を含むだけの文は残す');
        }
        $this->assertContains('人々に「歓びと感動」を届ける', $panel);
    }

    public function test_the_job_listing_box_prefers_a_non_news_link_and_falls_back_to_a_news_link(): void
    {
        $finder = app(JobListingPathFinder::class);
        $hosts = ['self.example.com'];
        $news = '<main><a href="/news/page/7583">【新卒採用】2027新卒採用本エントリー開始のお知らせ</a></main>';
        $fresh = '<main><a href="/recruit/fresh/entry/">新卒採用エントリー</a></main>';

        $both = $finder->find([
            ['html' => '<html><body>'.$news.'</body></html>', 'base_url' => self::BASE.'/', 'clicks' => 0, 'source' => 'recruit_top'],
            ['html' => '<html><body>'.$fresh.'</body></html>', 'base_url' => self::BASE.'/fresh/', 'clicks' => 1, 'source' => 'crawled'],
        ], $hosts);
        $this->assertSame('https://self.example.com/recruit/fresh/entry/', $both['url'], 'ニュース記事より、クリックが多くても記事でないリンク');

        $onlyNews = $finder->find([
            ['html' => '<html><body>'.$news.'</body></html>', 'base_url' => self::BASE.'/', 'clicks' => 0, 'source' => 'recruit_top'],
        ], $hosts);
        $this->assertSame('https://self.example.com/news/page/7583', $onlyNews['url'], 'ほかに無ければ記事へのリンクを使う');

        $this->assertFalse($finder->isDeprioritized('https://self.example.com/career/information/requirements_newgradu2028.html'));
        $this->assertFalse($finder->isDeprioritized('https://self.example.com/newsroom-entry/'), 'パスの区切りとして一致するものだけ');
    }
}
