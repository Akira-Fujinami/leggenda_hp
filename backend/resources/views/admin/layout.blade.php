<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '管理者ダッシュボード')</title>
    <style>
        :root {
            --border: #E3E5E8;
            --bg: #F7F8FA;
            --text: #1F2328;
            --muted: #6B7280;
            --brand: #1D2088;
            --danger: #C2372B;
            --warn: #B8860B;
            --ok: #1F8A57;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, "Segoe UI", "Hiragino Kaku Gothic ProN", Meiryo, sans-serif; color: var(--text); background: var(--bg); }
        a { color: var(--brand); text-decoration: none; }
        a:hover { text-decoration: underline; }

        .shell { display: flex; min-height: 100vh; }
        .sidebar { width: 220px; flex-shrink: 0; background: #fff; border-right: 1px solid var(--border); padding: 20px 0; }
        .sidebar h1 { font-size: 15px; font-weight: 700; margin: 0 20px 24px; color: var(--brand); }
        .sidebar nav a { display: block; padding: 10px 20px; font-size: 14px; color: var(--text); }
        .sidebar nav a:hover { background: var(--bg); text-decoration: none; }
        .sidebar nav a.active { background: #EEF0FB; color: var(--brand); font-weight: 700; border-right: 3px solid var(--brand); }
        .sidebar .logout { margin-top: 24px; border-top: 1px solid var(--border); padding-top: 12px; }
        .sidebar .logout button { background: none; border: none; color: var(--muted); font-size: 13px; padding: 10px 20px; cursor: pointer; width: 100%; text-align: left; }
        .sidebar .logout button:hover { color: var(--text); }

        .main { flex: 1; min-width: 0; padding: 28px 32px; }
        .main h2 { font-size: 20px; margin: 0 0 20px; }

        .flash { background: #EAF7EF; border: 1px solid #BFE6CC; color: var(--ok); padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; }
        .errors { background: #FDEEEC; border: 1px solid #F3C6C0; color: var(--danger); padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; }

        .kpi-row { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin-bottom: 24px; }
        @media (max-width: 1279px) { .kpi-row { grid-template-columns: repeat(3, 1fr); } }
        .kpi-card { background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 14px 16px; }
        .kpi-card .label { font-size: 12px; color: var(--muted); margin-bottom: 6px; }
        .kpi-card .value { font-size: 26px; font-weight: 700; line-height: 1; }
        .kpi-card.attention .value { color: var(--danger); }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
        @media (max-width: 1279px) { .grid-2 { grid-template-columns: 1fr; } }

        .card { background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 18px 20px; }
        .card h3 { font-size: 14px; margin: 0 0 14px; }
        .card .empty { color: var(--muted); font-size: 13px; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.list th { text-align: left; font-size: 12px; color: var(--muted); font-weight: 600; padding: 8px 10px; border-bottom: 1px solid var(--border); white-space: nowrap; }
        table.list td { padding: 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        table.list tr.clickable { cursor: pointer; }
        table.list tr.clickable:hover { background: var(--bg); }

        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .badge.status-uncontacted { background: #EEF0F2; color: #4B5563; }
        .badge.status-contacted { background: #E4EEFB; color: #1D5FA8; }
        .badge.status-meeting { background: #FFF3D9; color: var(--warn); }
        .badge.status-won { background: #E4F6EA; color: var(--ok); }
        .badge.status-lost { background: #F2F2F2; color: #8A8A8A; }
        .badge.status-completed { background: #E4F6EA; color: var(--ok); }
        .badge.status-partial { background: #FFF3D9; color: var(--warn); }
        .badge.status-failed { background: #FDEEEC; color: var(--danger); }
        .badge.status-pending, .badge.status-queued, .badge.status-running { background: #EEF0F2; color: #4B5563; }
        {{-- 2026-08-24追加: レポート生成の意図的な見送り(白紙防止)。診断回数を
             消費していない点でstatus-failed(生成失敗・消費済み)と区別する ――
             警告色ではなく中立色にして「不具合」ではないことを一目で示す。 --}}
        .badge.status-skipped { background: #EEF0F2; color: #4B5563; }

        .fire { color: #D9534F; }

        .toolbar { display: flex; gap: 10px; align-items: center; margin-bottom: 16px; flex-wrap: wrap; }
        .toolbar input[type=text], .toolbar select { padding: 7px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; }
        .toolbar input[type=text] { flex: 1; min-width: 220px; }
        .btn { display: inline-block; padding: 7px 16px; border-radius: 6px; border: 1px solid var(--brand); background: var(--brand); color: #fff; font-size: 13px; cursor: pointer; }
        .btn:hover { opacity: .9; text-decoration: none; }
        .btn.secondary { background: #fff; color: var(--text); border-color: var(--border); }

        .pagination { margin-top: 16px; font-size: 13px; }
        .pagination nav { display: flex; gap: 4px; }

        .info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
        @media (max-width: 1279px) { .info-grid { grid-template-columns: repeat(2, 1fr); } }
        .info-grid .item .label { font-size: 12px; color: var(--muted); margin-bottom: 4px; }
        .info-grid .item .value { font-size: 15px; }

        .history-item { border-bottom: 1px solid var(--border); padding: 14px 0; }
        .history-item:last-child { border-bottom: none; }
        .history-item .date { font-weight: 700; margin-bottom: 4px; }
        .history-item dl { display: grid; grid-template-columns: max-content 1fr; gap: 2px 10px; font-size: 13px; margin: 6px 0; }
        .history-item dt { color: var(--muted); }

        textarea { width: 100%; min-height: 120px; padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; font-family: inherit; }

    </style>
</head>
<body>
    {{--
        このレイアウトはEnsureAdminAuthenticatedミドルウェアを通過した
        (=セッションにadmin_authenticated===trueがある)リクエストにしか
        描画されない。未認証時はミドルウェアがadmin.guestビュー(認証
        モーダルのみ)を返すため、ここに`if(auth)`のような分岐は不要 ――
        このファイルに到達した時点で認証済みであることが保証されている。
    --}}
    <div class="shell">
        <aside class="sidebar">
            <h1>管理者ダッシュボード</h1>
            <nav>
                <a href="{{ route('admin.dashboard', [], false) }}" class="{{ request()->routeIs('admin.dashboard*') ? 'active' : '' }}">ダッシュボード</a>
                <a href="{{ route('admin.companies.index', [], false) }}" class="{{ request()->routeIs('admin.companies.*') ? 'active' : '' }}">診断企業</a>
                <a href="{{ route('admin.analyses.index', [], false) }}" class="{{ request()->routeIs('admin.analyses.*') ? 'active' : '' }}">診断管理</a>
            </nav>
            <div class="logout">
                <form method="POST" action="{{ route('admin.logout', [], false) }}">
                    @csrf
                    <button type="submit">ログアウト</button>
                </form>
            </div>
        </aside>
        <main class="main">
            @if (session('status'))
                <div class="flash">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="errors">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif
            @yield('content')
        </main>
    </div>
</body>
</html>
