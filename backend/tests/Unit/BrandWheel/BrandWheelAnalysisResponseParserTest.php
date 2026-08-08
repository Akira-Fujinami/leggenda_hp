<?php

namespace Tests\Unit\BrandWheel;

use App\Services\BrandWheel\BrandWheelAnalysisException;
use App\Services\BrandWheel\BrandWheelAnalysisResponseParser;
use App\Services\BrandWheel\Data\BrandWheelAnalysisInput;
use Tests\TestCase;

class BrandWheelAnalysisResponseParserTest extends TestCase
{
    private BrandWheelAnalysisResponseParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new BrandWheelAnalysisResponseParser;
    }

    private function makeInput(
        string $recruitBody = '',
        string $homepageBody = '',
        array $recruitHeadings = [],
        array $businessLinkLabels = [],
        array $allLinkLabels = [],
    ): BrandWheelAnalysisInput {
        return new BrandWheelAnalysisInput(
            websiteAnalysisId: 1,
            recruitPageTitle: null,
            recruitPageBodyText: $recruitBody,
            recruitPageHeadings: $recruitHeadings,
            homepageTitle: null,
            homepageBodyText: $homepageBody,
            homepageHeadings: [],
            businessLinkLabels: $businessLinkLabels,
            inputTruncated: false,
            sourcePages: ['recruit_page' => 'read', 'home_page' => 'read'],
            allLinkLabels: $allLinkLabels,
        );
    }

    /**
     * config('brand_wheel.axes')の24キー全部を matched:false で埋めた
     * sub_elementsを組み立て、$overridesで個別に上書きする。AI応答の
     * sub_elementsは24キー全部を要求される(guardAgainstIncompleteSchema)ため、
     * 個々のテストの関心事以外のキーもすべて満たしておく必要がある。
     *
     * @param  array<string, array{matched: bool, evidence?: string|null}>  $overrides
     * @return array<string, array{matched: bool, evidence: string|null}>
     */
    private function completeSubElements(array $overrides = []): array
    {
        $keys = [];
        foreach ((array) config('brand_wheel.axes', []) as $axis) {
            $keys = array_merge($keys, array_keys((array) $axis['sub_elements']));
        }

        $base = array_fill_keys($keys, ['matched' => false, 'evidence' => null]);

        foreach ($overrides as $key => $entry) {
            $base[$key] = array_merge(['matched' => false, 'evidence' => null], $entry);
        }

        return $base;
    }

    public function test_evidence_that_exists_in_the_source_text_survives_and_is_used_for_state(): void
    {
        $input = $this->makeInput(recruitBody: '私たちはエネルギー事業とモビリティ事業を展開しています。');

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'business_expansion' => ['matched' => true, 'evidence' => 'エネルギー事業とモビリティ事業を展開しています'],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v1');

        $axis = collect($result->axes)->firstWhere('axisKey', 'will_activity');
        $this->assertCount(1, $axis->matchedSubElements);
        $this->assertSame('business_expansion', $axis->matchedSubElements[0]->key);
        $this->assertSame('partial', $axis->state);
        $this->assertSame(1, $axis->claimedSubElementCount);
        $this->assertSame([], $axis->discardedSubElements);
    }

    public function test_evidence_not_present_in_source_text_is_discarded_with_reason(): void
    {
        $input = $this->makeInput(recruitBody: '私たちは地域社会に貢献しています。');

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'business_expansion' => ['matched' => true, 'evidence' => 'これはサイトに存在しない捏造された抜粋です'],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v1');

        $axis = collect($result->axes)->firstWhere('axisKey', 'will_activity');
        $this->assertSame([], $axis->matchedSubElements);
        $this->assertSame('unread', $axis->state);
        $this->assertCount(1, $axis->discardedSubElements);
        $this->assertSame('evidence_not_found', $axis->discardedSubElements[0]->reason);
        // 検証前の申告件数(1)は残る ―― 「AIは該当ありと申告したが検証で
        // 全滅した」という事実を、claimedSubElementCountとstateの比較から
        // 再構成できるようにするため。
        $this->assertSame(1, $axis->claimedSubElementCount);
    }

    public function test_matched_false_is_ignored_even_when_evidence_is_present(): void
    {
        // 2026-08-05: 新方式ではAIがmatched:falseの項目にもevidenceを
        // 誤って詰めてくる可能性があるが、matchedがtrueでない限り無視する。
        $input = $this->makeInput(recruitBody: 'エネルギー事業を展開しています。');

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'business_expansion' => ['matched' => false, 'evidence' => 'エネルギー事業を展開しています'],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v4');

        $axis = collect($result->axes)->firstWhere('axisKey', 'will_activity');
        $this->assertSame([], $axis->matchedSubElements);
        $this->assertSame(0, $axis->claimedSubElementCount);
        $this->assertSame([], $axis->discardedSubElements);
    }

    public function test_empty_evidence_is_discarded_and_not_counted_as_claimed(): void
    {
        $input = $this->makeInput(recruitBody: '存在する本文です。');

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'business_expansion' => ['matched' => true, 'evidence' => '   '],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v1');

        $axis = collect($result->axes)->firstWhere('axisKey', 'will_activity');
        $this->assertSame(0, $axis->claimedSubElementCount);
        $this->assertSame('empty_evidence', $axis->discardedSubElements[0]->reason);
    }

    public function test_state_uses_default_thresholds_of_partial_one_and_read_two(): void
    {
        $input = $this->makeInput(recruitBody: 'パーパスを掲げ、事業を展開し、新たな取組を進めています。');

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'purpose' => ['matched' => true, 'evidence' => 'パーパスを掲げ'],
                'business_expansion' => ['matched' => true, 'evidence' => '事業を展開し'],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v1');

        $axis = collect($result->axes)->firstWhere('axisKey', 'will_activity');
        $this->assertCount(2, $axis->matchedSubElements);
        $this->assertSame('read', $axis->state);
    }

    public function test_per_axis_state_threshold_override_is_applied_instead_of_default(): void
    {
        config(['brand_wheel.state_thresholds.overrides.emotional_benefit' => ['partial' => 1, 'read' => 1]]);

        $input = $this->makeInput(recruitBody: '誇りを持って働ける職場です。');

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'pride' => ['matched' => true, 'evidence' => '誇りを持って働ける'],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v1');

        $axis = collect($result->axes)->firstWhere('axisKey', 'emotional_benefit');
        // overrideが無ければ1件はpartialのはずだが、overrideのread=1により
        // readになる。
        $this->assertSame('read', $axis->state);
    }

    public function test_axis_state_counts_breaks_down_read_partial_unread_separately_across_all_six_axes(): void
    {
        $input = $this->makeInput(recruitBody: 'パーパスを掲げています。誇りを持って働ける職場です。');

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'purpose' => ['matched' => true, 'evidence' => 'パーパスを掲げています'],
                'pride' => ['matched' => true, 'evidence' => '誇りを持って働ける'],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v1');

        $this->assertCount(6, $result->axes);
        // will_activity/emotional_benefitはpartial(1件)、残り4軸はunread。
        // read+partialを合算しない ―― 内訳をそのまま保持する。
        $this->assertSame(['read' => 0, 'partial' => 2, 'unread' => 4], $result->axisStateCounts);
    }

    public function test_core_value_is_readable_only_when_evidence_exists_in_source(): void
    {
        $input = $this->makeInput(recruitBody: '仕事の舞台裏にこそ価値がある、という考え方を大切にしています。');
        $subElements = $this->completeSubElements();

        $raw = ['sub_elements' => $subElements, 'core_value' => ['readable' => true, 'evidence' => '存在しないでっちあげの抜粋']];
        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v1');
        $this->assertFalse($result->coreValue->readable);
        $this->assertNull($result->coreValue->evidence);

        $raw = ['sub_elements' => $subElements, 'core_value' => ['readable' => true, 'evidence' => '仕事の舞台裏にこそ価値がある']];
        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v1');
        $this->assertTrue($result->coreValue->readable);
        $this->assertSame('仕事の舞台裏にこそ価値がある', $result->coreValue->evidence);
    }

    public function test_normalization_matches_across_full_width_and_half_width_and_whitespace_differences(): void
    {
        // 原文は半角英数字・改行を含むが、AIのevidenceは全角・空白無しで
        // 引用してきたケースを再現する(表記ゆれの吸収を確認する)。
        $input = $this->makeInput(recruitBody: "2023年に新オフィス\nABC124を開設しました。");

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'office_facility' => ['matched' => true, 'evidence' => '２０２３年に新オフィスＡＢＣ１２４を開設'],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v1');

        $axis = collect($result->axes)->firstWhere('axisKey', 'asset');
        $this->assertCount(1, $axis->matchedSubElements);
    }

    public function test_does_not_strip_punctuation_so_a_claim_spanning_a_sentence_boundary_is_rejected(): void
    {
        // 原文は句点で区切られた別々の文。句読点を除去して連結すると
        // 地続きの1文であるかのような偽陽性が発生してしまうため、
        // 句読点を除去しないことでこれを弾けることを確認する。
        $input = $this->makeInput(recruitBody: '資産は十分です。事業は展開しています。');

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'competitiveness' => ['matched' => true, 'evidence' => '資産は十分です事業は展開しています'],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v1');

        $axis = collect($result->axes)->firstWhere('axisKey', 'asset');
        $this->assertSame([], $axis->matchedSubElements);
        $this->assertSame('evidence_not_found', $axis->discardedSubElements[0]->reason);
    }

    /**
     * 2026-08-05: 20文字以上の見出しの完全一致は(label_only_evidenceの
     * 対象外のため)引き続き有効なevidenceとして採用される。見出しという
     * 情報源そのものを禁じているわけではなく、短い見出し単体の丸写しだけを
     * 禁じている点を区別するための回帰確認。リンクラベルは長さに関わらず
     * 破棄されるため対象外(test_evidence_that_exactly_matches_a_link_label_
     * of_twenty_characters_or_more_is_still_discarded()参照、2026-08-05の
     * 閾値設計修正)。
     */
    public function test_evidence_may_come_from_a_long_heading_not_only_body_text(): void
    {
        $input = $this->makeInput(
            recruitHeadings: [['level' => 2, 'text' => '挑戦する人材を求めています、一緒に新しい未来を創りましょう']],
        );

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'project_initiative' => ['matched' => true, 'evidence' => '挑戦する人材を求めています、一緒に新しい未来を創りましょう'],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v1');

        $axis = collect($result->axes)->firstWhere('axisKey', 'will_activity');
        $this->assertCount(1, $axis->matchedSubElements);
    }

    /**
     * 2026-08-05の指摘・実測(味の素9件中6件が見出しラベル単体)への対応。
     * evidenceが見出し文字列そのものと完全一致し、かつ20文字未満の場合、
     * 「その単語がページにある」を「それについて書かれている」証拠に
     * すり替える循環論法として破棄する。
     */
    public function test_evidence_that_exactly_matches_a_short_heading_is_discarded_as_label_only_evidence(): void
    {
        $input = $this->makeInput(recruitHeadings: [['level' => 2, 'text' => '事業紹介']]);

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'business_expansion' => ['matched' => true, 'evidence' => '事業紹介'],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v5');

        $axis = collect($result->axes)->firstWhere('axisKey', 'will_activity');
        $this->assertSame([], $axis->matchedSubElements);
        $this->assertSame('label_only_evidence', $axis->discardedSubElements[0]->reason);
    }

    /**
     * 短いラベルと同じ理由で、短いビジネスリンクラベル単体の丸写しも
     * label_only_evidenceとして破棄されることを確認する(見出しと同じ規則が
     * リンクラベルにも適用される)。
     */
    public function test_evidence_that_exactly_matches_a_short_business_link_label_is_discarded_as_label_only_evidence(): void
    {
        $input = $this->makeInput(businessLinkLabels: ['制度・福利厚生']);

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'benefits' => ['matched' => true, 'evidence' => '制度・福利厚生'],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v5');

        $axis = collect($result->axes)->firstWhere('axisKey', 'financial_benefit');
        $this->assertSame([], $axis->matchedSubElements);
        $this->assertSame('label_only_evidence', $axis->discardedSubElements[0]->reason);
    }

    /**
     * 2026-08-05の実測(味の素)の回帰テスト。`<div class="gnav_wrap">`の
     * ようにセマンティックタグ(header/nav/footer)を使わずナビゲーションを
     * 実装しているサイトでは、そのリンクラベルはbusinessLinkLabels
     * (extractNavigationLinkLabels()、header/nav/footer限定)には含まれない。
     * allLinkLabels(ページ内の全リンク、タグの意味に依存しない)経由で
     * label_only_evidenceが働くことを確認する。
     */
    public function test_evidence_that_exactly_matches_a_link_label_outside_semantic_nav_tags_is_discarded(): void
    {
        $input = $this->makeInput(
            recruitBody: '事業紹介ページへのリンクがあります。',
            allLinkLabels: ['事業紹介'],
        );

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'business_expansion' => ['matched' => true, 'evidence' => '事業紹介'],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v5');

        $axis = collect($result->axes)->firstWhere('axisKey', 'will_activity');
        $this->assertSame([], $axis->matchedSubElements);
        $this->assertSame('label_only_evidence', $axis->discardedSubElements[0]->reason);
    }

    /**
     * 20文字以上の見出し完全一致はlabel_only_evidenceの対象外(採用される)。
     * 長さだけで切ると本物を殺すため、完全一致かつ20文字未満の両方を
     * 条件にしている ―― この境界を直接固定する。
     */
    public function test_evidence_that_exactly_matches_a_heading_of_twenty_characters_or_more_is_not_discarded(): void
    {
        // 20文字以上(22文字)の見出し。
        $heading = '新規事業を企画・運用する環境が整っています。';
        $this->assertSame(22, mb_strlen($heading));

        $input = $this->makeInput(recruitHeadings: [['level' => 2, 'text' => $heading]]);

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'project_initiative' => ['matched' => true, 'evidence' => $heading],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v5');

        $axis = collect($result->axes)->firstWhere('axisKey', 'will_activity');
        $this->assertCount(1, $axis->matchedSubElements);
        $this->assertSame([], $axis->discardedSubElements);
    }

    /**
     * 2026-08-05の閾値設計修正(DE&Iの実測再現)。リンクラベルは定義上
     * ナビゲーションであり、見出しと違って「長い一致は本物の文章のことが
     * ある」という理屈が成立しない。20文字以上でも完全一致すれば
     * label_only_evidenceとして破棄されることを固定する ―― 20文字閾値を
     * 見出しにのみ適用し、リンクラベルには適用しない、という種類による
     * 条件分岐そのものを確認する回帰テスト。
     */
    public function test_evidence_that_exactly_matches_a_link_label_of_twenty_characters_or_more_is_still_discarded(): void
    {
        // 実測(味の素)そのままの28文字のリンクラベル。
        $longLinkLabel = 'DE&I（ダイバーシティ・エクイティ＆インクルージョン）';
        $this->assertSame(28, mb_strlen($longLinkLabel));

        $input = $this->makeInput(businessLinkLabels: [$longLinkLabel]);

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'core_values' => ['matched' => true, 'evidence' => $longLinkLabel],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v6');

        $axis = collect($result->axes)->firstWhere('axisKey', 'personality');
        $this->assertSame([], $axis->matchedSubElements);
        $this->assertSame('label_only_evidence', $axis->discardedSubElements[0]->reason);
    }

    /**
     * 本文中の短い一文(見出し・リンクラベルではない)は、20文字未満でも
     * label_only_evidenceの対象にならない ―― 完全一致の対象がlabelSet
     * (見出し・リンクラベルの集合)に無いため。実例(leggendaの正当な根拠
     * 「互いに認め合い、高め合うこと」14文字)での回帰確認。
     */
    public function test_a_short_sentence_from_body_text_that_is_not_a_heading_or_label_is_not_discarded(): void
    {
        $input = $this->makeInput(recruitBody: '正解のない仕事をやり抜く鍵は、互いに認め合い、高め合うことです。');
        $shortSentence = '互いに認め合い、高め合うこと';
        $this->assertSame(14, mb_strlen($shortSentence));

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'core_values' => ['matched' => true, 'evidence' => $shortSentence],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v5');

        $axis = collect($result->axes)->firstWhere('axisKey', 'personality');
        $this->assertCount(1, $axis->matchedSubElements);
        $this->assertSame([], $axis->discardedSubElements);
    }

    /**
     * evidenceが見出しの一部だけを含み、見出し全体とは完全一致しない場合
     * (=より具体的な本文の一部として引用されている場合)は、完全一致条件を
     * 満たさないためlabel_only_evidenceの対象にならない。
     */
    public function test_evidence_containing_part_of_a_heading_but_not_an_exact_match_is_not_discarded(): void
    {
        $input = $this->makeInput(
            recruitBody: '事業紹介ページでは、エネルギー事業とモビリティ事業の詳細を説明しています。',
            recruitHeadings: [['level' => 2, 'text' => '事業紹介']],
        );

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'business_expansion' => ['matched' => true, 'evidence' => '事業紹介ページでは、エネルギー事業とモビリティ事業の詳細を説明しています'],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v5');

        $axis = collect($result->axes)->firstWhere('axisKey', 'will_activity');
        $this->assertCount(1, $axis->matchedSubElements);
        $this->assertSame([], $axis->discardedSubElements);
    }

    public function test_result_never_contains_a_numeric_score_field(): void
    {
        $input = $this->makeInput(recruitBody: '本文。');

        $result = $this->parser->parse(['sub_elements' => $this->completeSubElements()], $input, 'openai', 'gpt-4o-mini', false, 'v1');
        $array = $result->toArray();

        $this->assertArrayNotHasKey('score', $array);
        $this->assertArrayNotHasKey('overall_score', $array);
        $this->assertArrayNotHasKey('percentage', $array);
        $this->assertArrayNotHasKey('rating', $array);
        foreach ($array['axes'] as $axis) {
            $this->assertArrayNotHasKey('score', $axis);
        }
    }

    public function test_key_message_is_parsed_when_present(): void
    {
        $input = $this->makeInput(recruitBody: '本文。');

        $result = $this->parser->parse([
            'sub_elements' => $this->completeSubElements(),
            'key_message' => 'このページから読み取れるキーメッセージ。',
            'impression' => ['事実の記載が中心という印象です。'],
        ], $input, 'openai', 'gpt-4o-mini', false, 'v2');

        $this->assertSame('このページから読み取れるキーメッセージ。', $result->keyMessage);
    }

    /**
     * 2026-08-08: impressionはstring(1〜3文)からlist<string>(2〜4件)へ
     * 変更された(prompt_version v7〜)。各項目は個別にevidence実在検証の
     * 対象外(key_messageと同じ、サイト全体の要約・印象のため)だが、
     * forbidden_phrasesチェックは項目ごとに独立して適用される。
     */
    public function test_impression_is_parsed_as_a_list_of_strings_when_present(): void
    {
        $input = $this->makeInput(recruitBody: '本文。');

        $result = $this->parser->parse([
            'sub_elements' => $this->completeSubElements(),
            'impression' => ['事実の記載が中心という印象です。', '情緒的な訴求は控えめです。'],
        ], $input, 'openai', 'gpt-4o-mini', false, 'v7');

        $this->assertSame(['事実の記載が中心という印象です。', '情緒的な訴求は控えめです。'], $result->impression);
    }

    public function test_key_message_is_null_and_impression_is_an_empty_list_when_absent(): void
    {
        $input = $this->makeInput(recruitBody: '本文。');

        $result = $this->parser->parse(['sub_elements' => $this->completeSubElements()], $input, 'openai', 'gpt-4o-mini', false, 'v2');

        $this->assertNull($result->keyMessage);
        $this->assertSame([], $result->impression);
    }

    /**
     * impressionが文字列(旧v6以前の形式)やnullなど配列でない場合は、
     * 例外を投げず空配列として扱う(3-3の縮退方針と同じ、AIの出力形式が
     * 想定と違っても診断全体を止めない)。
     */
    public function test_impression_is_an_empty_list_when_not_an_array(): void
    {
        $input = $this->makeInput(recruitBody: '本文。');

        $result = $this->parser->parse([
            'sub_elements' => $this->completeSubElements(),
            'impression' => 'これは配列ではない旧形式の文字列です。',
        ], $input, 'openai', 'gpt-4o-mini', false, 'v6');

        $this->assertSame([], $result->impression);
    }

    /**
     * impression/key_messageは社外に出る文章のため、evidence実在検証とは別に
     * forbidden_phrasesを含む場合はnullにする(プロンプト側の指示だけに
     * 頼らない、AIの出力を無条件に信用しないという既存方針の適用、
     * 2026-08-03のユーザー指摘)。2026-08-08: impressionは配列化されたため、
     * 禁止語を含む項目だけを個別に落とす ―― 1件が禁止語を含んでいても
     * 他の項目まで巻き添えで捨てない(下位要素の破棄が1件ずつ独立している
     * 既存方針と同じ)。
     */
    public function test_impression_items_containing_a_forbidden_phrase_are_dropped_individually(): void
    {
        $input = $this->makeInput(recruitBody: '本文。');
        $forbiddenPhrase = ((array) config('brand_wheel.forbidden_phrases'))[0];

        $result = $this->parser->parse([
            'sub_elements' => $this->completeSubElements(),
            'impression' => ["この記述は{$forbiddenPhrase}という印象です。", '事実の記載が中心という印象です。'],
        ], $input, 'openai', 'gpt-4o-mini', false, 'v7');

        $this->assertSame(['事実の記載が中心という印象です。'], $result->impression);
    }

    public function test_impression_items_that_are_not_strings_or_are_blank_are_excluded(): void
    {
        $input = $this->makeInput(recruitBody: '本文。');

        $result = $this->parser->parse([
            'sub_elements' => $this->completeSubElements(),
            'impression' => ['有効な印象です。', '   ', null, 123, ''],
        ], $input, 'openai', 'gpt-4o-mini', false, 'v7');

        $this->assertSame(['有効な印象です。'], $result->impression);
    }

    public function test_key_message_containing_a_forbidden_phrase_is_also_discarded_to_null(): void
    {
        // impressionだけでなくkey_messageも同じ画面の紺帯に表示されるため、
        // 安全側に倒して同じ検証を適用する。
        $input = $this->makeInput(recruitBody: '本文。');
        $forbiddenPhrase = ((array) config('brand_wheel.forbidden_phrases'))[0];

        $result = $this->parser->parse([
            'sub_elements' => $this->completeSubElements(),
            'key_message' => "{$forbiddenPhrase}な状態です。",
        ], $input, 'openai', 'gpt-4o-mini', false, 'v2');

        $this->assertNull($result->keyMessage);
    }

    /**
     * 2026-08-04の実測(gpt-5.6-terra)で、同一のevidence文字列が異なる2つの
     * 下位要素の根拠として二重計上される事例が確認されたための回帰テスト。
     * will_activity(config順で最初)とemotional_benefit(config順で後)が同じ
     * 抜粋を主張した場合、config順で先に来るwill_activity側を残す。
     */
    public function test_duplicate_evidence_across_axes_keeps_the_one_earlier_in_config_order(): void
    {
        $input = $this->makeInput(recruitBody: '互いに認め合い、高め合うことを大切にしています。');

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'purpose' => ['matched' => true, 'evidence' => '互いに認め合い、高め合うこと'],
                'pride' => ['matched' => true, 'evidence' => '互いに認め合い、高め合うこと'],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o', false, 'v2');

        $willActivity = collect($result->axes)->firstWhere('axisKey', 'will_activity');
        $emotionalBenefit = collect($result->axes)->firstWhere('axisKey', 'emotional_benefit');

        $this->assertCount(1, $willActivity->matchedSubElements);
        $this->assertSame('purpose', $willActivity->matchedSubElements[0]->key);

        $this->assertSame([], $emotionalBenefit->matchedSubElements);
        $this->assertCount(1, $emotionalBenefit->discardedSubElements);
        $this->assertSame('pride', $emotionalBenefit->discardedSubElements[0]->key);
        $this->assertSame('duplicate_evidence', $emotionalBenefit->discardedSubElements[0]->reason);
        $this->assertSame('互いに認め合い、高め合うこと', $emotionalBenefit->discardedSubElements[0]->evidence);

        // claimedSubElementCountは検証前の申告件数を表すため、重複破棄後も
        // 1のまま(evidence_not_foundと同じ扱い方針)。
        $this->assertSame(1, $emotionalBenefit->claimedSubElementCount);
    }

    /**
     * 同一軸内の異なる下位要素キーが同じevidenceを主張した場合も、
     * 軸をまたぐケースと同じ規則(config順で先に来るほうを残す)で除去する。
     * personalityのsub_elements順はleadership→org_structure→
     * company_character→core_valuesなので、org_structureがcore_valuesより
     * 先に残る。
     */
    public function test_duplicate_evidence_within_the_same_axis_keeps_the_one_earlier_in_sub_element_order(): void
    {
        $input = $this->makeInput(recruitBody: '自らの意思で挑戦する組織です。');

        $raw = [
            'sub_elements' => $this->completeSubElements([
                'core_values' => ['matched' => true, 'evidence' => '自らの意思で挑戦する'],
                'org_structure' => ['matched' => true, 'evidence' => '自らの意思で挑戦する'],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o', false, 'v2');

        $personality = collect($result->axes)->firstWhere('axisKey', 'personality');

        $this->assertCount(1, $personality->matchedSubElements);
        $this->assertSame('org_structure', $personality->matchedSubElements[0]->key);
        $this->assertCount(1, $personality->discardedSubElements);
        $this->assertSame('core_values', $personality->discardedSubElements[0]->key);
        $this->assertSame('duplicate_evidence', $personality->discardedSubElements[0]->reason);
    }

    public function test_evidence_that_only_differs_by_whitespace_or_width_is_still_treated_as_a_duplicate(): void
    {
        $input = $this->makeInput(recruitBody: '2023年に成長機会を提供する制度を新設しました。');

        // financial_benefitのsub_elements順はsalary_level→benefits→
        // growth_opportunity→employment_stabilityなので、benefits(index1)が
        // growth_opportunity(index2)より先に残る。
        $raw = [
            'sub_elements' => $this->completeSubElements([
                'growth_opportunity' => ['matched' => true, 'evidence' => '２０２３年に成長機会を提供する制度を新設'],
                'benefits' => ['matched' => true, 'evidence' => "2023年に\n成長機会を提供する制度を新設"],
            ]),
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o', false, 'v2');

        $financialBenefit = collect($result->axes)->firstWhere('axisKey', 'financial_benefit');

        $this->assertCount(1, $financialBenefit->matchedSubElements);
        $this->assertSame('benefits', $financialBenefit->matchedSubElements[0]->key);
        $this->assertSame('growth_opportunity', $financialBenefit->discardedSubElements[0]->key);
        $this->assertSame('duplicate_evidence', $financialBenefit->discardedSubElements[0]->reason);
    }

    public function test_blank_key_message_is_normalized_to_null_and_empty_impression_list_stays_empty(): void
    {
        $input = $this->makeInput(recruitBody: '本文。');

        $result = $this->parser->parse([
            'sub_elements' => $this->completeSubElements(),
            'key_message' => '   ',
            'impression' => [],
        ], $input, 'openai', 'gpt-4o-mini', false, 'v7');

        $this->assertNull($result->keyMessage);
        $this->assertSame([], $result->impression);
    }

    /**
     * 3-3の縮退方針: 24キーのうち5個欠落(1/4=6個の閾値以下)しても、
     * 例外にならず残り19個で成立する。欠落キーはmatched:false扱いになる。
     */
    public function test_up_to_six_missing_sub_element_keys_degrade_instead_of_throwing(): void
    {
        $input = $this->makeInput(recruitBody: 'パーパスを掲げています。');

        $subElements = $this->completeSubElements([
            'purpose' => ['matched' => true, 'evidence' => 'パーパスを掲げています'],
        ]);

        // 5キーを完全に欠落させる(配列から削除する ―― キー自体が無い状態)。
        foreach (['talkable', 'satisfaction', 'superiority', 'salary_level', 'benefits'] as $missingKey) {
            unset($subElements[$missingKey]);
        }

        $result = $this->parser->parse(['sub_elements' => $subElements], $input, 'openai', 'gpt-4o', false, 'v4');

        // 欠落キーはmatched:false扱いとして通常どおりstateへ反映される
        // (該当なしのため空配列・unreadになる)。
        $emotionalBenefit = collect($result->axes)->firstWhere('axisKey', 'emotional_benefit');
        $this->assertSame([], $emotionalBenefit->matchedSubElements);
        $this->assertSame('unread', $emotionalBenefit->state);

        $willActivity = collect($result->axes)->firstWhere('axisKey', 'will_activity');
        $this->assertCount(1, $willActivity->matchedSubElements);
    }

    /**
     * 3-3の縮退方針の境界: 24キーのうち7個欠落(1/4=6個を超過)すると、
     * 診断を丸ごと捨てず「異常」として扱うため、AI_INCOMPLETE_SCHEMAを投げる。
     */
    public function test_more_than_six_missing_sub_element_keys_throws_ai_incomplete_schema(): void
    {
        $input = $this->makeInput(recruitBody: 'パーパスを掲げています。');

        $subElements = $this->completeSubElements();

        foreach ([
            'talkable', 'satisfaction', 'superiority', 'salary_level',
            'benefits', 'growth_opportunity', 'employment_stability',
        ] as $missingKey) {
            unset($subElements[$missingKey]);
        }

        $this->expectException(BrandWheelAnalysisException::class);

        try {
            $this->parser->parse(['sub_elements' => $subElements], $input, 'openai', 'gpt-4o', false, 'v4');
        } catch (BrandWheelAnalysisException $e) {
            $this->assertSame('AI_INCOMPLETE_SCHEMA', $e->errorCode);

            throw $e;
        }
    }

    /**
     * matched が真偽値でない(構造が壊れている)エントリも欠落キーと同じ扱いに
     * すること ―― 「キー自体が無い」場合と「値の型が壊れている」場合を
     * 区別しない(いずれも安全側に倒してmatched:falseとする)。
     */
    public function test_malformed_matched_value_is_treated_as_missing_and_degrades(): void
    {
        $input = $this->makeInput(recruitBody: '本文。');

        $subElements = $this->completeSubElements();
        $subElements['purpose'] = ['matched' => 'yes', 'evidence' => null];

        $result = $this->parser->parse(['sub_elements' => $subElements], $input, 'openai', 'gpt-4o', false, 'v4');

        $axis = collect($result->axes)->firstWhere('axisKey', 'will_activity');
        $this->assertSame([], $axis->matchedSubElements);
    }
}
