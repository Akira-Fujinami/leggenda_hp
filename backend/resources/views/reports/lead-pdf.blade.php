<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>Webサイト診断レポート</title>
<style>
{{--
    2026-08-04: A4横のスライド構成に全面書き直し(docs/lead-report-layout/
    report-layout-draft.htmlを移植元とする)。dompdfの制約により、
    flexbox・CSS grid・box-shadow・clip-pathは一切使わない(すべてtable+
    インラインスタイルで組む)。六角形のラベル(スライドのクリップパス)は
    紺の矩形(.hexbox)で代用する。
--}}
    @page {
        size: A4 landscape;
        margin: 0;
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
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: 'IPAexGothic', sans-serif;
        color: #1a1a1a;
        font-size: 11pt;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    p { margin: 0 0 2mm; word-wrap: break-word; overflow-wrap: break-word; }
    {{--
        高さ(height/min-height)は一切指定しない ―― 210mm(A4横の高さ)を
        指定すると、内容が短いページでも直後に空白ページが1枚挿入される
        不具合が実PDF確認で見つかった(2026-08-04、min-height化でも再現)。
        @page { size: A4 landscape } が既に1ページの大きさを決めているため、
        div側の高さ指定は不要 ―― 幅とパディングだけ指定し、高さは内容に
        まかせる(page-break-afterが物理ページの区切りを作る)。
    --}}
    .page { width: 297mm; padding: 14mm 16mm; position: relative; page-break-after: always; }
    {{-- :last-child はdompdfで確実に効くとは限らないため、最後のページ
         (.cta)は個別クラスで明示的にpage-break-afterを打ち消す。 --}}
    .page.cta { page-break-after: auto; }
    h1 { font-size: 24pt; margin: 0 0 6mm; font-weight: normal; }
    h2 { font-size: 15pt; margin: 0 0 5mm; font-weight: normal; border-bottom: 1px solid #d8d8d8; padding-bottom: 2mm; }
    .cover { padding-top: 60mm; text-align: center; }
    .cover p { margin: 1.5mm 0; font-size: 11pt; color: #333; }
    {{--
        table-layout:fixedを既定にする ―― dompdfのtable-layout:auto(既定)は
        列幅をセル内容から再計算するため、画像やURLを含む列で意図した幅を
        超えてページ右端からはみ出す(2026-08-04の実PDF確認で発覚)。
        各テーブルの列幅はwidth(%またはmm)で明示済みなので、fixedで
        その指定値をそのまま使わせる。
    --}}
    table { border-collapse: collapse; width: 100%; table-layout: fixed; }
    td { vertical-align: top; }
    .lead { font-size: 9.5pt; color: #5b5b5b; margin: 0 0 4mm; }
    .hexbox { background: #33587f; color: #fff; font-size: 9pt; line-height: 1.7; padding: 6mm 4mm; text-align: center; width: 44mm; }
    .sumhead { font-size: 10.5pt; font-weight: bold; margin: 0 0 2mm; }
    .sum { font-size: 10pt; line-height: 1.8; margin: 0; padding-left: 5mm; }
    .legend { text-align: center; font-size: 9pt; color: #5b5b5b; margin-top: 1mm; }
    .sw { display: inline-block; width: 9px; height: 9px; margin: 0 2px 0 10px; }
    .bandrow td { color: #fff; text-align: center; font-size: 10.5pt; font-weight: bold; padding: 2mm; }
    .axcell { width: 16.66%; padding: 0 1mm; }
    .axhead { border: 1px solid #d8d8d8; background: #f4f6f8; text-align: center; font-size: 10pt; font-weight: bold; padding: 1.5mm; }
    .axbody { border: 1px solid #d8d8d8; border-top: none; padding: 2mm; height: 30mm; }
    .cnt { font-size: 8.5pt; color: #5b5b5b; margin: 0 0 1mm; }
    .hits { margin: 0; padding-left: 4mm; font-size: 9pt; line-height: 1.6; }
    .none { font-size: 9pt; color: #8a8a8a; margin: 0; }
    .darkband { background: #33587f; color: #fff; padding: 3mm 5mm; margin-top: 3mm; }
    .darkband p { margin: 1mm 0; font-size: 10pt; line-height: 1.7; }
    .pane { border: 1px solid #33587f; padding: 4mm; width: 48%; }
    .pane h4 { margin: 0 0 2mm; font-size: 11pt; }
    .pane ul { margin: 0; padding-left: 5mm; font-size: 10pt; line-height: 1.9; }
    .arrow { text-align: center; font-size: 20pt; color: #33587f; padding: 3mm 0; }
    .one { border: 1px solid #d8d8d8; padding: 4mm; }
    .one h4 { margin: 0 0 2mm; font-size: 11pt; }
    .one p { margin: 0; font-size: 10pt; line-height: 1.8; }
    .pcell { width: 50%; padding: 0 3mm 6mm 0; }
    {{--
        height:100%は付けない ―― dompdfは制約の無い(高さを持つ祖先が無い)
        文脈で100%を「ページの残り高さ全体」に近い値として解決することがあり、
        カード1つがページ全体の高さまで異常に引き伸ばされてfoot(絶対配置)と
        重なって文字化けする不具合が実PDF確認で見つかった(2026-08-04)。
        高さはpadding+内容にまかせる(auto)。
    --}}
    .pbox { border: 1px solid #d8d8d8; padding: 4mm; }
    .ptitle { font-size: 11.5pt; margin: 0 0 2mm; }
    .badge { display: inline-block; padding: 0.8mm 3mm; font-size: 9pt; }
    .badge.good { background: #e6f4ea; color: #1e6b38; }
    .badge.review { background: #fff8e1; color: #8a6d00; }
    .badge.warn { background: #fdecea; color: #9b2c22; }
    .badge.neutral { background: #eeeeee; color: #555555; }
    .pdesc { font-size: 10pt; line-height: 1.8; margin: 2.5mm 0 0; }
    .reccell { width: 33.3%; padding: 0 2mm 4mm 0; }
    .rectitle { font-size: 11pt; margin: 0 0 2mm; }
    .recdesc { font-size: 9.5pt; line-height: 1.8; margin: 0 0 3mm; }
    .recmeta { font-size: 9pt; color: #5b5b5b; margin: 0; }
    .recbox { border: 1px solid #d8d8d8; padding: 4mm; height: 46mm; }
    {{--
        position:absoluteは使わない ―― 実PDF確認で2つの不具合が見つかった
        (2026-08-04): (1) 既定のoverflow-wrap:break-wordのままだと、2行に
        折り返す境界の文字がまれに欠落する、(2) word-wrap:normalに変えると
        今度は折り返し自体が起きず、右端(right:16mm)より先の文章が丸ごと
        見えなくなる(2文目が消える)。通常フロー(position指定なし)の段落は
        このドキュメントの他の場所(AI解析による印象、サマリー等)で問題なく
        複数行に折り返せているため、position:absolute自体が原因と判断し、
        通常フローに変更した。ページ最下部への固定配置ではなくなるが、
        内容が欠落するよりはるかに安全。
    --}}
    .foot { margin-top: 6mm; font-size: 8.5pt; color: #7a7a7a; line-height: 1.6; }
    .cta { text-align: center; padding-top: 45mm; }
    .cta p { font-size: 12pt; line-height: 2; }
</style>
</head>
<body>

@php
    $selfWheel = $viewModel->brandWheelSelf;
    $competitorWheel = $viewModel->brandWheelCompetitor;
    $selfReadable = ($selfWheel['status'] ?? null) === 'success' && ! empty($selfWheel['axes']);
    $competitorReadable = ($competitorWheel['status'] ?? null) === 'success' && ! empty($competitorWheel['axes']);
    $comparison = $viewModel->brandWheelComparison;
    $hasComparisonContent = ! empty($comparison['self_points']) || ! empty($comparison['competitor_points']) || $comparison['one_point'] !== null;

    $groupBands = [
        'company_appeal' => ['label' => '青／会社の魅力', 'color' => '#3f6fa3'],
        'company_distance' => ['label' => '緑／会社との距離', 'color' => '#4a7d5f'],
        'job_appeal' => ['label' => '赤／仕事の魅力', 'color' => '#a3413c'],
    ];

    $badgeClassByStatus = [
        'good' => 'good',
        'needs_review' => 'review',
        'needs_improvement' => 'warn',
        'not_measured' => 'neutral',
        'not_applicable' => 'neutral',
        'not_detected' => 'neutral',
        'unavailable' => 'neutral',
    ];
@endphp

{{-- 1. 表紙 --}}
<div class="page cover">
    <h1>Webサイト診断レポート</h1>
    <p>{{ $viewModel->companyDisplayName }}</p>
    <p>対象サイト: {{ $viewModel->selfWebsiteUrl }}</p>
    @if ($viewModel->competitorWebsiteUrl)
        <p>比較サイト: {{ $viewModel->competitorWebsiteUrl }}</p>
    @endif
    <p>{{ $viewModel->generatedAtLabel }}</p>
    @if ($viewModel->isPartial)
        <p style="font-size: 9pt; color: #7a7a7a;">一部のデータは取得できませんでしたが、取得できた範囲での診断結果です。</p>
    @endif
</div>

{{-- 2. 自社ページの分析結果 --}}
<div class="page">
    <h2>自社ページの分析結果</h2>

    @if (! $selfReadable)
        {{-- 6項目すべて0件の図・表は「魅力のない会社」の意味になるため出さない。
             理由の文言はconfig('brand_wheel.status_messages')が唯一の定義元。 --}}
        <p>{{ $selfWheel['status_message'] ?? '' }}</p>
    @else
        {{--
            列幅をすべて明示する(hexbox 44mm + summary 126mm + radar 95mm =
            265mm、ページの実効幅297mm-左右パディング16mm*2と一致)。
            table-layout:fixedと組み合わせて、右端の列(画像)がページ外へ
            はみ出さないようにする(2026-08-04の実PDF確認で発覚した崩れの修正)。
            画像は105mmから95mmに縮小(高さも比例して約69mmに)し、この
            ページの合計高さが1ページに収まるようにしている(縮小前は
            「AI解析による印象」の行だけが次ページに追い出されていた)。
        --}}
        <table style="width: 265mm;"><tr>
            <td class="hexbox" style="width: 44mm;">各項目について<br>該当する内容が<br>何件読み取れたかを<br>集計しています</td>
            <td style="width: 126mm; padding-left: 8mm;">
                <p class="sumhead">サマリー</p>
                <ul class="sum">
                    @if ($selfWheel['analyzed_url'])
                        <li>解析したURL：{{ $selfWheel['analyzed_url'] }}</li>
                    @endif
                    @foreach ($comparison['self_points'] as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
            </td>
            <td style="width: 95mm;">
                @if ($viewModel->brandWheelRadarPng)
                    {{-- レーダー図のviewBoxは380x276(縦横比380:276)。dompdfは
                         widthのみ指定だと縦横比を正しく保持しないことがあるため、
                         heightも明示して指定どおりの比率で描画させる。 --}}
                    <img src="data:image/png;base64,{{ base64_encode($viewModel->brandWheelRadarPng) }}" style="width: 95mm; height: 69mm;">
                    <div class="legend">
                        <span class="sw" style="background: #2a78d6;"></span>自社サイト
                        @if ($competitorReadable)
                            <span class="sw" style="background: #eb6834;"></span>競合サイト
                        @endif
                    </div>
                @endif
            </td>
        </tr></table>

        <table class="bandrow" style="margin-top: 4mm;"><tr>
            @foreach ($groupBands as $band)
                <td colspan="2" style="background: {{ $band['color'] }};">{{ $band['label'] }}</td>
            @endforeach
        </tr></table>

        <table style="margin-top: 2mm;"><tr>
            @foreach ($selfWheel['axes'] as $axis)
                <td class="axcell">
                    <div class="axhead">{{ $axis['name'] }}</div>
                    <div class="axbody">
                        <p class="cnt">読み取れた内容 {{ $axis['matched_count'] }} / {{ $axis['max_count'] }}件</p>
                        @if (count($axis['matched_sub_elements']) > 0)
                            <ul class="hits">
                                @foreach ($axis['matched_sub_elements'] as $sub)
                                    <li>{{ $sub['name'] }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="none">読み取れた内容はありません</p>
                        @endif
                    </div>
                </td>
            @endforeach
        </tr></table>

        @if ($selfWheel['key_message'] || $selfWheel['impression'])
            <div class="darkband">
                @if ($selfWheel['key_message'])
                    <p><b>キーメッセージ：</b>{{ $selfWheel['key_message'] }}</p>
                @endif
                @if ($selfWheel['impression'])
                    <p><b>AI解析による印象：</b>{{ $selfWheel['impression'] }}</p>
                @endif
            </div>
        @endif
    @endif
</div>

{{-- 3. 他社ページ比較とのまとめ --}}
<div class="page">
    <h2>他社ページ比較とのまとめ</h2>

    @if (! $hasComparisonContent)
        <p>{{ $selfWheel['status_message'] ?? '比較のまとめを今回はご用意できませんでした。' }}</p>
    @else
        <table><tr>
            @if (! empty($comparison['self_points']))
                <td class="pane">
                    <h4>【自社ページ】</h4>
                    <ul>
                        @foreach ($comparison['self_points'] as $point)
                            <li>{{ $point }}</li>
                        @endforeach
                    </ul>
                </td>
                <td style="width: 4%;"></td>
            @endif
            @if (! empty($comparison['competitor_points']))
                <td class="pane">
                    <h4>【他社ページ】</h4>
                    <ul>
                        @foreach ($comparison['competitor_points'] as $point)
                            <li>{{ $point }}</li>
                        @endforeach
                    </ul>
                </td>
            @endif
        </tr></table>

        @if ($comparison['one_point'])
            <div class="arrow">▼</div>
            <div class="one">
                <h4>【ワンポイント】</h4>
                <p>{{ $comparison['one_point']['text'] }}</p>
            </div>
        @endif
    @endif

    {{--
        1つの<p>にまとめず2文に分ける ―― 1つの長い段落のまま2行に折り返す
        と、折り返し境界の文字がまれに欠落する不具合が実PDF確認で見つかった
        (2026-08-04、position:absolute解消・setPaper修正・フォント
        サブセット無効化のいずれでも再現し、原因を切り分けられなかった)。
        文単位で<p>を分けることで折り返し位置自体を変え、回避する。
    --}}
    <p class="foot">ブランド・ホイールは本来、サイトだけでなくグループインタビュー・口コミ・内定者/辞退者インタビュー・説明会・SNSなども併せて構築するものです。今回はそのうちサイトの記述のみを拝見しています。</p>
    <p class="foot" style="margin-top: 1mm;">キーメッセージと印象の読み取りにはAIを使用しています。</p>
</div>

{{-- 4. 採用担当の視点で見た診断結果(4観点。判定と理由1文のみ、個別指標名は出さない) --}}
<div class="page">
    <h2>採用担当の視点で見た診断結果</h2>
    <p class="lead">4つの観点それぞれについて、判定と、その理由を一言で記載しています。</p>

    <table>
        @foreach (array_chunk($viewModel->perspectives, 2) as $row)
            <tr>
                @foreach ($row as $perspective)
                    <td class="pcell">
                        <div class="pbox">
                            <p class="ptitle">{{ $perspective['heading'] }}</p>
                            <span class="badge {{ $badgeClassByStatus[$perspective['status']] ?? 'neutral' }}">
                                {{ \App\Services\Lead\LeadPerspectiveComposer::statusLabel($perspective['status']) }}
                            </span>
                            <p class="pdesc">{{ $perspective['one_liner'] }}</p>
                        </div>
                    </td>
                @endforeach
            </tr>
        @endforeach
    </table>

    <p class="foot">
        取得できなかった項目は0点として扱わず、算出の対象から外しています(測定カバー率 {{ number_format($viewModel->selfScore['coverage_rate'], 1) }}%／確信度 {{ number_format($viewModel->selfScore['confidence_rate'], 1) }}%)。
    </p>
</div>

{{-- 5. 改善提案 --}}
<div class="page">
    <h2>改善提案</h2>
    <p class="lead">特に改善効果が見込まれる項目を、優先度の高い順に記載しています。</p>

    @if (count($viewModel->topRecommendations) === 0)
        <p>現時点で優先度の高い改善提案はありません。</p>
    @else
        <table>
            @foreach (array_chunk($viewModel->topRecommendations, 3) as $row)
                <tr>
                    @foreach ($row as $recommendation)
                        <td class="reccell">
                            <div class="recbox">
                                <p class="rectitle">{{ $recommendation->title }}</p>
                                <p class="recdesc">{{ $recommendation->description }}</p>
                                <p class="recmeta">優先度: {{ $recommendation->priorityLabel }}　影響度: {{ $recommendation->impactLabel }}　対応工数: {{ $recommendation->effortLabel }}</p>
                            </div>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    @endif
</div>

{{-- 6. ご相談 --}}
<div class="page cta">
    <h2>より詳しい診断・ご相談はこちら</h2>
    <p>今回は自社サイト{{ $viewModel->competitorWebsiteUrl ? '・比較サイト1社' : '' }}の簡易診断結果です。</p>
    <p>他社比較(3〜5社)や、詳細な改善提案については、担当者までお気軽にご相談ください。</p>
</div>

</body>
</html>
