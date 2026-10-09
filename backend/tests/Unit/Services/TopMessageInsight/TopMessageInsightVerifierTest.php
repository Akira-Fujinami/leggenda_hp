<?php

namespace Tests\Unit\Services\TopMessageInsight;

use App\Services\TopMessageInsight\TopMessageInsightVerifier;
use App\Services\TopMessageInsight\TopMessagePage;
use Tests\TestCase;

/**
 * 依頼CQ-3「サーバー側の確認」。AIの出力が原文に合わなければ捨てる/ページを作らない。
 * 本文は人工のもの(実在のサイトの文章ではない)。
 */
class TopMessageInsightVerifierTest extends TestCase
{
    private const QUOTE = 'ものづくりは人づくりだと信じています。';

    private function messagePage(): TopMessagePage
    {
        return new TopMessagePage(
            'https://example.com/message',
            '代表メッセージ | 例社',
            "私たちは、\n".self::QUOTE."\n一社如一家の精神で、チーム力を高めます。",
        );
    }

    /**
     * @return list<TopMessagePage>
     */
    private function programPages(): array
    {
        return [
            new TopMessagePage('https://example.com/training', '研修制度', '新入社員は1カ月の新人研修を受けます。先輩がつくサポーター制度があります。'),
            new TopMessagePage('https://example.com/welfare/', '福利厚生', '家賃補助 最大80%を支給します。年間休日は120日です。資格取得費用を支給します。'),
        ];
    }

    private function program(string $name, string $detail, string $url, string $evidence, string $category = '研修・育成'): array
    {
        return ['name' => $name, 'category' => $category, 'detail' => $detail, 'source_url' => $url, 'evidence' => $evidence];
    }

    /**
     * @return array<string, mixed>
     */
    private function validOutput(): array
    {
        return [
            'quote' => self::QUOTE,
            'keywords' => [
                [
                    'keyword' => 'ものづくりは人づくり',
                    'programs' => [
                        $this->program('新人研修', '入社後1カ月', 'https://example.com/training', '1カ月の新人研修'),
                        $this->program('サポーター制度', '先輩がつく', 'https://example.com/training', 'サポーター制度があります'),
                    ],
                ],
                [
                    'keyword' => '一社如一家',
                    'programs' => [
                        $this->program('家賃補助', '最大80%', 'https://example.com/welfare', '家賃補助 最大80%を支給'),
                    ],
                ],
            ],
        ];
    }

    private function verify(array $output): array
    {
        return (new TopMessageInsightVerifier)->verify($output, $this->messagePage(), $this->programPages());
    }

    public function test_a_valid_output_passes_unchanged(): void
    {
        $result = $this->verify($this->validOutput());

        $this->assertTrue($result['ok']);
        $this->assertSame(self::QUOTE, $result['quote']);
        $this->assertCount(2, $result['keywords']);
        $this->assertSame(3, array_sum(array_map(fn ($k) => count($k['programs']), $result['keywords'])));
        $this->assertSame([], $result['discarded']);
    }

    public function test_quote_differing_only_in_whitespace_and_newlines_is_accepted(): void
    {
        $output = $this->validOutput();
        $output['quote'] = 'ものづくりは 人づくりだと　信じています。';

        $this->assertTrue($this->verify($output)['ok']);
    }

    public function test_a_quote_that_is_not_in_the_message_page_makes_the_page_not_created(): void
    {
        $output = $this->validOutput();
        $output['quote'] = 'ものづくりは人づくりだと私は強く信じています。';

        $result = $this->verify($output);

        $this->assertFalse($result['ok']);
        $this->assertSame('quote_invalid', $result['reason']);
        $this->assertSame(['quote_not_in_page' => 1], $result['discarded']);
    }

    public function test_an_empty_quote_makes_the_page_not_created(): void
    {
        $output = $this->validOutput();
        $output['quote'] = '';

        $this->assertSame('quote_invalid', $this->verify($output)['reason']);
    }

    public function test_a_quote_longer_than_the_limit_makes_the_page_not_created(): void
    {
        config(['top_message_insight.quote_max_chars' => 10]);

        $result = $this->verify($this->validOutput());

        $this->assertFalse($result['ok']);
        $this->assertSame(['quote_too_long' => 1], $result['discarded']);
    }

    public function test_a_keyword_that_is_not_in_the_message_page_is_discarded_with_its_programs(): void
    {
        $output = $this->validOutput();
        $output['keywords'][] = [
            'keyword' => '挑戦する風土',
            'programs' => [$this->program('資格取得支援', '費用を支給', 'https://example.com/welfare', '資格取得費用を支給します')],
        ];

        $result = $this->verify($output);

        $this->assertTrue($result['ok']);
        $this->assertSame(['ものづくりは人づくり', '一社如一家'], array_column($result['keywords'], 'keyword'));
        $this->assertSame(['keyword_not_in_message' => 1], $result['discarded']);
    }

