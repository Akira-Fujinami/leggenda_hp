@extends('admin.layout')

@section('title', '診断詳細 - 管理者ダッシュボード')

@section('content')
@php
    $selfWebsite = $analysis->project?->websites?->firstWhere('is_primary', true);
    // 依頼AB(2026-08-27): 競合が複数(管理者起点の比較)の場合に備え、
    // display_order順で全件取得する(旧: firstWhereで1件目のみだった)。
    $competitorWebsites = $analysis->project?->websites?->where('is_primary', false)->values() ?? collect();
@endphp
<h2>診断詳細 #{{ $analysis->id }}</h2>

<p><a href="{{ route('admin.companies.show', $analysis->project?->lead_company_id, false) }}">&larr; {{ $analysis->project?->leadCompany?->company_name ?? '企業詳細' }}へ戻る</a></p>

{{-- 依頼AB-2: 比較↔起点の相互リンク。サイト数からの暗黙の判別はせず、
     source_analysis_id/comparisonsの有無で明示的に判断する。 --}}
@if ($analysis->source_analysis_id)
    <p class="empty">
        この比較は、無料診断
        <a href="{{ route('admin.analyses.show', $analysis->source_analysis_id, false) }}">#{{ $analysis->source_analysis_id }}</a>
        から作成されました。
    </p>
@endif
@if ($analysis->comparisons->isNotEmpty())
    <p class="empty">
        この診断から作成した比較:
        @foreach ($analysis->comparisons as $comparison)
            <a href="{{ route('admin.analyses.show', $comparison->id, false) }}">#{{ $comparison->id }}</a>{{ ! $loop->last ? '、' : '' }}
        @endforeach
    </p>
@endif
@if (! $analysis->source_analysis_id)
    <p>
        <a href="{{ route('admin.analyses.compare.create', $analysis->id, false) }}" class="btn">3〜5社で比較する</a>
    </p>
@endif

<div class="info-grid">
    <div class="item">
        <div class="label">診断日時</div>
        <div class="value">{{ $analysis->created_at->format('Y/n/j H:i') }}</div>
    </div>
    <div class="item">
        <div class="label">状態</div>
        <div class="value"><span class="badge status-{{ $analysis->status->value }}">{{ $analysis->status->value }}</span></div>
    </div>
    <div class="item">
        <div class="label">自社URL</div>
        <div class="value">{{ $selfWebsite?->url ?? '—' }}</div>
    </div>
    <div class="item">
        {{-- 依頼AB: 競合が2件以上(管理者起点の比較)の場合は件数をラベルに
             出し、URLを列挙する。競合1件(通常の無料診断)の場合は従来どおり。 --}}
        <div class="label">比較URL{{ $competitorWebsites->count() > 1 ? '('.$competitorWebsites->count().'件)' : '' }}</div>
        <div class="value">
            @forelse ($competitorWebsites as $competitorWebsite)
                {{ $competitorWebsite->url }}@if (! $loop->last)<br>@endif
            @empty
                —
            @endforelse
        </div>
    </div>
    {{-- 2026-08-24追加: 「消費済みなのにレポートが渡っていない」を営業が
         見分けるための表示(依頼者指定)。レポートがSkipped(見送り)なら
         必ず未消費、Failed(生成失敗)なら通常消費済みになるはずだが、
         実際の値をそのまま見せることで前提のズレにも気づける。 --}}
    <div class="item">
        <div class="label">診断回数消費</div>
        <div class="value">
            @if ($analysis->lead_quota_consumed_at)
                消費済み({{ $analysis->lead_quota_consumed_at->format('Y/n/j H:i') }})
            @else
                未消費
            @endif
        </div>
    </div>
</div>

