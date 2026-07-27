<?php

namespace App\Http\Controllers\Api\Lead;

use App\Http\Controllers\Controller;
use App\Services\Lead\LeadSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * リード獲得フォームの受付。未ログインの公開エンドポイントであり、
 * auth:sanctumやPolicyは一切関与しない。個人情報(会社名・氏名・メール・
 * 電話番号)を扱うため、ログ・例外メッセージへそのまま出力しないこと。
 */
class LeadOnboardingController extends Controller
{
    public function __construct(private readonly LeadSessionService $leadSessions) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'industry' => ['nullable', 'string', 'max:255'],
            'employee_range' => ['nullable', 'string', 'max:100'],
            'privacy_policy_agreed' => ['required', 'accepted'],
        ]);

        unset($data['privacy_policy_agreed']);

        $result = $this->leadSessions->createOrReuse($data);

        return $this->success([
            'token' => $result['token'],
            'expires_at' => $result['session']->expires_at->toIso8601String(),
        ], [], null, 201);
    }
}
