<?php

namespace App\Console\Commands;

use App\Models\LeadSession;
use App\Models\Project;
use App\Services\Analysis\AnalysisService;
use App\Services\Lead\LeadSessionService;
use App\Services\WebsiteService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * 非本番環境限定。#99の実測作業のために、既存データを一切書き換えず、
 * 毎回新規にテスト用LeadSessionを発行して診断を開始し、アクセス用の
 * ワンタイムURLを標準出力へ表示する。
 *
 * 製品機能としての「同じURLからの再診断」を可能にするものではない ――
 * 本番の「1トークン1回」という制限(config('lead.max_analyses_per_token'))は
 * このコマンドが作るセッションにもそのまま適用される。実行回数の消費を
 * 通常の診断開始(LeadAnalysisController::store())と同じ経路
 * (LeadSessionService::recordAnalysisStarted())で記録するため、
 * production環境の挙動を一切変えない。何度でも診断を回したい場合は、
 * このコマンドを都度実行して新しいセッション/トークンを都度発行すること。
 *
 * リード情報はテスト用のダミー値(会社名・氏名は固定文字列、メールアドレスは
 * @example.com)を使う。実在しそうな値は使わない。
 *
 * 【重要】このコマンドの出力(ワンタイムURL)にはトークンが含まれる。
 * 実行結果をログファイルや報告に貼らないこと。
 */
#[Signature('lead:issue-test-session {self_url : 自社サイトのURL} {competitor_url? : 競合サイトのURL(任意)}')]
#[Description('非本番環境限定: テスト用のLeadSessionを新規発行し、診断を開始してワンタイムURLを表示する(#99の実測作業向け)')]
class IssueTestLeadSessionCommand extends Command
{
    public function handle(
        LeadSessionService $leadSessions,
        WebsiteService $websites,
        AnalysisService $analyses,
    ): int {
        if (app()->environment('production')) {
            $this->error('production環境ではこのコマンドを実行できません。');

            return self::FAILURE;
        }

        $selfUrl = (string) $this->argument('self_url');
        $competitorUrl = $this->argument('competitor_url');

        $token = Str::random(64);
        $dummyEmail = sprintf('lead-test-%s@example.com', Str::lower(Str::random(10)));

        // LeadSessionService::createOrReuse()の「同じメールアドレスなら既存
        // セッションを再利用する」経路は使わない ―― 既存データを書き換えず、
        // 毎回新規にLeadSessionを作成する方式にするため。
        $session = LeadSession::query()->create([
            'company_name' => 'テスト株式会社',
            'contact_name' => 'テスト太郎',
            'email' => $dummyEmail,
            'phone' => null,
            'industry' => null,
            'employee_range' => null,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays((int) config('lead.token_expiry_days')),
            'analyses_used' => 0,
        ]);

        $sentinelUser = $leadSessions->sentinelUser();

        $project = new Project(['name' => 'テスト株式会社 様セルフ診断(CLI発行)']);
        $project->user_id = $sentinelUser->id;
        $project->lead_session_id = $session->id;
        $project->save();

        $websites->create($project, ['name' => '自社サイト', 'url' => $selfUrl, 'is_primary' => true]);

        if (! empty($competitorUrl)) {
            $websites->create($project, ['name' => '比較サイト', 'url' => (string) $competitorUrl, 'is_primary' => false]);
        }

        $analyses->start($project, [
            'max_websites' => (int) config('lead.max_websites'),
            'skip_lighthouse' => (bool) config('lead.skip_lighthouse'),
            'skip_screenshots' => (bool) config('lead.skip_screenshots'),
        ], $sentinelUser);

        $leadSessions->recordAnalysisStarted($session);

        $frontendUrl = rtrim((string) config('cors.frontend_url'), '/');
        $url = "{$frontendUrl}/lead/diagnose?token={$token}";

        $this->info('テスト用リードセッションを発行し、診断を開始しました。');
        $this->line($url);
        $this->warn('このURLにはトークンが含まれます。ログファイルや報告に貼らないでください。');

        return self::SUCCESS;
    }
}
