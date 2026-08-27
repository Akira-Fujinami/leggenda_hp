@extends('admin.layout')

@section('title', '比較の作成 - 管理者ダッシュボード')

@section('content')
<p><a href="{{ route('admin.analyses.show', $analysis->id, false) }}">&larr; 診断詳細 #{{ $analysis->id }}へ戻る</a></p>

<h2>{{ $analysis->project?->leadCompany?->company_name }} の比較を作成</h2>
<p class="empty">
    無料診断 #{{ $analysis->id }} を起点に、自社+競合{{ $minCompetitors }}〜{{ $maxCompetitors }}社の比較を実行します。
    所要時間は長くなります(管理者専用の機能のため、許容しています)。
</p>

<div class="card">
    <form method="POST" action="{{ route('admin.analyses.compare.store', $analysis->id, false) }}">
        @csrf

        <div class="item" style="margin-bottom: 16px;">
            <div class="label">自社サイトURL</div>
            <input type="text" name="self_url" value="{{ old('self_url', $selfUrl) }}" style="width: 100%;">
        </div>

        <div class="label">競合サイトURL・企業名({{ $minCompetitors }}〜{{ $maxCompetitors }}件)</div>
        <p class="empty" style="margin-top: 4px;">企業名は比較レポートの表の見出しに使います。空欄の場合はURLのドメインから自動生成します。</p>
        @for ($i = 0; $i < $maxCompetitors; $i++)
            <div class="item" style="margin-bottom: 8px; display: flex; gap: 8px;">
                <input
                    type="text"
                    name="competitor_urls[]"
                    value="{{ old('competitor_urls.'.$i, $i === 0 ? $existingCompetitorUrl : null) }}"
                    placeholder="{{ $i < $minCompetitors ? '競合サイトURL'.($i + 1).'(必須)' : '競合サイトURL'.($i + 1).'(任意)' }}"
                    style="flex: 2;"
                >
                <input
                    type="text"
                    name="competitor_names[]"
                    value="{{ old('competitor_names.'.$i) }}"
                    placeholder="企業名(任意)"
                    style="flex: 1;"
                >
            </div>
        @endfor

        <button type="submit" class="btn" style="margin-top: 8px;">比較を開始する</button>
    </form>
</div>
@endsection
