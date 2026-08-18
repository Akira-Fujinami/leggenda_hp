@extends('admin.layout')

@section('title', '診断管理 - 管理者ダッシュボード')

@section('content')
<h2>診断管理</h2>

<form method="GET" action="{{ route('admin.analyses.index') }}" class="toolbar">
    <select name="status">
        <option value="">診断状態(すべて)</option>
        @foreach (['pending', 'queued', 'running', 'completed', 'partial', 'failed', 'cancelled'] as $value)
            <option value="{{ $value }}" @selected($status === $value)>{{ $value }}</option>
        @endforeach
    </select>
    <button type="submit" class="btn">絞り込む</button>
</form>

@if ($analyses->isEmpty())
    <div class="card"><p class="empty">条件に一致する診断がありません。</p></div>
@else
    <table class="list">
        <thead>
            <tr><th>診断日時</th><th>企業</th><th>自社URL</th><th>状態</th></tr>
        </thead>
        <tbody>
            @foreach ($analyses as $analysis)
                @php $selfWebsite = $analysis->project?->websites?->firstWhere('is_primary', true); @endphp
                <tr class="clickable" onclick="location.href='{{ route('admin.analyses.show', $analysis->id) }}'">
                    <td>{{ $analysis->created_at->format('Y/n/j H:i') }}</td>
                    <td>{{ $analysis->project?->leadCompany?->company_name ?? '—' }}</td>
                    <td>{{ $selfWebsite?->url ?? '—' }}</td>
                    <td><span class="badge status-{{ $analysis->status->value }}">{{ $analysis->status->value }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="pagination">{{ $analyses->links() }}</div>
@endif
@endsection
