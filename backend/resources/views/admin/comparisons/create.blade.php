@extends('admin.layout')

@section('title', '比較の作成 - 管理者ダッシュボード')

{{--
    依頼BI-1(2026-09-08): 3ステップの起票フォームに作り直す
    (Claude outputs/compare_form_mockup.html のレイアウトをそのまま実装、
    依頼者承認済み)。ここで使うクラス(.step/.req/.opt/.filerow/.cond/.callout等)は
    admin/layout.blade.php には無い新規クラスだが、このビュー内の<style>に
    閉じており、layout.blade.php 自体には手を加えていない ―― 他画面へ
    影響する余地はない。配色は既存の管理画面テーマ(layout.blade.php の
    :root変数、--brand/--border/--muted/--bg/--warn)にそのまま合わせている。
--}}

@section('content')
<div class="compare-wizard">
    <style>
        .compare-wizard { --accent: #1D5FA8; --warn-bg: #FFF8EC; --warn-line: #F0D9A0; }
        .compare-wizard .back { font-size: 13px; margin-bottom: 10px; }
        .compare-wizard h2 { margin-bottom: 8px; }
        .compare-wizard .lede { color: var(--text); font-size: 13.5px; margin-bottom: 6px; }
        .compare-wizard .outputs { list-style: none; margin: 4px 0 22px; padding: 0; font-size: 13.5px; }
        .compare-wizard .outputs li { padding-left: 18px; position: relative; }
        .compare-wizard .outputs li::before { content: "・"; position: absolute; left: 2px; color: var(--accent); font-weight: 700; }
        .compare-wizard .outputs li span { color: var(--muted); }

        .compare-wizard .step { background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 0 0 20px; margin-bottom: 18px; }
        .compare-wizard .step-head { border-bottom: 1px solid var(--border); padding: 11px 20px; display: flex; align-items: baseline; gap: 10px; background: var(--bg); border-radius: 8px 8px 0 0; }
        .compare-wizard .step-num { font-size: 11px; letter-spacing: .09em; color: #fff; background: var(--brand); padding: 3px 8px; border-radius: 3px; font-weight: 700; }
        .compare-wizard .step-title { font-size: 14.5px; font-weight: 700; }
        .compare-wizard .step-note { font-size: 12px; color: var(--muted); margin-left: auto; }
        .compare-wizard .step-body { padding: 18px 20px 0; }

        .compare-wizard .field { margin-bottom: 18px; }
        .compare-wizard .label { font-size: 13px; font-weight: 700; margin-bottom: 5px; }
        .compare-wizard .help { font-size: 12px; color: var(--muted); margin: 4px 0 0; }
        .compare-wizard input[type=text] { width: 100%; padding: 7px 9px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; font-family: inherit; box-sizing: border-box; background: #fff; }

        .compare-wizard table.rows { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .compare-wizard table.rows td { padding: 0 0 8px; vertical-align: middle; }
        .compare-wizard td.tag { width: 92px; padding-right: 10px; white-space: nowrap; }
        .compare-wizard .req, .compare-wizard .opt { font-size: 11px; padding: 2px 6px; border-radius: 3px; font-weight: 700; margin-right: 6px; }
        .compare-wizard .req { background: #E4EEFB; color: var(--accent); }
        .compare-wizard .opt { background: #EEF0F2; color: var(--muted); }
        .compare-wizard td.tag em { font-style: normal; font-size: 12.5px; }
        .compare-wizard td.url { padding-right: 8px; }
        .compare-wizard td.name { width: 230px; }

        .compare-wizard .filerow { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
        .compare-wizard .filename { font-size: 13px; }
        .compare-wizard .filename b { font-weight: 700; }
        .compare-wizard .clear { font-size: 12px; }
        .compare-wizard .refile-note { font-size: 12.5px; color: var(--danger); background: #FDEEEC; border: 1px solid #F3C6C0; border-radius: 6px; padding: 8px 12px; margin-bottom: 12px; }

        .compare-wizard .cond { background: var(--warn-bg); border: 1px solid var(--warn-line); border-radius: 6px; padding: 11px 15px; margin-top: 12px; }
        .compare-wizard .cond-h { font-size: 12.5px; font-weight: 700; margin-bottom: 5px; }
        .compare-wizard .cond ul { margin: 0; padding-left: 18px; font-size: 12.5px; }
        .compare-wizard .cond li { margin-bottom: 2px; }
        .compare-wizard .cond .after { font-size: 12px; color: #8A6D2F; margin: 7px 0 0; }

        .compare-wizard .runline { font-size: 13px; margin-bottom: 4px; }
        .compare-wizard .callout { margin-top: 26px; border-left: 3px solid var(--accent); background: #EEF4FA; padding: 12px 16px; border-radius: 0 6px 6px 0; font-size: 12.5px; color: var(--text); }
        .compare-wizard .callout b { color: var(--brand); }
    </style>

    <p class="back"><a href="{{ route('admin.analyses.show', $analysis->id, false) }}">&larr; 診断詳細 #{{ $analysis->id }}へ戻る</a></p>

    <h2>{{ $analysis->project?->leadCompany?->company_name }} の比較を作成</h2>
    <p class="lede">
        無料診断 #{{ $analysis->id }} を起点に、自社と競合{{ $minCompetitors }}〜{{ $maxCompetitors }}社の採用サイトを、同じ項目で比較します。完了すると次の2つがダウンロードできます。
    </p>
    <ul class="outputs">
        <li>比較レポート(PDF)</li>
        <li>営業資料に比較ページを差し込んだ PowerPoint <span>― STEP 2 で資料を添付した場合のみ</span></li>
    </ul>

    <form method="POST" action="{{ route('admin.analyses.compare.store', $analysis->id, false) }}" enctype="multipart/form-data">
        @csrf

        {{-- STEP 1: 比較する会社 --}}
        <div class="step">
            <div class="step-head">
                <span class="step-num">STEP 1</span>
                <span class="step-title">比較する会社</span>
            </div>
            <div class="step-body">
                <div class="field">
                    <div class="label">自社サイトURL</div>
                    <input type="text" name="self_url" value="{{ old('self_url', $selfUrl) }}">
                    <p class="help">診断 #{{ $analysis->id }} で使ったURLです。サイトが変わっていれば書き換えてください。</p>
                </div>

                <div class="label">競合サイト({{ $minCompetitors }}〜{{ $maxCompetitors }}社)</div>
                <p class="help">企業名は、比較レポートの表と、営業資料に差し込む比較ページの見出しに使います。空欄のときはURLのドメインから自動で作ります。</p>

                <table class="rows">
                    @for ($i = 0; $i < $maxCompetitors; $i++)
                        <tr>
                            <td class="tag">
                                @if ($i < $minCompetitors)
                                    <span class="req">必須</span>
                                @else
                                    <span class="opt">任意</span>
                                @endif
                                <em>競合{{ $i + 1 }}</em>
                            </td>
                            <td class="url">
                                <input
                                    type="text"
                                    name="competitor_urls[]"
                                    value="{{ old('competitor_urls.'.$i, $i === 0 ? $existingCompetitorUrl : null) }}"
                                    placeholder="https://…"
                                >
                            </td>
                            <td class="name">
                                <input
                                    type="text"
                                    name="competitor_names[]"
                                    value="{{ old('competitor_names.'.$i) }}"
                                    placeholder="企業名"
                                >
                            </td>
                        </tr>
                    @endfor
                </table>
            </div>
        </div>

        {{-- STEP 2: 営業資料に差し込む --}}
        <div class="step">
            <div class="step-head">
                <span class="step-num">STEP 2</span>
                <span class="step-title">営業資料に差し込む</span>
                <span class="step-note">任意 ― 添付しなくても比較は実行できます</span>
            </div>
            <div class="step-body">
                @if ($errors->has('sales_deck'))
                    <p class="refile-note">
                        営業資料でエラーが発生したため、選択したファイルは保持されていません。<b>お手数ですが、もう一度ファイルを選び直してください。</b>
                    </p>
                @endif

                <div class="filerow">
                    <input type="file" name="sales_deck" id="sales_deck-input" accept=".pptx" style="display:none;" onchange="compareWizardOnFileChange(this)">
                    <label for="sales_deck-input" class="btn secondary" style="cursor:pointer;">ファイルを選択</label>
                    <span class="filename" id="sales_deck-filename" style="display:none;"></span>
                    <a class="clear" href="#" id="sales_deck-clear" style="display:none;" onclick="compareWizardClearFile();return false;">取り消す</a>
                </div>

                <p class="help" style="font-size:12.5px;color:var(--text);">
                    資料を添付すると、比較ページを1枚だけ、資料の「{{ implode('」または「', $salesDeckReferenceKeywords) }}」ページの直前に差し込んだ PowerPoint を、完了後にダウンロードできます。
                    <b>元の資料は書き換えません。</b>グラフ・画像・ノートもそのまま残ります。
                </p>

                <div class="cond">
                    <div class="cond-h">添付できる資料の条件</div>
                    <ul>
                        <li>PowerPoint(.pptx)／{{ $salesDeckMaxSizeMb }}MB まで</li>
                        <li>スライドが 16:9({{ $salesDeckSlideSizeLabel }})であること</li>
                        <li>「{{ implode('」または「', $salesDeckReferenceKeywords) }}」を含むページがあること</li>
                    </ul>
                    <p class="after">条件を満たさない資料は、<b>この画面で送信した時点でお知らせします。</b>比較を実行してから気づくことはありません。添付しない場合は、比較レポート(PDF)のみが結果になります。</p>
                </div>
            </div>
        </div>

        {{-- STEP 3: 実行 --}}
        <div class="step">
            <div class="step-head">
                <span class="step-num">STEP 3</span>
                <span class="step-title">実行</span>
            </div>
            <div class="step-body">
                <p class="runline">開始すると、新しく作られた比較の詳細画面へ移ります。進み具合はその画面で確認できます。</p>
                <p class="runline" style="color:var(--muted);">所要時間の目安：競合の数だけ時間がかかります。進み具合は次の画面で確認できます。</p>
                <p style="margin-top:14px;"><button type="submit" class="btn">比較を開始する</button></p>
            </div>
        </div>
    </form>

    <div class="callout">
        <b>この画面で添付した資料は、いま作る「比較」に紐づきます。</b>
        あとから差し替えたいときは、比較の詳細画面にある添付欄から入れ替えてください。差し込み版は押した時点で作るので、資料を差し替えたらもう一度ダウンロードするだけで最新になります。
    </div>
</div>

<script>
    function compareWizardOnFileChange(input) {
        var nameEl = document.getElementById('sales_deck-filename');
        var clearEl = document.getElementById('sales_deck-clear');
        if (input.files && input.files.length > 0) {
            var file = input.files[0];
            var kb = Math.round(file.size / 1024);
            nameEl.innerHTML = '<b>' + file.name.replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }) + '</b>（' + kb + ' KB）';
            nameEl.style.display = '';
            clearEl.style.display = '';
        } else {
            compareWizardClearFile();
        }
    }
    function compareWizardClearFile() {
        var input = document.getElementById('sales_deck-input');
        input.value = '';
        document.getElementById('sales_deck-filename').style.display = 'none';
        document.getElementById('sales_deck-clear').style.display = 'none';
    }
</script>
@endsection
