<?php

namespace Tests\Feature\Admin;

use App\Models\Analysis;
use App\Models\AnalysisAttachment;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼AD-1(2026-08-27): 診断への既存資料アップロード。フォーマットが
 * 未確定のため、内容を解釈しない「受け取って保管する」部分のみを対象とする。
 */
class AnalysisAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
    }

    private function asAdmin(): static
    {
        return $this->withSession(['admin_authenticated' => true]);
    }

    private function makeAnalysis(): Analysis
    {
        $company = LeadCompany::factory()->create();
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_company_id = $company->id;
        $project->save();

        return Analysis::factory()->create(['project_id' => $project->id]);
    }

    private function pdf(string $name = 'material.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF",
        );
    }

    /**
     * 診断詳細画面(admin.analyses.show)に「既存資料」カードを追加した際の
     * Bladeの構文崩れ・未定義変数を検知するスモークテスト(資料あり/なしの
     * 両方)。
     */
    public function test_the_analysis_show_page_renders_with_and_without_an_attachment(): void
    {
        $analysis = $this->makeAnalysis();

        $this->asAdmin()->get("/admin/analyses/{$analysis->id}")
            ->assertOk()
            ->assertSee('アップロードされた資料はありません。');

        $this->asAdmin()->post("/admin/analyses/{$analysis->id}/attachment", ['file' => $this->pdf('企業説明資料.pdf')]);

        $this->asAdmin()->get("/admin/analyses/{$analysis->id}")
            ->assertOk()
            ->assertSee('企業説明資料.pdf');
    }

    // ------------------------------------------------------------------
    // 正常系: アップロード・ダウンロード・削除
    // ------------------------------------------------------------------

    public function test_an_allowed_file_can_be_uploaded_and_is_linked_to_the_analysis(): void
    {
        $analysis = $this->makeAnalysis();

        $this->asAdmin()->post("/admin/analyses/{$analysis->id}/attachment", [
            'file' => $this->pdf('企業説明資料.pdf'),
        ])->assertRedirect();

        $attachment = AnalysisAttachment::where('analysis_id', $analysis->id)->first();
        $this->assertNotNull($attachment);
        $this->assertSame('企業説明資料.pdf', $attachment->original_filename);
        $this->assertSame('pdf', $attachment->extension);
        Storage::disk('analysis')->assertExists($attachment->storage_path);
    }

    /**
     * 依頼者必須要件: クライアントが送ってきたファイル名を保存パスに使わない。
     * UUID等サーバ生成の保存名であることを確認する(元のファイル名の
     * 断片が一切含まれない)。
     */
    public function test_storage_path_does_not_contain_the_client_supplied_filename(): void
    {
        $analysis = $this->makeAnalysis();

        $this->asAdmin()->post("/admin/analyses/{$analysis->id}/attachment", [
            'file' => $this->pdf('SECRET-CLIENT-NAME.pdf'),
        ])->assertRedirect();

        $attachment = AnalysisAttachment::where('analysis_id', $analysis->id)->firstOrFail();
        $this->assertStringNotContainsString('SECRET-CLIENT-NAME', $attachment->storage_path);
        $this->assertMatchesRegularExpression(
            '#^attachments/'.$analysis->id.'/[0-9a-f-]{36}\.pdf$#',
            $attachment->storage_path,
        );
    }

    /**
     * パストラバーサルを試みるファイル名を送っても、保存パスは破られない
     * (保存パスがUUID決め打ちのため、そもそもクライアント入力を経由しない)。
     */
    public function test_path_traversal_attempts_in_the_filename_do_not_escape_the_attachment_directory(): void
    {
        $analysis = $this->makeAnalysis();

        $this->asAdmin()->post("/admin/analyses/{$analysis->id}/attachment", [
            'file' => $this->pdf('../../../../etc/passwd.pdf'),
        ])->assertRedirect();

        $attachment = AnalysisAttachment::where('analysis_id', $analysis->id)->firstOrFail();
        $this->assertStringStartsWith("attachments/{$analysis->id}/", $attachment->storage_path);
        $this->assertStringNotContainsString('..', $attachment->storage_path);
        // Symfony\Component\HttpFoundation\File\UploadedFile自体が
        // getClientOriginalName()からパス区切り文字を除去するため、
        // original_filenameにも"../"は残らない(多重防御の1つ)。
        // 重要なのは、保存パス(storage_path)がUUID決め打ちで、
        // クライアント入力を一切経由しないこと。
        $this->assertStringNotContainsString('..', $attachment->original_filename);
        $this->assertStringEndsWith('passwd.pdf', $attachment->original_filename);
    }

    public function test_downloading_returns_the_original_filename_as_an_attachment(): void
    {
        $analysis = $this->makeAnalysis();
        $this->asAdmin()->post("/admin/analyses/{$analysis->id}/attachment", [
            'file' => $this->pdf('企業説明資料.pdf'),
        ]);
        $attachment = AnalysisAttachment::where('analysis_id', $analysis->id)->firstOrFail();

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}/attachment/{$attachment->id}");

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('企業説明資料.pdf', rawurldecode($disposition));
    }

    public function test_deleting_removes_both_the_database_row_and_the_file_on_disk(): void
    {
        $analysis = $this->makeAnalysis();
        $this->asAdmin()->post("/admin/analyses/{$analysis->id}/attachment", ['file' => $this->pdf()]);
        $attachment = AnalysisAttachment::where('analysis_id', $analysis->id)->firstOrFail();
        $path = $attachment->storage_path;

        $this->asAdmin()->delete("/admin/analyses/{$analysis->id}/attachment/{$attachment->id}")->assertRedirect();

        $this->assertNull(AnalysisAttachment::find($attachment->id));
        Storage::disk('analysis')->assertMissing($path);
    }

    /**
     * 差し替え: 新しいファイルをアップロードすると、既存の1件が置き換わる
     * (単一スロット運用)。
     */
    public function test_uploading_again_replaces_the_existing_attachment(): void
    {
        $analysis = $this->makeAnalysis();
        $this->asAdmin()->post("/admin/analyses/{$analysis->id}/attachment", ['file' => $this->pdf('old.pdf')]);
        $old = AnalysisAttachment::where('analysis_id', $analysis->id)->firstOrFail();
        $oldPath = $old->storage_path;

        $this->asAdmin()->post("/admin/analyses/{$analysis->id}/attachment", ['file' => $this->pdf('new.pdf')]);

        $this->assertSame(1, AnalysisAttachment::where('analysis_id', $analysis->id)->count());
        $new = AnalysisAttachment::where('analysis_id', $analysis->id)->firstOrFail();
        $this->assertSame('new.pdf', $new->original_filename);
        Storage::disk('analysis')->assertMissing($oldPath);
    }

    // ------------------------------------------------------------------
    // セキュリティ: 拡張子ホワイトリスト・マジックバイト・サイズ上限
    // ------------------------------------------------------------------

    public function test_a_disallowed_extension_is_rejected(): void
    {
        $analysis = $this->makeAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$analysis->id}/attachment", [
            'file' => UploadedFile::fake()->createWithContent('malware.exe', 'MZ'),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, AnalysisAttachment::where('analysis_id', $analysis->id)->count());
    }

    /**
     * 依頼者の必須要件: HTML/SVG等スクリプトが動きうる形式を許可しない。
     * .htmlはそもそも拡張子ホワイトリストに含まれないため弾かれる。
     */
    public function test_html_files_are_rejected_by_the_extension_whitelist(): void
    {
        $analysis = $this->makeAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$analysis->id}/attachment", [
            'file' => UploadedFile::fake()->createWithContent('page.html', '<html><script>alert(1)</script></html>'),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, AnalysisAttachment::where('analysis_id', $analysis->id)->count());
    }

    /**
     * 依頼者の必須要件・最重要: 拡張子は許可されているが、中身が別形式
     * (ここではHTML)のファイルは、マジックバイト検証で弾かれる。
     */
    public function test_a_file_with_an_allowed_extension_but_mismatched_content_is_rejected(): void
    {
        $analysis = $this->makeAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$analysis->id}/attachment", [
            'file' => UploadedFile::fake()->createWithContent('fake.pdf', '<html><script>alert(1)</script></html>'),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, AnalysisAttachment::where('analysis_id', $analysis->id)->count());
    }

    public function test_an_svg_disguised_with_an_allowed_extension_is_rejected(): void
    {
        $analysis = $this->makeAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$analysis->id}/attachment", [
            'file' => UploadedFile::fake()->createWithContent('image.pdf', '<svg onload="alert(1)"></svg>'),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, AnalysisAttachment::where('analysis_id', $analysis->id)->count());
    }

    /**
     * docx/pptx(実体はzip)は、正規のファイルであることを確認する
     * (誤検知でmagicチェックを通らない、という回帰を防ぐ)。
     */
    public function test_a_genuine_docx_file_is_accepted(): void
    {
        $analysis = $this->makeAnalysis();
        $zip = new \ZipArchive();
        $tmpPath = tempnam(sys_get_temp_dir(), 'docx');
        $zip->open($tmpPath, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
        $zip->addFromString('word/document.xml', '<document/>');
        $zip->close();

        $response = $this->asAdmin()->post("/admin/analyses/{$analysis->id}/attachment", [
            'file' => UploadedFile::fake()->createWithContent('proposal.docx', (string) file_get_contents($tmpPath)),
        ]);
        unlink($tmpPath);

        $response->assertSessionDoesntHaveErrors('file');
        $this->assertSame(1, AnalysisAttachment::where('analysis_id', $analysis->id)->count());
    }

    public function test_a_file_exceeding_the_configured_size_limit_is_rejected(): void
    {
        // フェイクPDFの中身(約70バイト)より確実に小さい上限にする。
        config(['analysis_attachment.max_file_size_bytes' => 10]);
        $analysis = $this->makeAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$analysis->id}/attachment", [
            'file' => $this->pdf(),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, AnalysisAttachment::where('analysis_id', $analysis->id)->count());
    }

    public function test_upload_is_rejected_once_the_aggregate_storage_budget_is_exceeded(): void
    {
        config(['analysis_attachment.total_storage_limit_bytes' => 10]);
        $analysis = $this->makeAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$analysis->id}/attachment", [
            'file' => $this->pdf(),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, AnalysisAttachment::where('analysis_id', $analysis->id)->count());
    }

    // ------------------------------------------------------------------
    // 認証: admin.auth配下のみ。
    // ------------------------------------------------------------------

    public function test_unauthenticated_upload_is_blocked(): void
    {
        $analysis = $this->makeAnalysis();

        $response = $this->post("/admin/analyses/{$analysis->id}/attachment", ['file' => $this->pdf()]);

        $response->assertStatus(401);
        $this->assertSame(0, AnalysisAttachment::where('analysis_id', $analysis->id)->count());
    }

    /**
     * admin.auth配下のGETは、未認証時401ではなく認証モーダルのみのビュー
     * (admin.guest)を返す仕様(EnsureAdminAuthenticatedのdocblock参照、
     * AdminComparisonTest::test_unauthenticated_access_is_blocked()と
     * 同じ既存仕様)。重要なのは、ファイルの中身が一切返らないこと。
     */
    /**
     * withSession()で立てた認証状態は、同一テスト内の後続リクエストにも
     * 引き継がれてしまう(TestCaseのセッションはメソッド内で共有される)ため、
     * 「未認証」を検証するテストではasAdmin()経由でattachmentを作らず、
     * 直接factory + fakeディスクへの書き込みで用意する。
     */
    private function makeAttachmentDirectly(Analysis $analysis): AnalysisAttachment
    {
        $path = "attachments/{$analysis->id}/".\Illuminate\Support\Str::uuid().'.pdf';
        Storage::disk('analysis')->put($path, '%PDF-1.4 dummy');

        return AnalysisAttachment::factory()->create([
            'analysis_id' => $analysis->id,
            'original_filename' => 'material.pdf',
            'storage_path' => $path,
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 14,
        ]);
    }

    public function test_unauthenticated_download_does_not_return_the_file(): void
    {
        $analysis = $this->makeAnalysis();
        $attachment = $this->makeAttachmentDirectly($analysis);

        $response = $this->get("/admin/analyses/{$analysis->id}/attachment/{$attachment->id}");

        $response->assertOk();
        $this->assertNull($response->headers->get('Content-Disposition'));
    }

    public function test_unauthenticated_delete_is_blocked(): void
    {
        $analysis = $this->makeAnalysis();
        $attachment = $this->makeAttachmentDirectly($analysis);

        $response = $this->delete("/admin/analyses/{$analysis->id}/attachment/{$attachment->id}");

        $response->assertStatus(401);
        $this->assertNotNull(AnalysisAttachment::find($attachment->id));
    }

    /**
     * IDOR対策: 別の診断に属するattachment idを、他の診断のURLへ渡しても
     * 削除・ダウンロードできない。
     */
    public function test_an_attachment_cannot_be_accessed_through_a_different_analysis_id(): void
    {
        $analysisA = $this->makeAnalysis();
        $analysisB = $this->makeAnalysis();
        $this->asAdmin()->post("/admin/analyses/{$analysisA->id}/attachment", ['file' => $this->pdf()]);
        $attachment = AnalysisAttachment::where('analysis_id', $analysisA->id)->firstOrFail();

        $this->asAdmin()->get("/admin/analyses/{$analysisB->id}/attachment/{$attachment->id}")->assertNotFound();
        $this->asAdmin()->delete("/admin/analyses/{$analysisB->id}/attachment/{$attachment->id}")->assertNotFound();
        $this->assertNotNull(AnalysisAttachment::find($attachment->id));
    }

    /**
     * どちらの診断種別にも付けられること(依頼者指定)。
     */
    public function test_an_attachment_can_be_added_to_a_comparison_analysis(): void
    {
        $company = LeadCompany::factory()->create();
        $sourceProject = new Project(['name' => '起点']);
        $sourceProject->user_id = User::factory()->create()->id;
        $sourceProject->lead_company_id = $company->id;
        $sourceProject->save();
        $sourceAnalysis = Analysis::factory()->create(['project_id' => $sourceProject->id]);

        $project = new Project(['name' => '比較']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_company_id = $company->id;
        $project->save();
        $comparison = Analysis::factory()->create(['project_id' => $project->id, 'source_analysis_id' => $sourceAnalysis->id]);

        $this->asAdmin()->post("/admin/analyses/{$comparison->id}/attachment", ['file' => $this->pdf()])
            ->assertRedirect();

        $this->assertSame(1, AnalysisAttachment::where('analysis_id', $comparison->id)->count());
    }
}
