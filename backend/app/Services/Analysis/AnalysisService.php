<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisStatus;
use App\Exceptions\Analysis\AnalysisAlreadyRunningException;
use App\Jobs\Analysis\StartAnalysisJob;
use App\Models\Analysis;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnalysisService
{
    /**
     * @param  array{website_ids?: array<int, int>, max_websites?: int, skip_lighthouse?: bool, skip_screenshots?: bool, skip_brand_wheel?: bool}  $data
     */
    public function start(Project $project, array $data, User $user): Analysis
    {
        return DB::transaction(function () use ($project, $data, $user) {
            // 同時リクエストでも「実行中の分析は1件まで」を守るため、
            // プロジェクト行をロックしてから既存の実行中Analysisを確認する。
            $lockedProject = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();

            $alreadyRunning = Analysis::query()
                ->where('project_id', $lockedProject->id)
                ->whereIn('status', [AnalysisStatus::Pending, AnalysisStatus::Queued, AnalysisStatus::Running])
                ->exists();

            if ($alreadyRunning) {
                throw new AnalysisAlreadyRunningException;
            }

            $websites = $this->resolveTargetWebsites($lockedProject, $data);

            $analysis = Analysis::query()->create([
                'project_id' => $lockedProject->id,
                'created_by' => $user->id,
                'status' => AnalysisStatus::Pending,
                'progress' => 0,
                // リード向け簡易分析(LeadAnalysisController)のみtrueを渡す。
                // 社内向けの既存呼び出し元はこれらのキーを渡さないため常にfalseで、
                // AnalysisPipelineの挙動は一切変わらない。
                'skip_lighthouse' => (bool) ($data['skip_lighthouse'] ?? false),
                'skip_screenshots' => (bool) ($data['skip_screenshots'] ?? false),
                // skip_brand_wheelは他の2つと既定値の向きが逆(既定true=実行
                // しない)。ブランド・ホイールはOpenAIへの課金呼び出しであり、
                // サイト本文を外部(OpenAI)へ送信する処理でもあるため、
                // 「指定を忘れたら黙って実行される」側を既定にしない ―― 将来
                // Analysisを作る経路が増えても、明示的にfalseを渡した呼び出し元
                // (LeadAnalysisController::store())だけが実行対象になる
                // (2026-08-03のユーザー指摘)。
                'skip_brand_wheel' => (bool) ($data['skip_brand_wheel'] ?? true),
                // 依頼L-1: LeadAnalysisController::store()のみ明示的に
                // config('lead.crawl_site')を渡す。他の呼び出し元は渡さない
                // ため常にfalseで、既存の挙動(トップページ・採用ページの
                // 2枚のみ)は一切変わらない。
                'crawl_site' => (bool) ($data['crawl_site'] ?? false),
            ]);

            foreach ($websites as $website) {
                $analysis->websiteAnalyses()->create([
                    'website_id' => $website->id,
                    'status' => \App\Enums\WebsiteAnalysisStatus::Pending,
                    'progress' => 0,
                ]);
            }

            StartAnalysisJob::dispatch($analysis->id)->onQueue('analysis');

            return $analysis->fresh(['websiteAnalyses']);
        });
    }

    /**
     * @param  array{website_ids?: array<int, int>, max_websites?: int}  $data
     * @return \Illuminate\Support\Collection<int, \App\Models\Website>
     */
    private function resolveTargetWebsites(Project $project, array $data): \Illuminate\Support\Collection
    {
        $maxWebsites = (int) ($data['max_websites'] ?? config('analysis.max_websites_per_analysis'));

        if (isset($data['website_ids']) && $data['website_ids'] !== []) {
            $requestedIds = array_values(array_unique($data['website_ids']));

            $websites = $project->websites()->whereIn('id', $requestedIds)->get();

            if ($websites->count() !== count($requestedIds)) {
                throw ValidationException::withMessages([
                    'website_ids' => ['指定されたサイトの中に、このプロジェクトに属さないものが含まれています。'],
                ]);
            }

            if ($websites->count() > $maxWebsites) {
                throw ValidationException::withMessages([
                    'website_ids' => ["一度に分析できるサイトは{$maxWebsites}件までです。"],
                ]);
            }

            return $websites;
        }

        $websites = $project->websites()->orderBy('display_order')->limit($maxWebsites)->get();

        if ($websites->isEmpty()) {
            throw ValidationException::withMessages([
                'website_ids' => ['分析対象のサイトが登録されていません。先にサイトを登録してください。'],
            ]);
        }

        return $websites;
    }
}
