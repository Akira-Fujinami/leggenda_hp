<?php

namespace Tests\Unit\Services\Analysis;

use App\Services\Analysis\RecruitmentTrackPageFilter;
use Tests\TestCase;

/**
 * 依頼BB-2/依頼BC-1〜3: 除外判定・区分適用可否判定の単体テスト。
 */
class RecruitmentTrackPageFilterTest extends TestCase
{
    private function filter(): RecruitmentTrackPageFilter
    {
        return new RecruitmentTrackPageFilter;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['brand_wheel.recruitment_track_keywords' => [
            'new_graduate' => ['shinsotsu', 'students', 'student', 'graduate', '新卒'],
            'career' => ['career', 'careers', 'chuto', 'mid-career', '中途', 'キャリア採用'],
        ]]);
        config(['brand_wheel.recruitment_track_recruit_section_hostname_keywords' => [
            'recruit', 'careers', 'career', 'saiyo', 'job', 'jobs', 'hr', 'recruiting', 'employment',
        ]]);
        config(['brand_wheel.recruitment_track_recruit_section_path_keywords' => [
            'recruit', 'careers', 'career', 'saiyo', 'job', 'jobs', 'hr', '採用', 'recruiting', 'employment',
        ]]);
    }

    /**
     * 起点がホスト名自体にcareersを含む(https://careers.mercari.com/)場合、
     * ホスト名を理由に除外されないこと(依頼BB「最重要」)。
     */
    public function test_host_name_keyword_is_never_used_for_exclusion(): void
    {
        $origin = 'https://careers.mercari.com/';

        // ホストにcareersを含むだけで、パス自体には反対側の語が無い候補は
        // 除外されない。
        $this->assertFalse($this->filter()->shouldExclude(
            'https://careers.mercari.com/jp/about/',
            $origin,
            'new_graduate',
        ));
    }

    /**
     * 起点パス自体(/careers/)に含まれるcareersを理由に除外されないこと。
     */
    public function test_origin_path_keyword_is_never_used_for_exclusion(): void
    {
        $origin = 'https://www.cyberagent.co.jp/careers/';

        $this->assertFalse($this->filter()->shouldExclude(
            'https://www.cyberagent.co.jp/careers/',
            $origin,
            'new_graduate',
        ));
    }

    public function test_new_graduate_side_word_beyond_origin_path_is_kept(): void
    {
        $origin = 'https://www.cyberagent.co.jp/careers/';

        $this->assertFalse($this->filter()->shouldExclude(
            'https://www.cyberagent.co.jp/careers/students/',
            $origin,
            'new_graduate',
        ));
    }

    public function test_career_side_word_beyond_origin_path_is_excluded_when_new_graduate_selected(): void
    {
        $origin = 'https://www.cyberagent.co.jp/careers/';

        $this->assertTrue($this->filter()->shouldExclude(
            'https://www.cyberagent.co.jp/careers/mid-career/',
            $origin,
            'new_graduate',
        ));
    }

    public function test_new_graduate_side_word_is_excluded_when_career_selected(): void
    {
        $origin = 'https://www.cyberagent.co.jp/careers/';

        $this->assertTrue($this->filter()->shouldExclude(
            'https://www.cyberagent.co.jp/careers/students/',
            $origin,
            'career',
        ));
        $this->assertFalse($this->filter()->shouldExclude(
            'https://www.cyberagent.co.jp/careers/mid-career/',
            $origin,
            'career',
        ));
    }

    public function test_pages_without_either_keyword_are_kept(): void
    {
        $origin = 'https://www.cyberagent.co.jp/careers/';

        foreach (['/about/', '/benefits/', '/interview/'] as $path) {
            $this->assertFalse($this->filter()->shouldExclude(
                "https://www.cyberagent.co.jp/careers{$path}",
                $origin,
                'new_graduate',
            ), "{$path} は誤って除外されてはいけない");
            $this->assertFalse($this->filter()->shouldExclude(
                "https://www.cyberagent.co.jp/careers{$path}",
                $origin,
                'career',
            ), "{$path} は誤って除外されてはいけない");
        }
    }

    public function test_matching_is_case_insensitive(): void
    {
        $origin = 'https://www.cyberagent.co.jp/careers/';

        $this->assertFalse($this->filter()->shouldExclude(
            'https://www.cyberagent.co.jp/Careers/Students/',
            $origin,
            'new_graduate',
        ));
        $this->assertTrue($this->filter()->shouldExclude(
            'https://www.cyberagent.co.jp/Careers/Students/',
            $origin,
            'career',
        ));
    }

    public function test_matching_decodes_japanese_paths(): void
    {
        config(['brand_wheel.recruitment_track_keywords' => [
            'new_graduate' => ['新卒'],
            'career' => ['中途', 'キャリア採用'],
        ]]);
        $origin = 'https://example.co.jp/';

        $encodedShinsotsu = 'https://example.co.jp/'.rawurlencode('採用').'/'.rawurlencode('新卒').'/';
        $encodedChuto = 'https://example.co.jp/'.rawurlencode('採用').'/'.rawurlencode('中途').'/';

        $this->assertFalse($this->filter()->shouldExclude($encodedShinsotsu, $origin, 'new_graduate'));
        $this->assertTrue($this->filter()->shouldExclude($encodedShinsotsu, $origin, 'career'));
        $this->assertTrue($this->filter()->shouldExclude($encodedChuto, $origin, 'new_graduate'));
        $this->assertFalse($this->filter()->shouldExclude($encodedChuto, $origin, 'career'));
    }

    public function test_unspecified_track_never_excludes_anything(): void
    {
        $origin = 'https://www.cyberagent.co.jp/careers/';

        $this->assertFalse($this->filter()->shouldExclude(
            'https://www.cyberagent.co.jp/careers/mid-career/',
            $origin,
            'unspecified',
        ));
    }

    /**
     * 依頼BC-1: 起点パスに前方一致するだけの別ブランチ(/career-portal/)は、
     * 起点の内側とはみなさず、セグメント全体("career-portal")を新しい
     * 部分として判定する(境界ガード自体は依頼BBのまま変更しない)。ただし
     * セグメント完全一致に切り替えたことで、"career-portal"は"career"とは
     * 一致しなくなり、除外されなくなる(依頼BC-1で修正した部分一致の問題の
     * 典型例そのもの)。
     */
    public function test_sibling_path_that_merely_shares_a_string_prefix_is_not_excluded(): void
    {
        $origin = 'https://example.co.jp/career';

        $this->assertFalse($this->filter()->shouldExclude(
            'https://example.co.jp/career-portal/students/',
            $origin,
            'new_graduate',
        ));
    }

    public function test_query_string_is_also_checked(): void
    {
        $origin = 'https://example.co.jp/recruit/';

        $this->assertTrue($this->filter()->shouldExclude(
            'https://example.co.jp/recruit/list?track=chuto',
            $origin,
            'new_graduate',
        ));
    }

    // ------------------------------------------------------------------
    // 依頼BC-1: パスセグメントの境界一致(半角英数=完全一致、日本語=部分一致)。
    // ------------------------------------------------------------------

    /**
     * "career"を含むだけの共通ページ(新卒・キャリアの区別ではなく、
     * 入社後の成長機会を説明するページ)は、部分一致では誤って除外されて
     * いた。セグメント完全一致に切り替えたことで残ること。
     */
    public function test_common_pages_that_merely_contain_the_substring_career_are_kept(): void
    {
        $origin = 'https://example.co.jp/recruit/';

        foreach (['career-path', 'careerplan', 'career-support', 'career-development'] as $segment) {
            $this->assertFalse($this->filter()->shouldExclude(
                "https://example.co.jp/recruit/{$segment}/",
                $origin,
                'new_graduate',
            ), "/{$segment}/ は共通ページのため除外されてはいけない");
        }
    }

    /**
     * ハイフンを含む語("mid-career")自体はセグメント全体との完全一致で
     * 正しくヒットする(セグメント分割は"/"のみで行い、語の内部の"-"では
     * 分割しないため)。
     */
    public function test_hyphenated_keyword_still_matches_the_full_segment(): void
    {
        $origin = 'https://example.co.jp/recruit/';

        $this->assertTrue($this->filter()->shouldExclude(
            'https://example.co.jp/recruit/mid-career/',
            $origin,
            'new_graduate',
        ));
    }

    /**
     * "intern"を含むだけの無関係なセグメント("international"・"internal-*")は
     * 除外されないこと(依頼BC-1でintern単体を語一覧から削除したため、
     * 本番設定では最初から該当しないが、テスト用の一覧に"intern"を含めた
     * 場合でもセグメント完全一致であれば無関係な語には誤爆しないことを
     * 明示的に確認する)。
     */
    public function test_intern_keyword_does_not_match_unrelated_segments_containing_it_as_a_substring(): void
    {
        config(['brand_wheel.recruitment_track_keywords' => [
            'new_graduate' => ['intern', 'internship'],
            'career' => ['career'],
        ]]);
        $origin = 'https://example.co.jp/';

        $this->assertFalse($this->filter()->shouldExclude(
            'https://example.co.jp/company/international/',
            $origin,
            'career',
        ));
        $this->assertFalse($this->filter()->shouldExclude(
            'https://example.co.jp/recruit/internal-system/',
            $origin,
            'career',
        ));
        $this->assertTrue($this->filter()->shouldExclude(
            'https://example.co.jp/recruit/intern/',
            $origin,
            'career',
        ));
    }

    /**
     * 日本語の語は、依頼BB当初どおりセグメント内の部分一致でよい
     * ("新卒採用"という1セグメントの中に"新卒"が現れる形を想定)。
     */
    public function test_japanese_keyword_matches_as_a_substring_within_a_segment(): void
    {
        $origin = 'https://example.co.jp/';

        $this->assertTrue($this->filter()->shouldExclude(
            'https://example.co.jp/'.rawurlencode('新卒採用').'/',
            $origin,
            'career',
        ));
    }

    /**
     * "キャリア"単体は語一覧から削除した ―― "キャリアパス"・
     * "キャリア形成"のような共通ページに誤爆しないことを確認する。
     */
    public function test_bare_japanese_career_word_is_not_in_the_default_list_and_common_pages_survive(): void
    {
        config(['brand_wheel.recruitment_track_keywords' => [
            'new_graduate' => ['新卒'],
            'career' => ['キャリア採用', 'キャリア入社'],
        ]]);
        $origin = 'https://example.co.jp/recruit/';

        foreach (['キャリアパス', 'キャリア形成', 'キャリアアップ'] as $segment) {
            $this->assertFalse($this->filter()->shouldExclude(
                'https://example.co.jp/recruit/'.rawurlencode($segment).'/',
                $origin,
                'new_graduate',
            ), "{$segment} は共通ページのため除外されてはいけない");
        }

        $this->assertTrue($this->filter()->shouldExclude(
            'https://example.co.jp/recruit/'.rawurlencode('キャリア採用').'/',
            $origin,
            'new_graduate',
        ));
    }

    /**
     * クエリのパラメータ値は完全一致で判定する(部分一致にしない) ――
     * "?type=careerpath"のような無関係な値には誤爆しないこと。
     */
    public function test_query_parameter_value_requires_an_exact_match_not_a_substring(): void
    {
        $origin = 'https://example.co.jp/recruit/';

        $this->assertFalse($this->filter()->shouldExclude(
            'https://example.co.jp/recruit/list?type=careerpath',
            $origin,
            'new_graduate',
        ));
        $this->assertTrue($this->filter()->shouldExclude(
            'https://example.co.jp/recruit/list?type=career',
            $origin,
            'new_graduate',
        ));
    }

    // ------------------------------------------------------------------
    // 依頼BC-2: 両側の語を含むページは除外しない(残す)。
    // ------------------------------------------------------------------

    /**
     * 起点が/recruit/(採用トップ自体には反対側の語が無い)で、候補URLに
     * 「反対側」の語(careers)と「選んだ側」の語(students)の両方が
     * 含まれる場合、除外せず残す(新卒選択時)。
     */
    public function test_page_containing_both_sides_words_is_kept_when_new_graduate_selected(): void
    {
        $origin = 'https://example.co.jp/recruit/';

        $this->assertFalse($this->filter()->shouldExclude(
            'https://example.co.jp/recruit/careers/students/',
            $origin,
            'new_graduate',
        ));
    }

    /**
     * 同じURLに対し、キャリア選択時も一貫して残す(依頼者の推奨どおり、
     * どちら向きでも「残す」に統一する ―― 実装報告で根拠を説明)。
     */
    public function test_page_containing_both_sides_words_is_kept_when_career_selected(): void
    {
        $origin = 'https://example.co.jp/recruit/';

        $this->assertFalse($this->filter()->shouldExclude(
            'https://example.co.jp/recruit/careers/students/',
            $origin,
            'career',
        ));
    }

    // ------------------------------------------------------------------
    // 依頼BC-3/依頼BD-2: 起点が採用セクションの外にあるとき、区分を適用しない。
    // ------------------------------------------------------------------

    public function test_origin_with_a_recruit_word_in_the_path_is_inside_the_recruit_section(): void
    {
        $this->assertTrue($this->filter()->isOriginInsideRecruitSection('https://www.example.co.jp/recruit/'));
        $this->assertTrue($this->filter()->isOriginInsideRecruitSection('https://www.cyberagent.co.jp/careers/'));
        $this->assertTrue($this->filter()->isOriginInsideRecruitSection('https://www.example.co.jp/saiyo/shinsotsu/'));
    }

    public function test_origin_at_root_with_a_recruit_hostname_word_is_inside_the_recruit_section(): void
    {
        $this->assertTrue($this->filter()->isOriginInsideRecruitSection('https://careers.mercari.com/'));
        $this->assertTrue($this->filter()->isOriginInsideRecruitSection('https://recruit-example.co.jp/'));
    }

    public function test_origin_at_root_without_a_recruit_hostname_word_is_outside_the_recruit_section(): void
    {
        $this->assertFalse($this->filter()->isOriginInsideRecruitSection('https://www.example.co.jp/'));
        $this->assertFalse($this->filter()->isOriginInsideRecruitSection('https://www.example.co.jp'));
    }

    /**
     * 依頼BD-1: ホスト名の判定は部分一致ではなくラベル(`.`/`-`区切り)単位の
     * 完全一致にする ―― "threebond"は"three"+"bond"のどちらのラベルにも
     * "hr"という2文字の部分文字列としては現れるが、ラベル全体としては
     * "hr"と一致しないため、区分は適用されない。
     */
    public function test_hostname_substring_match_no_longer_false_positives_on_short_keywords(): void
    {
        $this->assertFalse($this->filter()->isOriginInsideRecruitSection('https://threebond.co.jp/'));
    }

    /**
     * 依頼BD-2: 言語プレフィックス付きのコーポレートトップ(採用系の語を
     * 一切含まない)は、ルートパスでなくても「内側」と判定しない ――
     * 旧実装(依頼BC-3、「ルートでなければ内側」)はこれらを誤って
     * 「内側」と判定し、除外の適用対象にしてしまっていた。
     */
    public function test_language_prefixed_corporate_top_without_a_recruit_word_is_outside_the_recruit_section(): void
    {
        $this->assertFalse($this->filter()->isOriginInsideRecruitSection('https://www.example.co.jp/ja/'));
        $this->assertFalse($this->filter()->isOriginInsideRecruitSection('https://www.example.co.jp/jp/'));
        $this->assertFalse($this->filter()->isOriginInsideRecruitSection('https://www.example.co.jp/index.html'));
    }

    // ------------------------------------------------------------------
    // 依頼BE-3: ハイフンをアンダースコアで代用した表記ゆれの語を追加。
    // マッチ方式(セグメント完全一致)は変更しない。
    // ------------------------------------------------------------------

    public function test_underscore_variant_new_graduate_is_excluded_when_career_selected(): void
    {
        config(['brand_wheel.recruitment_track_keywords' => [
            'new_graduate' => ['new_graduate', 'graduate'],
            'career' => ['career', 'mid_career', 'mid-career'],
        ]]);
        $origin = 'https://example.co.jp/recruit/';

        $this->assertTrue($this->filter()->shouldExclude(
            'https://example.co.jp/recruit/new_graduate/',
            $origin,
            'career',
        ));
    }

    public function test_underscore_variant_mid_career_is_excluded_when_new_graduate_selected(): void
    {
        config(['brand_wheel.recruitment_track_keywords' => [
            'new_graduate' => ['new_graduate', 'graduate'],
            'career' => ['career', 'mid_career', 'mid-career'],
        ]]);
        $origin = 'https://example.co.jp/recruit/';

        $this->assertTrue($this->filter()->shouldExclude(
            'https://example.co.jp/recruit/mid_career/',
            $origin,
            'new_graduate',
        ));
    }

    /**
     * 依頼BC-1の回帰確認: `new_graduate`/`mid_career`を追加しても、
     * `career-path`(共通ページ)・`forcareer`(旧実装で誤爆していた実例)は
     * 引き続き残ること ―― セグメント完全一致というマッチ方式自体は
     * 変えていないため、これらのハイフン連結・非分割セグメントには
     * 一致しない。
     */
    public function test_career_path_and_forcareer_still_survive_after_the_be3_additions(): void
    {
        config(['brand_wheel.recruitment_track_keywords' => [
            'new_graduate' => ['new_graduate', 'graduate', 'students', 'student'],
            'career' => ['career', 'careers', 'mid_career', 'mid-career'],
        ]]);
        $origin = 'https://example.co.jp/recruit/';

        $this->assertFalse($this->filter()->shouldExclude(
            'https://example.co.jp/recruit/career-path/',
            $origin,
            'new_graduate',
        ));
        $this->assertFalse($this->filter()->shouldExclude(
            'https://example.co.jp/recruit/forcareer/',
            $origin,
            'new_graduate',
        ));
    }

    // ------------------------------------------------------------------
    // 依頼BF-1: `jobs`をホスト名/パスいずれの判定語一覧にも追加。
    // ------------------------------------------------------------------

    /**
     * 依頼BE-1〜3の調査で実際に触ったサイト(jobs.freee.co.jp)そのものが、
     * `job`(単数形)しか一覧に無かったために「採用セクションの外」と
     * 誤判定されていた実例。コーパス実測(189件)に基づき`jobs`を追加した
     * ことで、ルートパスのままでも「内側」と判定されること。
     */
    public function test_jobs_freee_co_jp_is_inside_the_recruit_section(): void
    {
        $this->assertTrue($this->filter()->isOriginInsideRecruitSection('https://jobs.freee.co.jp/'));
    }

    /**
     * `recruiting`/`employment`をホスト側一覧にも追加した(依頼BF-2、
     * パス側との非対称を解消)。
     */
    public function test_recruiting_and_employment_hostnames_are_inside_the_recruit_section(): void
    {
        $this->assertTrue($this->filter()->isOriginInsideRecruitSection('https://recruiting.example.co.jp/'));
        $this->assertTrue($this->filter()->isOriginInsideRecruitSection('https://employment.example.co.jp/'));
    }
}
