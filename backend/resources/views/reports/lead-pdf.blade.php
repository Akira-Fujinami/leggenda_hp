<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>Webサイト診断レポート</title>
<style>
    @page {
        margin: 20mm 15mm;
    }
    @font-face {
        font-family: 'IPAexGothic';
        src: url('{{ $ipaexGothicFontPath }}') format('truetype');
        font-weight: normal;
        font-style: normal;
    }
    @font-face {
        font-family: 'IPAexGothic';
        src: url('{{ $ipaexGothicFontPath }}') format('truetype');
        font-weight: bold;
        font-style: normal;
    }
    body {
        font-family: 'IPAexGothic', sans-serif;
        font-size: 11pt;
        color: #1a1a1a;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    p {
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    h1, h2, h3 {
        font-family: 'IPAexGothic', sans-serif;
    }
    .page {
        page-break-after: always;
        padding: 24px 0;
    }
    .page:last-child {
        page-break-after: auto;
    }
    .cover {
        text-align: center;
        padding-top: 160px;
    }
    .cover h1 {
        font-size: 24pt;
        margin-bottom: 40px;
    }
    .cover p {
        font-size: 12pt;
        margin: 4px 0;
    }
    table {
        width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
        margin-top: 12px;
    }
    th, td {
        border: 1px solid #ccc;
        padding: 6px 8px;
        text-align: left;
        font-size: 10pt;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    th {
        background-color: #f2f2f2;
    }
    .score-headline {
        font-size: 20pt;
        font-weight: bold;
        margin: 12px 0;
    }
    .reference-badge {
        display: inline-block;
        border: 1px solid #b8860b;
        color: #b8860b;
        padding: 2px 8px;
        font-size: 9pt;
        border-radius: 4px;
        margin-left: 8px;
    }
    .summary-text {
        line-height: 1.8;
        margin-top: 16px;
    }
    .recommendation {
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 10px 12px;
        margin-bottom: 10px;
    }
    .recommendation .title {
        font-weight: bold;
        font-size: 11.5pt;
    }
    .recommendation .meta {
        font-size: 9pt;
        color: #555;
        margin-top: 2px;
    }
    .cta {
        text-align: center;
        padding-top: 120px;
    }
    .cta h2 {
        font-size: 18pt;
    }
    .footnote {
        font-size: 8.5pt;
        color: #666;
        margin-top: 24px;
    }
    .perspective {
        margin-bottom: 20px;
    }
    .perspective h3 {
        font-size: 13pt;
        margin-bottom: 4px;
    }
    .perspective-status {
        display: inline-block;
        font-weight: bold;
        padding: 2px 10px;
        border-radius: 4px;
        font-size: 10pt;
        margin-bottom: 6px;
    }
    .perspective-status.good { background-color: #e6f4ea; color: #1e7e34; }
    .perspective-status.needs_review { background-color: #fff8e1; color: #8a6d00; }
    .perspective-status.needs_improvement { background-color: #fdecea; color: #b3261e; }
    .perspective-status.not_measured,
    .perspective-status.not_applicable,
    .perspective-status.not_detected,
    .perspective-status.unavailable { background-color: #eeeeee; color: #555555; }
    .perspective-note {
        font-size: 9pt;
        color: #666;
        margin: 4px 0;
    }
    .perspective-items {
        margin: 6px 0 0 0;
        padding-left: 18px;
    }
    .perspective-items li {
        font-size: 10pt;
        margin-bottom: 3px;
    }
</style>
</head>
<body>

<div class="page cover">
    <h1>Webサイト診断レポート</h1>
    <p>{{ $viewModel->companyDisplayName }}</p>
    <p>対象サイト: {{ $viewModel->selfWebsiteUrl }}</p>
    @if ($viewModel->competitorWebsiteUrl)
        <p>比較サイト: {{ $viewModel->competitorWebsiteUrl }}</p>
    @endif
    <p>{{ $viewModel->generatedAtLabel }}</p>
    @if ($viewModel->isPartial)
        <p class="footnote">一部のデータは取得できませんでしたが、取得できた範囲での診断結果です。</p>
    @endif
</div>

<div class="page">
    <h2>総合結果</h2>
    <p class="footnote" style="margin-top: 0;">採用サイトとして重要な4観点での評価(社内版の点数とは別建てです)</p>
    <p class="score-headline">
        {{ $viewModel->selfScore['display_score'] }}点 <span style="font-size: 12pt; font-weight: normal;">/ {{ (int) round($viewModel->selfScore['configured_max_score']) }}点</span>
        @if ($viewModel->selfScore['coverage_rate'] < 70)
            <span class="reference-badge">参考スコア</span>
        @endif
    </p>
    <p>測定カバー率: {{ number_format($viewModel->selfScore['coverage_rate'], 2) }}%　確信度: {{ number_format($viewModel->selfScore['confidence_rate'], 2) }}%</p>
    <p class="summary-text">{{ $viewModel->overallSummaryText }}</p>
    @if ($viewModel->comparisonSentence)
        <p class="summary-text">{{ $viewModel->comparisonSentence }}</p>
    @endif
</div>

<div class="page">
    <h2>採用担当の視点で見た診断結果</h2>
    @foreach ($viewModel->perspectives as $perspective)
        <div class="perspective">
            <h3>{{ $perspective['heading'] }}</h3>
            <span class="perspective-status {{ $perspective['status'] }}">
                {{ \App\Services\Lead\LeadPerspectiveComposer::statusLabel($perspective['status']) }}
            </span>
            @if (!empty($perspective['note']))
                <p class="perspective-note">{{ $perspective['note'] }}</p>
            @endif
            @if (!empty($perspective['summary']))
                <p>{{ $perspective['summary'] }}</p>
            @endif
            @if (!empty($perspective['items']))
                <ul class="perspective-items">
                    @foreach ($perspective['items'] as $item)
                        <li>{{ $item['label'] }}: {{ \App\Services\Lead\LeadPerspectiveComposer::statusLabel($item['status']) }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach
</div>

<div class="page">
    <h2>改善提案</h2>
    @if (count($viewModel->topRecommendations) === 0)
        <p>現時点で優先度の高い改善提案はありません。</p>
    @else
        @foreach ($viewModel->topRecommendations as $recommendation)
            <div class="recommendation">
                <p class="title">{{ $recommendation->title }}</p>
                <p>{{ $recommendation->description }}</p>
                <p class="meta">優先度: {{ $recommendation->priorityLabel }}　影響度: {{ $recommendation->impactLabel }}　対応工数: {{ $recommendation->effortLabel }}</p>
            </div>
        @endforeach
    @endif
</div>

<div class="page cta">
    <h2>より詳しい診断・ご相談はこちら</h2>
    <p>今回は自社サイト{{ $viewModel->competitorWebsiteUrl ? '・比較サイト1社' : '' }}の簡易診断結果です。</p>
    <p>他社比較(3〜5社)や、詳細な改善提案については、担当者までお気軽にご相談ください。</p>
</div>

</body>
</html>
