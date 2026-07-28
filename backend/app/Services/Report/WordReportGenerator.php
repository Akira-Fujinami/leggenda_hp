<?php

namespace App\Services\Report;

use App\Services\Lead\LeadPerspectiveComposer;
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
        $this->addPerspectivesSection($phpWord, $viewModel);
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
        // 社内版(7カテゴリ100点)の点数とは別建てであることを明示する
        // (2026-07-28のユーザー指摘: 商談時に取り違えないため)。
        $section->addText('採用サイトとして重要な4観点での評価', ['size' => 9, 'italic' => true]);

        $displayScore = (int) $viewModel->selfScore['display_score'];
        $coverageRate = (float) $viewModel->selfScore['coverage_rate'];
        $confidenceRate = (float) $viewModel->selfScore['confidence_rate'];
        // LeadScoreCalculatorは4観点に表示している指標だけを対象に算出する
        // ため、満点が常に100点とは限らない ―― 固定値にしないこと。
        $maxScore = (int) round((float) $viewModel->selfScore['configured_max_score']);

        $scoreLine = "{$displayScore}点 / {$maxScore}点";

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

    /**
     * 採用担当向けの4観点(①書くべきこと・②メッセージ・③導線・④見やすさ)。
     * 内部の7カテゴリ(technical_seo等)は表示しない ―― 表示のグルーピングを
     * 変えるだけで、採点ロジック自体は変更していない(LeadPerspectiveComposer参照)。
     */
    private function addPerspectivesSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();
        $section->addTitle('採用担当の視点で見た診断結果', 1);

        foreach ($viewModel->perspectives as $perspective) {
            $section->addTitle($perspective['heading'], 2);
            $section->addText(LeadPerspectiveComposer::statusLabel($perspective['status']), ['bold' => true]);

            if (! empty($perspective['note'])) {
                $section->addText($perspective['note'], ['size' => 9, 'italic' => true]);
            }

            if (! empty($perspective['summary'])) {
                $section->addText($perspective['summary']);
            }

            foreach ($perspective['items'] as $item) {
                $section->addText("・{$item['label']}: ".LeadPerspectiveComposer::statusLabel($item['status']));
            }

            $section->addTextBreak(1);
        }
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
