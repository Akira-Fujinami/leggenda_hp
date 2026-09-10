<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\Report\ComparisonSlideInsertionException;
use App\Http\Controllers\Controller;
use App\Models\Analysis;
use App\Models\AnalysisAttachment;
use App\Services\Admin\AnalysisAttachmentService;
use App\Services\Report\AdminComparisonPptxInserter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 依頼AD-1(2026-08-27): 診断(Analysis)への既存資料アップロード・ダウンロード・
 * 削除。admin.auth配下のみ(リード向けの公開エンドポイントは一切追加しない、
 * 依頼者の必須要件)。
 */
class AnalysisAttachmentController extends Controller
{
    public function __construct(
        private readonly AnalysisAttachmentService $attachments,
        private readonly AdminComparisonPptxInserter $pptxInserter,
    ) {}

    public function store(Request $request, Analysis $analysis): RedirectResponse
    {
        // 依頼BR-1(2026-09-11): $request->validate(['file' => ['required', 'file']])
        // だと、PHP層(upload_max_filesize)で弾かれたアップロードがLaravel
        // 標準の英語メッセージ("The file failed to upload.")で落ちてしまう
        // (AnalysisAttachmentService::assertUploadSucceeded()参照)。
        // 'required'相当の判定も含め、このクラスで日本語のまま完結させる。
        $file = $request->file('file');
        $this->attachments->assertUploadSucceeded($file, 'file');
        if ($file === null) {
            throw ValidationException::withMessages(['file' => ['ファイルを選択してください。']]);
        }

        // 依頼BI-3: PPTXの場合のみ、スライドサイズ・参照元ページの検証も
        // かける(AdminComparisonPptxInserter::validate()、比較作成フォーム
        // (ComparisonController)と同じ判定を共有 ―― 経路によって通ったり
        // 通らなかったりしないようにするため)。PDF/DOCXにはこの検証を
        // 一切かけない(既存の3拡張子を引き続き受け付ける、依頼者指定)。
        $extension = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if ($extension === 'pptx') {
            try {
                $this->pptxInserter->validate($file->getRealPath());
            } catch (ComparisonSlideInsertionException $e) {
                throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
            }
        }

        $this->attachments->store($analysis, $file);

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
