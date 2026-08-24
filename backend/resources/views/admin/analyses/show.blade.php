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
                    </td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty">レポートは未生成です。</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
