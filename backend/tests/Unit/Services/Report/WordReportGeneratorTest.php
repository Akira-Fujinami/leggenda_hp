<?php

namespace Tests\Unit\Services\Report;

use App\Services\Report\WordReportGenerator;
use App\Support\Report\ReportRecommendationRow;
use App\Support\Report\ReportViewModel;
use App\Support\Lead\LeadMetricCatalog;
use Tests\TestCase;
use ZipArchive;

class WordReportGeneratorTest extends TestCase
{
    private function viewModel(): ReportViewModel
    {
        return new ReportViewModel(
            companyDisplayName: '株式会社サンプル様',
            generatedAtLabel: '2026年7月27日',
            selfWebsiteUrl: 'https://example.com',
            competitorWebsiteUrl: null,
            selfScore: ['display_score' => 76, 'configured_max_score' => 100, 'coverage_rate' => 92.5, 'confidence_rate' => 88.0],
            competitorScore: null,
            overallSummaryText: '株式会社サンプル様の自社サイトは、総合スコア76点(100点満点)という結果になりました。',
            comparisonSentence: null,
            perspectives: [
                [
                    'key' => LeadMetricCatalog::PERSPECTIVE_COMPLETENESS,
                    'label' => LeadMetricCatalog::PERSPECTIVE_LABELS[LeadMetricCatalog::PERSPECTIVE_COMPLETENESS],
                    'heading' => LeadMetricCatalog::PERSPECTIVE_HEADINGS[LeadMetricCatalog::PERSPECTIVE_COMPLETENESS],
                    'note' => LeadMetricCatalog::COMPLETENESS_LEGAL_ITEMS_NOTE,
                    'status' => 'not_detected',
                    'summary' => '採用ページを検出できませんでした。トップページに採用に関する案内が見つからなかったため、この観点は今回「計測対象外」です。',
                    'items' => [],
                ],
                [
                    'key' => LeadMetricCatalog::PERSPECTIVE_CLARITY,
                    'label' => LeadMetricCatalog::PERSPECTIVE_LABELS[LeadMetricCatalog::PERSPECTIVE_CLARITY],
                    'heading' => LeadMetricCatalog::PERSPECTIVE_HEADINGS[LeadMetricCatalog::PERSPECTIVE_CLARITY],
                    'note' => null,
                    'status' => 'good',
                    'items' => [
                        ['label' => 'ページタイトルの設定', 'status' => 'good', 'detail' => null],
                    ],
                ],
            ],
            topRecommendations: [
                new ReportRecommendationRow('画像を圧縮してください', '表示速度の改善につながります。', '緊急', '高', '小'),
            ],
            isPartial: false,
        );
    }

    public function test_it_generates_a_valid_docx_document_with_correct_japanese_text(): void
    {
        $docx = app(WordReportGenerator::class)->generate($this->viewModel());

        $tempPath = tempnam(sys_get_temp_dir(), 'word-report-test-').'.docx';
        file_put_contents($tempPath, $docx);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tempPath) === true, '生成されたファイルが有効なzip(docx)であること');

        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($tempPath);

        $this->assertNotFalse($documentXml);
        $this->assertStringContainsString('株式会社サンプル様', $documentXml);
        $this->assertStringContainsString('総合結果', $documentXml);
        $this->assertStringContainsString('計測対象外', $documentXml);
        $this->assertStringContainsString('書くべきことが書けているか', $documentXml);
        // 見出しは質問形(heading)を使い、内部向けのlabel(「メッセージの
        // 分かりやすさ」)は見出しとして出さない ―― 画面と同じ定義元
        // (LeadMetricCatalog::PERSPECTIVE_HEADINGS)を使っていることの確認。
        $this->assertStringContainsString('伝えたいことが分かりやすく伝わっているか', $documentXml);
        $this->assertStringNotContainsString('メッセージの分かりやすさ', $documentXml);
        // 内部専用情報が絶対に含まれないこと。
        $this->assertStringNotContainsString('job_type', $documentXml);
        $this->assertStringNotContainsString('error_code', $documentXml);
    }
}
