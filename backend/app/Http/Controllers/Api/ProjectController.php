<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\ProjectDetailResource;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $projects)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $paginator = $request->user()
                ->projects()
                ->withCount('websites')
                ->orderByDesc('updated_at')
                ->paginate(12);
        } catch (Throwable $e) {
            // Cookie値・セッションID・DBパスワード等の機微情報は含めない。
            // request_idはAssignRequestIdミドルウェアがLog::withContext()で
            // 全ログ行に自動付与するため、ここで明示的に含める必要はない。
            Log::error('projects.index_failed', [
                'endpoint' => 'GET /api/projects',
                'user_id' => $request->user()?->id,
                'exception' => $e::class,
                'status' => 500,
            ]);

            throw $e;
        }

        return $this->success(
            ProjectResource::collection($paginator->items()),
            [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
        );
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = $this->projects->create($request->user(), $request->validated());

        return $this->success(new ProjectResource($project), [], '作成しました。', 201);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $project->load('websites');

        return $this->success(new ProjectDetailResource($project));
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $project = $this->projects->update($project, $request->validated());

        return $this->success(new ProjectResource($project), [], '更新しました。');
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        $this->projects->delete($project);

        return $this->success([], [], '削除しました。');
    }
}
