<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>採用サイト診断が完了しました</title>
</head>
<body style="margin:0; padding:0; background-color:#fcfcfb; font-family: 'Hiragino Kaku Gothic ProN', 'Yu Gothic', sans-serif; color:#0b0b0b;">
<div style="max-width:600px; margin:0 auto; padding:16px;">

  <p>この度は採用サイト診断をご利用いただき、誠にありがとうございます。診断が完了しましたので、内容をご案内します。</p>
  <p>
    貴社の採用に関する情報発信について、サイト({{ $targetUrl }})の記述から
    確認できた内容を簡単にご案内します。
  </p>

  <h2>サイトから確認できた内容</h2>
  <p>{{ $axisStateSummaryText }}</p>

  <table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse; margin:12px 0;">
    <thead>
      <tr style="background-color:#e1e0d9; text-align:left;">
        <th style="border:1px solid #e1e0d9;">項目</th>
        <th style="border:1px solid #e1e0d9;">確認状況</th>
        <th style="border:1px solid #e1e0d9;">サイトからの抜粋</th>
      </tr>
    </thead>
    <tbody>
@foreach($axes as $axis)
      <tr>
        <td style="border:1px solid #e1e0d9; vertical-align:top;">{{ $axis['nameJa'] }}</td>
        <td style="border:1px solid #e1e0d9; vertical-align:top;">{{ $axis['stateLabel'] }}</td>
        <td style="border:1px solid #e1e0d9; vertical-align:top;">
@if($axis['evidence'] !== null)
          「{{ $axis['evidence'] }}」
@else
          ―
@endif
        </td>
      </tr>
@endforeach
    </tbody>
  </table>

  <p style="font-size:13px; color:#52514e;">{{ $sourceDescription }}</p>

  <h3>本内容についての大切なお断り</h3>
  <ul style="font-size:13px; color:#52514e;">
    <li>本内容はサイト上の記述から読み取れた範囲を示すものであり、貴社の実態そのものを評価したものではありません。</li>
    <li>採用ブランディングは本来、グループインタビュー・口コミ・内定者や辞退者へのインタビュー・説明会・SNS等、サイト以外の情報も併せて構築するものです。今回はサイトの記述のみを拝見しております。</li>
    <li>本内容はAIが生成したものです。</li>
  </ul>

@if($hasPdfAttachment)
  <p>
    本メールには、今回の診断結果をまとめたレポート(PDF)を添付しております。あわせてご確認ください。
  </p>
@endif
  <p>
    今後の採用ブランディングについてより詳しくお話しできる機会がございましたら、
    本メールにご返信ください。
  </p>

</div>
</body>
</html>
