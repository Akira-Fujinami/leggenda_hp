<?php

namespace Tests\Unit\BrandWheel;

use App\Services\BrandWheel\BrandWheelReasonBracketNameValidator;
use Tests\TestCase;

/**
 * 依頼AP-1(2026-08-28): focus_items_reason中の『』の中身が24項目
 * (sub_elements)・6カテゴリ(axes.*.name_ja)・3領域(group_labels)いずれの
 * 正式名称とも一致しない場合を検知する ―― AIがaxis_name/sub_nameを混ぜて
 * 存在しない名前を合成する事故(実物確認: 依頼AOの検証で「事業運営スタイル」
 * という存在しない名前が出力された)への機械的な防御。
 */
class BrandWheelReasonBracketNameValidatorTest extends TestCase
{
    private function validator(): BrandWheelReasonBracketNameValidator
    {
        return new BrandWheelReasonBracketNameValidator;
    }

    public function test_returns_empty_when_text_is_null(): void
    {
        $this->assertSame([], $this->validator()->invalidBracketedNames(null));
    }

    public function test_returns_empty_when_text_has_no_brackets(): void
    {
        $this->assertSame([], $this->validator()->invalidBracketedNames('カードの内容は特にありません。'));
    }

    /**
     * 24項目(sub_elements)の正式名称は有効。
     */
    public function test_accepts_all_24_official_sub_element_names(): void
    {
        $validator = $this->validator();

        foreach ((array) config('brand_wheel.axes') as $axis) {
            foreach ((array) $axis['sub_elements'] as $subName) {
                $this->assertSame(
                    [],
                    $validator->invalidBracketedNames("『{$subName}』について触れています。"),
                    "sub_name={$subName} が誤って無効と判定された"
                );
            }
        }
    }

    /**
     * 6カテゴリ(axes.*.name_ja)の正式名称も有効(依頼者指定: 24項目・6カテゴリ・
     * 3領域のいずれか)。
     */
    public function test_accepts_all_6_axis_category_names(): void
    {
        $validator = $this->validator();

        foreach ((array) config('brand_wheel.axes') as $axis) {
            $this->assertSame(
                [],
                $validator->invalidBracketedNames("『{$axis['name_ja']}』の観点から重要です。"),
            );
        }
    }

    /**
     * 3領域(group_labels)の正式名称も有効。
     */
    public function test_accepts_all_3_group_labels(): void
    {
        $validator = $this->validator();

        foreach ((array) config('brand_wheel.group_labels') as $label) {
            $this->assertSame([], $validator->invalidBracketedNames("『{$label}』において差があります。"));
        }
    }

    /**
     * 実物確認: axis_name(経営スタイル)とsub_name(組織構造等)が混ざって
     * 合成された、実在しない名前を検知する。
     */
    public function test_detects_a_nonexistent_composed_name(): void
    {
        $result = $this->validator()->invalidBracketedNames(
            '御社は既に『事業運営スタイル』を伝えており、組織構造を具体的に示すことで候補者の理解が深まる可能性があります。'
        );

        $this->assertSame(['事業運営スタイル'], $result);
    }

    public function test_detects_multiple_distinct_invalid_names_without_duplicates(): void
    {
        $result = $this->validator()->invalidBracketedNames(
            '『架空の項目A』は重要です。『架空の項目A』はもう一度言及されています。『架空の項目B』も重要です。'
        );

        $this->assertSame(['架空の項目A', '架空の項目B'], $result);
    }

    public function test_mixed_valid_and_invalid_names_reports_only_the_invalid_one(): void
    {
        $result = $this->validator()->invalidBracketedNames(
            '御社は既に『リーダーシップ』を伝えており、『架空の項目』についても触れるべきです。'
        );

        $this->assertSame(['架空の項目'], $result);
    }
}
