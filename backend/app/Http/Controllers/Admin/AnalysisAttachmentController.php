<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Analysis;
use App\Models\AnalysisAttachment;
use App\Services\Admin\AnalysisAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 依頼AD-1(2026-08-27): 診断(Analysis)への既存資料アップロード・ダウンロード・
 * 削除。admin.auth配下のみ(リード向けの公開エンドポイントは一切追加しない、
 * 依頼者の必須要件)。
 */
class AnalysisAttachmentController extends Controller
{
    public function __construct(private readonly AnalysisAttachmentService $attachments) {}

    public function store(Request $request, Analysis $analysis): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file']]);

        $this->attachments->store($analysis, $request->file('file'));

        return back()->with('status', '資料をアップロードしました。');
    }

    /**
     * ダウンロードは必ずこのコントローラ経由とし、Storage::disk()->download()
     * (Content-Disposition: attachmentを付与、ブラウザにインライン表示させない)
     * を使う。storage_path(UUID)を直接公開URLとして配信しない。
     */
    public function download(Analysis $analysis, AnalysisAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->analysis_id === $analysis->id, 404);
        abort_unless(Storage::disk('analysis')->exists($attachment->storage_path), 404);

        return Storage::disk('analysis')->download($attachment->storage_path, $attachment->original_filename, [
            'Content-Type' => $attachment->mime_type,
        ]);
    }

    public function destroy(Analysis $analysis, AnalysisAttachment $attachment): RedirectResponse
    {
        abort_unless($attachment->analysis_id === $analysis->id, 404);

        $this->attachments->delete($attachment);

        return back()->with('status', '資料を削除しました。');
    }
}