<div class="card">
    <h3>サイトごとの状態</h3>
    <table class="list">
        <thead><tr><th>サイト</th><th>状態</th><th>HTTPステータス</th><th>応答時間</th></tr></thead>
        <tbody>
            @foreach ($analysis->websiteAnalyses as $wa)
                <tr>
                    <td>{{ $wa->website?->name }}</td>
                    <td><span class="badge status-{{ $wa->status->value }}">{{ $wa->status->value }}</span></td>
                    <td>{{ $wa->http_status ?? '—' }}</td>
                    <td>{{ $wa->response_time_ms ? $wa->response_time_ms.'ms' : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{--
    依頼BU-2/BU-3(2026-09-11): サイトごとの巡回実績。「巡回がページに
    届いていないのでは」という推測を、見た人が自分で判断できるようにする
    (依頼者指定、この依頼の主目的)。巡回のロジック自体はここでは変えず、
    既にDBにある実績(analysis_crawled_pages)と、依頼BU-1で新たに保存した
    終了理由(website_analyses.crawl_finished_reason)を表示するだけ。
--}}
<div class="card">
    <h3>巡回の実績</h3>
    {{--
        依頼BV-1: crawl_site=trueなのに巡回が始まらなかった(robots.txt
        未取得・許可ホスト0件)場合も、依頼BU時点ではここが「この診断では
        巡回を行っていません。」という一律の文言に隠れてしまい、なぜ
        始まらなかったかが見えなかった(単独診断で1サイトしかない場合、
        全サイトがhas_crawl_data=falseになるため)。crawl_site自体が
        falseの診断(機能を使っていない、多数派)のときだけ、この簡潔な
        表示に倒す。
    --}}
    @if (! $analysis->crawl_site)
        <p class="empty">この診断では巡回を行っていません。</p>
    @else
        <div style="overflow-x: auto;">
        <table class="list">
            <thead>
                <tr>
                    <th>サイト</th>
                    <th>取得</th>
                    <th>失敗</th>
                    <th>除外(パターン/robots/対象外/新卒キャリア)</th>
                    <th>未処理</th>
                    <th>レンダリング</th>
                    <th>終了理由</th>
                    <th>所要時間</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($analysis->websiteAnalyses as $wa)
                    @php
                        $crawlSummary = $crawlSummaries[$wa->id] ?? null;
                        $hasCrawlData = $crawlSummary['has_crawl_data'] ?? false;
                        $hasWarning = $hasCrawlData && count($crawlSummary['warnings']) > 0;
                        $criticalWarning = $crawlSummary['critical_warning'] ?? null;
                        $durationLabel = null;
                        if ($hasCrawlData && $crawlSummary['duration_seconds'] !== null) {
                            $durationLabel = sprintf('%d分%02d秒', intdiv($crawlSummary['duration_seconds'], 60), $crawlSummary['duration_seconds'] % 60);
                        }
                        $renderLabel = '—';
                        if ($hasCrawlData) {
                            $renderLabel = $crawlSummary['render_candidate_count'] !== null
                                ? "{$crawlSummary['render_candidate_count']}件→{$crawlSummary['rendered_count']}件"
                                : "{$crawlSummary['rendered_count']}件";
                        }
                    @endphp
                    {{-- 依頼BV-3: critical_warning(crawl_site=trueなのにこの
                         サイトだけ巡回が始まらなかった)は、BU-3の3条件より
                         一段重い赤系で目立たせる ―― 同じ黄色にしない
                         (依頼者指定)。 --}}
                    <tr style="{{ $criticalWarning ? 'background: #FDEEEC;' : ($hasWarning ? 'background: #FFF8EC;' : '') }}">
                        <td>
                            {{ $wa->website?->name }}
                            @if ($criticalWarning)
                                <span title="このサイトは巡回されていません" style="color: #C2372B;">&#9940;</span>
                            @elseif ($hasWarning)
                                <span title="この結果は確認が必要です" style="color: #B8860B;">&#9888;</span>
                            @endif
                        </td>
                        @if (! $hasCrawlData)
                            <td colspan="6" style="{{ $criticalWarning ? 'color: #C2372B; font-weight: 600;' : 'color: var(--muted); font-size: 13px;' }}">
                                巡回していません。@if ($crawlSummary['finished_reason'] !== null)({{ $crawlSummary['finished_reason_label'] }})@endif
                            </td>
                        @else
                            <td>{{ $crawlSummary['fetched_count'] }}</td>
                            <td>{{ $crawlSummary['failed_count'] }}</td>
                            <td>{{ $crawlSummary['excluded_counts']['by_pattern'] }}/{{ $crawlSummary['excluded_counts']['by_robots'] }}/{{ $crawlSummary['excluded_counts']['by_scope'] }}/{{ $crawlSummary['excluded_counts']['by_track'] }}</td>
                            <td>{{ $crawlSummary['pending_count'] }}</td>
                            <td>{{ $renderLabel }}</td>
                            <td>{{ $crawlSummary['finished_reason_label'] }}</td>
                            <td>{{ $durationLabel ?? '—' }}</td>
                        @endif
                    </tr>
                    @if ($criticalWarning)
                        <tr style="background: #FDEEEC;">
                            <td colspan="7" style="padding-top: 0;">
                                <div style="color: #C2372B; font-size: 13px; font-weight: 600;">&#9940; {{ $criticalWarning['message'] }}</div>
                            </td>
                        </tr>
                    @endif
                    @if ($hasWarning)
                        <tr style="background: #FFF8EC;">
                            <td colspan="7" style="padding-top: 0;">
                                @foreach ($crawlSummary['warnings'] as $warning)
                                    <div style="color: #7a5c00; font-size: 13px;">&#9888; {{ $warning['message'] }}</div>
                                @endforeach
                            </td>
                        </tr>
                    @endif
                    @if ($hasCrawlData && count($crawlSummary['failed_urls']) > 0)
                        <tr>
                            <td colspan="7" style="padding-top: 0;">
                                <details>
                                    <summary style="cursor: pointer; font-size: 13px; color: #4B5563;">失敗したURL({{ $crawlSummary['failed_count'] }}件)を見る</summary>
                                    <table class="list" style="margin-top: 8px;">
                                        <thead><tr><th>URL</th><th>HTTPステータス</th></tr></thead>
                                        <tbody>
                                            @foreach ($crawlSummary['failed_urls'] as $failedUrl)
                                                <tr>
                                                    <td style="word-break: break-all;">{{ $failedUrl['url'] }}</td>
                                                    <td>{{ $failedUrl['http_status'] ?? '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                    @if ($crawlSummary['failed_urls_overflow_count'] > 0)
                                        <p class="empty">ほか{{ $crawlSummary['failed_urls_overflow_count'] }}件</p>
                                    @endif
                                </details>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
        </div>
    @endif
</div>

<div class="card">
    <h3>Brand Wheel</h3>
    @if ($brandWheelResults->isEmpty())
        <p class="empty">Brand Wheel分析結果がありません。</p>
    @else
        <table class="list">
            <thead><tr><th>サイト</th><th>状態</th><th>エラー</th></tr></thead>
            <tbody>
                @foreach ($brandWheelResults as $result)
                    <tr>
                        <td>{{ $result->websiteAnalysis?->website?->name }}</td>
                        <td>{{ $result->status }}</td>
                        <td>{{ $result->error_message ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

@php
    // 2026-08-24追加: Skipped(見送り・診断回数は消費していない)とFailed
    // (生成失敗・診断回数は消費済み)を、同じ「レポートが無い」でも対応が
    // 違うと分かるようラベルで明示する(依頼者指定)。
    $reportStatusLabels = [
        'completed' => '生成成功',
        'pending' => '生成中',
        'skipped' => '見送り(診断回数は消費していません)',
        'failed' => '生成失敗(診断回数は消費済みです)',
    ];
@endphp
<div class="card">
    <h3>レポート</h3>
    <table class="list">
        <thead><tr><th>形式</th><th>状態</th></tr></thead>
        <tbody>
            @forelse ($analysis->reports as $report)
                <tr>
                    <td>{{ $report->format->value }}</td>
                    <td>
                        <span class="badge status-{{ $report->status->value }}">
                            {{ $reportStatusLabels[$report->status->value] ?? $report->status->value }}
                        </span>
                        {{-- 依頼AC(2026-08-27): 比較Analysis(source_analysis_idが
                             非null)のpdfレポートは多社比較レポート。既存の
                             リード向けdownloadReport()(トークン認証)とは別の
                             admin.auth配下の専用エンドポイントからダウンロードする。 --}}
                        @if ($analysis->source_analysis_id && $report->format->value === 'pdf' && $report->status->value === 'completed')
                            <a href="{{ route('admin.analyses.comparison-report.download', $analysis->id, false) }}" style="margin-left: 8px;">ダウンロード</a>
                            {{-- 依頼BG(2026-09-08): 営業資料(PPTX)がアップロード
                                 されている比較Analysisにのみ、差し込み版の
                                 ダウンロード導線を並べて出す。添付が無い/PPTX
                                 でない場合はボタンを出さず、理由を短く示す
                                 (依頼者指定)。 --}}
                            @php
                                $pptxAttachment = $analysis->attachments->firstWhere('extension', 'pptx');
                            @endphp
                            @if ($pptxAttachment)
                                <a href="{{ route('admin.analyses.comparison-report.pptx-insert', $analysis->id, false) }}" style="margin-left: 8px;">営業資料に差し込む</a>
                            @else
                                <span class="empty" style="margin-left: 8px;">営業資料(PPTX)をアップロードすると、比較ページを差し込んだ資料をダウンロードできます。</span>
                            @endif
                        @elseif (! $analysis->source_analysis_id && $report->status->value === 'completed')
                            {{-- 依頼AG-1(2026-08-27): 無料診断(比較でない)の
                                 レポートは、管理者が生トークンを持たないため
                                 リード向けURLを組み立てられない。admin.auth
                                 配下の専用エンドポイントからダウンロードする。 --}}
                            <a href="{{ route('admin.analyses.lead-report.download', [$analysis->id, $report->format->value], false) }}" style="margin-left: 8px;">ダウンロード</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">レポートは未生成です。</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{--
    依頼AD-1(2026-08-27): 商談相手ごとの既存資料(フォーマット未確定)。
    無料診断・多社比較のどちらでも表示する(区別しない)。現時点では1診断
    1件に制限している(AnalysisAttachmentServiceのdocblock参照) ―― 既に
    1件ある状態でアップロードすると、既存の1件を自動的に差し替える。
--}}
<div class="card">
    <h3>既存資料</h3>
    @if ($analysis->attachments->isEmpty())
        <p class="empty">アップロードされた資料はありません。</p>
    @else
        <table class="list">
            <thead><tr><th>ファイル名</th><th>サイズ</th><th>アップロード日時</th><th></th></tr></thead>
            <tbody>
                @foreach ($analysis->attachments as $attachment)
                    <tr>
                        <td>{{ $attachment->original_filename }}</td>
                        <td>{{ number_format($attachment->size_bytes / 1024, 1) }}KB</td>
                        <td>{{ $attachment->created_at->format('Y/n/j H:i') }}</td>
                        <td>
                            <a href="{{ route('admin.analyses.attachment.download', [$analysis->id, $attachment->id], false) }}">ダウンロード</a>
                            <form
                                method="POST"
                                action="{{ route('admin.analyses.attachment.destroy', [$analysis->id, $attachment->id], false) }}"
                                style="display: inline; margin-left: 8px;"
                                onsubmit="return confirm('この資料を削除します。よろしいですか?');"
                            >
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn secondary">削除</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <form
        method="POST"
        action="{{ route('admin.analyses.attachment.store', $analysis->id, false) }}"
        enctype="multipart/form-data"
        style="margin-top: 14px;"
    >
        @csrf
        <input type="file" name="file" required>
        <button type="submit" class="btn" style="margin-left: 8px;">
            {{ $analysis->attachments->isEmpty() ? 'アップロード' : '差し替える' }}
        </button>
        <p class="empty" style="margin-top: 8px;">
            許可される形式: {{ implode(' / ', config('analysis_attachment.allowed_extensions')) }}
            (最大{{ number_format(config('analysis_attachment.max_file_size_bytes') / 1024 / 1024, 0) }}MB)
        </p>
        @error('file')
            <p style="color: #c0392b; font-size: 13px;">{{ $message }}</p>
        @enderror
    </form>
</div>
@endsection
