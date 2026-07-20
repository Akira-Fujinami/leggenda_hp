<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiAnalysisResultResource;
use App\Jobs\GenerateAiAnalysisJob;
use App\Models\AiAnalysisResult;
use App\Models\WebsiteAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WebsiteAnalysis単位のAI分析(参考情報)を生成・取得する。
 * AI呼び出しはAPIコストが発生するため、以下を必ず守る:
 * - 実行中(pending/running)の重複生成は拒否する
 * - 直近のcooldown期間内の再生成は拒否する(confirm=trueで明示的に上書き可能)
 * - 所有権(Project経由)を必ず確認する
 */
class AiAnalysisController extends Controller
{
    private const COOLDOWN_SECONDS = 60;

    public function show(Request $request, WebsiteAnalysis $websiteAnalysis): JsonResponse
    {
        $this->authorize('view', $websiteAnalysis->analysis);

        $latest = AiAnalysisResult::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->latest('created_at')
            ->first();

        if ($latest === null) {
            return $this->success(null);
        }

        return $this->success(new AiAnalysisResultResource($latest));
    }

    public function store(Request $request, WebsiteAnalysis $websiteAnalysis): JsonResponse
    {
        $this->authorize('view', $websiteAnalysis->analysis);

        $validated = $request->validate([
            'confirm' => ['sometimes', 'boolean'],
        ]);
        $confirm = (bool) ($validated['confirm'] ?? false);

        $latest = AiAnalysisResult::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->latest('created_at')
            ->first();

        if ($latest !== null && in_array($latest->status, ['pending', 'running'], true)) {
            return $this->success(new AiAnalysisResultResource($latest), [], 'AI分析は既に実行中です。', 409);
        }

        if ($latest !== null) {
            // cooldownはconfirm=trueでも回避できない(連打によるAPIコスト浪費を防ぐため)。
            $secondsSinceLast = $latest->created_at?->diffInSeconds(now()) ?? self::COOLDOWN_SECONDS;

            if ($secondsSinceLast < self::COOLDOWN_SECONDS) {
                return response()->json([
                    'data' => new AiAnalysisResultResource($latest),
                    'meta' => ['needs_confirmation' => false, 'cooldown_remaining_seconds' => self::COOLDOWN_SECONDS - $secondsSinceLast],
                    'message' => 'しばらく待ってから再度実行してください(連続実行防止のためのcooldown)。',
                ], 429);
            }

            if (! $confirm) {
                return response()->json([
                    'data' => new AiAnalysisResultResource($latest),
                    'meta' => ['needs_confirmation' => true],
                    'message' => '既にAI分析結果が存在します。再生成するにはconfirm=trueを指定してください(APIコストが発生します)。',
                ], 409);
            }
        }

        $record = AiAnalysisResult::query()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'provider' => (string) config('services.ai.provider', 'mock'),
            'status' => 'pending',
            'is_mock' => false,
            'input_hash' => '',
        ]);

        GenerateAiAnalysisJob::dispatch($record->id)->onQueue('ai');

        return $this->success(new AiAnalysisResultResource($record), [], 'AI分析を開始しました。', 202);
    }
}
