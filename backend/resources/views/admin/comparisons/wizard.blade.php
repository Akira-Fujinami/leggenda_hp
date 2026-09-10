@extends('admin.layout')

@section('title', '比較レポートを作る - 管理者ダッシュボード')

{{--
    依頼BP-1(2026-09-10): チャット風の比較ウィザード(Claude outputs/
    comparison_wizard_mockup.html のレイアウトをそのまま実装、依頼者承認済み)。

    AIは使わない ―― 決まった順番の質問(STEP1〜5)を1問ずつ出すだけで、
    自由文の解釈はしない(依頼者最重要指定)。JavaScriptのフレームワークは
    持ち込まず素のJSのみ(既存の管理画面がonclickを使っているのに合わせる)。
    ビルド工程も追加しない。

    送信の最終経路は既存のPOST /admin/analyses/{analysis}/compare
    (ComparisonController::store())のまま ―― この<form>はSTEP2で選んだ
    診断のIDをJSでactionに組み込んで、それをそのままPOSTするだけであり、
    比較を作る処理をこのビュー・このコントローラで別に持たない。STEP3
    (競合URL・企業名)の欄は、依頼BIで作ったcomparisons/create.blade.php
    と同じ構造(必須/任意ラベル・企業名必須の説明)にしている ―― ただし
    このウィザードはページ遷移せずJSだけでステップを進めるため、
    create.blade.phpのファイル自体は変更せず、同じ構造をこのファイル内に
    複製している(部分ビューへの分割は承認外のファイル追加になるため行わない)。

    途中状態はDB・セッションのどこにも保存しない。バリデーション失敗で
    このページへ戻ってきたとき(old())だけ、STEP1の検索文字列とSTEP2で
    選んだ診断IDをhidden inputで復元する ―― これは通常のold()と同じ
    1往復だけのセッションフラッシュであり、「中断された下書きが溜まる」
    仕組みとは別物(禁止されているのは後者)。
--}}