    public function test_a_program_whose_evidence_is_not_on_the_source_page_is_discarded(): void
    {
        $output = $this->validOutput();
        // evidenceは福利厚生のページにあるが、source_urlは研修のページ。
        $output['keywords'][0]['programs'][1] = $this->program('家賃補助', '最大80%', 'https://example.com/training', '家賃補助 最大80%を支給');

        $result = $this->verify($output);

        $this->assertSame(1, $result['discarded']['evidence_not_in_page']);
        $this->assertSame(['新人研修'], array_column($result['keywords'][0]['programs'], 'name'));
    }

    public function test_a_program_whose_source_url_was_not_given_as_input_is_discarded(): void
    {
        $output = $this->validOutput();
        $output['keywords'][1]['programs'][0] = $this->program('家賃補助', '最大80%', 'https://example.com/other', '家賃補助 最大80%を支給');

        $result = $this->verify($output);

        $this->assertSame(1, $result['discarded']['source_url_not_in_input']);
    }

    public function test_a_source_url_is_matched_ignoring_trailing_slash_and_fragment(): void
    {
        $output = $this->validOutput();
        // 入力は https://example.com/welfare/ 、AIは末尾スラッシュ無し+#付きで返した。
        $output['keywords'][1]['programs'][0] = $this->program('家賃補助', '最大80%', 'https://example.com/welfare#top', '家賃補助 最大80%を支給');

        $result = $this->verify($output);

        $this->assertSame([], $result['discarded']);
        // 保存するURLはAIの文字列ではなく、入力のページのURL。
        $this->assertSame('https://example.com/welfare/', $result['keywords'][1]['programs'][0]['source_url']);
    }

    public function test_a_program_whose_name_is_not_in_its_evidence_is_discarded(): void
    {
        $output = $this->validOutput();
        $output['keywords'][0]['programs'][1] = $this->program('メンター制度', '先輩がつく', 'https://example.com/training', 'サポーター制度があります');

        $result = $this->verify($output);

        $this->assertSame(1, $result['discarded']['name_not_in_evidence']);
    }

    public function test_a_detail_with_a_number_that_is_not_in_the_evidence_discards_the_program(): void
    {
        $output = $this->validOutput();
        // 本文は「最大80%」、出力は「最大90%」。
        $output['keywords'][1]['programs'][0] = $this->program('家賃補助', '最大90%', 'https://example.com/welfare', '家賃補助 最大80%を支給');

        $result = $this->verify($output);

        $this->assertSame(1, $result['discarded']['number_not_in_evidence']);
        $this->assertFalse($result['ok'], '残りが1キーワード・2制度になるため作らない');
        $this->assertSame('too_few_keywords', $result['reason']);
    }

    public function test_a_number_is_matched_as_a_whole_not_as_a_prefix(): void
    {
        $output = $this->validOutput();
        // 本文は「120日」。「12日」は120の一部だが、別の数字。
        $output['keywords'][1]['programs'][0] = $this->program('年間休日', '12日', 'https://example.com/welfare', '年間休日は120日です');

        $this->assertSame(1, $this->verify($output)['discarded']['number_not_in_evidence']);
    }

    public function test_full_width_digits_are_treated_as_the_same_number(): void
    {
        $output = $this->validOutput();
        $output['keywords'][1]['programs'][0] = $this->program('年間休日', '１２０日', 'https://example.com/welfare', '年間休日は120日です');

        $this->assertArrayNotHasKey('number_not_in_evidence', $this->verify($output)['discarded']);
    }

    public function test_a_detail_longer_than_the_limit_is_truncated_and_a_number_cut_in_half_is_dropped(): void
    {
        config(['top_message_insight.detail_max_chars' => 5]);
        $output = $this->validOutput();
        // 5文字で切ると「最大8」ではなく「最大80%」の「最大80」。数字は切れずに残る → 説明は残る。
        $output['keywords'][1]['programs'][0] = $this->program('家賃補助', '最大80%を毎月支給します', 'https://example.com/welfare', '家賃補助 最大80%を支給');
        $kept = $this->verify($output)['keywords'][1]['programs'][0]['detail'];
        $this->assertLessThanOrEqual(6, mb_strlen($kept));

        // 4文字で切ると「最大8」 ―― 数字の途中で切れ、本文に無い数字になる → 説明は付けない(制度は残る)。
        config(['top_message_insight.detail_max_chars' => 3]);
        $cut = $this->verify($output);
        $this->assertSame('', $cut['keywords'][1]['programs'][0]['detail']);
        $this->assertSame(1, $cut['discarded']['detail_dropped_after_cut']);
    }

    public function test_too_few_keywords_or_programs_makes_the_page_not_created(): void
    {
        $oneKeyword = $this->validOutput();
        array_pop($oneKeyword['keywords']);
        $this->assertSame('too_few_keywords', $this->verify($oneKeyword)['reason']);

        $twoPrograms = $this->validOutput();
        array_pop($twoPrograms['keywords'][0]['programs']);
        $result = $this->verify($twoPrograms);
        $this->assertFalse($result['ok']);
        $this->assertSame('too_few_programs', $result['reason']);
    }

