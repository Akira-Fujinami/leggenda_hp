<?php

namespace Tests\Unit\BrandWheel;

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
        );
    }

    public function test_evidence_that_exists_in_the_source_text_survives_and_is_used_for_state(): void
    {
        $input = $this->makeInput(recruitBody: '私たちはエネルギー事業とモビリティ事業を展開しています。');

        $raw = [
            'axes' => [
                'will_activity' => ['matched_sub_elements' => [
                    ['key' => 'business_expansion', 'evidence' => 'エネルギー事業とモビリティ事業を展開しています'],
                ]],
            ],
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
            'axes' => [
                'will_activity' => ['matched_sub_elements' => [
                    ['key' => 'business_expansion', 'evidence' => 'これはサイトに存在しない捏造された抜粋です'],
                ]],
            ],
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

    public function test_unknown_sub_element_key_is_discarded_and_not_counted_as_claimed(): void
    {
        $input = $this->makeInput(recruitBody: '存在する本文です。');

        $raw = [
            'axes' => [
                'will_activity' => ['matched_sub_elements' => [
                    ['key' => 'not_a_real_sub_element', 'evidence' => '存在する本文です'],
                ]],
            ],
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v1');

        $axis = collect($result->axes)->firstWhere('axisKey', 'will_activity');
        $this->assertSame([], $axis->matchedSubElements);
        $this->assertSame(0, $axis->claimedSubElementCount);
        $this->assertCount(1, $axis->discardedSubElements);
        $this->assertSame('unknown_sub_element', $axis->discardedSubElements[0]->reason);
    }

    public function test_empty_evidence_is_discarded_and_not_counted_as_claimed(): void
    {
        $input = $this->makeInput(recruitBody: '存在する本文です。');

        $raw = [
            'axes' => [
                'will_activity' => ['matched_sub_elements' => [
                    ['key' => 'business_expansion', 'evidence' => '   '],
                ]],
            ],
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
            'axes' => [
                'will_activity' => ['matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => 'パーパスを掲げ'],
                    ['key' => 'business_expansion', 'evidence' => '事業を展開し'],
                ]],
            ],
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
            'axes' => [
                'emotional_benefit' => ['matched_sub_elements' => [
                    ['key' => 'pride', 'evidence' => '誇りを持って働ける'],
                ]],
            ],
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
            'axes' => [
                'will_activity' => ['matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => 'パーパスを掲げています'],
                ]],
                'emotional_benefit' => ['matched_sub_elements' => [
                    ['key' => 'pride', 'evidence' => '誇りを持って働ける'],
                ]],
            ],
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

        $raw = ['core_value' => ['readable' => true, 'evidence' => '存在しないでっちあげの抜粋']];
        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v1');
        $this->assertFalse($result->coreValue->readable);
        $this->assertNull($result->coreValue->evidence);

        $raw = ['core_value' => ['readable' => true, 'evidence' => '仕事の舞台裏にこそ価値がある']];
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
            'axes' => [
                'asset' => ['matched_sub_elements' => [
                    ['key' => 'office_facility', 'evidence' => '２０２３年に新オフィスＡＢＣ１２４を開設'],
                ]],
            ],
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
            'axes' => [
                'asset' => ['matched_sub_elements' => [
                    ['key' => 'competitiveness', 'evidence' => '資産は十分です事業は展開しています'],
                ]],
            ],
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v1');

        $axis = collect($result->axes)->firstWhere('axisKey', 'asset');
        $this->assertSame([], $axis->matchedSubElements);
        $this->assertSame('evidence_not_found', $axis->discardedSubElements[0]->reason);
    }

    public function test_evidence_may_come_from_headings_or_business_link_labels_not_only_body_text(): void
    {
        $input = $this->makeInput(
            recruitHeadings: [['level' => 2, 'text' => '挑戦する人材求む']],
            businessLinkLabels: ['エネルギー事業'],
        );

        $raw = [
            'axes' => [
                'will_activity' => ['matched_sub_elements' => [
                    ['key' => 'business_expansion', 'evidence' => 'エネルギー事業'],
                    ['key' => 'project_initiative', 'evidence' => '挑戦する人材求む'],
                ]],
            ],
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o-mini', false, 'v1');

        $axis = collect($result->axes)->firstWhere('axisKey', 'will_activity');
        $this->assertCount(2, $axis->matchedSubElements);
    }

    public function test_result_never_contains_a_numeric_score_field(): void
    {
        $input = $this->makeInput(recruitBody: '本文。');

        $result = $this->parser->parse([], $input, 'openai', 'gpt-4o-mini', false, 'v1');
        $array = $result->toArray();

        $this->assertArrayNotHasKey('score', $array);
        $this->assertArrayNotHasKey('overall_score', $array);
        $this->assertArrayNotHasKey('percentage', $array);
        $this->assertArrayNotHasKey('rating', $array);
        foreach ($array['axes'] as $axis) {
            $this->assertArrayNotHasKey('score', $axis);
        }
    }

    public function test_key_message_and_impression_are_parsed_when_present(): void
    {
        $input = $this->makeInput(recruitBody: '本文。');

        $result = $this->parser->parse([
            'key_message' => 'このページから読み取れるキーメッセージ。',
            'impression' => 'このページが与える印象は、事実の記載が中心という印象です。',
        ], $input, 'openai', 'gpt-4o-mini', false, 'v2');

        $this->assertSame('このページから読み取れるキーメッセージ。', $result->keyMessage);
        $this->assertSame('このページが与える印象は、事実の記載が中心という印象です。', $result->impression);
    }

    public function test_key_message_and_impression_are_null_when_absent(): void
    {
        $input = $this->makeInput(recruitBody: '本文。');

        $result = $this->parser->parse([], $input, 'openai', 'gpt-4o-mini', false, 'v2');

        $this->assertNull($result->keyMessage);
        $this->assertNull($result->impression);
    }

    /**
     * impression/key_messageは社外に出る文章のため、evidence実在検証とは別に
     * forbidden_phrasesを含む場合はnullにする(プロンプト側の指示だけに
     * 頼らない、AIの出力を無条件に信用しないという既存方針の適用、
     * 2026-08-03のユーザー指摘)。
     */
    public function test_impression_containing_a_forbidden_phrase_is_discarded_to_null(): void
    {
        $input = $this->makeInput(recruitBody: '本文。');
        $forbiddenPhrase = ((array) config('brand_wheel.forbidden_phrases'))[0];

        $result = $this->parser->parse([
            'impression' => "この記述は{$forbiddenPhrase}という印象です。",
        ], $input, 'openai', 'gpt-4o-mini', false, 'v2');

        $this->assertNull($result->impression);
    }

    public function test_key_message_containing_a_forbidden_phrase_is_also_discarded_to_null(): void
    {
        // impressionだけでなくkey_messageも同じ画面の紺帯に表示されるため、
        // 安全側に倒して同じ検証を適用する。
        $input = $this->makeInput(recruitBody: '本文。');
        $forbiddenPhrase = ((array) config('brand_wheel.forbidden_phrases'))[0];

        $result = $this->parser->parse([
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
            'axes' => [
                'will_activity' => ['matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => '互いに認め合い、高め合うこと'],
                ]],
                'emotional_benefit' => ['matched_sub_elements' => [
                    ['key' => 'pride', 'evidence' => '互いに認め合い、高め合うこと'],
                ]],
            ],
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
            'axes' => [
                'personality' => ['matched_sub_elements' => [
                    ['key' => 'core_values', 'evidence' => '自らの意思で挑戦する'],
                    ['key' => 'org_structure', 'evidence' => '自らの意思で挑戦する'],
                ]],
            ],
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
            'axes' => [
                'financial_benefit' => ['matched_sub_elements' => [
                    ['key' => 'growth_opportunity', 'evidence' => '２０２３年に成長機会を提供する制度を新設'],
                    ['key' => 'benefits', 'evidence' => "2023年に\n成長機会を提供する制度を新設"],
                ]],
            ],
        ];

        $result = $this->parser->parse($raw, $input, 'openai', 'gpt-4o', false, 'v2');

        $financialBenefit = collect($result->axes)->firstWhere('axisKey', 'financial_benefit');

        $this->assertCount(1, $financialBenefit->matchedSubElements);
        $this->assertSame('benefits', $financialBenefit->matchedSubElements[0]->key);
        $this->assertSame('growth_opportunity', $financialBenefit->discardedSubElements[0]->key);
        $this->assertSame('duplicate_evidence', $financialBenefit->discardedSubElements[0]->reason);
    }

    public function test_blank_key_message_and_impression_are_normalized_to_null(): void
    {
        $input = $this->makeInput(recruitBody: '本文。');

        $result = $this->parser->parse([
            'key_message' => '   ',
            'impression' => '',
        ], $input, 'openai', 'gpt-4o-mini', false, 'v2');

        $this->assertNull($result->keyMessage);
        $this->assertNull($result->impression);
    }
}
