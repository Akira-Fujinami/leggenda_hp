<?php

namespace App\Services\Admin;

use App\Models\Analysis;
use App\Models\AnalysisAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 依頼AD-1(2026-08-27): 診断(Analysis)への既存資料アップロード。フォーマットが
 * 未確定のため、内容を解釈する処理は一切持たない(受け取って保管するだけ)。
 *
 * 【セキュリティ方針、依頼者の必須要件】
 * - 保存パスにクライアント提供のファイル名を一切使わない(storagePath()参照。
 *   original_filenameはDBに保持し、表示・ダウンロードのファイル名にのみ使う)。
 * - 拡張子はconfig('analysis_attachment.allowed_extensions')のホワイトリストで
 *   弾く。
 * - 拡張子だけでなく、finfo(マジックバイト)で検出した実際のMIMEタイプが
 *   ALLOWED_MIME_TYPES_BY_EXTENSIONと一致するかを検証する(拡張子を
 *   偽装したHTML/SVG等を弾く)。
 * - 1診断あたり1件(単一スロット)。差し替えは「新しいファイルを保存して
 *   成功したら古いものを消す」順序で行い、アップロード失敗時に既存の資料を
 *   失わないようにする。
 *
 * 【1診断あたりの上限】現時点では1件に制限する(依頼者の想定どおり、
 * 差し替えUIも1件を前提にする)。将来複数件が必要になった場合は、
 * このクラスの「新規保存時に既存を全て削除する」処理を外すだけで対応でき、
 * マイグレーションは不要(analysis_attachments.analysis_idにunique制約を
 * 付けていないため)。
 */
class AnalysisAttachmentService
{
    /**
     * 拡張子ごとに許可する実際のMIMEタイプ(finfoの検出結果)。
     * docx/pptxはOOXML(実体はzip)のため、libmagicのバージョンによっては
     * 内部のコンテンツタイプまで判別せずapplication/zipとして検出される
     * ことを実機で確認済み(2026-08-27実測)。誤ってapplication/zipを
     * 一律拒否すると正規のdocx/pptxまで弾いてしまうため、両方を許可する。
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_MIME_TYPES_BY_EXTENSION = [
        'pdf' => ['application/pdf'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
        'pptx' => [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/zip',
        ],
    ];

    public function store(Analysis $analysis, UploadedFile $file): AnalysisAttachment
    {
        $extension = $this->assertExtensionAllowed($file);
        $this->assertContentMatchesExtension($file, $extension);
        $this->assertSizeWithinPerFileLimit($file);
        $this->assertWithinStorageBudget($analysis, $file->getSize());

        $storedName = Str::uuid()->toString().'.'.$extension;
        $storagePath = $this->storagePath($analysis->id, $storedName);

        Storage::disk($this->diskName())->put($storagePath, (string) file_get_contents($file->getRealPath()));

        $attachment = AnalysisAttachment::create([
            'analysis_id' => $analysis->id,
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'extension' => $extension,
            'mime_type' => $this->detectMimeType($file),
            'size_bytes' => $file->getSize(),
        ]);

        // 単一スロット運用: 新しいファイルの保存・DB作成が成功した後に、
        // 同じ診断の既存の資料を削除する(差し替え中にアップロードが失敗
        // しても、既存の資料を失わないようにするための順序)。
        $analysis->attachments()
            ->where('id', '!=', $attachment->id)
            ->get()
            ->each(fn (AnalysisAttachment $old) => $this->delete($old));

        return $attachment;
    }

    public function delete(AnalysisAttachment $attachment): void
    {
        $path = $attachment->storage_path;
        $attachment->delete();

        try {
            Storage::disk($this->diskName())->delete($path);
        } catch (\Throwable $e) {
            // 依頼M-2(PurgeExpiredLeadSessions)と同じ方針: ファイル削除は
            // ベストエフォート。DBの削除自体は既に完了しているため、失敗しても
            // ログに残すだけで例外は投げ直さない。
            Log::warning('Failed to delete analysis attachment file after DB deletion', [
                'analysis_attachment_path' => $path,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function assertExtensionAllowed(UploadedFile $file): string
    {
        // getClientOriginalName()はクライアント入力(信用しない) ―― ここでは
        // 「拡張子として何を名乗っているか」の判定にのみ使い、保存パスには
        // 一切使わない(storagePath()はUUIDのみを使う)。
        $extension = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $allowed = (array) config('analysis_attachment.allowed_extensions');

        if (! in_array($extension, $allowed, true)) {
            throw ValidationException::withMessages([
                'file' => ['この拡張子は許可されていません(許可: '.implode('/', $allowed).')。'],
            ]);
        }

        return $extension;
    }

    /**
     * 拡張子だけを信用せず、実際の中身(マジックバイト)で検証する
     * (依頼者の必須要件)。finfo(ext-fileinfo)はサーバ側でファイルの
     * バイト列を読んで判定するため、クライアントが送るContent-Typeヘッダ
     * (容易に偽装できる)とは異なり信頼できる。
     */
    private function assertContentMatchesExtension(UploadedFile $file, string $extension): void
    {
        $detectedMimeType = $this->detectMimeType($file);
        $allowedMimeTypes = self::ALLOWED_MIME_TYPES_BY_EXTENSION[$extension] ?? [];

        if (! in_array($detectedMimeType, $allowedMimeTypes, true)) {
            throw ValidationException::withMessages([
                'file' => ['ファイルの中身が拡張子と一致しないため、アップロードできません。'],
            ]);
        }
    }

