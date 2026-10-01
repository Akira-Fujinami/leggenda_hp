<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * 依頼CJ-1(2026-10-01): 「比較かどうか」をanalyses.source_analysis_idの
 * 有無で判定する古いパターンが復活していないことを、個別のテストとは別に、
 * ソースコードそのものから機械的に確認する(既存の
 * tests/Unit/Jobs/Analysis/CrawlFinishedReasonCoverageTestと同じ手法)。
 *
 * 背景: 無料診断を経由しない比較(依頼CJ-2、source_analysis_idがnullのまま)
 * を作れるようになったため、「source_analysis_idが非nullなら比較」という
 * 判定は常に正しいとは限らなくなった。将来だれかがこのパターンを復活させると、
 * 無料診断を経由しない比較が「比較でない」と誤判定される。このテストは
 * その復活を検知する(1箇所を意図的にsource_analysis_id判定へ戻すと赤くなる
 * ことを実装時に確認済み)。
 *
 * 残してよい用法(このテストの対象外): Analysis::sourceAnalysis()/
 * comparisons()リレーション定義そのもの、AdminComparisonService.php内の
 * 「起点への実際のリンク書き込み」('source_analysis_id' => $sourceAnalysis->id)、
 * ComparisonController.php内のold('source_analysis_id')(フォームの
 * hidden inputの名前であり、モデル列ではない)、各Bladeの表示用の値埋め込み
 * ({{ $analysis->source_analysis_id }})、wizard.blade.phpのhidden input。
 */
class AnalysisKindDetectionCoverageTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const DETECTION_NEEDLES = [
        'source_analysis_id === null',
        'source_analysis_id !== null',
        "whereNull('source_analysis_id')",
        "whereNotNull('source_analysis_id')",
    ];

    /**
     * @var list<string>
     */
    private const SCANNED_APP_FILES = [
        'Jobs/Analysis/FinalizeAnalysisJob.php',
        'Jobs/Report/GenerateAdminComparisonReportJob.php',
        'Http/Controllers/Admin/AnalysisController.php',
        'Http/Controllers/Admin/ComparisonController.php',
        'Services/Admin/DashboardMetricsService.php',
        'Services/Admin/AdminComparisonService.php',
    ];

    /**
     * 依頼CJ-1: 「比較かどうか」の判定(トップレベル分岐)はすべてkindへ
     * 置き換えたが、各ファイルに1箇所ずつ、kind===AdminComparisonで
     * 絞り込んだ内側で「起点への実際のリンクがあるか」を見る、意図的に
     * 残した@ifがある(analyses/show.blade.php:21、companies/show.blade.php:86、
     * 実装時に確認済み)。この数が変わったら、新しい比較判定の@ifが
     * 増えていないか/減っていないかを確認すること。
     *
     * @var array<string, int>
     */
    private const SCANNED_BLADE_FILES = [
        'admin/analyses/show.blade.php' => 1,
        'admin/companies/show.blade.php' => 1,
    ];

    public function test_no_production_code_detects_comparisons_via_source_analysis_id_nullness(): void
    {
        foreach (self::SCANNED_APP_FILES as $relativePath) {
            $contents = (string) file_get_contents(app_path($relativePath));

            foreach (self::DETECTION_NEEDLES as $needle) {
                $this->assertSame(
                    0,
                    substr_count($contents, $needle),
                    "app/{$relativePath} にcomparison判定としての `{$needle}` が見つかりました。".
                    'App\\Enums\\AnalysisKind(kind列)を使ってください。',
                );
            }
        }
    }

    public function test_blade_views_do_not_gain_new_source_analysis_id_branches(): void
    {
        foreach (self::SCANNED_BLADE_FILES as $relativePath => $expectedCount) {
            $contents = (string) file_get_contents(resource_path('views/'.$relativePath));

            // 真偽判定(@if/@unless)のみを対象にする。表示用の値埋め込み
            // ({{ $analysis->source_analysis_id }}や、リンクのhref引数と
            // しての利用)はこの対象に含まない。
            $matches = [];
            preg_match_all('/@(if|unless)\s*\(\s*!?\s*\$analysis->source_analysis_id\s*\)/', $contents, $matches);

            $this->assertSame(
                $expectedCount,
                count($matches[0]),
                "resources/views/{$relativePath} の source_analysis_id によるBlade分岐".
                "(@if/@unless)の数が{$expectedCount}件から変わりました: ".implode(', ', $matches[0]).
                ' ―― kindを使わない新しい比較判定が増えていないか確認してください'
                .'(意図的に残した1箇所は、kind===AdminComparisonで絞り込んだ内側で'
                .'「起点への実際のリンクがあるか」を見るためのものです)。',
            );
        }
    }

    public function test_the_real_source_analysis_id_link_write_still_exists(): void
    {
        // 置き換えで誤って消していないことの確認(起点への実際のリンク書き込み)。
        $contents = (string) file_get_contents(app_path('Services/Admin/AdminComparisonService.php'));

        $this->assertStringContainsString(
            "'source_analysis_id' => \$sourceAnalysis->id",
            $contents,
            'AdminComparisonService::createFromSourceAnalysis()は、起点のAnalysis IDを'
            .'source_analysis_idへ明示的に書き込み続ける必要がある(これは比較判定では'
            .'なく、起点への実際のリンクであり、このテストの対象外)。',
        );
    }
}