@section('content')
<div class="wizard">
    <style>
        .wizard { --accent: #1D5FA8; --warn-bg: #FFF8EC; --warn-line: #F0D9A0; }
        .wizard .lede { color: var(--text); font-size: 13.5px; margin: 0 0 18px; max-width: 640px; }
        .wizard .fallback { font-size: 12.5px; color: var(--muted); background: #fff; border: 1px solid var(--border); border-radius: 6px; padding: 10px 14px; margin-bottom: 20px; }

        .wizard .steps { display: flex; gap: 6px; margin-bottom: 22px; font-size: 11.5px; color: var(--muted); flex-wrap: wrap; }
        .wizard .steps i { font-style: normal; padding: 4px 11px; border-radius: 11px; background: #EEF0F2; }
        .wizard .steps i.done { background: var(--brand); color: #fff; }
        .wizard .steps i.now { background: #fff; color: var(--brand); border: 1.5px solid var(--brand); font-weight: 700; }

        .wizard .row { display: flex; gap: 10px; margin-bottom: 12px; align-items: flex-start; }
        .wizard .row.me { flex-direction: row-reverse; }
        .wizard .av { width: 26px; height: 26px; border-radius: 50%; flex: none; background: var(--brand); color: #fff; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; margin-top: 2px; }
        .wizard .row.me .av { background: #8A93A5; }
        .wizard .bub { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 10px 14px; font-size: 13.5px; max-width: 100%; }
        .wizard .row.me .bub { background: #EEF1FB; border-color: #CFD6EE; }
        .wizard .bub .q { font-weight: 700; }
        .wizard .bub .hint { font-size: 12px; color: var(--muted); margin-top: 3px; }
        .wizard .edit { font-size: 12px; color: var(--brand); margin-left: 10px; text-decoration: underline; cursor: pointer; }

        .wizard .qa { margin-bottom: 6px; }
        .wizard .card { background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 16px 18px; margin: 0 0 22px 36px; }
        .wizard .card.active { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(29, 32, 136, .08); }

        .wizard input[type=text] { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 13.5px; font-family: inherit; background: #fff; box-sizing: border-box; }
        .wizard .err { font-size: 12.5px; color: var(--danger); margin: 8px 0 0; }

        .wizard table.rows { width: 100%; border-collapse: collapse; }
        .wizard table.rows td { padding: 0 0 8px; vertical-align: middle; }
        .wizard td.tag { width: 92px; padding-right: 10px; white-space: nowrap; }
        .wizard .req, .wizard .opt { font-size: 11px; padding: 2px 6px; border-radius: 3px; font-weight: 700; margin-right: 6px; }
        .wizard .req { background: #E4EEFB; color: var(--accent); }
        .wizard .opt { background: #EEF0F2; color: var(--muted); }
        .wizard td.tag em { font-style: normal; font-size: 12.5px; }
        .wizard td.url { padding-right: 8px; }
        .wizard td.name { width: 230px; }

        .wizard .pick { border: 1px solid var(--border); border-radius: 7px; padding: 10px 13px; margin-bottom: 8px; display: flex; gap: 11px; align-items: baseline; cursor: pointer; background: #fff; }
        .wizard .pick:hover { border-color: var(--brand); }
        .wizard .pick .id { font-size: 12px; color: var(--brand); font-weight: 700; flex: none; }
        .wizard .pick b { font-size: 13.5px; }
        .wizard .pick span { font-size: 12.5px; color: var(--muted); }
        .wizard .empty-hint { font-size: 13px; color: var(--muted); }
        .wizard .empty-hint a { text-decoration: underline; }
        .wizard .truncated-hint { font-size: 12.5px; color: var(--warn); background: var(--warn-bg); border: 1px solid var(--warn-line); border-radius: 6px; padding: 8px 12px; margin-bottom: 10px; }

        .wizard .filerow { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 10px; }
        .wizard .filename { font-size: 13px; }
        .wizard .clear, .wizard .skip { font-size: 12.5px; color: var(--brand); text-decoration: underline; cursor: pointer; }
        .wizard .refile-note { font-size: 12.5px; color: var(--danger); background: #FDEEEC; border: 1px solid #F3C6C0; border-radius: 6px; padding: 8px 12px; margin-bottom: 12px; }

        .wizard .cond { background: var(--warn-bg); border: 1px solid var(--warn-line); border-radius: 6px; padding: 11px 15px; margin-top: 10px; }
        .wizard .cond-h { font-size: 12.5px; font-weight: 700; margin-bottom: 5px; }
        .wizard .cond ul { margin: 0; padding-left: 18px; font-size: 12.5px; }
        .wizard .cond li { margin-bottom: 2px; }
        .wizard .cond .after { font-size: 12px; color: #8A6D2F; margin: 7px 0 0; }

        .wizard .summary dl { margin: 0; display: grid; grid-template-columns: 104px 1fr; gap: 6px 12px; font-size: 13.5px; }
        .wizard .summary dt { color: var(--muted); font-size: 12.5px; }
        .wizard .summary dd { margin: 0; }
        .wizard .time { font-size: 12.5px; color: var(--muted); margin-top: 10px; }
    </style>

    <h2>比較レポートを作る</h2>
    <p class="lede">自社と競合{{ $minCompetitors }}〜{{ $maxCompetitors }}社の採用サイトを、同じ24項目で比較します。営業資料(PowerPoint)を添付すると、比較ページを差し込んだPowerPointも作れます。</p>

    <p class="fallback">
        個別の診断からすぐに比較を作りたい場合や、この画面がうまく動かない場合は、
        <a href="{{ route('admin.analyses.index', [], false) }}">診断一覧</a>から対象の診断を開き、詳細画面の「3〜5社で比較する」からお進みください。
    </p>

    <div class="steps" id="wizard-steps">
        <i data-step="1">1 会社</i><i data-step="2">2 診断</i><i data-step="3">3 競合</i><i data-step="4">4 営業資料</i><i data-step="5">5 確認</i>
    </div>

    @php
        $hasCompetitorErrors = $errors->has('competitor_urls') || $errors->has('competitor_urls.*') || $errors->has('competitor_names.*') || $errors->has('self_url') || $errors->has('source_analysis_id');
        $hasSalesDeckErrors = $errors->has('sales_deck');

        $initialStep = 1;
        if ($selectedAnalysis) {
            $initialStep = 3;
        }
        if ($hasCompetitorErrors) {
            $initialStep = 3;
        }
        if ($hasSalesDeckErrors) {
            $initialStep = 4;
        }

        $selfWebsite = $selectedAnalysis?->project?->websites?->firstWhere('is_primary', true);
        $selfHost = $selfWebsite?->url !== null ? strtolower((string) parse_url($selfWebsite->url, PHP_URL_HOST)) : null;

        $competitorSummaryParts = [];
        for ($i = 0; $i < $maxCompetitors; $i++) {
            $u = trim((string) old('competitor_urls.'.$i, ''));
            if ($u === '') {
                continue;
            }
            $n = trim((string) old('competitor_names.'.$i, ''));
            $competitorSummaryParts[] = $n !== '' ? $n : $u;
        }
        $competitorSummaryText = $competitorSummaryParts !== [] ? implode(' ／ ', $competitorSummaryParts) : '';

        // 依頼BP-1: @json()ディレクティブの引数パーサーは複数行の配列リテラルを
        // 正しく扱えない(実機確認、Unclosed '['エラー)ため、JSへ渡すデータは
        // ここで組み立てて@json($selectedAnalysisForJs)のように1変数で渡す。
        $selectedAnalysisForJs = $selectedAnalysis ? [
            'id' => $selectedAnalysis->id,
            'company_name' => $selectedAnalysis->project?->leadCompany?->company_name,
            'self_host' => $selfHost,
            'analyzed_at' => $selectedAnalysis->created_at?->format('Y年n月j日'),
            'status' => $selectedAnalysis->status->value,
        ] : null;
    @endphp

    <form method="POST"
          action="{{ $selectedAnalysis ? route('admin.analyses.compare.store', $selectedAnalysis->id, false) : '#' }}"
          enctype="multipart/form-data" id="wizard-form">
        @csrf
        <input type="hidden" name="company_query" id="company_query_hidden" value="{{ old('company_query') }}">
        <input type="hidden" name="source_analysis_id" id="source_analysis_id_hidden" value="{{ old('source_analysis_id', $selectedAnalysis->id ?? '') }}">

        {{-- STEP 1: 会社名 --}}
        <div class="qa" id="step-1">
            <div class="row"><div class="av">L</div><div class="bub">
                <div class="q">どちらの会社の比較を作りますか？</div>
                <div class="hint">会社名の一部でかまいません。</div>
            </div></div>

            <div class="card" id="step-1-input">
                <input type="text" id="company-query-input" placeholder="例）マネーフォワード" value="{{ old('company_query') }}">
                <p class="err" id="step-1-error" hidden></p>
                <p style="margin-top:12px;"><button type="button" class="btn" id="step-1-search-btn">検索する</button></p>
            </div>
            <div class="row me" id="step-1-answer">
                <div class="av">担当</div>
                <div class="bub"><span id="step-1-answer-text">{{ old('company_query', $selectedAnalysis?->project?->leadCompany?->company_name) }}</span><a class="edit" data-goto="1">変更</a></div>
            </div>
        </div>

        {{-- STEP 2: 起点の診断を選ぶ --}}
        <div class="qa" id="step-2">
            <div class="row"><div class="av">L</div><div class="bub">
                <div class="q" id="step-2-question">見つかった診断から、どれを起点にしますか？</div>
            </div></div>

            <div class="card" id="step-2-input">
                <div id="step-2-truncated" class="truncated-hint" hidden>候補が多すぎます。会社名をもう少し絞り込んでください。</div>
                <div id="step-2-empty" class="empty-hint" hidden>
                    見つかりませんでした。会社名の一部で試すか、<a href="{{ route('admin.analyses.index', [], false) }}">診断一覧</a>から探してください。
                </div>
                <div id="step-2-list"></div>
            </div>
            <div class="row me" id="step-2-answer">
                <div class="av">担当</div>
                <div class="bub">
                    <span id="step-2-answer-text">
                        @if ($selectedAnalysis)
                            #{{ $selectedAnalysis->id }}　{{ $selectedAnalysis->project?->leadCompany?->company_name }}
                        @endif
                    </span><br>
                    <span style="font-size:12.5px;color:var(--muted);" id="step-2-answer-sub">
                        @if ($selectedAnalysis)
                            {{ $selfHost ?? '—' }} ／ {{ $selectedAnalysis->created_at?->format('Y年n月j日') }}
                        @endif
                    </span>
                    <a class="edit" data-goto="2">変更</a>
                </div>
            </div>
        </div>

        {{-- STEP 3: 競合(依頼BIのcreate.blade.phpと同じ構造) --}}
        <div class="qa" id="step-3">
            <div class="row"><div class="av">L</div><div class="bub">
                <div class="q">比較する競合を{{ $minCompetitors }}〜{{ $maxCompetitors }}社、教えてください。</div>
                <div class="hint">企業名は、比較レポートの表と、営業資料に差し込む比較ページの見出しに使います。<b>URLを入力した行は、企業名も入力してください（必須）。</b></div>
            </div></div>

            <div class="card" id="step-3-input">
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
                                <input type="text" name="competitor_urls[]" value="{{ old('competitor_urls.'.$i) }}" placeholder="https://…">
                            </td>
                            <td class="name">
                                <input type="text" name="competitor_names[]" value="{{ old('competitor_names.'.$i) }}" placeholder="企業名（URL入力時は必須）">
                            </td>
                        </tr>
                    @endfor
                </table>
                <p style="margin-top:10px;"><button type="button" class="btn" id="step-3-next-btn">次へ</button></p>
            </div>
            <div class="row me" id="step-3-answer">
                <div class="av">担当</div>
                <div class="bub"><span id="step-3-answer-text">{{ $competitorSummaryText }}</span><a class="edit" data-goto="3">変更</a></div>
            </div>
        </div>

        {{-- STEP 4: 営業資料 --}}
        <div class="qa" id="step-4">
            <div class="row"><div class="av">L</div><div class="bub">
                <div class="q">営業資料（PowerPoint）はありますか？</div>
                <div class="hint">添付すると、比較ページを資料の「{{ implode('」または「', $salesDeckReferenceKeywords) }}」ページの直前に差し込んだPowerPointを、完了後にダウンロードできます。元の資料は書き換えません。</div>
            </div></div>

            <div class="card" id="step-4-input">
                @if ($errors->has('sales_deck'))
                    <p class="refile-note">
                        営業資料でエラーが発生したため、選択したファイルは保持されていません。<b>お手数ですが、もう一度ファイルを選び直してください。</b>
                    </p>
                @endif

                <div class="filerow">
                    <input type="file" name="sales_deck" id="sales_deck-input" accept=".pptx" style="display:none;">
                    <label for="sales_deck-input" class="btn secondary" style="cursor:pointer;">ファイルを選択</label>
                    <span class="filename" id="sales_deck-filename" style="display:none;"></span>
                    <span class="clear" id="sales_deck-clear" style="display:none;">取り消す</span>
                </div>
                <p><span class="skip" id="step-4-skip-btn">営業資料なしで進む（比較レポートPDFのみ）</span></p>

                <div class="cond">
                    <div class="cond-h">添付できる資料の条件</div>
                    <ul>
                        <li>PowerPoint(.pptx)／{{ $salesDeckMaxSizeMb }}MB まで</li>
                        <li>スライドが 16:9({{ $salesDeckSlideSizeLabel }})であること</li>
                        <li>「{{ implode('」または「', $salesDeckReferenceKeywords) }}」を含むページがあること</li>
                    </ul>
                    <p class="after">条件を満たさない資料は、<b>この画面で送信した時点でお知らせします。</b>比較を実行してから気づくことはありません。</p>
                </div>

                <p style="margin-top:14px;"><button type="button" class="btn" id="step-4-next-btn">次へ</button></p>
            </div>
            <div class="row me" id="step-4-answer">
                <div class="av">担当</div>
                <div class="bub"><span id="step-4-answer-text"></span><a class="edit" data-goto="4">変更</a></div>
            </div>
        </div>

        {{-- STEP 5: 確認 --}}
        <div class="qa" id="step-5">
            <div class="row"><div class="av">L</div><div class="bub">
                <div class="q">この内容で始めます。よろしいですか？</div>
            </div></div>

            <div class="card" id="step-5-input">
                <div class="summary">
                    <dl>
                        <dt>起点の診断</dt><dd id="summary-source">—</dd>
                        <dt>自社サイト</dt><dd id="summary-self">—</dd>
                        <dt>競合</dt><dd id="summary-competitors">—</dd>
                        <dt>営業資料</dt><dd id="summary-salesdeck">なし（比較レポートPDFのみ）</dd>
                        <dt>できるもの</dt><dd>比較レポート(PDF)＋比較ページを差し込んだPowerPoint(営業資料を添付した場合のみ)</dd>
                    </dl>
                </div>
                <p style="margin-top:14px;"><button type="submit" class="btn">比較を開始する</button></p>
                <p class="time">開始すると新しく作られた比較の詳細画面へ移ります。所要時間の目安：競合の数だけ時間がかかります。進み具合はその画面で確認できます。</p>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    var STEP_COUNT = 5;
    var currentStep = {{ $initialStep }};
    var selected = @json($selectedAnalysisForJs);
    var searchUrl = @json(route('admin.comparisons.search', [], false));
    var storeUrlTemplate = @json(route('admin.analyses.compare.store', ['analysis' => '__ID__'], false));

    function el(id) { return document.getElementById(id); }

    function escapeHtml(text) {
        return String(text).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function renderSteps() {
        var pills = document.querySelectorAll('#wizard-steps i');
        for (var p = 0; p < pills.length; p++) {
            var n = parseInt(pills[p].getAttribute('data-step'), 10);
            pills[p].classList.remove('done', 'now');
            if (n < currentStep) { pills[p].classList.add('done'); }
            if (n === currentStep) { pills[p].classList.add('now'); }
        }
        for (var i = 1; i <= STEP_COUNT; i++) {
            var qa = el('step-' + i);
            if (!qa) { continue; }
            qa.style.display = (i > currentStep) ? 'none' : '';

            var inputBlock = el('step-' + i + '-input');
            var answerBlock = el('step-' + i + '-answer');
            var answered = i < currentStep;
            if (inputBlock) { inputBlock.style.display = answered ? 'none' : ''; }
            if (answerBlock) { answerBlock.style.display = answered ? '' : 'none'; }
        }
    }

    function goTo(n) {
        currentStep = n;
        renderSteps();
    }

    var editLinks = document.querySelectorAll('.edit[data-goto]');
    for (var e = 0; e < editLinks.length; e++) {
        editLinks[e].addEventListener('click', function (ev) {
            ev.preventDefault();
            goTo(parseInt(this.getAttribute('data-goto'), 10));
        });
    }

    // ------------------------------------------------------------------
    // STEP 1: 会社名で検索する。
    // ------------------------------------------------------------------
    function submitStep1() {
        var q = el('company-query-input').value.trim();
        var errEl = el('step-1-error');
        if (q === '') {
            errEl.textContent = '会社名を入力してください。';
            errEl.hidden = false;
            return;
        }
        errEl.hidden = true;
        el('company_query_hidden').value = q;
        el('step-1-answer-text').textContent = q;

        fetch(searchUrl + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
            .then(function (res) {
                if (!res.ok) { throw new Error('search failed'); }
                return res.json();
            })
            .then(function (data) {
                renderStep2(data, q);
                currentStep = 2;
                renderSteps();
            })
            .catch(function () {
                errEl.textContent = '検索に失敗しました。もう一度お試しください。';
                errEl.hidden = false;
            });
    }

    el('step-1-search-btn').addEventListener('click', submitStep1);
    el('company-query-input').addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
            ev.preventDefault();
            submitStep1();
        }
    });

    // ------------------------------------------------------------------
    // STEP 2: 候補から選ぶ。
    // ------------------------------------------------------------------
    function renderStep2(data, query) {
        var list = el('step-2-list');
        list.innerHTML = '';
        el('step-2-truncated').hidden = !data.truncated;
        el('step-2-empty').hidden = data.truncated || data.results.length !== 0;
        el('step-2-question').textContent = data.results.length > 0
            ? (data.results.length + '件の診断が見つかりました。どれを起点にしますか？')
            : '見つかりませんでした。';

        data.results.forEach(function (r) {
            var div = document.createElement('div');
            div.className = 'pick';
            div.innerHTML = '<span class="id">#' + r.id + '</span><span><b>' + escapeHtml(r.company_name || '(企業名未設定)') + '</b><br>'
                + '<span>' + escapeHtml(r.self_host || '—') + ' ／ ' + escapeHtml(r.analyzed_at || '—') + ' ／ ' + escapeHtml(r.status) + '</span></span>';
            div.addEventListener('click', function () { selectAnalysis(r); });
            list.appendChild(div);
        });
    }

    function selectAnalysis(r) {
        selected = r;
        el('source_analysis_id_hidden').value = r.id;
        el('step-2-answer-text').textContent = '#' + r.id + '　' + (r.company_name || '');
        el('step-2-answer-sub').textContent = (r.self_host || '—') + ' ／ ' + (r.analyzed_at || '—');
        el('wizard-form').action = storeUrlTemplate.replace('__ID__', r.id);
        currentStep = 3;
        renderSteps();
    }

    // ------------------------------------------------------------------
    // STEP 3: 競合。
    // ------------------------------------------------------------------
    function collectCompetitors() {
        var urls = document.querySelectorAll('input[name="competitor_urls[]"]');
        var names = document.querySelectorAll('input[name="competitor_names[]"]');
        var rows = [];
        for (var i = 0; i < urls.length; i++) {
            var u = urls[i].value.trim();
            if (u === '') { continue; }
            var n = names[i] ? names[i].value.trim() : '';
            rows.push({ url: u, name: n });
        }
        return rows;
    }

    el('step-3-next-btn').addEventListener('click', function () {
        var rows = collectCompetitors();
        el('step-3-answer-text').textContent = rows.length
            ? rows.map(function (r) { return r.name || r.url; }).join(' ／ ')
            : '(未入力)';
        currentStep = 4;
        renderSteps();
    });

    // ------------------------------------------------------------------
    // STEP 4: 営業資料。
    // ------------------------------------------------------------------
    var salesDeckInput = el('sales_deck-input');
    salesDeckInput.addEventListener('change', function () {
        var nameEl = el('sales_deck-filename');
        var clearEl = el('sales_deck-clear');
        if (salesDeckInput.files && salesDeckInput.files.length > 0) {
            var file = salesDeckInput.files[0];
            var kb = Math.round(file.size / 1024);
            nameEl.innerHTML = '<b>' + escapeHtml(file.name) + '</b>（' + kb + ' KB）';
            nameEl.style.display = '';
            clearEl.style.display = '';
        } else {
            clearSalesDeck();
        }
    });

    function clearSalesDeck() {
        salesDeckInput.value = '';
        el('sales_deck-filename').style.display = 'none';
        el('sales_deck-clear').style.display = 'none';
    }

    el('sales_deck-clear').addEventListener('click', clearSalesDeck);

    function currentSalesDeckLabel() {
        return (salesDeckInput.files && salesDeckInput.files.length > 0)
            ? salesDeckInput.files[0].name
            : 'なし（比較レポートPDFのみ）';
    }

    function advanceFromStep4() {
        el('step-4-answer-text').textContent = currentSalesDeckLabel();
        renderStep5();
        currentStep = 5;
        renderSteps();
    }

    el('step-4-next-btn').addEventListener('click', advanceFromStep4);
    el('step-4-skip-btn').addEventListener('click', function () {
        clearSalesDeck();
        advanceFromStep4();
    });

    // ------------------------------------------------------------------
    // STEP 5: 確認。
    // ------------------------------------------------------------------
    function renderStep5() {
        el('summary-source').textContent = selected ? ('#' + selected.id + '　' + (selected.company_name || '')) : '—';
        el('summary-self').textContent = selected ? (selected.self_host || '—') : '—';

        var rows = collectCompetitors();
        el('summary-competitors').textContent = rows.length
            ? rows.map(function (r) { return r.name || r.url; }).join(' ／ ')
            : '—';

        el('summary-salesdeck').textContent = currentSalesDeckLabel();
    }

    renderSteps();
}());
</script>
@endsection
