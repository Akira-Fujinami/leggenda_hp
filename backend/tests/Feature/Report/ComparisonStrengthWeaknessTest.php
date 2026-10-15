<?php

namespace Tests\Feature\Report;

use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\Report\ComparisonStrengthWeaknessComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * 依頼CT-1: ブランド・ホイール比較の見出しの下の「強み」「弱み」の一文。点数から決まった形で作る(AIは使わない)。
 */
class ComparisonStrengthWeaknessTest extends TestCase
{
    use RefreshDatabase;

    private const AXES = ['活動的魅力', '資産的魅力', '経営スタイル', '就業環境', '情緒的便益', '金銭的便益'];

    /**
     * @param  list<int>  $self  領域ごとの自社の点
     * @param  list<list<int>>  $competitors  競合ごとの、領域ごとの点
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function data(array $self, array $competitors, array $names = ['A社', 'B社', 'C社', 'D社', 'E社'], array $insufficient = []): array
    {
        $axes = [];
        foreach (self::AXES as $i => $name) {
            $counts = array_column($competitors, $i);
            $axes[] = ['name' => $name, 'caption' => null, 'denominator' => 4, 'self_count' => $self[$i], 'competitor_counts' => $counts, 'self_gap' => $self[$i] < max($counts)];
        }
        $companies = [['name' => '自社名', 'matched' => array_sum($self), 'total' => 24, 'is_self' => true, 'material_sufficient' => ! in_array(0, $insufficient, true)]];
        foreach ($competitors as $j => $c) {
            $companies[] = ['name' => $names[$j], 'matched' => array_sum($c), 'total' => 24, 'is_self' => false, 'material_sufficient' => ! in_array($j + 1, $insufficient, true)];
        }

        return [$companies, $axes];
    }

    private function compose(array $self, array $competitors, array $names = ['A社', 'B社', 'C社', 'D社', 'E社'], array $insufficient = []): ?array
    {
        [$companies, $axes] = $this->data($self, $competitors, $names, $insufficient);

        return (new ComparisonStrengthWeaknessComposer)->compose($companies, $axes, true);
    }

    public function test_the_strength_is_the_area_with_the_largest_lead_over_the_best_competitor(): void
    {
        // 活動的魅力 4(競合最高3, 差1)、情緒的便益 3(競合最高0, 差3) → 差が最大の情緒的便益。
        $out = $this->compose([4, 1, 1, 1, 3, 1], [[3, 2, 2, 2, 0, 2], [1, 2, 4, 3, 0, 1], [2, 1, 2, 1, 0, 3]]);

        $this->assertSame('情緒的便益は3/4で、競合3社の最高（0/4）を上回っています。', $out['strength']);
    }

    public function test_the_strength_example_of_the_request(): void
    {
        $out = $this->compose([4, 1, 0, 0, 0, 0], [[3, 2, 4, 1, 4, 2], [1, 2, 2, 2, 1, 3], [2, 1, 2, 3, 0, 1]]);

        $this->assertSame('活動的魅力は4/4で、競合3社の最高（3/4）を上回っています。', $out['strength']);
    }

    public function test_ties_in_the_lead_are_decided_by_the_order_of_the_areas(): void
    {
        // 活動的魅力・就業環境がともに差2 → 先の活動的魅力。
        $out = $this->compose([3, 1, 1, 3, 1, 1], [[1, 2, 2, 1, 2, 2]]);

        $this->assertStringStartsWith('活動的魅力は3/4', $out['strength']);
    }

    public function test_when_nothing_is_above_the_best_competitor_the_strength_is_the_highest_tie(): void
    {
        // 上回る領域は無い。並ぶのは 資産的魅力(2) と 金銭的便益(3) → 自社の点が高い金銭的便益。
        $out = $this->compose([1, 2, 0, 0, 0, 3], [[2, 2, 3, 3, 4, 3]]);

        $this->assertSame('金銭的便益は3/4で、競合の最高と並んでいます。', $out['strength']);
    }

    public function test_a_tie_at_zero_is_not_called_a_strength(): void
    {
        // 並ぶのは 0点どうしの領域だけ → 自社の中で最も高い領域(活動的魅力 1)。
        $out = $this->compose([1, 0, 0, 0, 0, 0], [[2, 1, 0, 0, 0, 0]]);

        $this->assertSame('活動的魅力は1/4で、自社の中で最も高い領域です。', $out['strength']);
    }

    public function test_when_neither_lead_nor_tie_exists_the_strength_is_the_highest_of_the_company_itself(): void
    {
        $out = $this->compose([1, 3, 2, 0, 1, 1], [[2, 4, 3, 1, 2, 2]]);

        $this->assertSame('資産的魅力は3/4で、自社の中で最も高い領域です。', $out['strength']);
    }

    public function test_the_weakness_is_the_largest_gap_with_the_top_company_named(): void
    {
        $out = $this->compose([2, 2, 2, 2, 0, 2], [[1, 1, 1, 1, 1, 1], [1, 1, 1, 1, 4, 1], [1, 1, 1, 1, 2, 1]], ['MIXI', 'DeNA', 'カヤック']);

        $this->assertSame('情緒的便益は0/4で、競合の最高（4/4・DeNA）との差が最も大きい領域です。', $out['weakness']);
    }

    public function test_several_top_companies_are_shown_as_the_first_one_and_more(): void
    {
        $out = $this->compose([2, 2, 2, 2, 0, 2], [[1, 1, 1, 1, 4, 1], [1, 1, 1, 1, 4, 1]], ['MIXI', 'DeNA']);

        $this->assertSame('情緒的便益は0/4で、競合の最高（4/4・MIXIほか）との差が最も大きい領域です。', $out['weakness']);
    }

    public function test_with_four_or_five_competitors_the_symbol_of_the_table_header_is_used(): void
    {
        $out = $this->compose([2, 2, 2, 2, 0, 2], [[1, 1, 1, 1, 1, 1], [1, 1, 1, 1, 1, 1], [1, 1, 1, 1, 4, 1], [1, 1, 1, 1, 2, 1]], ['MIXI', 'DeNA', 'カヤック', 'ほか社']);

        $this->assertSame('情緒的便益は0/4で、競合の最高（4/4・C）との差が最も大きい領域です。', $out['weakness']);
    }

    public function test_weakness_ties_are_decided_by_the_order_of_the_areas(): void
    {
        // 資産的魅力・金銭的便益がともに差2 → 先の資産的魅力。
        $out = $this->compose([2, 0, 2, 2, 2, 0], [[1, 2, 1, 1, 1, 2]]);

        $this->assertStringStartsWith('資産的魅力は0/4', $out['weakness']);
    }

    public function test_when_no_area_is_below_the_best_competitor_the_weakness_says_so_in_one_sentence(): void
    {
        $out = $this->compose([3, 3, 3, 3, 3, 3], [[3, 2, 1, 0, 3, 2]]);

        $this->assertSame('すべての領域で、競合の最高と同じか上回っています。', $out['weakness']);
    }

    public function test_when_a_company_has_too_little_material_only_one_notice_line_is_made(): void
    {
        foreach ([[0], [1]] as $insufficient) {
            $out = $this->compose([4, 1, 1, 1, 1, 1], [[3, 2, 2, 2, 2, 2]], ['A社'], $insufficient);
            $this->assertSame('読み取れた記述が少ない会社があるため、強み・弱みは表示していません。', $out['notice']);
            $this->assertNull($out['strength']);
            $this->assertNull($out['weakness']);
        }
    }

    public function test_nothing_is_made_when_the_self_judgement_is_not_available_or_there_is_no_competitor(): void
    {
        [$companies, $axes] = $this->data([4, 1, 1, 1, 1, 1], [[3, 2, 2, 2, 2, 2]]);
        $this->assertNull((new ComparisonStrengthWeaknessComposer)->compose($companies, $axes, false));

        [$companies, $axes] = $this->data([4, 1, 1, 1, 1, 1], [[3, 2, 2, 2, 2, 2]]);
        $this->assertNull((new ComparisonStrengthWeaknessComposer)->compose([$companies[0]], $axes, true));
    }

    public function test_the_same_input_always_makes_the_same_sentences(): void
    {
        $a = $this->compose([2, 3, 1, 0, 2, 4], [[1, 3, 2, 1, 0, 2], [2, 2, 2, 2, 2, 2]]);
        $b = $this->compose([2, 3, 1, 0, 2, 4], [[1, 3, 2, 1, 0, 2], [2, 2, 2, 2, 2, 2]]);

        $this->assertSame($a, $b);
    }

    public function test_the_wording_is_in_the_config_and_has_no_assertions_of_absence(): void
    {
        $config = require base_path('config/admin_comparison_pptx.php');
        $words = implode('', array_filter($config, fn ($v, $k) => str_starts_with((string) $k, 'strength_weakness_') && is_string($v), ARRAY_FILTER_USE_BOTH));

        foreach (['ありません', '発信していない', '不足'] as $word) {
            $this->assertStringNotContainsString($word, $words);
        }
    }

    public function test_the_slide_shows_the_two_lines_under_the_title_and_the_notice_instead_when_material_is_short(): void
    {
        [$companies, $axes] = $this->data([4, 1, 1, 1, 1, 1], [[3, 2, 2, 2, 2, 2]]);
        $base = ['self_company_name' => '自社名', 'self_readable' => true, 'companies' => $companies, 'axes' => $axes, 'source_note' => 'x', 'page_number' => null];
        $generator = app(AdminComparisonPptxGenerator::class);

        $text = (new ComparisonStrengthWeaknessComposer)->compose($companies, $axes, true);
        $xml = $this->slideXml($generator->generate($base + ['strength_weakness' => $text]));
        $this->assertStringContainsString('強み：', $xml);
        $this->assertStringContainsString($text['strength'], $xml);
        $this->assertStringContainsString('弱み：', $xml);
        $this->assertStringContainsString($text['weakness'], $xml);

        $notice = ['notice' => (string) config('admin_comparison_pptx.strength_weakness_insufficient'), 'strength' => null, 'weakness' => null];
        $xml = $this->slideXml($generator->generate($base + ['strength_weakness' => $notice]));
        $this->assertStringContainsString($notice['notice'], $xml);
        $this->assertStringNotContainsString('強み：', $xml);

        $xml = $this->slideXml($generator->generate($base + ['strength_weakness' => null]));
        $this->assertStringNotContainsString('強み：', $xml);
    }

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
}
