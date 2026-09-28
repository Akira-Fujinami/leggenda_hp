<?php

namespace Tests\Unit\Services\Analysis;

use App\Services\Analysis\CrawlLinkExtractor;
use Tests\TestCase;

/**
 * 依頼CD-4(2026-09-28): 「../」を含む相対hrefが、正規化されないまま絶対URL
 * 化されてanalysis_crawled_pagesに保存されていた不具合の修正。
 *
 * 実物の資料で観測した事実: 起点 https://www.shinkin.co.jp/ssc/recruit/
 * 配下のページからの相対リンク「../greeting.html」が
 * ".../ssc/recruit/../greeting.html" という文字列のまま絶対URL化され、
 * 階層図スライドの枝名が".."になっていた。resolveAbsoluteUrl()が
 * href(相対・サイト内絶対パス・完全URL・プロトコル相対の全分岐)を絶対URLへ
 * 組み立てる唯一の箇所であり(CrawlWebsiteJob/CrawlWebsitePageJobの2箇所
 * からのみ呼ばれる、grep済み)、ここに正規化を入れる。
 */
class CrawlLinkExtractorTest extends TestCase
{
    private CrawlLinkExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new CrawlLinkExtractor;
    }

    private function extract(string $html, string $pageUrl): array
    {
        return $this->extractor->extractAbsoluteLinks($html, $pageUrl);
    }

    /**
     * 依頼CD-4の背景そのもの: /ssc/recruit/のページからの「../greeting.html」
     * は、そのまま連結すると "/ssc/recruit/../greeting.html" になるが、
     * 実際に指しているのは "/ssc/greeting.html"(1つ上のディレクトリ)。
     * 正規化後のURLで保存されるべき。
     */
    public function test_relative_href_with_parent_directory_segment_is_resolved_to_the_canonical_path(): void
    {
        $html = '<a href="../greeting.html">ご挨拶</a>';
        $links = $this->extract($html, 'https://www.shinkin.co.jp/ssc/recruit/index.html');

        $this->assertSame(['https://www.shinkin.co.jp/ssc/greeting.html'], $links);
        $this->assertStringNotContainsString('..', $links[0]);
    }

    /**
     * サイト内絶対パス(/で始まるhref)に「../」が含まれる場合も、同じく
     * 正規化されること。
     */
    public function test_absolute_path_href_with_dot_segments_is_normalized(): void
    {
        $html = '<a href="/ssc/recruit/../about/company.html">会社概要</a>';
        $links = $this->extract($html, 'https://www.shinkin.co.jp/ssc/recruit/index.html');

        $this->assertSame(['https://www.shinkin.co.jp/ssc/about/company.html'], $links);
    }

    /**
     * 完全URL(https://…)のhrefに「../」が含まれる場合も正規化すること
     * (resolveAbsoluteUrl()の別分岐、正規化を1箇所〈normalizeDotSegments〉に
     * 集約していることの確認)。
     */
    public function test_fully_qualified_href_with_dot_segments_is_normalized(): void
    {
        $html = '<a href="https://www.shinkin.co.jp/ssc/recruit/../history.html">沿革</a>';
        $links = $this->extract($html, 'https://www.shinkin.co.jp/other/page.html');

        $this->assertSame(['https://www.shinkin.co.jp/ssc/history.html'], $links);
    }

    /**
     * 依頼CD-4の確認事項: 正規化により、別々の文字列だった2つのURL
     * ("../greeting.html"由来と、直接の絶対パス由来の同じ実ページ)が
     * 同じ文字列に収束すること ―― url_hash一意制約が正しく機能し、
     * analysis_crawled_pagesへの二重登録を防げるようになったことの確認
     * (CrawlLinkExtractor自体は保存を行わないため、ここでは「同じ絶対URL
     * 文字列に解決されること」までを保証する ―― enqueueSeed()側の重複判定
     * は変更していない)。
     */
    public function test_dot_segment_variant_and_canonical_url_resolve_to_the_identical_string(): void
    {
        $viaDotSegment = $this->extract(
            '<a href="../greeting.html">ご挨拶</a>',
            'https://www.shinkin.co.jp/ssc/recruit/index.html',
        );
        $viaCanonical = $this->extract(
            '<a href="/ssc/greeting.html">ご挨拶</a>',
            'https://www.shinkin.co.jp/ssc/recruit/index.html',
        );

        $this->assertSame($viaCanonical, $viaDotSegment);
    }

    /**
     * 禁止事項: ルートより上に遡ろうとする「../」は静かに無視し、存在しない
     * URLを作らないこと(例: ルート直下からの「../foo.html」)。
     */
    public function test_dot_segments_do_not_escape_above_the_root(): void
    {
        $html = '<a href="../../../etc.html">test</a>';
        $links = $this->extract($html, 'https://example.com/a/b.html');

        $this->assertSame(['https://example.com/etc.html'], $links);
    }

    /**
     * 単一の「./」(カレントディレクトリ)も取り除かれること。
     */
    public function test_current_directory_dot_segment_is_removed(): void
    {
        $html = '<a href="./flow.html">流れ</a>';
        $links = $this->extract($html, 'https://example.com/recruit/index.html');

        $this->assertSame(['https://example.com/recruit/flow.html'], $links);
    }

    /**
     * クエリ文字列は正規化の影響を受けず、そのまま保持されること。
     */
    public function test_query_string_is_preserved_across_normalization(): void
    {
        $html = '<a href="../search.html?keyword=engineer&page=2">検索</a>';
        $links = $this->extract($html, 'https://example.com/recruit/index.html');

        $this->assertSame(['https://example.com/search.html?keyword=engineer&page=2'], $links);
    }

    /**
     * 既存動作の非退行確認: 「../」を含まない通常のhref(絶対・相対・
     * サイト内絶対パス・プロトコル相対)の解決結果が、この修正の前後で
     * 変わらないこと。
     */
    public function test_ordinary_links_without_dot_segments_are_unaffected(): void
    {
        $html = <<<'HTML'
            <a href="https://other.example.com/page.html">外部</a>
            <a href="/careers/list.html">一覧</a>
            <a href="detail.html">詳細</a>
            <a href="//cdn.example.com/asset.html">プロトコル相対</a>
            HTML;

        $links = $this->extract($html, 'https://example.com/recruit/index.html');

        $this->assertSame([
            'https://other.example.com/page.html',
            'https://example.com/careers/list.html',
            'https://example.com/recruit/detail.html',
            'https://cdn.example.com/asset.html',
        ], $links);
    }

    /**
     * 既存動作の非退行確認: fragment(#…)は正規化後も除去されたままで
     * あること。
     */
    public function test_fragment_is_still_stripped_after_normalization(): void
    {
        $html = '<a href="../about.html#team">チーム</a>';
        $links = $this->extract($html, 'https://example.com/recruit/index.html');

        $this->assertSame(['https://example.com/about.html'], $links);
    }
}
