<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ブランド・ホイール診断結果</title>
</head>
<body style="margin:0; padding:0; background-color:#fcfcfb; font-family: 'Hiragino Kaku Gothic ProN', 'Yu Gothic', sans-serif; color:#0b0b0b;">
<div style="max-width:600px; margin:0 auto; padding:16px;">

  <p>{{ $companyName }}様(ご担当: {{ $contactName }})より相談のお申し込みがありました。</p>
  <p>対象サイト: <a href="{{ $targetUrl }}">{{ $targetUrl }}</a></p>

  {{-- 画像が読み込まれない環境でも、以下のHTMLの表だけで内容が完全に伝わる
       ように作る(画像は補助情報であり、情報の担い手にしない)。 --}}

@if($insufficientInput)

  <h2>ブランド・ホイール評価: 評価不可</h2>
  <p>
    採用ページ・トップページから十分な文章量を読み取れなかったため、
    ブランド・ホイール(6軸)の評価は実施していません。相談のお申し込み自体は
    正常に受け付けています。
  </p>

@else

  <h2>ブランド・ホイール評価(6軸)</h2>
  <p>{{ $axisStateSummaryText }}</p>

@if(isset($pngBytes) && $pngBytes !== null)
  <img src="{{ $message->embedData($pngBytes, 'brand-wheel-hexagon.png', 'image/png') }}"
       width="380"
       alt="{{ $altText }}"
       style="display:block; width:100%; max-width:380px; height:auto; margin:16px 0;">
@endif

  <table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse; margin:12px 0;">
    <thead>
      <tr style="background-color:#e1e0d9; text-align:left;">
        <th style="border:1px solid #e1e0d9;">軸</th>
        <th style="border:1px solid #e1e0d9;">判定</th>
        <th style="border:1px solid #e1e0d9;">サイトからの抜粋</th>
      </tr>
    </thead>
    <tbody>
@foreach($axes as $axis)
      <tr>
        <td style="border:1px solid #e1e0d9; vertical-align:top;">{{ $axis['nameJa'] }}</td>
        <td style="border:1px solid #e1e0d9; vertical-align:top;">{{ $axis['stateLabel'] }}</td>
        <td style="border:1px solid #e1e0d9; vertical-align:top;">
@if(count($axis['matchedSubElements']) > 0)
          <ul style="margin:0; padding-left:18px;">
@foreach($axis['matchedSubElements'] as $match)
            <li><strong>{{ $match['label'] }}</strong>: 「{{ $match['evidence'] }}」</li>
@endforeach
          </ul>
@else
          (該当する記述は確認できませんでした)
@endif
        </td>
      </tr>
@endforeach
    </tbody>
  </table>

  <h3>Core Value</h3>
  <p>
    {{ $coreValue['label'] }}
@if($coreValue['evidence'] !== null)
    ―― 「{{ $coreValue['evidence'] }}」
@endif
  </p>

@if(count($qualityDimensionNotes) > 0)
  <h3>質の観点</h3>
  <ul>
@foreach($qualityDimensionNotes as $note)
    <li><strong>{{ $note['nameJa'] }}</strong>: {{ $note['note'] }}</li>
@endforeach
  </ul>
@endif

@if(count($cautions) > 0)
  <h3>留意事項</h3>
  <ul>
@foreach($cautions as $caution)
    <li>{{ $caution }}</li>
@endforeach
  </ul>
@endif

  <p style="font-size:12px; color:#52514e; border-top:1px solid #e1e0d9; padding-top:12px; margin-top:16px;">
    {{ $disclaimer }}
  </p>

@endif

  <h3 style="font-size:13px; color:#52514e;">サイト取得状況(参考)</h3>
  <ul style="font-size:13px; color:#52514e;">
    <li>{{ $sourcePages['recruit_page']['nameJa'] }}: {{ $sourcePages['recruit_page']['label'] }}</li>
    <li>{{ $sourcePages['home_page']['nameJa'] }}: {{ $sourcePages['home_page']['label'] }}</li>
  </ul>

@if($leadEmailBlockedReason !== null)
  <p style="font-size:13px; color:#52514e; background-color:#e1e0d9; padding:8px;">
    リード企業向けメールは送信していません(理由: {{ $leadEmailBlockedReason }})。
    必要に応じて個別にご連絡ください。
  </p>
@else
  <p style="font-size:13px; color:#52514e;">
    リード企業向けメールも送信対象です(別途送信されます)。
  </p>
@endif

</div>
</body>
</html>
