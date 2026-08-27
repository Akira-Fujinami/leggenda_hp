@extends('admin.layout')

@section('title', $company->company_name.' - 管理者ダッシュボード')

@section('content')
<h2>{{ $company->company_name }}</h2>

<div class="info-grid">
    <div class="item">
        <div class="label">担当者</div>
        <div class="value">{{ $company->primary_contact_name }}</div>
    </div>
    <div class="item">
        <div class="label">メール</div>
        <div class="value">{{ $company->primary_contact_email }}</div>
    </div>
    <div class="item">
        <div class="label">ドメイン</div>
        <div class="value">{{ $company->normalized_domain ?? '—' }}</div>
    </div>
    <div class="item">
        <div class="label">診断回数</div>
        <div class="value">{{ $analyses->total() }}回</div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <h3>営業ステータス</h3>
        <form method="POST" action="{{ route('admin.companies.sales-status', $company->id, false) }}">
            @csrf
            @method('PATCH')
            <select name="sales_status" onchange="this.form.submit()">
                @foreach ($salesStatusOptions as $value => $label)
                    <option value="{{ $value }}" @selected($company->sales_status === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="btn" style="margin-left: 8px;">更新</button></noscript>
        </form>
    </div>

    <div class="card">
        <h3>営業メモ</h3>
        <form method="POST" action="{{ route('admin.companies.sales-note', $company->id, false) }}">
            @csrf
            @method('PATCH')
            <textarea name="sales_note" placeholder="商談メモ・次回アクション等">{{ old('sales_note', $company->sales_note) }}</textarea>
            <button type="submit" class="btn" style="margin-top: 8px;">保存</button>
        </form>
    </div>
</div>

<div class="card">
    <h3>診断履歴</h3>
    @if ($analyses->isEmpty())
        <p class="empty">診断履歴がありません。</p>
    @else
        @foreach ($analyses as $analysis)
            @php
                $websites = $analysis->project?->websites ?? collect();
                $selfWebsite = $websites->firstWhere('is_primary', true);
                // 依頼AB(2026-08-27): 競合が複数(管理者起点の比較)の場合に
                // 備え、display_order順で全件取得する(旧: firstWhereで
                // 1件目のみだった)。
                $competitorWebsites = $websites->where('is_primary', false)->values();
                $pdfReport = $analysis->reports->firstWhere('format', 'pdf');
                $duration = $analysis->started_at && $analysis->completed_at
                    ? $analysis->started_at->diff($analysis->completed_at)
                    : null;
                $brandWheelSummary = $analysis->brandWheelResults->isEmpty()
                    ? '—'
                    : $analysis->brandWheelResults->pluck('status')->unique()->implode(' / ');
                $leadSession = $analysis->project?->leadSession;
            @endphp
            <div class="history-item">
                <div class="date">
                    {{ $analysis->created_at->format('Y/n/j H:i') }}
                    <span class="badge status-{{ $analysis->status->value }}">{{ $analysis->status->value }}</span>
                    {{-- 依頼AB-2: 無料診断と比較を一覧で見分けられるようにする
                         (source_analysis_idの有無で明示的に判断、サイト数からの
                         推測はしない)。 --}}
                    @if ($analysis->source_analysis_id)
                        <span class="badge">比較(#{{ $analysis->source_analysis_id }}から作成)</span>
                    @endif
                </div>
                <dl>
                    <dt>自社</dt><dd>{{ $selfWebsite?->url ?? '—' }}</dd>
                    @if ($competitorWebsites->isNotEmpty())
                        <dt>比較{{ $competitorWebsites->count() > 1 ? '('.$competitorWebsites->count().'件)' : '' }}</dt>
                        <dd>
                            @foreach ($competitorWebsites as $competitorWebsite)
                                {{ $competitorWebsite->url }}@if (! $loop->last)<br>@endif
                            @endforeach
                        </dd>
                    @endif
                    <dt>所要時間</dt><dd>{{ $duration ? $duration->format('%i分%s秒') : '—' }}</dd>
                    <dt>Brand Wheel</dt><dd>{{ $brandWheelSummary }}</dd>
                    <dt>PDF</dt><dd>{{ $pdfReport?->status ?? 'processing' }}</dd>
                    @unless ($analysis->status->isTerminal())
                        <dt>⚠ 実行中</dt>
                        <dd>
                            開始から{{ $analysis->created_at->diffForHumans(null, true) }}経過({{ $analysis->status->value }})。
                            異常終了で止まっている場合、診断回数リセットだけでは復旧しません。
                            <form
                                method="POST"
                                action="{{ route('admin.analyses.force-terminate', $analysis->id, false) }}"
                                style="display: inline;"
                                onsubmit="return confirm('この診断(ID: {{ $analysis->id }})を強制終了します。よろしいですか?');"
                            >
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn" style="margin-left: 8px;">診断を強制終了</button>
                            </form>
                        </dd>
                    @endunless
                    @if ($leadSession)
                        <dt>診断回数</dt>
                        <dd>
                            {{ $leadSession->analyses_used }} / {{ config('lead.max_analyses_per_token') }}
                            <form
                                method="POST"
                                action="{{ route('admin.lead-sessions.reset-analyses-used', $leadSession->id, false) }}"
                                style="display: inline;"
                                onsubmit="return confirm('この申込(診断回数 {{ $leadSession->analyses_used }} / {{ config('lead.max_analyses_per_token') }})を0にリセットします。よろしいですか?');"
                            >
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn" style="margin-left: 8px;">診断回数をリセット</button>
                            </form>
                        </dd>
                    @endif
                </dl>
                <a href="{{ route('admin.analyses.show', $analysis->id, false) }}">詳細を見る &rarr;</a>
            </div>
        @endforeach

        <div class="pagination">{{ $analyses->links() }}</div>
    @endif
</div>
@endsection
