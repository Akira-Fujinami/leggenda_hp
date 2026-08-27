<?php

namespace App\Services\Report;

use App\Support\Report\ReportViewModel;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Language;

/**
 * レポートWord(.docx)を生成する。文面・カテゴリ判定ロジックは一切持たず、
 * PdfReportGenerator同様、ReportViewModelBuilderが組み立てたViewModelを
 * そのままレイアウトへ差し込むだけに徹する(PDF版と表示内容を一致させるため)。
 *
 * 2026-08-08: PDF版(lead-pdf.blade.php)の7ページ構成(表紙/前置き/自社サイトの
 * 分析結果/競合サイトの分析結果/○△－の対比表/改善提案/最終ページ)への
 * 再編に合わせて全面書き直し。旧「総合結果」(社内向け4観点スコアの
 * Word版限定セクション、PDF版には無かった)・「サイトから読み取れた記述」・
 * 「採用担当の視点で見た診断結果」(4観点)・「サイトで触れられていなかった
 * 項目」は削除し、PDF版と1:1で一致する構成にした(ユーザー指示「Word版も
 * 同じ構成に合わせること」)。
 *
 * .docxはWordアプリ側のフォントで表示されるため、PDFのようなフォント埋め込みは
 * 不要 ―― 游ゴシック(Windows/Office標準の日本語フォント)を指定し、
 * 万一未インストールの環境でもWordが自動的に代替フォントへ切り替える。
 */
class WordReportGenerator
{
    private const FONT_NAME = '游ゴシック';

    /**
     * ブランド・ホイール(6軸24項目)の固定説明図。PdfReportGeneratorと同じ
     * 静的アセットを使う ―― 分析結果に依存しないため、ここでも動的生成はしない。
     * config('brand_wheel.axes.*.sub_elements')を変更したら、この画像も
     * 作り直すこと(README「リリース前チェックリスト」参照)。
     */
    private const BRAND_WHEEL_FRAMEWORK_IMAGE_PATH = 'images/brand-wheel-framework.png';

    // PDF版のgroupBandsと同じ配色(docs/lead-report-layout/README.md)。
    private const GROUP_LABELS = [
        'company_appeal' => '会社の魅力',
        'company_distance' => '会社との距離',
        'job_appeal' => '仕事の魅力',
    ];