    private function detectMimeType(UploadedFile $file): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file->getRealPath());
        finfo_close($finfo);

        return $mimeType !== false ? $mimeType : 'application/octet-stream';
    }

    private function assertSizeWithinPerFileLimit(UploadedFile $file): void
    {
        $maxBytes = (int) config('analysis_attachment.max_file_size_bytes');

        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => ['ファイルサイズが上限('.$this->formatBytes($maxBytes).')を超えています。'],
            ]);
        }
    }

    /**
     * ディスク(/var/analysis-storage、巡回結果と共用・9.8GB)をこの機能だけで
     * 圧迫しないよう、全診断合計の上限を設ける(依頼者指定)。差し替え対象の
     * 診断が既に持っている資料のサイズは、差し替え後に削除される前提のため
     * 集計から除く。
     */
    private function assertWithinStorageBudget(Analysis $analysis, int $newFileSize): void
    {
        $currentTotal = (int) AnalysisAttachment::query()->sum('size_bytes');
        $thisAnalysisExisting = (int) AnalysisAttachment::query()->where('analysis_id', $analysis->id)->sum('size_bytes');
        $projectedTotal = $currentTotal - $thisAnalysisExisting + $newFileSize;
        $limit = (int) config('analysis_attachment.total_storage_limit_bytes');

        if ($projectedTotal > $limit) {
            throw ValidationException::withMessages([
                'file' => ['既存資料の保存容量の上限に達しているため、アップロードできません。管理者にお問い合わせください。'],
            ]);
        }
    }

    /**
     * 巡回結果の保存領域(App\Services\Analysis\AnalysisStoragePaths、
     * analyses/{id}/websites/{id}/...)とは物理的に別のディレクトリに置く
     * (依頼者指定)。$storedNameはUUID(store()側で生成済み)のみで
     * 構成されるため、パストラバーサルの余地がない。
     */
    private function storagePath(int $analysisId, string $storedName): string
    {
        return "attachments/{$analysisId}/{$storedName}";
    }

    private function diskName(): string
    {
        return (string) config('analysis.storage_disk', 'analysis');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024 ** 2) {
            return round($bytes / 1024, 1).'KB';
        }

        return round($bytes / 1024 ** 2, 1).'MB';
    }
}
