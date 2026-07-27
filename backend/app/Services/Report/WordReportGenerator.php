<?php

namespace App\Services\Report;

use App\Support\Report\ReportCategoryRow;
use App\Support\Report\ReportRecommendationRow;
use App\Support\Report\ReportViewModel;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Language;

/**
 * レポートWord(.docx)を生成する。文面・カテゴリ判定・スコア計算のロジックは
 * 一切持たず、PdfReportGenerator同様、ReportViewModelBuilderが組み立てた
 * ViewModelをそのままレイアウトへ差し込むだけに徹する(PDF版と表示内容を
 * 一致させるため)。
 *
 * .docxはWordアプリ側のフォントで表示されるため、PDFのようなフォント埋め込みは
 * 不要 ―― 游ゴシック(Windows/Office標準の日本語フォント)を指定し、
 * 万一未インストールの環境でもWordが自動的に代替フォントへ切り替える。
 */
class WordReportGenerator
{
    private const FONT_NAME = '游ゴシック';

    public function generate(ReportViewModel $viewModel): string
    {
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName(self::FONT_NAME);
        $phpWord->setDefaultFontSize(11);
        $phpWord->getSettings()->setThemeFontLang(new Language(Language::JA_JP));

        $this->addCoverSection($phpWord, $viewModel);
        $this->addOverallResultsSection($phpWord, $viewModel);
        $this->addCategoryBreakdownSection($phpWord, $viewModel);
        $this->addRecommendationsSection($phpWord, $viewModel);
        $this->addCallToActionSection($phpWord, $viewModel);

        $tempPath = tempnam(sys_get_temp_dir(), 'lead-report-').'.docx';

        try {
            IOFactory::createWriter($phpWord, 'Word2007')->save($tempPath);

            return file_get_contents($tempPath);
        } finally {
            @unlink($tempPath);
        }
    }

    private function addCoverSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();

        $section->addText('Webサイト診断レポート', ['bold' => true, 'size' => 28], ['spaceAfter' => 400, 'alignment' => Jc::CENTER]);
        $section->addTextBreak(4);
        $section->addText($viewModel->companyDisplayName, ['size' => 13], ['alignment' => Jc::CENTER]);
        $section->addText('対象サイト: '.$viewModel->selfWebsiteUrl, [], ['alignment' => Jc::CENTER]);

        if ($viewModel->competitorWebsiteUrl !== null) {
            $section->addText('比較サイト: '.$viewModel->competitorWebsiteUrl, [], ['alignment' => Jc::CENTER]);
        }

        $section->addText($viewModel->generatedAtLabel, [], ['alignment' => Jc::CENTER]);

        if ($viewModel->isPartial) {
            $section->addText(
                '一部のデータは取得できませんでしたが、取得できた範囲での診断結果です。',
                ['size' => 9, 'italic' => true],
                ['alignment' => Jc::CENTER],
            );
        }
    }

    private function addOverallResultsSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();

        $section->addTitle('総合結果', 1);

        $displayScore = (int) $viewModel->selfScore['display_score'];
        $coverageRate = (float) $viewModel->selfScore['coverage_rate'];
        $confidenceRate = (float) $viewModel->selfScore['confidence_rate'];

        $scoreLine = "{$displayScore}点 / 100点";

        if ($coverageRate < 70) {
            $scoreLine .= '(参考スコア)';
        }

        $section->addText($scoreLine, ['bold' => true, 'size' => 18]);
        $section->addText(sprintf('測定カバー率: %s%%　確信度: %s%%', number_format($coverageRate, 2), number_format($confidenceRate, 2)));
        $section->addTextBreak(1);
        $section->addText($viewModel->overallSummaryText);

        if ($viewModel->comparisonSentence !== null) {
            $section->addTextBreak(1);
            $section->addText($viewModel->comparisonSentence);
        }
    }

    private function addCategoryBreakdownSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();

        $section->addTitle('カテゴリ別スコア', 1);

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'cccccc', 'cellMargin' => 80]);

        $table->addRow();
        $table->addCell(2000)->addText('カテゴリ', ['bold' => true]);
        $table->addCell(4500)->addText('説明', ['bold' => true]);
        $table->addCell(1800)->addText('スコア', ['bold' => true]);
        $table->addCell(3200)->addText('カバー率', ['bold' => true]);

        foreach ($viewModel->categoryBreakdown as $category) {
            $table->addRow();
            $table->addCell(2000)->addText($category->name);
            $table->addCell(4500)->addText($category->description);
            $table->addCell(1800)->addText($this->categoryScoreLabel($category));
            $table->addCell(3200)->addText($this->categoryCoverageLabel($category));
        }
    }

    private function categoryScoreLabel(ReportCategoryRow $category): string
    {
        return match ($category->availability) {
            CategoryAvailabilityClassifier::NOT_MEASURED => '計測対象外',
            CategoryAvailabilityClassifier::UNAVAILABLE => '評価不可',
            default => "{$category->score} / {$category->configuredMaxScore}",
        };
    }

    private function categoryCoverageLabel(ReportCategoryRow $category): string
    {
        return match ($category->availability) {
            CategoryAvailabilityClassifier::NOT_MEASURED => '今回の診断では計測していません',
            CategoryAvailabilityClassifier::UNAVAILABLE => 'データを取得できませんでした',
            default => number_format($category->coverageRate, 2).'%',
        };
    }

    private function addRecommendationsSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();

        $section->addTitle('改善提案', 1);

        if ($viewModel->topRecommendations === []) {
            $section->addText('現時点で優先度の高い改善提案はありません。');

            return;
        }

        foreach ($viewModel->topRecommendations as $recommendation) {
            $this->addRecommendation($section, $recommendation);
        }
    }

    private function addRecommendation(Section $section, ReportRecommendationRow $recommendation): void
    {
        $section->addText($recommendation->title, ['bold' => true, 'size' => 12]);
        $section->addText($recommendation->description);
        $section->addText(
            "優先度: {$recommendation->priorityLabel}　影響度: {$recommendation->impactLabel}　対応工数: {$recommendation->effortLabel}",
            ['size' => 9, 'color' => '555555'],
        );
        $section->addTextBreak(1);
    }

    private function addCallToActionSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();

        $section->addTextBreak(6);
        $section->addText('より詳しい診断・ご相談はこちら', ['bold' => true, 'size' => 18], ['alignment' => Jc::CENTER]);
        $section->addTextBreak(1);

        $competitorClause = $viewModel->competitorWebsiteUrl !== null ? '・比較サイト1社' : '';
        $section->addText("今回は自社サイト{$competitorClause}の簡易診断結果です。", [], ['alignment' => Jc::CENTER]);
        $section->addText('他社比較(3〜5社)や、詳細な改善提案については、担当者までお気軽にご相談ください。', [], ['alignment' => Jc::CENTER]);
    }
}