    public function generate(ReportViewModel $viewModel): string
    {
        // 依頼R(2026-08-26)で「○と判定した根拠」ページの引用(サイトの生の
        // 原文抜粋、&/</>を含みうる)を追加した際に判明: PhpWordは既定
        // (Settings::$outputEscapingEnabled=false、0.13.0互換のための既定値)
        // ではaddText()に渡した文字列をXMLエスケープせず生のまま書き込む
        // ―― &を含むテキストが1件でもあると、生成される.docxのdocument.xml
        // 自体が不正なXMLになり、Wordで開けなくなる(実際に確認: DOMDocument::
        // loadXML()が構文エラーで失敗する)。この不具合はこのメソッドの
        // addText()呼び出しすべて(evidence/competitor_evidence/key_message/
        // impression/会社名等、サイト由来の自由記述を渡す箇所すべて)に
        // 及んでいた、この依頼以前からの潜在バグのため、1箇所で確実に
        // 直せるこのフラグを有効化する(個々のaddText()呼び出しへ手作業で
        // エスケープ処理を加える方式は、将来のaddText()追加箇所での
        // 対応漏れを構造的に防げないため採らない)。
        Settings::setOutputEscapingEnabled(true);

        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName(self::FONT_NAME);
        $phpWord->setDefaultFontSize(11);
        $phpWord->getSettings()->setThemeFontLang(new Language(Language::JA_JP));

        $this->addCoverSection($phpWord, $viewModel);
        $this->addBrandWheelFrameworkIntroSection($phpWord, $viewModel);
        $this->addBrandWheelAnalysisSection(
            $phpWord, '自社サイトの分析結果', $viewModel->brandWheelSelf,
            '自社サイト', $viewModel->selfTotalMatched, $viewModel->selfTotalMax,
            $viewModel->brandWheelComparison['self_points'],
            $viewModel->selfLowContentNotice,
        );
        if ($this->competitorReadable($viewModel)) {
            $this->addBrandWheelAnalysisSection(
                $phpWord, '競合サイトの分析結果', $viewModel->brandWheelCompetitor,
                '競合サイト', $viewModel->competitorTotalMatched, $viewModel->competitorTotalMax,
                $viewModel->brandWheelComparison['competitor_points'],
            );
        }
        $this->addComparisonSection($phpWord, $viewModel);
        $this->addEvidenceSection($phpWord, $viewModel);
        $this->addImprovementProposalSection($phpWord, $viewModel);
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

            // 依頼O-2/P-3(2026-08-25): 競合サイトの分析が成立しなかった場合、
            // PDF版と同じ注記を添える(案B、依頼者確定)。$competitorReadable()の
            // 判定ロジック自体は変更しない(既存のまま)。
            if (! $this->competitorReadable($viewModel)) {
                $section->addText(
                    (string) config('brand_wheel.cover_competitor_unreadable_notice'),
                    ['size' => 9, 'italic' => true],
                    ['alignment' => Jc::CENTER],
                );
            }
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

    /**
     * PDF版の「採用ブランドの捉え方 ―― ブランド・ホイール」ページ(2ページ目)
     * と同内容の前置き。分析結果に依存しない固定コンテンツのため、
     * ReportViewModelを受け取らない(PDF側と同じ理由でBrandWheelHexagonRenderer
     * も通さない、2026-08-04)。
     *
     * ここに含む「読み取れなかった項目は…」の一文は、この診断で最も誤解を
     * 招きやすい箇所の断り書きのため、要約・省略せず原文のまま出すこと
     * (引用符は『』を使う ―― ユーザー指定の「絶対に消してはいけない文言」
     * 原文どおり、2026-08-04)。
     */
    private function addBrandWheelFrameworkIntroSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();
        $section->addTitle('採用ブランドの捉え方 ―― ブランド・ホイール', 1);

        $section->addImage(
            resource_path(self::BRAND_WHEEL_FRAMEWORK_IMAGE_PATH),
            ['width' => 260, 'height' => 260, 'alignment' => Jc::CENTER],
        );

        $section->addTextBreak(1);
        $section->addText('採用ブランドは、大きく3つの領域に分けて捉えます。', ['size' => 10.5]);

        $table = $section->addTable(['cellMargin' => 80]);
        $groups = [
            ['label' => self::GROUP_LABELS['company_appeal'], 'desc' => 'その会社が何を目指し、どれだけの実績・規模を持っているか。活動的魅力・資産的魅力'],
            ['label' => self::GROUP_LABELS['company_distance'], 'desc' => 'どんな経営で、どんな人たちが、どんな環境で働いているか。経営スタイル・就業環境'],
            ['label' => self::GROUP_LABELS['job_appeal'], 'desc' => 'その仕事に就くと、何が得られるか。情緒的便益・金銭的便益'],
        ];

        foreach ($groups as $group) {
            $table->addRow();
            $table->addCell(2500)->addText($group['label'], ['bold' => true]);
            $table->addCell(6500)->addText($group['desc']);
        }

        // 2026-08-17: 軸単位の説明(config('brand_wheel.axes.*.definition')、
        // 既存)を1段落にまとめて追加する(依頼者指定#6)。PDF版で表(セル内
        // ネスト)に入れたところ実PDF確認で深刻なページ分割不具合が見つかった
        // ため、Word版も最初から表の外の通常の段落として追加する(PDF版との
        // 構造整合)。
        $section->addTextBreak(1);
        $axisDefsText = collect((array) config('brand_wheel.axes', []))
            ->map(fn ($axis) => $axis['name_ja'].'：'.$axis['definition'])
            ->implode('　');
        $section->addText($axisDefsText, ['size' => 9, 'color' => '4A4A4A']);

        $section->addTextBreak(1);
        $section->addText(
            '6つの項目にはそれぞれ4つの下位要素があり、合計24項目です。中心のCore Value(約束する価値)は、'.
            'その24項目を貫く「この会社が候補者に約束するもの」にあたります。',
        );
        // 2026-08-17: 件数集計フレーミングを弱め、レポートの目的を主文にする
        // (依頼者指定#3)。URL分析対象範囲の明記(依頼者指定#5)も追加。
        $section->addText(
            '本レポートでは、サイト上から確認できた情報をもとに、候補者に伝わる情報や印象を分析しています。',
        );
        // 依頼Q-1(2026-08-25): PDF版(lead-pdf.blade.php)と同じ出し分け
        // (config('brand_wheel.crawl_disabled_scope_notice')/
        // crawl_enabled_scope_notice、依頼者確定文言)。
        $section->addText(
            $viewModel->crawlSiteEnabled
                ? (string) config('brand_wheel.crawl_enabled_scope_notice')
                : (string) config('brand_wheel.crawl_disabled_scope_notice'),
            ['size' => 9.5, 'color' => '6B6767'],
        );

        $section->addTextBreak(1);
        $section->addText(
            '読み取れなかった項目は、その魅力が『無い』という意味ではありません。サイトにそう書かれていない、というだけです。'.
            'また、採用ブランドは本来、グループインタビュー・口コミ・内定者や辞退者へのインタビュー・説明会・SNSなども併せて構築するものです。'.
            '今回はそのうちサイトの記述のみを拝見しています。',
            ['size' => 9, 'color' => '666666'],
        );
    }

