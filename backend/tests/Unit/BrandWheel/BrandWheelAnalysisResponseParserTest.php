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
}
