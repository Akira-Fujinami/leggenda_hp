<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>多社比較レポート</title>
<style>
{{--
    依頼AC(2026-08-27): 管理者向け多社比較レポート(自社1×競合N社、N=3〜5、
    PDFのみ)専用テンプレート。既存のreports/lead-pdf.blade.phpは無改修のまま
    (依頼AC禁止事項) ―― こちらは新規に独立したテンプレートとして作る。
    dompdfの制約(flexbox・grid不可)・IPAexフォント埋め込み・A4横は
    lead-pdf.blade.phpと同じ方針を踏襲する。
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
        color: #393636;
        font-size: 11pt;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    p { margin: 0 0 2mm; word-wrap: break-word; overflow-wrap: break-word; }
    .page { width: 297mm; padding: 6mm 16mm 20mm; position: relative; page-break-after: always; }
    .page:last-child { page-break-after: auto; }
    h1 { font-size: 24pt; margin: 0 0 6mm; font-weight: normal; }
    h2 { width: 265mm; font-size: 15pt; margin: 0 0 3mm; font-weight: normal; border-bottom: 1px solid #E0E0E0; padding-bottom: 1.5mm; }
    .cover { width: 265mm; padding-top: 50mm; text-align: center; }
    .cover p { margin: 1.5mm 0; font-size: 11pt; color: #333; }
    table { border-collapse: collapse; width: 100%; table-layout: fixed; }
    td { vertical-align: top; }
    .lead1 { width: 265mm; font-size: 9.5pt; color: #6B6767; margin: 0 0 2mm; line-height: 1.3; }

    .logo-cover { display: block; margin: 0 auto 10mm; width: 72mm; }
    .logo-mark { position: absolute; left: 251mm; bottom: 7mm; width: 30mm; opacity: .9; }

    {{-- 前置き(フレームワーク説明)ページ。 --}}
    .introlead { font-size: 11.5pt; margin: 0 0 2.5mm; }
    .grouptbl { margin-bottom: 2mm; }
    .grouptbl td { padding: 1.3mm 0; vertical-align: middle; }
    .gcell { width: 34mm; text-align: center; font-size: 10pt; font-weight: bold; padding: 2mm 1mm; }
    .gdesc { width: 105mm; font-size: 10pt; line-height: 1.5; padding-left: 4mm; }
    .introbody { width: 265mm; font-size: 9.5pt; line-height: 1.4; margin: 0 0 1.5mm; }
    .axisdefs { width: 139mm; font-size: 8pt; line-height: 1.3; color: #4a4a4a; margin: 1.5mm 0 0; }

    {{-- 自社分析結果ページ。 --}}
    .statbox { border: 1px solid #E0E0E0; padding: 2.5mm 4mm; display: inline-block; }
    .statbox .lab { font-size: 9.5pt; color: #6B6767; margin: 0 0 1.5mm; }
    .statbox .num { font-size: 26pt; font-weight: bold; line-height: 1; margin: 0; }
    .statbox .num small { font-size: 11pt; font-weight: normal; color: #6B6767; }
    .legend { text-align: center; font-size: 9pt; color: #5b5b5b; margin-top: 1mm; }
    .sw { display: inline-block; width: 9px; height: 9px; margin: 0 2px 0 10px; }

    {{-- ①自社に足りない項目・②自社の強み ページ。 --}}
    .itemcard { border: 1px solid #E0E0E0; padding: 2.5mm 4mm; margin: 0 0 3mm; page-break-inside: avoid; }
    .itemcard .axis { font-size: 8.5pt; color: #6B6767; margin: 0 0 0.5mm; }
    .itemcard .sub { font-size: 12pt; font-weight: bold; margin: 0 0 1mm; }
    .itemcard .def { font-size: 9pt; color: #4a4a4a; line-height: 1.35; margin: 0 0 1.5mm; }
    .itemcard .badge { display: inline-block; font-size: 8.5pt; color: #fff; background: #E95446; padding: 0.6mm 2.2mm; margin: 0 0 1.5mm; }
    .itemcard .badge.self { background: #1D2088; }
    .itemcard .quotebox { border-left: 3px solid #E95446; padding-left: 2.5mm; margin: 1mm 0 0; }
    .itemcard .quotebox .company { font-size: 8.5pt; font-weight: bold; color: #393636; margin: 0 0 0.5mm; }
    .itemcard .quotebox .quote { font-size: 9pt; line-height: 1.3; color: #393636; margin: 0; }
    .itemcard .quotebox .quote-translation { font-size: 8pt; line-height: 1.3; color: #8A8A8A; margin: 0.5mm 0 0; }
    .itemcard .reco { font-size: 8.5pt; color: #1D2088; margin: 1.5mm 0 0; line-height: 1.3; }
    .emptynotice { width: 265mm; font-size: 10pt; color: #6B6767; line-height: 1.5; }

    {{-- 対比表ページ。個社ごとの列(自社+競合N社)を1本の縦長tableで並べる ――
         3グループ側並びの既存レイアウト(競合1社専用)は、列数が最大6列
         (自社+競合5社)まで増える多社比較では横幅が破綻するため採用しない。 --}}
    {{--
        依頼AC-2実測: white-space:nowrap+overflow:hiddenの組み合わせは、
        dompdfが列幅に収まらない分の文字を「視覚的にクリップする」のではなく
        「レイアウト時点で読み込まず落とす」ため、PHP側でmb_substrにより
        文字数を絞って省略記号を付けても、実際に描画される文字数がさらに
        少なくなり省略記号自体が消える不具合を実PDF確認(PyMuPDFでのテキスト
        抽出)で確認した。nowrap/overflow指定をやめ、折り返しを許すことで
        文字を一切失わないようにする(長い社名は見出し行が2行になるだけ)。
    --}}
    .cmptbl th { font-size: 8.5pt; font-weight: bold; padding: 1.2mm 1mm; border-bottom: 1px solid #E0E0E0; color: #6B6767; text-align: center; background: #F5F5F5; line-height: 1.2; }
    .cmptbl th.sub { text-align: left; background: #fff; }
    .cmptbl td { font-size: 8.5pt; padding: 1mm; border-bottom: 1px solid #EFEFEF; }
    .cmptbl td.sub { text-align: left; }
    .cmptbl td.mk { text-align: center; font-size: 10.5pt; }
    .cmptbl .grouphead td { color: #fff; font-size: 9.5pt; font-weight: bold; padding: 1mm 2mm; }
    .mkon { color: #1D2088; font-weight: bold; }
    .mkon.cp { color: #E95446; }
    .mkoff { color: #BFBFBF; }
    .cmplegend { width: 265mm; font-size: 8.5pt; color: #6B6767; margin: 1.5mm 0 0; line-height: 1.4; }
    .urllegend { width: 265mm; font-size: 8pt; color: #8A8A8A; margin: 1.5mm 0 0; line-height: 1.5; }

    {{-- 「○と判定した根拠」ページ(依頼R方式踏襲)。 --}}
    .evidenceintro { width: 265mm; font-size: 9pt; color: #6B6767; margin: 0 0 3mm; line-height: 1.3; }
    .evidenceaxis { margin: 0 0 3mm; }
    .evidenceaxis .axisname { width: 265mm; font-size: 11.5pt; font-weight: bold; color: #1D2088; margin: 0 0 1.5mm; border-bottom: 1px solid #D3D4EC; padding-bottom: 0.8mm; }
    .evidenceitem { width: 265mm; margin: 0 0 2mm; page-break-inside: avoid; }
    .evidenceitem .subname { font-size: 9.5pt; font-weight: bold; margin: 0 0 0.5mm; }
    .evidenceitem .quote { font-size: 9pt; line-height: 1.3; margin: 0; border-left: 3px solid #3A3FC0; padding-left: 2.5mm; color: #393636; }
    .evidenceitem .quote-translation { font-size: 8pt; line-height: 1.3; margin: 0.5mm 0 0; padding-left: 2.5mm; color: #8A8A8A; }
</style>
</head>
<body>

@php
    $groupBands = [
        'company_appeal' => ['label' => '会社の魅力', 'color' => '#1D2088', 'tint' => '#D3D4EC'],
        'company_distance' => ['label' => '会社との距離', 'color' => '#2C7F96', 'tint' => '#CFE3EA'],
        'job_appeal' => ['label' => '仕事の魅力', 'color' => '#C03A28', 'tint' => '#F7DCD7'],
    ];

    // 依頼AC-2: 列幅に収まるよう社名を切り詰め、切り詰めたことが見た目で
    // 分かるよう省略記号(…)を付ける(依頼者指定 ―― 「競合1」等の記号表記は
    // 不可、実名を列見出しに必ず入れる)。
    //
    // 実PDF確認(PyMuPDFでのテキスト抽出): 当初、列幅(mm)から収容文字数を
    // 逆算しwhite-space:nowrap+overflow:hiddenで1行に収めようとしたところ、
    // dompdfがCSSのoverflow:hiddenを「レイアウト時点で文字を読み込まず
    // 落とす」形で実装しており、PHP側で計算した文字数より短く、かつ
    // 省略記号(…)自体が失われる形で描画される不具合が見つかった。
    // white-space:nowrap/overflowを外し折り返しを許すことで文字を一切
    // 失わないようにした(長い社名は見出し行が2行になるだけ、依頼AC-2の
    // 「実測して収まらない場合は報告」を踏まえた対応)。折り返しに任せる
    // ため、ここでの切り詰めは列幅ちょうどへの厳密な逆算ではなく、
    // 極端に長い社名(数十文字の自由入力)で見出し行が際限なく高くなる
    // ことを避けるための上限として機能させる。
    $dataColumnCount = $viewModel->competitorCount + 1;
    $subNameColumnWidthMm = 42;
    $dataColumnWidthMm = (265 - $subNameColumnWidthMm) / $dataColumnCount;
    $maxNameChars = 16;
    $truncateName = function (string $name) use ($maxNameChars) {
        return mb_strlen($name) > $maxNameChars
            ? mb_substr($name, 0, $maxNameChars).'…'
            : $name;
    };
@endphp

{{-- 1. 表紙(自社名・競合N社名・作成日)。 --}}
<div class="page cover">
    <img class="logo-cover" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
    <h1>多社比較レポート</h1>
    <p>{{ $viewModel->selfCompanyDisplayName }}</p>
    <p>対象サイト: {{ $viewModel->selfWebsiteUrl }}</p>
    <p>比較対象: {{ $viewModel->competitorCount }}社</p>
    @foreach ($viewModel->competitors as $competitor)
        <p style="font-size: 9.5pt; color: #6B6767;">{{ $competitor['name'] }}（{{ $competitor['url'] }}）</p>
    @endforeach
    <p>{{ $viewModel->generatedAtLabel }}</p>
    <p style="font-size: 8.5pt; color: #9A9A9A; margin-top: 6mm;">この資料は営業活動での利用を目的とした社内限定資料です。社外への配布・提出はできません。</p>
</div>

{{-- 2. 採用ブランドの捉え方 ―― ブランド・ホイール(前置き、既存の固定資産を流用)。 --}}
<div class="page">
    <h2>採用ブランドの捉え方 ―― ブランド・ホイール</h2>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
    <table style="width: 265mm;"><tr>
        <td style="width: 126mm; padding-right: 8mm;">
            <img src="data:image/png;base64,{{ $brandWheelFrameworkImageBase64 }}" style="width: 124mm;">
        </td>
        <td style="width: 139mm; vertical-align: top; padding-top: 2mm;">
            <p class="introlead">採用ブランドは、大きく3つの領域に分けて捉えます。</p>
            <table class="grouptbl"><tr>
                <td class="gcell" style="background: {{ $groupBands['company_appeal']['tint'] }};">会社の魅力</td>
                <td class="gdesc">その会社が何を目指し、どれだけの実績・規模を持っているか。<br><b>活動的魅力</b>・<b>資産的魅力</b></td>
            </tr><tr>
                <td class="gcell" style="background: {{ $groupBands['company_distance']['tint'] }};">会社との距離</td>
                <td class="gdesc">どんな経営で、どんな人たちが、どんな環境で働いているか。<br><b>経営スタイル</b>・<b>就業環境</b></td>
            </tr><tr>
                <td class="gcell" style="background: {{ $groupBands['job_appeal']['tint'] }};">仕事の魅力</td>
                <td class="gdesc">その仕事に就くと、何が得られるか。<br><b>情緒的便益</b>・<b>金銭的便益</b></td>
            </tr></table>
            <p class="axisdefs">
                @foreach ((array) config('brand_wheel.axes', []) as $axis)
                    <b>{{ $axis['name_ja'] }}</b>：{{ $axis['definition'] }}
                @endforeach
            </p>
        </td>
    </tr></table>
    {{-- 依頼AG-2(2026-08-27): 「以降のページでは」という相対位置表現を削除
         (依頼AEと同じ考え方 ―― ページ構成の変更で実態とズレる恐れがある)。 --}}
    <p class="introbody">6つの項目にはそれぞれ4つの下位要素があり、合計24項目です。本レポートでは、自社と競合{{ $viewModel->competitorCount }}社を、この24項目で比較します。</p>
    <p class="introbody" style="font-size: 9.5pt; color: #6B6767;">読み取れなかった項目は、その魅力が『無い』という意味ではありません。サイトにそう書かれていない、というだけです。</p>
</div>

{{-- 3. 自社サイトの分析結果(スコア・レーダー図)。 --}}
<div class="page">
    <h2>自社サイトの分析結果</h2>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
    @if (! $viewModel->selfReadable)
        <p class="lead1">自社サイトの記述からは、今回の比較に必要な情報を十分に読み取れませんでした。</p>
    @else
        <table style="width: 265mm;"><tr>
            <td style="width: 100mm; vertical-align: top;">
                <div class="statbox">
                    <p class="lab">確認できた項目数</p>
                    <p class="num">{{ $viewModel->selfTotalMatched }} <small>/ {{ $viewModel->selfTotalMax }}項目</small></p>
                </div>
            </td>
            <td style="width: 165mm; text-align: center;">
                @if ($viewModel->brandWheelRadarPngCombined)
                    <img src="data:image/png;base64,{{ base64_encode($viewModel->brandWheelRadarPngCombined) }}" style="width: 92mm; height: 66.9mm;">
                    <div class="legend">
                        <span class="sw" style="background: #1D2088;"></span>自社サイト(実線)
                        <span class="sw" style="background: #E95446;"></span>競合{{ $viewModel->competitorCount }}社平均(破線)
                    </div>
                @endif
            </td>
        </tr></table>
    @endif
</div>

{{--
    4. 自社に足りない項目(依頼AC-1の①、結論を先に置く ―― 対比表より前)。
    依頼X-1と同じ種類の事故(0件のときにダングリングした文言を出す)を
    繰り返さないため、0件の場合は専用の分岐で自然な文にする。
--}}
<div class="page">
    <h2>自社に足りない項目</h2>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
    <p class="lead1">競合{{ $viewModel->competitorCount }}社中{{ $viewModel->majorityThreshold }}社以上が言及しているにもかかわらず、自社サイトでは確認できなかった項目です。</p>
    @if ($viewModel->missingFromSelf === [])
        <p class="emptynotice">競合{{ $viewModel->competitorCount }}社と比べて、今回の比較の範囲では、自社に不足している項目は見つかりませんでした。</p>
    @else
        @foreach ($viewModel->missingFromSelf as $item)
            <div class="itemcard">
                <p class="axis">{{ $item['axis_name'] }}</p>
                <p class="sub">{{ $item['sub_name'] }}</p>
                <p class="def">{{ $item['definition'] }}</p>
                <span class="badge">競合{{ $viewModel->competitorCount }}社中{{ $item['competitor_matched_count'] }}社が言及</span>
                @if ($item['quote'] !== null)
                    <div class="quotebox">
                        <p class="company">{{ $item['representative_company_name'] }}の記述より</p>
                        <p class="quote">{{ $item['quote'] }}</p>
                        @if ($item['quote_translation'])
                            <p class="quote-translation">日本語訳: {{ $item['quote_translation'] }}</p>
                        @endif
                    </div>
                @endif
                @if ($item['recommendation'] !== '')
                    <p class="reco">{{ $item['recommendation'] }}</p>
                @endif
            </div>
        @endforeach
    @endif
</div>

{{-- 5. 自社の強み(依頼AC-1の②)。 --}}
<div class="page">
    <h2>自社の強み</h2>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
    <p class="lead1">自社サイトでは確認できましたが、競合{{ $viewModel->competitorCount }}社の過半数({{ $viewModel->majorityThreshold }}社以上)では確認できなかった項目です。</p>
    @if ($viewModel->selfStrengths === [])
        <p class="emptynotice">競合{{ $viewModel->competitorCount }}社と比べて、今回の比較の範囲では、自社だけの強みとして際立つ項目は見つかりませんでした。</p>
    @else
        @foreach ($viewModel->selfStrengths as $item)
            <div class="itemcard">
                <p class="axis">{{ $item['axis_name'] }}</p>
                <p class="sub">{{ $item['sub_name'] }}</p>
                <p class="def">{{ $item['definition'] }}</p>
                <span class="badge self">競合{{ $viewModel->competitorCount }}社中{{ $item['competitor_matched_count'] }}社のみ言及</span>
            </div>
        @endforeach
    @endif
</div>

{{-- 6. 24項目×(自社+競合N社)の対比表。結論(④⑤)の根拠として後ろに置く。 --}}
<div class="page">
    <h2>24項目の対比表</h2>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
    @if (! $viewModel->selfReadable)
        <p class="lead1">自社サイトの記述からは、今回の比較に必要な情報を十分に読み取れませんでした。</p>
    @else
        @php
            $tableByGroup = collect($viewModel->comparisonTable)->groupBy('group');
        @endphp
        {{--
            依頼AC-2実測: table要素にwidth:265mmを明示する。table{width:100%}
            (CSS冒頭の汎用ルール)のままだと、dompdfが親.pageのpadding
            (16mm×2)を差し引かずに.pageの宣言幅297mmを100%の基準にしてしまい、
            <colgroup>で265mm分に収まるよう指定した列幅の合計より実際の
            テーブル幅が広くなる。table-layout:fixedはtable自身の幅に対して
            列幅の合計が不足する分を(主に最終列に)追加で割り当てるため、
            結果として最終列(競合の最終列)がページ右端(297mm)を超えて
            はみ出し、PyMuPDFでの実測で対応するマーク列が丸ごと欠落する
            不具合が見つかった(h2/.darkband等、既存のlead-pdf.blade.phpの
            他の要素と同じ理由・同じ対策、CSS冒頭のh2コメント参照)。
        --}}
        <table class="cmptbl" style="width: 265mm;"><colgroup>
            <col style="width: {{ $subNameColumnWidthMm }}mm;">
            @for ($i = 0; $i < $dataColumnCount; $i++)
                <col style="width: {{ $dataColumnWidthMm }}mm;">
            @endfor
        </colgroup>
        <tr>
            <th class="sub"></th>
            <th>自社</th>
            @foreach ($viewModel->competitors as $competitor)
                <th>{{ $truncateName($competitor['name']) }}</th>
            @endforeach
        </tr>
        @foreach ($groupBands as $groupKey => $band)
            <tr class="grouphead"><td colspan="{{ $dataColumnCount + 1 }}" style="background: {{ $band['color'] }};">{{ $band['label'] }}</td></tr>
            @foreach ($tableByGroup->get($groupKey, []) as $item)
                <tr>
                    <td class="sub">{{ $item['sub_name'] }}</td>
                    <td class="mk">{!! $item['self_matched'] ? '<span class="mkon">○</span>' : '<span class="mkoff">－</span>' !!}</td>
                    @foreach ($item['competitor_matched'] as $matched)
                        <td class="mk">{!! $matched ? '<span class="mkon cp">○</span>' : '<span class="mkoff">－</span>' !!}</td>
                    @endforeach
                </tr>
            @endforeach
        @endforeach
        </table>
        <p class="cmplegend">
            <span class="mkon">○</span> 本文の記述から確認できた項目　<span class="mkoff">－</span> 該当する記述が見つからなかった項目(『魅力が無い』という意味ではありません)
        </p>
        <p class="urllegend">
            @foreach ($viewModel->competitors as $competitor)
                {{ $competitor['name'] }}: {{ $competitor['url'] }}@if (! $loop->last)　@endif
            @endforeach
        </p>
    @endif
</div>

{{-- 7. 自社の「○」と判定した根拠(依頼R方式を踏襲、競合の引用は含まない)。 --}}
@if ($viewModel->selfEvidenceByAxis !== [])
<div class="page">
    <h2>自社の「○」と判定した根拠</h2>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
    <p class="evidenceintro">{{ config('brand_wheel.evidence_page_intro') }}</p>
    @foreach ($viewModel->selfEvidenceByAxis as $axisGroup)
        <div class="evidenceaxis">
            <p class="axisname">{{ $axisGroup['axis_name'] }}</p>
            @foreach ($axisGroup['items'] as $item)
                <div class="evidenceitem">
                    <p class="subname">{{ $item['sub_name'] }}</p>
                    <p class="quote">{{ $item['evidence'] }}</p>
                    @if ($item['evidence_translation'])
                        <p class="quote-translation">日本語訳: {{ $item['evidence_translation'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach
</div>
@endif

</body>
</html>
