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
        {{--
            2026-08-21: 左列(件数ボックス＋サマリー)と右列(レーダー)の高さが
            内容量(サマリーの行数・レーダー画像の有無)によって変わり、自社/
            競合ページを重ねて比較すると6カテゴリ帯以下がページごとに数mm
            ずれて見える不具合が実PDF確認で見つかった(依頼者指摘 ――
            「同じテンプレートに別データを流し込んだように見える」状態にする
            ため)。dompdfはCSS gridのalign-items:stretch相当を持たないため、
            両列の内側に同じmin-heightのdivを入れ、内容量に関わらず行の高さを
            常に一定に固定する。サマリー行数の実質的な最大値は
            BrandWheelComparisonSummaryComposer::pointsForReport()の構成上
            4行(最充足軸1行＋0件軸まとめ1行＋グループ差最大2行 ―― 3グループ
            構成では自グループ自身は「差が大きい」側になり得ないため、
            sparse_groupは残り2グループが上限)。実PDF確認で、この4行構成でも
            190mm上限に対し7mm以上の余白を保てる68mmを両列共通の下限とした。
            2026-08-24: 上記68mmは「サマリー最大4行」のケースを基準にしていたが、
            実データの大半はサマリー1〜2行で収まり、68mm固定だと本文終了後に
            大きな空白が残る不具合が依頼者指摘で見つかった(「サマリー下の
            大きな空白」)。pointsForReport()が実際に返しうる最大構成
            (最充足軸の根拠1行+0件軸まとめ1行+sparse_group最大2行、133mm幅の
            列で折り返すため合計6〜7行相当になる)を実PDF実測したところ
            自社/競合とも65.4mm前後を要したため、66mmまでの縮小に留めた
            (52〜58mmまでは縮められない ―― それ以下だと自社が最大構成・
            競合が短いサマリーのような非対称ケースで、下の6カテゴリ表・
            青ボックスの開始Y座標が自社/競合でずれてしまう)。
        --}}
        <table class="statrow" style="width: 265mm; table-layout: fixed;"><tr>
            <td style="width: 133mm; vertical-align: top;">
                <div style="min-height: 66mm;">
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
                </div>
            </td>
            <td style="width: 8mm;"></td>
            <td style="width: 124mm; text-align: center; vertical-align: middle;">
                {{--
                    2026-08-24: レーダー列だけvertical-align:middleにする
                    (依頼者指摘 ―― レーダーが列の上側に寄って見える)。この列の
                    内側divにはmin-heightを持たせない(自然な高さのまま)。
                    左列側のdiv(min-height:66mm)が行全体の高さを決めるため、
                    このtdのvertical-align:middleが「66mmの行の中で、
                    レーダー画像+凡例(自然な高さ約56mm)を上下中央に配置する」
                    という効果になる ―― 左列はサマリー本文から始まるため
                    従来通りtopのまま。
                --}}
                <div>
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
                </div>
            </td>
        </tr></table>

        {{--
            2026-08-22: 大分類帯(3本、colspan=2)と6カテゴリカードを1つの
            tableへ統合した(依頼者指定 ―― 別tableだと縦線が理論上一致して
            いてもdompdfの列幅計算がtableごとに独立するため、丸め方次第で
            サブpx単位のズレが起こりうる。同じ<colgroup>を共有させることで
            構造的にズレを無くす)。列幅は<colgroup>の6本の<col>で一元管理
            (44.16mm×6=264.96mm、2026-08-04の実測値を踏襲)。
        --}}
        <table class="brandwheeltbl">
            <colgroup>
                @for ($col = 0; $col < 6; $col++)
                    <col style="width: 44.16mm;">
                @endfor
            </colgroup>
            <tr class="bandrow">
                @foreach ($groupBands as $band)
                    <td colspan="2" style="background: {{ $band['color'] }};">{{ $band['label'] }}</td>
                @endforeach
            </tr>
            <tr>
                @foreach ($wheel['axes'] as $axis)
                    <td class="axcell">
                        <div class="axhead">{{ $axis['name'] }}</div>
                        <div class="axbody">
                            <div class="axscore">
                                <p class="axcnt">{{ $axis['matched_count'] }}<small> / {{ $axis['max_count'] }}件</small></p>
                            </div>
                            {{--
                                この四角は「壊れて出ていない」のか「調べた結果0件」
                                なのかを区別するための表示。matched_count===0でも
                                省略しない(docs/lead-report-layout/README.md)。
                                四角の数はmax_countから生成する(固定値で書かない)。
                            --}}
                            <div class="axind">
                                <p class="dots">
                                    @for ($dot = 1; $dot <= $axis['max_count']; $dot++)
                                        <span class="dot {{ $dot <= $axis['matched_count'] ? 'on' : '' }}" @if ($dot <= $axis['matched_count']) style="background: {{ $seriesColor }};" @endif></span>
                                    @endfor
                                </p>
                            </div>
                            <div class="axcontent">
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
                        </div>
                    </td>
                @endforeach
            </tr>
        </table>

        @if ($wheel['key_message'] || $wheel['positive_impression'] || $wheel['negative_impression'])
            <div class="darkband">
                {{--
                    2026-08-22: 内部を3領域(.msgkey/.msgpos/.msgneg)に分け、
                    それぞれmin-heightを持たせた(依頼者指定 ―― 自社/競合で
                    文章量が違っても各項目の開始位置が一致するようにする)。
                    3項目とも任意表示(該当データが無ければブロックごと
                    非表示)のため、heightでの完全固定ではなくmin-heightに
                    している ―― 例えば自社にはネガティブな印象が無く競合には
                    ある場合、無理に自社側へ空白ブロックを作らない。
                --}}
                {{-- 2026-08-17: 「収集した情報から」→「サイト上の情報から」に
                     変更(依頼者指定 ―― サイト上の情報からの推定であることを
                     より明確にする)。 --}}
                @if ($wheel['key_message'])
                    <div class="msgkey">
                        <p><b>サイト上の情報から想定されるキーメッセージ：</b>{{ $wheel['key_message'] }}</p>
                    </div>
                @endif
                {{--
                    2026-08-18: 「候補者に与える印象」という単一見出し配下に
                    ポジ/ネガを箇条書きで並べていたところ、依頼者指定により
                    「ポジティブな印象」「ネガティブな印象」を別見出しとして
                    明確に分離した(読み手が一瞬で区別できるようにするため)。
                --}}
                @if ($wheel['positive_impression'])
                    <div class="msgpos">
                        <p style="margin-top: 1.2mm; margin-bottom: 0;"><b>ポジティブな印象：</b></p>
                        <p class="impgood">{{ $wheel['positive_impression'] }}</p>
                    </div>
                @endif
                @if ($wheel['negative_impression'])
                    <div class="msgneg">
                        <p style="margin-top: 1.2mm; margin-bottom: 0;"><b>ネガティブな印象：</b></p>
                        <p class="impbad">{{ $wheel['negative_impression'] }}</p>
                    </div>
                @endif
            </div>
        @endif
    @endif
</div>