    /**
     * PDF版3・4ページ目(自社/競合サイトの分析結果、
     * partials/lead-pdf-brand-wheel-page.blade.php)と同内容。主体
     * (自社/競合)を引数で切り替えるだけの完全に同じ形式(2026-08-08、
     * ユーザー指定)。合計件数は呼び出し側($viewModel->selfTotalMatched等、
     * 対比表・改善提案と同じ集計値)を渡す ―― セクションごとに個別集計しない。
     *
     * @param  ?array<string, mixed>  $wheel  brandWheelSelf/brandWheelCompetitor
     * @param  list<string>  $summaryPoints  BrandWheelComparisonSummaryComposer::points()の戻り値(self_points/competitor_points)
     */
    private function addBrandWheelAnalysisSection(
        PhpWord $phpWord,
        string $title,
        ?array $wheel,
        string $seriesLabel,
        int $totalMatched,
        int $totalMax,
        array $summaryPoints,
        ?string $lowContentNotice = null,
    ): void {
        $section = $phpWord->addSection();
        $section->addTitle($title, 1);

        if (($wheel['status'] ?? null) !== 'success' || ($wheel['axes'] ?? []) === []) {
            // 6項目すべて0件の表は「魅力のない会社」の意味になるため出さない。
            // 理由の文言はconfig('brand_wheel.status_messages')が唯一の定義元。
            $section->addText((string) ($wheel['status_message'] ?? ''));

            return;
        }

        $section->addText(
            '本レポートでは、サイト上から確認できた情報をもとに、候補者に伝わる情報や印象を分析しています。'.
            '解析したURL：'.$wheel['analyzed_url'],
            ['size' => 9, 'italic' => true],
        );

        $section->addTextBreak(1);
        $section->addText("{$seriesLabel}　確認できた情報：{$totalMatched} / {$totalMax}項目", ['bold' => true, 'size' => 14]);
        $section->addText('ブランドホイール24項目のうち、サイト上で情報を確認できた項目数', ['size' => 8, 'color' => '9A9A9A']);
        if ($lowContentNotice !== null) {
            // 2026-08-25追加(修正5): 自社の合計matched件数が閾値未満のときの但し書き。
            $section->addText($lowContentNotice, ['size' => 8, 'color' => '6B6767']);
        }

        if ($summaryPoints !== []) {
            $section->addTextBreak(1);
            $section->addText('サマリー', ['bold' => true]);
            foreach ($summaryPoints as $point) {
                $section->addText("・{$point}");
            }
        }

        $section->addTextBreak(1);
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'cccccc', 'cellMargin' => 80]);
        $table->addRow();
        $table->addCell(2500)->addText('項目', ['bold' => true]);
        $table->addCell(1500)->addText('件数', ['bold' => true]);
        $table->addCell(5000)->addText('読み取れた内容', ['bold' => true]);

        foreach ($wheel['axes'] as $axis) {
            $matchedNames = array_column($axis['matched_sub_elements'], 'name');

            $table->addRow();
            $table->addCell(2500)->addText($axis['name']);
            $table->addCell(1500)->addText("{$axis['matched_count']} / {$axis['max_count']}件");
            // 「該当する記述は見つかりませんでした」を使う(内容が無い会社、
            // と読める表現を避けるため)。PDF版(.none2)と表記を揃える。
            $table->addCell(5000)->addText($matchedNames === [] ? '該当する記述は見つかりませんでした' : implode('、', $matchedNames));
        }

        // 2026-08-18: 「候補者に与える印象」の単一見出し配下にポジ/ネガを
        // 箇条書きで並べる形から、「ポジティブな印象」「ネガティブな印象」を
        // 別見出しとして明確に分離した(依頼者指定、PDF版と同内容)。あわせて
        // AI利用の開示文言も削除した(依頼者指定 ―― UI/PDF上でAI利用を前面に
        // 出さない)。
        $positiveImpression = $wheel['positive_impression'] ?? null;
        $negativeImpression = $wheel['negative_impression'] ?? null;
        if ($wheel['key_message'] || $positiveImpression || $negativeImpression) {
            $section->addTextBreak(1);

            if ($wheel['key_message']) {
                $section->addText('サイト上の情報から想定されるキーメッセージ：'.$wheel['key_message']);
            }

            if ($positiveImpression) {
                $section->addText('ポジティブな印象：');
                $section->addText($positiveImpression);
            }
            if ($negativeImpression) {
                $section->addText('ネガティブな印象：');
                $section->addText($negativeImpression);
            }
        }
    }

    /**
     * PDF版5ページ目「○△－の対比表」と同内容。2026-08-08: ●／－の2値から
     * ○△－の3値へ変更。○×は使わない(正解・不正解の記号であり、2ページ目の
     * 断り書きと矛盾する)。判定はBrandWheelSubElementComparisonComposerが
     * すべて行う(AIには一切判定させない)。$viewModel->subElementComparison
     * (config順、24項目)が唯一の情報源。self_matched/competitor_matched
     * (○のみtrue)は改善提案の選定ロジック専用のため、この表示には
     * self_state/competitor_state('matched'|'label_only'|'none')を使う。
     */
    private function addComparisonSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();
        $section->addTitle('○△－の対比表', 1);

        if (($viewModel->brandWheelSelf['status'] ?? null) !== 'success' || ($viewModel->brandWheelSelf['axes'] ?? []) === []) {
            $section->addText((string) ($viewModel->brandWheelSelf['status_message'] ?? ''));

            return;
        }

        $showCompetitorColumn = $this->competitorReadable($viewModel);

        $section->addText(
            '24項目それぞれについて、サイトに該当する記述があったかどうかを3段階で示しています。',
            ['size' => 9, 'italic' => true],
        );

        // 2026-08-17追加: 比較結果サマリー(PDF版と同内容、依頼者指定#11)。
        if ($viewModel->comparisonOverview !== []) {
            $section->addTextBreak(1);
            $section->addText('比較結果サマリー', ['bold' => true, 'size' => 9.5]);
            foreach ($viewModel->comparisonOverview as $line) {
                $section->addText($line, ['size' => 9]);
            }
        }

        $section->addTextBreak(1);
        $section->addText('凡例', ['bold' => true, 'size' => 9.5]);
        $section->addText('○　本文の記述から確認できた項目', ['size' => 9]);
        $section->addText('△　見出し・メニュー名などのラベルのみで、本文からは確認できなかった項目', ['size' => 9]);
        $section->addText('－　該当する記述が見つからなかった項目(『魅力が無い』という意味ではありません)', ['size' => 9]);

        $section->addTextBreak(1);
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'cccccc', 'cellMargin' => 80]);
        $table->addRow();
        $table->addCell(2000)->addText('領域', ['bold' => true]);
        $table->addCell(3500)->addText('項目', ['bold' => true]);
        $table->addCell(1500)->addText('自社', ['bold' => true]);
        if ($showCompetitorColumn) {
            $table->addCell(1500)->addText('比較', ['bold' => true]);
        }

        foreach ($viewModel->subElementComparison as $item) {
            $table->addRow();
            $table->addCell(2000)->addText(self::GROUP_LABELS[$item['group']] ?? $item['group']);
            $table->addCell(3500)->addText($item['sub_name']);
            $table->addCell(1500)->addText($this->stateMark($item['self_state']));
            if ($showCompetitorColumn) {
                $table->addCell(1500)->addText($this->stateMark($item['competitor_state']));
            }
        }

        $section->addTextBreak(1);
        $legend = "合計　○自社サイト {$viewModel->selfTotalMatched} / {$viewModel->selfTotalMax}項目";
        if ($showCompetitorColumn) {
            $legend .= "　　○比較サイト {$viewModel->competitorTotalMatched} / {$viewModel->competitorTotalMax}項目";
        }
        $section->addText($legend, ['size' => 9.5, 'color' => '6B6767']);

        $refLegend = "(参考)　△自社 {$viewModel->selfTotalLabelOnly}件";
        if ($showCompetitorColumn) {
            $refLegend .= "　　△比較 {$viewModel->competitorTotalLabelOnly}件";
        }
        $section->addText($refLegend, ['size' => 9, 'color' => '8A8A8A']);
    }

    /**
     * 2026-08-18: PDF版(lead-pdf.blade.php)の$competitorReadableと同じ判定
     * (競合サイトのURLの有無ではなく、実際にstatus==='success'かつaxesが
     * 空でないかで判定する)。以前は競合サイト分析結果セクション(generate())と
     * ○△－の対比表(addComparisonSection())がどちらもcompetitorWebsiteUrlの
     * 有無だけで分岐していたため、URLはあるが読み取れなかった場合(403等)に、
     * ほぼ空のセクションと「比較サイト 0 / 0項目」が出力されてしまっていた
     * (ゴディバの403調査から派生した誤記載の修正、依頼者指定)。
     */
    private function competitorReadable(ReportViewModel $viewModel): bool
    {
        return ($viewModel->brandWheelCompetitor['status'] ?? null) === 'success'
            && ($viewModel->brandWheelCompetitor['axes'] ?? []) !== [];
    }

    private function stateMark(string $state): string
    {
        return match ($state) {
            'matched' => '○',
            'label_only' => '△',
            default => '－',
        };
    }

    /**
     * 依頼R(2026-08-26): 「○と判定した根拠」ページ(PDF版lead-pdf.blade.php
     * と同内容)。○△－の対比表の直後に独立ページとして追加する
     * (既存ページには一切差し込まない、依頼者指定)。$viewModel->
     * selfEvidenceByAxis(ReportViewModelBuilder::buildSelfEvidenceByAxis()、
     * 対比表と同じ軸順・下位要素順、自社のmatched項目のみ・evidenceが
     * 空文字の項目は含まない)が唯一の情報源。競合サイトの引用・
     * discarded_sub_elements(棄却された引用)はそもそもこのフィールドに
     * 含まれない。
     *
     * 空配列(matched=0件、または全項目のevidenceが空文字)の場合は
     * addSection()自体を呼ばない ―― 見出しだけの空セクション(空のページ)を
     * 作らない(PDF版の`@if ($viewModel->selfEvidenceByAxis !== [])`と同じ方針)。
     *
     * 1軸に複数件、matchedが多いサイト(実測: カヤック16件)で1ページに
     * 収まらない場合は、Wordの通常の文章送り(page-break-after相当の指定を
     * 一切していない)により自然に次ページへ続く ―― PDF版と同じ考え方
     * (.pageに高さを固定しないのと同様、Word側もこのセクション内で明示的な
     * 改ページを入れない)。
     */
    private function addEvidenceSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        if ($viewModel->selfEvidenceByAxis === []) {
            return;
        }

        $section = $phpWord->addSection();
        $section->addTitle('○と判定した根拠', 1);
        // 依頼AA(2026-08-27): PDF版と同じ出し分け(このレポート内に日本語訳が
        // 1件でもあるときだけ「(日本語訳を併記しています)」付きの説明文)。
        $intro = $viewModel->hasQuoteTranslations
            ? (string) config('brand_wheel.evidence_page_intro_with_translation')
            : (string) config('brand_wheel.evidence_page_intro');
        $section->addText($intro, ['size' => 9, 'color' => '6B6767']);

        $translationLabel = (string) config('brand_wheel.quote_translation_label');
        foreach ($viewModel->selfEvidenceByAxis as $axisGroup) {
            $section->addTextBreak(1);
            $section->addText($axisGroup['axis_name'], ['bold' => true, 'size' => 11.5, 'color' => '1D2088']);
            foreach ($axisGroup['items'] as $item) {
                $section->addText($item['sub_name'], ['bold' => true, 'size' => 9.5]);
                $section->addText('「'.$item['evidence'].'」', ['size' => 9]);
                // 依頼AA: 原文が主・訳が従であることが分かるよう、PDF版の
                // .quote-translationと同じ考え方(小さく・控えめに)。
                if (! empty($item['evidence_translation'])) {
                    $section->addText($translationLabel.'：'.$item['evidence_translation'], ['size' => 8, 'color' => '8A8A8A']);
                }
            }
        }
    }

    /**
     * PDF版6ページ目「改善提案」と同内容。ブランド・ホイール起点であること
     * (技術的な指標から作らない、docs/lead-report-layout/README.md)。
     * ワンポイントは自社のみで判定可能なため常に自社の状態から出す。
     * 領域差・3項目は競合ありなら$viewModel->improvementFocus、競合なし
     * (または読み取れない)なら$viewModel->improvementFocusSelfOnly
     * (2026-08-10追加、いずれも決定的な規則で選定済み、△は未該当扱いのまま
     * 選定ロジック無改修)が唯一の情報源。両方nullの場合は何も出さない
     * (自社24項目すべてが○の場合、実運用ではまず起きない)。
     *
     * 2026-08-08: 下部の技術的提案ブロック(「あわせて、サイトの作りに
     * ついて」)を削除した。4観点(測定結果)ページを削除したのに技術的提案
     * だけ残すのは整合が取れないため(ユーザー判断)。
     */
    private function addImprovementProposalSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $selfReadable = ($viewModel->brandWheelSelf['status'] ?? null) === 'success' && ($viewModel->brandWheelSelf['axes'] ?? []) !== [];

        // 2026-08-10: PDF版(lead-pdf.blade.php)と同じ省略条件。セクション
        // 自体をaddSection()する前に判定すること ―― 後から中身が無いと
        // わかってreturnしても、見出しだけの空セクションが残ってしまう。
        if ($selfReadable && $viewModel->improvementFocus === null && $viewModel->improvementFocusSelfOnly === null) {
            return;
        }

        $section = $phpWord->addSection();
        $section->addTitle('改善提案', 1);

        if (! $selfReadable) {
            $section->addText((string) ($viewModel->brandWheelSelf['status_message'] ?? ''));

            return;
        }

        // 2026-08-17: ワンポイントの文言を改善提案AIの生成結果へ切り替える
        // (PDF版と同内容)。$viewModel->improvementOnePointは未生成/失敗時に
        // 既存の決定的ロジックへ自動フォールバック済み(ReportViewModelBuilder参照)。
        if ($viewModel->improvementOnePoint !== null) {
            $section->addText('【ワンポイント】'.$viewModel->improvementOnePoint, ['bold' => true]);
            $section->addTextBreak(1);
        }

        // 2026-08-18追加: ワンポイントの理由(依頼者指定の構成、PDF版と同内容)。
        if ($viewModel->improvementReason !== null) {
            $section->addText('理由：'.$viewModel->improvementReason, ['size' => 9.5]);
            $section->addTextBreak(1);
        }

        $focus = $viewModel->improvementFocus;
        if ($focus !== null) {
            // 依頼X-1〜X-4(2026-08-26、レポート42): 従来ここに直書きしていた
            // 文言(選ばれた領域名・件数)は、自社が3領域すべてで競合を上回る
            // ケースで「項目を0件挙げます。」「差が最も大きかったのは実際は
            // 自社優位の領域」という事実に反する出力になっていた
            // (PDF版・lead-pdf.blade.phpの同箇所コメント参照)。
            // BrandWheelImprovementFocusComposer::compose()側で候補の有無・
            // 差の符号に応じた文言を組み立てるようにしたため、ここでは
            // $focus['lead_text']をそのまま出すだけでよい。
            $section->addText($focus['lead_text']);

            $section->addTextBreak(1);
            foreach ($focus['groups'] as $group) {
                $label = self::GROUP_LABELS[$group['group']] ?? $group['group'];
                $section->addText("{$label}：自社 {$group['self_count']} / {$group['max_count']}　比較 {$group['competitor_count']} / {$group['max_count']}");
            }

            // 依頼X-2: 候補が0件のときの「該当する項目はありませんでした」は
            // 廃止した(PDF版と同内容 ―― lead_textが既に状況を説明している)。
            if ($focus['items'] !== []) {
                // 依頼AH-2(2026-08-28): PDF版と同内容。①「追いつく」
                // (catch_up)/②「抜け出す」(breakout)をラベルで区別し、
                // ②には引用ブロック(比較サイトの記述)を出さない(比較サイトに
                // も記述が無いため引用するものが存在しない)。
                foreach ($focus['items'] as $i => $item) {
                    $section->addTextBreak(1);
                    $section->addText(($i + 1).'. '.$item['sub_name'], ['bold' => true]);
                    $section->addText(
                        (string) config('brand_wheel.improvement_card_type_labels.'.$item['type']),
                        ['size' => 8, 'bold' => true, 'color' => $item['type'] === 'catch_up' ? 'E95446' : '2C7F96'],
                    );
                    $section->addText($item['recommendation'], ['size' => 9, 'color' => '6B6767']);
                    $section->addText('（現在、サイトからは読み取れませんでした）', ['size' => 8, 'color' => '9A9A9A']);
                    if ($item['type'] === 'catch_up') {
                        $section->addText('比較サイトの記述：「'.$item['competitor_evidence'].'」');
                        // 依頼AA(2026-08-27): PDF版の.cmp-translationと同内容。
                        if (! empty($item['competitor_evidence_translation'])) {
                            $section->addText(
                                ((string) config('brand_wheel.quote_translation_label')).'：'.$item['competitor_evidence_translation'],
                                ['size' => 8, 'color' => '8A8A8A'],
                            );
                        }
                    }
                }

                // 2026-08-19: 「中長期的には：」の1行から、独立した見出し付き
                // ブロックへ格上げ(依頼者指定、PDF版の.diffboxと同内容)。
                // 依頼Q-2(2026-08-25): 「具体的に追加すべき情報」の箇条書き
                // (旧$viewModel->improvementRecommendedContents)は廃止した ――
                // 上のカード(sub_element_recommendationsの文面)と実質同じ
                // 内容を繰り返しており、「1ページ1推奨」の妨げになっていた
                // (依頼者指定、PDF版と同内容)。フィールド自体は変更していない、
                // 表示しないだけ。
                if ($viewModel->improvementMidTermAction !== null) {
                    $section->addTextBreak(1);
                    $section->addText('中長期の差別化ポイント', ['bold' => true, 'size' => 9.5, 'color' => '2C7F96']);
                    $section->addText($viewModel->improvementMidTermAction, ['size' => 9]);
                }

                $section->addTextBreak(1);
                $section->addText('サイト上の情報追加だけでなく、実態として存在する魅力の整理も重要です。');
            }

            return;
        }

        $focusSelfOnly = $viewModel->improvementFocusSelfOnly;
        if ($focusSelfOnly === null) {
            return;
        }

        // 2026-08-10: 競合が無い(または読み取れない)診断向け。PDF版
        // (lead-pdf.blade.php、@elseif ($viewModel->improvementFocusSelfOnly))
        // と同内容。「比較サイトが無いため、領域ごとの比較はご用意できません。」
        // の1行だけでページの大半が空白になり、営業資料として成立しないという
        // 指摘(ユーザー)への対応。棒グラフ(groups)は常にBrandWheelImprovement
        // FocusComposer::composeSelfOnly()の決定的な規則で選定した数値のまま
        // (無改修)。
        //
        // 依頼Q-2(2026-08-25): PDF版と同じ出し分け。改善提案AIが
        // focus_sub_element_keysで有効な項目を挙げていれば3枚のカードはAI
        // 由来に差し替わり($focusSelfOnly['items_source'] === 'ai')、
        // 「最も少なかったのは〜」の一文(規則側の主張)は出さない ――
        // 棒グラフに数値は残るため情報は失われない。AI未生成/失敗/有効な
        // 項目0件のときは従来どおり規則由来の一文+カード('rule')のまま。
        $focusSelfOnlyItemsSource = $focusSelfOnly['items_source'] ?? 'rule';
        if ($focusSelfOnlyItemsSource === 'rule') {
            $selectedLabelSelf = self::GROUP_LABELS[$focusSelfOnly['selected_group']] ?? $focusSelfOnly['selected_group'];
            $section->addText(
                "3つの領域のうち、サイトの記述から読み取れた項目が最も少なかったのは「{$selectedLabelSelf}」でした。".
                'この領域から、候補者が知りたがる項目を'.count($focusSelfOnly['items']).'件挙げます。',
            );
        }

        $section->addTextBreak(1);
        foreach ($focusSelfOnly['groups'] as $group) {
            $label = self::GROUP_LABELS[$group['group']] ?? $group['group'];
            $section->addText("{$label}：自社 {$group['self_count']} / {$group['max_count']}");
        }

        if ($focusSelfOnly['items'] === []) {
            $section->addTextBreak(1);
            $section->addText('該当する項目はありませんでした');

            return;
        }

        foreach ($focusSelfOnly['items'] as $i => $item) {
            $section->addTextBreak(1);
            $section->addText(($i + 1).'. '.$item['sub_name'], ['bold' => true]);
            $section->addText($item['recommendation'], ['size' => 9, 'color' => '6B6767']);
            $section->addText($this->selfOnlyReasonLabel($item['self_reason']), ['size' => 8, 'color' => '9A9A9A']);
        }

        if ($viewModel->improvementMidTermAction !== null) {
            $section->addTextBreak(1);
            $section->addText('中長期の差別化ポイント', ['bold' => true, 'size' => 9.5, 'color' => '2C7F96']);
            $section->addText($viewModel->improvementMidTermAction, ['size' => 9]);
        }

        $section->addTextBreak(1);
        $section->addText('サイト上の情報追加だけでなく、実態として存在する魅力の整理も重要です。');
    }

    /**
     * 2026-08-10: PDF版(lead-pdf.blade.php)の$selfOnlyReasonLabelクロージャと
     * 同内容。－(該当なし)と△(見出し・リンクラベルのみ)で文言を分ける
     * (ユーザー承認: 対比表ページの△の定義と一貫させるため、一律
     * 「記述が見つかりませんでした」にはしない)。
     */
    private function selfOnlyReasonLabel(string $reason): string
    {
        return $reason === 'label_only'
            ? '（現在、見出し・リンクラベルのみで、具体的な記述は見つかりませんでした）'
            : '（現在、サイトからは読み取れませんでした）';
    }

    /**
     * PDF版7ページ目(最終ページ)と同内容。2026-08-17: 長い説明文を削除し、
     * 営業CTAに集中させる(依頼者指定)。この新文言は2026-08-08時点の
     * 「旧CTA『他社比較(3〜5社)』は使わない」という制約と直接矛盾するが、
     * 今回の依頼文がこの文言を明示的に指定しているため優先する(PDF版の
     * コメント・実装報告に方針転換である旨を明記)。
     *
     * 連絡先は2026-08-10時点の方針を維持: https://leggenda-co.web-tools.biz/inquiry
     * のみを掲載し、外部フォームツール本体のURL・電話番号は掲載しない。
     * URLをそのまま長文表示せず、ボタン風のラベル付きリンクにする
     * (依頼者指定)。発行日は表紙と同じ$viewModel->generatedAtLabelを参照する。
     */
    private function addCallToActionSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();

        $section->addTextBreak(4);
        $section->addText(
            'さらに3〜5社の競合採用サイトと比較し、御社が優先して改善すべき課題を整理しませんか？',
            ['bold' => true, 'size' => 18],
            ['alignment' => Jc::CENTER],
        );
        $section->addTextBreak(1);
        $section->addText(
            '詳細な比較結果をもとに、採用課題についてディスカッションします。',
            ['size' => 10.5, 'color' => '6B6767'],
            ['alignment' => Jc::CENTER],
        );

        $section->addTextBreak(3);
        // ボタン風のラベル付きリンク(URL文字列をそのまま表示しない、依頼者指定)。
        // PhpWordのTableスタイルにセンタリング用のalignmentは持たせず
        // (Word版はPDF版ほど厳密な中央寄せ検証を必要としない)、セル内の
        // テキストはJc::CENTERで中央寄せする。
        $btnTable = $section->addTable();
        $btnTable->addRow();
        $btnCell = $btnTable->addCell(4500, ['bgColor' => '1D2088', 'valign' => 'center']);
        $btnCell->addLink(
            'https://leggenda-co.web-tools.biz/inquiry',
            '競合比較について相談する',
            ['bold' => true, 'size' => 13, 'color' => 'FFFFFF', 'underline' => 'none'],
            ['alignment' => Jc::CENTER, 'spaceBefore' => 150, 'spaceAfter' => 150],
        );

        $section->addTextBreak(1);
        $section->addText(
            "お問い合わせの際は、本レポートの発行日（{$viewModel->generatedAtLabel}）と貴社名をお知らせください。",
            ['size' => 9.5, 'color' => '9A9A9A'],
            ['alignment' => Jc::CENTER],
        );
    }
}
