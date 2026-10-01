@extends('admin.layout')

@section('title', $company->company_name.' の削除確認 - 管理者ダッシュボード')

@section('content')
@php
    $formatBytes = function (int $bytes): string {
        if ($bytes < 1024) {
            return "{$bytes}B";
        }
        if ($bytes < 1024 ** 2) {
            return round($bytes / 1024, 1).'KB';
        }
        if ($bytes < 1024 ** 3) {
            return round($bytes / 1024 ** 2, 1).'MB';
        }

        return round($bytes / 1024 ** 3, 2).'GB';
    };
@endphp

<h2>{{ $company->company_name }} のデータを削除</h2>

<div class="card" style="border-color: var(--danger); margin-bottom: 16px;">
    <h3 style="color: var(--danger);">この操作は元に戻せません</h3>
    <p>{{ config('lead_company_deletion.irreversible_notice') }}</p>
</div>

<div class="card" style="margin-bottom: 16px;">
    <h3>削除されるデータ</h3>
    <dl class="history-item" style="border-bottom: none; padding: 0;">
        <dt>担当者(セッション)</dt><dd>{{ $preview->sessionsCount }}件</dd>
        <dt>無料診断</dt><dd>{{ $preview->diagnosesCount }}件</dd>
        <dt>多社比較</dt><dd>{{ $preview->comparisonsCount }}件</dd>
        <dt>レポートファイル</dt><dd>{{ $preview->reportFilesCount }}件</dd>
        <dt>添付資料</dt><dd>{{ count($preview->attachmentFiles) }}件</dd>
        <dt>ディスク上の合計サイズ</dt><dd>{{ $formatBytes($preview->totalBytes) }}</dd>
    </dl>
    @if ($preview->attachmentFiles !== [])
        <p style="margin-top: 10px; color: var(--muted); font-size: 12px;">添付資料の内訳</p>
        <ul style="margin: 4px 0 0; padding-left: 20px; font-size: 13px;">
            @foreach ($preview->attachmentFiles as $file)
                <li>{{ $file['original_filename'] }}({{ $formatBytes($file['size_bytes']) }})</li>
            @endforeach
        </ul>
    @endif
</div>

@if ($preview->isBlocked())
    <div class="card" style="border-color: var(--danger);">
        <h3 style="color: var(--danger);">削除できません</h3>
        @if ($preview->hasRunningAnalyses)
            <p>{{ config('lead_company_deletion.running_analyses_blocked_reason') }}</p>
            <a href="{{ route('admin.companies.show', $company->id, false) }}">&rarr; 診断一覧へ戻る(強制終了はここから行えます)</a>
        @else
            <p>{{ sprintf((string) config('lead_company_deletion.shared_session_blocked_reason'), implode('、', array_column($preview->blockingCompanies, 'company_name'))) }}</p>
        @endif
    </div>
@else
    <div class="card">
        <h3>削除を実行</h3>
        <form
            method="POST"
            action="{{ route('admin.companies.destroy', $company->id, false) }}"
            onsubmit="return confirm('{{ $company->company_name }}のデータを完全に削除します。この操作は元に戻せません。よろしいですか?');"
        >
            @csrf
            @method('DELETE')
            <p>{{ config('lead_company_deletion.confirmation_input_label') }}</p>
            <p style="font-weight: 700;">{{ $company->company_name }}</p>
            <input
                type="text"
                name="confirmation_company_name"
                id="confirmation-input"
                autocomplete="off"
                value="{{ old('confirmation_company_name') }}"
                style="padding: 7px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; width: 320px;"
            >
            <div style="margin-top: 12px;">
                <button
                    type="submit"
                    id="delete-button"
                    class="btn"
                    disabled
                    style="background: var(--danger); border-color: var(--danger);"
                >完全に削除する</button>
            </div>
        </form>
    </div>
    <script>
        (function () {
            var expected = {!! json_encode($company->company_name) !!};
            var input = document.getElementById('confirmation-input');
            var button = document.getElementById('delete-button');
            function sync() {
                button.disabled = input.value.trim() !== expected;
            }
            input.addEventListener('input', sync);
            sync();
        })();
    </script>
@endif

<p style="margin-top: 16px;"><a href="{{ route('admin.companies.show', $company->id, false) }}">&larr; {{ $company->company_name }}の詳細へ戻る</a></p>
@endsection
