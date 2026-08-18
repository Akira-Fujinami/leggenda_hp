<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>管理者ログイン</title>
    <style>
        :root { --border: #E3E5E8; --bg: #F7F8FA; --text: #1F2328; --muted: #6B7280; --brand: #1D2088; --danger: #C2372B; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, "Segoe UI", "Hiragino Kaku Gothic ProN", Meiryo, sans-serif; color: var(--text); background: var(--bg); }
        .modal-wrap { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 16px; }
        .modal-box { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 36px 32px; width: 100%; max-width: 420px; box-shadow: 0 4px 24px rgba(0,0,0,0.06); }
        .modal-box h1 { font-size: 17px; font-weight: 700; margin: 0 0 24px; text-align: center; }
        .field { margin-bottom: 16px; }
        .field label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 6px; }
        .field input { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 14px; }
        .field input:focus { outline: 2px solid var(--brand); outline-offset: -1px; }
        .submit-btn { width: 100%; margin-top: 8px; padding: 11px; border-radius: 6px; border: 1px solid var(--brand); background: var(--brand); color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; }
        .submit-btn:disabled { opacity: .6; cursor: default; }
        .error-box { background: #FDEEEC; border: 1px solid #F3C6C0; color: var(--danger); padding: 9px 12px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; display: none; }
        .error-box.visible { display: block; }
    </style>
</head>
<body>
    <div class="modal-wrap">
        <div class="modal-box">
            <h1>管理者ログイン</h1>

            <div class="error-box" id="error-box"></div>

            <form id="admin-login-form" novalidate>
                <div class="field">
                    <label for="username">ユーザー名</label>
                    <input type="text" id="username" name="username" autocomplete="username" autofocus required>
                </div>
                <div class="field">
                    <label for="password">パスワード</label>
                    <input type="password" id="password" name="password" autocomplete="current-password" required>
                </div>
                <button type="submit" class="submit-btn" id="submit-btn">ログイン</button>
            </form>
        </div>
    </div>

    <script>
        (function () {
            var form = document.getElementById('admin-login-form');
            var errorBox = document.getElementById('error-box');
            var submitBtn = document.getElementById('submit-btn');
            var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            // ステータスコードごとの汎用メッセージ(依頼者指定)。詳細な
            // サーバーエラー文言("Unexpected token '<'"等)をそのまま
            // 画面に出さない。
            var STATUS_MESSAGES = {
                401: 'ユーザー名またはパスワードが正しくありません。',
                419: 'セッションの有効期限が切れました。ページを再読み込みしてください。',
                429: 'ログイン試行回数が多すぎます。しばらくしてから再度お試しください。',
                500: 'ログイン処理でエラーが発生しました。',
            };

            function setLoading(isLoading) {
                submitBtn.disabled = isLoading;
                submitBtn.textContent = isLoading ? 'ログイン中…' : 'ログイン';
            }

            function showError(message) {
                errorBox.textContent = message;
                errorBox.classList.add('visible');
            }

            form.addEventListener('submit', function (event) {
                event.preventDefault();

                errorBox.classList.remove('visible');
                errorBox.textContent = '';
                setLoading(true);

                {{--
                    2026-08-19: route()の絶対URL版(scheme+host付き)を使うと、
                    Render上でLaravelがX-Forwarded-Protoを信頼していない
                    (trustProxies未設定、bootstrap/app.php参照)ため、実際は
                    https://leggenda-hp-backend.onrender.com から配信された
                    ページ内で http://scheme のURLが生成されてしまい、fetch()が
                    mixed content(ブラウザのコンソールでは"Failed to fetch")
                    としてブロックされていた(依頼者指摘)。route()の第三引数
                    $absoluteをfalseにし、host/schemeを含まないパス相対URL
                    (例: /admin/auth)を生成する ―― ブラウザは常に現在の
                    ページのoriginに対して解決するため、frontend origin
                    (leggenda-hp.onrender.com)へ飛ぶことも無い(依頼者指定)。
                --}}
                fetch('{{ route('admin.auth', [], false) }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        username: document.getElementById('username').value,
                        password: document.getElementById('password').value,
                    }),
                })
                    .then(function (response) {
                        var contentType = response.headers.get('content-type') || '';

                        // 2026-08-19: CSRFトークン不一致(419)等でLaravelの
                        // HTML「Page Expired」ページが返っていたことがあり、
                        // response.json()がそのまま失敗して"Unexpected token
                        // '<'"がユーザーに見えていた(依頼者指摘)。まず
                        // Content-Typeを確認し、JSONでなければ本文を読まずに
                        // ステータスコードから汎用メッセージを組み立てる
                        // (認証情報はログに出さない、bodyの内容だけ診断用に
                        // console.errorへ残す)。
                        if (! contentType.includes('application/json')) {
                            return response.text().then(function (text) {
                                console.error('Admin auth returned a non-JSON response', {
                                    status: response.status,
                                    url: response.url,
                                    redirected: response.redirected,
                                    contentType: contentType,
                                    bodyPreview: text.substring(0, 300),
                                });

                                throw new Error(STATUS_MESSAGES[response.status] || 'サーバーから予期しないレスポンスが返されました。');
                            });
                        }

                        return response.json().then(function (data) {
                            if (response.ok) {
                                window.location.href = '{{ route('admin.dashboard', [], false) }}';
                                return;
                            }

                            throw new Error(STATUS_MESSAGES[response.status] || data.message || 'ログインに失敗しました。');
                        });
                    })
                    .catch(function (error) {
                        showError(error.message || 'ログインに失敗しました。');
                        setLoading(false);
                    });
            });
        })();
    </script>
</body>
</html>
