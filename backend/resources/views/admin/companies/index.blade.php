@extends('admin.layout')

@section('title', '診断企業 - 管理者ダッシュボード')

@section('content')
<h2>診断企業</h2>

<form method="GET" action="{{ route('admin.companies.index') }}" class="toolbar">
    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="企業名・担当者・メール・ドメインで検索">

    <select name="sales_status">
        <option value="">営業ステータス(すべて)</option>
        @foreach ($salesStatusOptions as $value => $label)
            <option value="{{ $value }}" @selected(($filters['sales_status'] ?? '') === $value)>{{ $label }}</option>
        @endforeach
    </select>

    <select name="diagnosis_status">
        <option value="">診断状態(すべて)</option>
        <option value="completed" @selected(($filters['diagnosis_status'] ?? '') === 'completed')>completed</option>
        <option value="partial" @selected(($filters['diagnosis_status'] ?? '') === 'partial')>partial</option>
        <option value="failed" @selected(($filters['diagnosis_status'] ?? '') === 'failed')>failed</option>
    </select>

    <select name="re_diagnosed">
        <option value="">再診断(すべて)</option>
        <option value="yes" @selected(($filters['re_diagnosed'] ?? '') === 'yes')>再診断あり</option>
        <option value="no" @selected(($filters['re_diagnosed'] ?? '') === 'no')>再診断なし</option>
    </select>

    <select name="sort">
        <option value="last_diagnosed_at" @selected(($filters['sort'] ?? '') === 'last_diagnosed_at' || empty($filters['sort']))>最終診断日</option>
        <option value="diagnosis_count" @selected(($filters['sort'] ?? '') === 'diagnosis_count')>診断回数</option>
        <option value="first_diagnosed_at" @selected(($filters['sort'] ?? '') === 'first_diagnosed_at')>初回診断日</option>
    </select>

    <button type="submit" class="btn">絞り込む</button>
    <a href="{{ route('admin.companies.index') }}" class="btn secondary">リセット</a>
</form>

@if ($companies->isEmpty())
    <div class="card"><p class="empty">条件に一致する企業がありません。</p></div>
@else
    <table class="list">
        <thead>
            <tr>
                <th>企業名</th>
                <th>担当者</th>
                <th>メール</th>
                <th>ドメイン</th>
                <th>初回診断日</th>
                <th>最終診断日</th>
                <th>診断回数</th>
                <th>最新診断結果</th>
                <th>営業ステータス</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($companies as $company)
                <tr class="clickable" onclick="location.href='{{ route('admin.companies.show', $company->id) }}'">
                    <td>{{ $company->company_name }}</td>
                    <td>{{ $company->primary_contact_name }}</td>
                    <td>{{ $company->primary_contact_email }}</td>
                    <td>{{ $company->normalized_domain ?? '—' }}</td>
                    <td>{{ $company->analyses_min_created_at?->format('Y/n/j') ?? '—' }}</td>
                    <td>{{ $company->analyses_max_created_at?->format('Y/n/j') ?? '—' }}</td>
                    <td>{{ $company->analyses_count }}回</td>
                    <td>
                        @if ($company->latest_analysis_status)
                            <span class="badge status-{{ $company->latest_analysis_status }}">{{ $company->latest_analysis_status }}</span>
                        @else
                            —
                        @endif
                    </td>
                    <td><span class="badge status-{{ $company->sales_status }}">{{ \App\Enums\SalesStatus::from($company->sales_status)->label() }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="pagination">{{ $companies->links() }}</div>
@endif
@endsection