    public function test_the_same_program_is_not_repeated_across_keywords(): void
    {
        $output = $this->validOutput();
        $output['keywords'][1]['programs'][] = $this->program('新人研修', '入社後1カ月', 'https://example.com/training', '1カ月の新人研修');

        $result = $this->verify($output);

        $this->assertSame(1, $result['discarded']['program_duplicate']);
        $this->assertCount(1, $result['keywords'][1]['programs']);
    }

    public function test_limits_on_keywords_and_programs_per_keyword_are_applied(): void
    {
        config(['top_message_insight.keywords_max' => 2, 'top_message_insight.programs_per_keyword_max' => 1]);
        $output = $this->validOutput();
        $output['keywords'][] = [
            'keyword' => 'チーム力',
            'programs' => [$this->program('資格取得支援', '費用を支給', 'https://example.com/welfare', '資格取得費用を支給します')],
        ];

        $result = $this->verify($output);

        $this->assertCount(2, $result['keywords']);
        $this->assertCount(1, $result['keywords'][0]['programs']);
        $this->assertSame(1, $result['discarded']['programs_over_limit']);
        $this->assertSame(1, $result['discarded']['keywords_over_limit']);
    }

    public function test_a_program_taken_from_the_message_page_is_discarded(): void
    {
        $page = new TopMessagePage('https://example.com/message', '代表メッセージ', self::QUOTE.'新人は1カ月の研修を受けます。');
        $output = $this->validOutput();
        $output['keywords'][0]['programs'][0] = $this->program('研修', '1カ月', 'https://example.com/message', '1カ月の研修を受けます');

        $result = (new TopMessageInsightVerifier)->verify($output, $page, $this->programPages());

        $this->assertSame(1, $result['discarded']['source_is_message_page']);
    }

    public function test_a_category_outside_the_list_or_missing_discards_the_program(): void
    {
        $output = $this->validOutput();
        $output['keywords'][0]['programs'][0]['category'] = '製品・サービス';
        unset($output['keywords'][0]['programs'][1]['category']);

        $result = $this->verify($output);

        $this->assertSame(2, $result['discarded']['category_invalid']);
    }

    public function test_every_listed_category_is_accepted(): void
    {
        foreach ((array) config('top_message_insight.program_categories') as $category) {
            $output = $this->validOutput();
            $output['keywords'][0]['programs'][0]['category'] = $category;

            $this->assertArrayNotHasKey('category_invalid', $this->verify($output)['discarded'], $category);
        }
    }

    public function test_a_name_that_differs_from_the_evidence_only_in_width_spacing_symbols_or_case_passes(): void
    {
        $pages = [new TopMessagePage('https://example.com/training', '研修', 'Ｓｕｐｐｏｒｔｅｒ 制度があります。ＯＪＴ・メンター制度も。')];
        $output = $this->validOutput();
        $output['keywords'] = [
            ['keyword' => 'ものづくりは人づくり', 'programs' => [
                $this->program('supporter制度', '', 'https://example.com/training', 'Ｓｕｐｐｏｒｔｅｒ 制度があります'),
                $this->program('OJT メンター制度', '', 'https://example.com/training', 'ＯＪＴ・メンター制度も'),
            ]],
            ['keyword' => '一社如一家', 'programs' => [$this->program('制度', '', 'https://example.com/training', 'Ｓｕｐｐｏｒｔｅｒ 制度があります')]],
        ];

        $result = (new TopMessageInsightVerifier)->verify($output, $this->messagePage(), $pages);

        $this->assertArrayNotHasKey('name_not_in_evidence', $result['discarded']);
        $this->assertTrue($result['ok']);
    }

    public function test_a_reworded_or_shortened_name_is_still_discarded(): void
    {
        $output = $this->validOutput();
        // evidenceは「サポーター制度があります」。名前を言い換えた/evidenceに無い語を足した。
        $output['keywords'][0]['programs'][1] = $this->program('先輩サポート制度', '先輩がつく', 'https://example.com/training', 'サポーター制度があります');
        $output['keywords'][1]['programs'][0] = $this->program('家賃補助制度', '最大80%', 'https://example.com/welfare', '家賃補助 最大80%を支給');

        $this->assertSame(2, $this->verify($output)['discarded']['name_not_in_evidence']);
    }

    public function test_malformed_output_is_discarded_without_errors(): void
    {
        $result = $this->verify(['quote' => self::QUOTE, 'keywords' => ['x', ['keyword' => 5], ['keyword' => 'ものづくりは人づくり', 'programs' => ['y', ['name' => 'a']]]]]);

        $this->assertFalse($result['ok']);
        $this->assertGreaterThan(0, array_sum($result['discarded']));
    }
}
