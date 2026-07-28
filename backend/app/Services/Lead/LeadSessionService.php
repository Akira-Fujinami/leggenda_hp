<?php

namespace App\Services\Lead;

use App\Models\LeadSession;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * リード獲得フォームの受付・ワンタイムトークンの発行/検証・分析実行回数の
 * 管理を担う。lead_sessionsは既存のUser/Sanctum認証とは完全に独立しており、
 * ここで扱うLeadSessionの属性(会社名・氏名・メール・電話番号)は個人情報のため
 * ログ・例外メッセージへ出力しないこと。
 */
class LeadSessionService
{
    /**
     * @param  array{company_name: string, contact_name: string, email: string, phone?: ?string, industry?: ?string, employee_range?: ?string}  $data
     * @return array{session: LeadSession, token: string}
     */
    public function createOrReuse(array $data): array
    {
        $existing = LeadSession::query()
            ->where('email', $data['email'])
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->first();

        $token = $this->generateToken();

        if ($existing !== null) {
            // 再訪を歓迎しつつ、analyses_used(実行回数の消費)はそのまま
            // 引き継ぐ ―― フォーム再送信だけで実行回数の上限を回避できて
            // しまわないようにするため。トークンは新しいものへ差し替え、
            // 有効期限も現在時刻基準で延長する(古いリンクは失効する)。
            $existing->update([
                'company_name' => $data['company_name'],
                'contact_name' => $data['contact_name'],
                'phone' => $data['phone'] ?? $existing->phone,
                'industry' => $data['industry'] ?? $existing->industry,
                'employee_range' => $data['employee_range'] ?? $existing->employee_range,
                'token_hash' => $this->hashToken($token),
                'expires_at' => now()->addDays((int) config('lead.token_expiry_days')),
            ]);

            return ['session' => $existing->fresh(), 'token' => $token];
        }

        $session = LeadSession::query()->create([
            'company_name' => $data['company_name'],
            'contact_name' => $data['contact_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'industry' => $data['industry'] ?? null,
            'employee_range' => $data['employee_range'] ?? null,
            'token_hash' => $this->hashToken($token),
            'expires_at' => now()->addDays((int) config('lead.token_expiry_days')),
            'analyses_used' => 0,
        ]);

        return ['session' => $session, 'token' => $token];
    }

    /**
     * 画面には「有効期限が切れています」等の理由の書き分けを一切出さない
     * (トークンの存在有無を推測されるとセキュリティ上望ましくないため)が、
     * サーバーログ側では「該当なし」と「期限切れ」を区別して記録する ――
     * リードから「開けない」と連絡が来た際に原因を切り分けられるようにする
     * ため(2026-07-29の実運用インシデントへの対応)。トークン値そのものは
     * 記録しない。
     */
    public function findValidByToken(string $plainToken): ?LeadSession
    {
        $session = LeadSession::query()->where('token_hash', $this->hashToken($plainToken))->first();

        if ($session === null) {
            Log::info('Lead token validation failed: not found');

            return null;
        }

        if ($session->isExpired()) {
            Log::info('Lead token validation failed: expired', ['lead_session_id' => $session->id]);

            return null;
        }

        return $session;
    }

    public function canStartAnalysis(LeadSession $session): bool
    {
        return $session->analyses_used < (int) config('lead.max_analyses_per_token');
    }

    public function recordAnalysisStarted(LeadSession $session): void
    {
        $session->increment('analyses_used');
    }

    /**
     * 相談リクエストの二重送信を防ぐ。同時押下・多重リクエストに対しても
     * 安全なよう、「consultation_requested_atがまだnullの行だけを更新する」
     * という条件付きUPDATEで一度だけ勝者を決める(read-then-writeのGET-CHECK-SET
     * だと競合が起こり得るため使わない)。
     *
     * @return bool  このリクエストが実際に更新した(=通知を送るべき)場合はtrue。
     *               既に送信済み(他のリクエストが先に更新した場合を含む)はfalse。
     */
    public function recordConsultationRequested(LeadSession $session): bool
    {
        $updated = LeadSession::query()
            ->where('id', $session->id)
            ->whereNull('consultation_requested_at')
            ->update(['consultation_requested_at' => now()]);

        return $updated > 0;
    }

    /**
     * リードのProject/Website/Analysisは既存のuser_id必須(FK制約)を満たす
     * ため、社内ユーザーとは別の固定sentinelユーザーが所有する。ログイン
     * 手段は付与しない(ランダムなハッシュ化パスワードのみ設定し、実際に
     * 使われることはない)。既存のProjectPolicy等はuser_id一致のみを見る
     * ため変更不要 ―― 社内ユーザーの一覧はこのsentinelユーザーのidと
     * 一致しない限り自然にリードのデータを含まない。
     */
    public function sentinelUser(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'lead-service@internal.invalid'],
            ['name' => 'Lead Self-Diagnosis (System)', 'password' => Hash::make(Str::random(64))],
        );
    }

    private function generateToken(): string
    {
        return Str::random(64);
    }

    private function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
