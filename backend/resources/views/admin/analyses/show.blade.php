@extends('admin.layout')

@section('title', '診断詳細 - 管理者ダッシュボード')

@section('content')
@php
    $selfWebsite = $analysis->project?->websites?->firstWhere('is_primary', true);
    $competitorWebsite = $analysis->project?->websites?->firstWhere('is_primary', false);
@endphp
<h2>診断詳細 #{{ $analysis->id }}</h2>

<p><a href="{{ route('admin.companies.show', $analysis->project?->lead_company_id, false) }}">&larr; {{ $analysis->project?->leadCompany?->company_name ?? '企業詳細' }}へ戻る</a></p>

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
        <div class="label">比較URL</div>
        <div class="value">{{ $competitorWebsite?->url ?? '—' }}</div>
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

<div class="card">
    <h3>レポート</h3>
    <table class="list">
        <thead><tr><th>形式</th><th>状態</th></tr></thead>
        <tbody>
            @forelse ($analysis->reports as $report)
                <tr>
                    <td>{{ $report->format->value }}</td>
                    <td><span class="badge status-{{ $report->status->value }}">{{ $report->status->value }}</span></td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">レポートは未生成です。</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
