@extends('admin.layout')

@section('title', 'ダッシュボード - 管理者ダッシュボード')

@section('content')
<h2>管理者ダッシュボード</h2>

{{--
    依頼BP-4(2026-09-10): 会社名から比較を始められる入口を、ダッシュボード
    の目立つ位置(見出し直下)に置く。

    依頼BW-3(2026-09-11): この管理画面を使う人間が日常的に行うことは
    「3〜5社比較を作る」ことと「ダッシュボードを見る」ことの2つ
    (依頼者指定)。並び順を「1.比較を作る(大きく) → 2.作成中・最近の
    比較 → 3.KPI → 4.最近の診断企業/注目企業 → 5.要確認・エラー」に
    組み替える(依頼者指定の並び)。既存のKPI・カードは1つも消さない
    (依頼者指定)。
--}}
<p style="margin: 0 0 20px;">
    <a href="{{ route('admin.comparisons.wizard', [], false) }}" class="btn" style="font-size: 16px; padding: 14px 28px;">＋ 3〜5社比較を作る</a>
</p>

<div class="card" style="margin-bottom: 24px;">
    <h3>作成中・最近の比較</h3>
    @if ($recentComparisons->isEmpty())
        <p class="empty">まだ比較レポートがありません。</p>
    @else
        <table class="list">
            <tbody>
                @foreach ($recentComparisons as $row)
                    <tr class="clickable" onclick="location.href='{{ route('admin.analyses.show', $row['id'], false) }}'">
                        <td>#{{ $row['id'] }}</td>
                        <td>{{ $row['company_name'] ?? '—' }}</td>
                        <td><span class="badge status-{{ $row['status'] }}">{{ $row['status'] }}</span></td>
                        <td>{{ $row['created_at']->diffForHumans() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    <p style="margin-top: 12px;"><a href="{{ route('admin.comparisons.index', [], false) }}">すべて見る &rarr;</a></p>
</div>

<div class="kpi-row">
    <div class="kpi-card">
        <div class="label">本日の診断数</div>
        <div class="value">{{ $kpis['today_count'] }}</div>
    </div>
    <div class="kpi-card">
        <div class="label">今月の診断数</div>
        <div class="value">{{ $kpis['month_count'] }}</div>
    </div>
    <div class="kpi-card">
        <div class="label">診断企業数</div>
        <div class="value">{{ $kpis['company_count'] }}</div>
    </div>
    <div class="kpi-card">
        <div class="label">再診断企業数</div>
        <div class="value">{{ $kpis['re_diagnosed_count'] }}</div>
    </div>
    <div class="kpi-card">
        <div class="label">相談リクエスト数</div>
        <div class="value">{{ $kpis['consultation_count'] }}</div>
    </div>
    <div class="kpi-card attention">
        <div class="label">要確認・エラー</div>
        <div class="value">{{ $kpis['needs_attention_count'] }}</div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <h3>最近の診断企業</h3>
        @if ($recentCompanies->isEmpty())
            <p class="empty">まだ診断企業がありません。</p>
        @else
            <table class="list">
                <tbody>
                    @foreach ($recentCompanies as $row)
                        <tr class="clickable" onclick="location.href='{{ route('admin.companies.show', $row['company_id'], false) }}'">
                            <td>{{ $row['company_name'] }}</td>
                            <td>{{ $row['diagnosis_count'] }}回</td>
                            <td>{{ $row['last_diagnosed_at']?->diffForHumans() }}</td>
                            <td><span class="badge status-{{ $row['sales_status'] }}">{{ \App\Enums\SalesStatus::from($row['sales_status'])->label() }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        <p style="margin-top: 12px;"><a href="{{ route('admin.companies.index', [], false) }}">すべて見る &rarr;</a></p>
    </div>

    <div class="card">
        <h3>注目企業(再診断あり)</h3>
        @if ($notableCompanies->isEmpty())
            <p class="empty">再診断のあった企業はまだありません。</p>
        @else
            @foreach ($notableCompanies as $row)
                <p style="margin: 0 0 10px;">
                    <a href="{{ route('admin.companies.show', $row['company_id'], false) }}">
                        <span class="fire">&#128293;</span> {{ $row['company_name'] }}
                    </a><br>
                    <span style="font-size: 12px; color: var(--muted);">
                        {{ $row['diagnosis_count'] }}回診断 / 最終診断 {{ $row['last_diagnosed_at']?->format('n/j') }}
                    </span>
                </p>
            @endforeach
        @endif
    </div>
</div>

<div class="card">
    <h3>要確認・エラー(直近30日)</h3>
    @if ($needsAttention->isEmpty())
        <p class="empty">直近30日で要確認の診断はありません。</p>
    @else
        <table class="list">
            <thead>
                <tr><th>企業</th><th>内容</th><th>発生</th><th></th></tr>
            </thead>
            <tbody>
                @foreach ($needsAttention as $issue)
                    <tr>
                        <td>{{ $issue['company_name'] ?? '(不明)' }}</td>
                        <td>{{ $issue['reason'] }}</td>
                        <td>{{ $issue['occurred_at']?->diffForHumans() }}</td>
                        <td><a href="{{ route('admin.analyses.show', $issue['analysis_id'], false) }}">詳細</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
