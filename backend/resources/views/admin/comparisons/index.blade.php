@extends('admin.layout')

@section('title', '比較レポート - 管理者ダッシュボード')

{{--
    依頼BW-2(2026-09-11、この依頼で新設): 比較レポートの一覧。
    admin/analyses/index.blade.php(診断管理、承認外)と同じ表組みの
    パターンに揃えるが、対象はsource_analysis_idが非nullのAnalysisのみ
    (依頼AB-2と同じ既存方針)。営業が日常的に使う画面(依頼者指定)のため、
    「開く」だけでなく完了していれば差し込んだ資料への導線も直接置く。
--}}
@section('content')
<h2>比較レポート</h2>

<p style="margin: 0 0 20px;">
    <a href="{{ route('admin.comparisons.wizard', [], false) }}" class="btn">＋ 3〜5社比較を作る</a>
</p>

@if ($comparisons->isEmpty())
    <div class="card"><p class="empty">まだ比較レポートがありません。</p></div>
@else
    <table class="list">
        <thead>
            <tr>
                <th>ID</th>
                <th>自社企業</th>
                <th>競合社数</th>
                <th>状態</th>
                <th>営業資料(PPTX)</th>
                <th>作成日時</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($comparisons as $comparison)
                @php
                    $competitorCount = $comparison->project?->websites?->where('is_primary', false)->count() ?? 0;
                    $pptxAttachment = $comparison->attachments->firstWhere('extension', 'pptx');
                    $pdfReport = $comparison->reports->first(fn ($r) => $r->format->value === 'pdf');
                    $canDownloadInsert = $pptxAttachment !== null && $pdfReport?->status?->value === 'completed';
                @endphp
                <tr>
                    <td><a href="{{ route('admin.analyses.show', $comparison->id, false) }}">#{{ $comparison->id }}</a></td>
                    <td>{{ $comparison->project?->leadCompany?->company_name ?? '—' }}</td>
                    <td>{{ $competitorCount }}社</td>
                    <td><span class="badge status-{{ $comparison->status->value }}">{{ $comparison->status->value }}</span></td>
                    <td>{{ $pptxAttachment ? 'あり' : 'なし' }}</td>
                    <td>{{ $comparison->created_at->format('Y/n/j H:i') }}</td>
                    <td>
                        <a href="{{ route('admin.analyses.show', $comparison->id, false) }}">開く</a>
                        @if ($canDownloadInsert)
                            <a href="{{ route('admin.analyses.comparison-report.pptx-insert', $comparison->id, false) }}" style="margin-left: 8px;">差し込んだ資料</a>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="pagination">{{ $comparisons->links() }}</div>
@endif
@endsection
