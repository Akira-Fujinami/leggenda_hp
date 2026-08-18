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
                $competitorWebsite = $websites->firstWhere('is_primary', false);
                $pdfReport = $analysis->reports->firstWhere('format', 'pdf');
                $duration = $analysis->started_at && $analysis->completed_at
                    ? $analysis->started_at->diff($analysis->completed_at)
                    : null;
                $brandWheelSummary = $analysis->brandWheelResults->isEmpty()
                    ? '—'
                    : $analysis->brandWheelResults->pluck('status')->unique()->implode(' / ');
            @endphp
            <div class="history-item">
                <div class="date">
                    {{ $analysis->created_at->format('Y/n/j H:i') }}
                    <span class="badge status-{{ $analysis->status->value }}">{{ $analysis->status->value }}</span>
                </div>
                <dl>
                    <dt>自社</dt><dd>{{ $selfWebsite?->url ?? '—' }}</dd>
                    @if ($competitorWebsite)
                        <dt>比較</dt><dd>{{ $competitorWebsite->url }}</dd>
                    @endif
                    <dt>所要時間</dt><dd>{{ $duration ? $duration->format('%i分%s秒') : '—' }}</dd>
                    <dt>Brand Wheel</dt><dd>{{ $brandWheelSummary }}</dd>
                    <dt>PDF</dt><dd>{{ $pdfReport?->status ?? 'processing' }}</dd>
                </dl>
                <a href="{{ route('admin.analyses.show', $analysis->id, false) }}">詳細を見る &rarr;</a>
            </div>
        @endforeach

        <div class="pagination">{{ $analyses->links() }}</div>
    @endif
</div>
@endsection
