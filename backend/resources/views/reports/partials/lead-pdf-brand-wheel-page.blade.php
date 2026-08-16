{{--
    自社/競合サイトの分析結果ページ(3・4ページ目)の共通レイアウト。
    2026-08-08: 7ページ構成への再編で、旧3ページ目(自社×競合を1ページに
    同居させていた版)から競合要素を分離し、自社・競合それぞれ単独の
    ページとしてこのpartialを2回includeする形にした(docs/lead-report-layout/
    README.md「3-1/3-2」)。呼び出し側は主体が変わるだけで完全に同じ形式・
    同じレイアウトにすること(ユーザー指定)。

    必須変数:
      $pageTitle      string  「自社サイトの分析結果」/「競合サイトの分析結果」
      $wheel          array   brandWheelSelf/brandWheelCompetitor(BrandWheelLeadResponseComposer::compose()の戻り値)
      $readable       bool    $selfReadable/$competitorReadable
      $seriesLabel    string  「自社サイト」/「競合サイト」
      $seriesColor    string  系列色(自社#3A3FC0/競合#E95446)
      $radarPng       ?string 単独レーダー図のPNG生バイナリ
      $totalMatched   int     ○の合計(3・4・5ページで同じソース)
      $totalMax       int     分母
      $leggendaLogoImageBase64  string
      $groupBands     array   本体テンプレート冒頭で定義済み
--}}
<div class="page">
    <h2>{{ $pageTitle }}</h2>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">

    @if (! $readable)
        {{-- 6項目すべて0件の図・表は「魅力のない会社」の意味になるため出さない。
             理由の文言はconfig('brand_wheel.status_messages')が唯一の定義元。 --}}
        <p>{{ $wheel['status_message'] ?? '' }}</p>
    @else
        <p class="lead1">本レポートでは、サイト上から確認できた情報をもとに、候補者に伝わる情報や印象を分析しています。<br>解析したURL：{{ $wheel['analyzed_url'] }}</p>

        {{--
            2026-08-10: 「件数ボックス＋サマリー」と「レーダー」を縦に2段
            (統計行→サマリー行)で並べていたところ、0件の軸が多いサイト
            (実測: 味の素)でサマリー箇条書きが長くなり、紺帯が190mm上限の
            残り0.2〜0.6mmしか余白が無い状態になった(ユーザー指摘: 「たまたま
            入っただけ」で構造的な保証が無い)。件数ボックス+サマリーを左列、
            レーダーを右列に**横並び**へ変更 ―― サマリーがレーダーと同じ行の
            横に収まる限り、サマリーの行数がページ全体の高さに追加コストを
            発生させなくなる(行の高さは両列のうち高い方で決まるため)。
            これによりレーダーの拡大にも同時に余白を回せる。
        --}}
        <table class="statrow" style="width: 265mm; table-layout: fixed;"><tr>
            <td style="width: 133mm; vertical-align: top;">
                <div class="statbox">
                    <p class="lab"><span class="swatch" style="background: {{ $seriesColor }};"></span>{{ $seriesLabel }}　確認できた情報</p>
                    <p class="num">{{ $totalMatched }}<small> / {{ $totalMax }}項目</small></p>
                    <p class="statnote">ブランドホイール24項目のうち、サイト上で情報を確認できた項目数</p>
                </div>
                <p class="sumhead" style="margin-top: 2mm;">サマリー</p>
                <ul class="sum">
                    @foreach ($summaryPoints as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
            </td>
            <td style="width: 8mm;"></td>
            <td style="width: 124mm; text-align: center; vertical-align: top;">
                @if ($radarPng)
                    {{-- レーダー図のviewBoxは380x276(縦横比380:276)。dompdfは
                         widthのみ指定だと縦横比を正しく保持しないことがあるため、
                         heightも明示して指定どおりの比率で描画させる。
                         2026-08-10: 上記の横並び化で確保した余白を使い、
                         62x45mm→72x52.3mm(縦横比380:276を維持)にさらに拡大した
                         (ユーザー指摘: 軸ラベルの可読性)。92x66.8mmまで試したが、
                         味の素(0件の軸が多くサマリーが長い)で190mm上限の残り
                         わずかしか余白が無くなったため、実PDF確認で全サイト
                         190mm上限に対し10mm以上の余白を確保できる72mmまでに
                         留めた。 --}}
                    <img src="data:image/png;base64,{{ base64_encode($radarPng) }}" style="width: 72mm; height: 52.3mm;">
                    <div class="legend">
                        <span class="sw" style="background: {{ $seriesColor }};"></span>{{ $seriesLabel }}
                    </div>
                @endif
            </td>
        </tr></table>

        <table class="bandrow" style="width: 265mm; margin-top: 2mm;"><tr>
            @foreach ($groupBands as $band)
                <td colspan="2" style="background: {{ $band['color'] }};">{{ $band['label'] }}</td>
            @endforeach
        </tr></table>

        <table style="width: 265mm; margin-top: 1mm;"><tr>
            @foreach ($wheel['axes'] as $axis)
                <td class="axcell">
                    <div class="axhead">{{ $axis['name'] }}</div>
                    <div class="axbody">
                        <p class="axcnt">{{ $axis['matched_count'] }}<small> / {{ $axis['max_count'] }}件</small></p>
                        {{--
                            この四角は「壊れて出ていない」のか「調べた結果0件」
                            なのかを区別するための表示。matched_count===0でも
                            省略しない(docs/lead-report-layout/README.md)。
                            四角の数はmax_countから生成する(固定値で書かない)。
                        --}}
                        <p class="dots">
                            @for ($i = 1; $i <= $axis['max_count']; $i++)
                                <span class="dot {{ $i <= $axis['matched_count'] ? 'on' : '' }}" @if ($i <= $axis['matched_count']) style="background: {{ $seriesColor }};" @endif></span>
                            @endfor
                        </p>
                        @if (count($axis['matched_sub_elements']) > 0)
                            <ul class="hits2">
                                @foreach ($axis['matched_sub_elements'] as $sub)
                                    <li>{{ $sub['name'] }}</li>
                                @endforeach
                            </ul>
                        @else
                            {{-- 「読み取れた内容はありません」は使わない
                                 (内容が無い会社、と読めるため)。 --}}
                            <p class="none2">該当する記述は見つかりませんでした</p>
                        @endif
                    </div>
                </td>
            @endforeach
        </tr></table>

        @if ($wheel['key_message'] || $wheel['positive_impression'] || $wheel['negative_impression'])
            <div class="darkband">
                {{-- 2026-08-17: 「収集した情報から」→「サイト上の情報から」に
                     変更(依頼者指定 ―― サイト上の情報からの推定であることを
                     より明確にする)。 --}}
                @if ($wheel['key_message'])
                    <p><b>サイト上の情報から想定されるキーメッセージ：</b>{{ $wheel['key_message'] }}</p>
                @endif
                {{--
                    2026-08-17: 「AI解析による候補者に与える印象」(短いフレーズの
                    箇条書き)から、ポジティブ/ネガティブの2文構成へ変更
                    (依頼者指定 ―― 単なる印象の列挙ではなく、良い点・気になる点を
                    分けて示す)。見出しからも「AI解析による」を外す(AI利用を
                    前面に出さない、依頼者指定)。
                --}}
                @if ($wheel['positive_impression'] || $wheel['negative_impression'])
                    <p style="margin-top: 0.5mm; margin-bottom: 0;"><b>候補者に与える印象：</b></p>
                    @if ($wheel['positive_impression'])
                        <p class="impgood">・{{ $wheel['positive_impression'] }}</p>
                    @endif
                    @if ($wheel['negative_impression'])
                        <p class="impbad">・{{ $wheel['negative_impression'] }}</p>
                    @endif
                @endif
                {{--
                    2026-08-08: 開示文をdarkbandの外(別の<p class="foot">)に
                    置いていたところ、この1行だけが実データ(自社側で本文が
                    2行に折り返すケース)で単独であふれて次ページへ孤立する
                    不具合が実PDF確認で見つかった。darkband自体は収まって
                    いるのに、直後のわずか1行のためだけに新しい物理ページが
                    生成されるのを避けるため、開示文をdarkbandの内側(同じ
                    原子的ブロック)に移し、白文字ではなく控えめな配色にする。
                --}}
                <p class="darkfoot">キーメッセージと印象の読み取りにはAIを使用しています。</p>
            </div>
        @endif
    @endif
</div>
