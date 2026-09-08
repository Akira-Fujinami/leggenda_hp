<?php

namespace Tests\Feature\Admin;

use App\Enums\AnalysisStatus;
use App\Enums\ReportFormat;
use App\Enums\ReportGenerationStatus;
use App\Models\Analysis;
use App\Models\AnalysisAttachment;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Models\BrandWheelAnalysisResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * 依頼BG: 多社比較スライドの営業資料(PPTX)への差し込みダウンロード。
 */
class AdminComparisonPptxInsertTest extends TestCase
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

    private function makeLeadAnalysis(): Analysis
    {
        $company = LeadCompany::factory()->create();
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_company_id = $company->id;
        $project->save();

        return Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
    }

    private function makeComparisonAnalysis(): Analysis
    {
        $company = LeadCompany::factory()->create();

        $sourceProject = new Project(['name' => '起点']);
        $sourceProject->user_id = User::factory()->create()->id;
        $sourceProject->lead_company_id = $company->id;
        $sourceProject->save();
        $sourceAnalysis = Analysis::factory()->create(['project_id' => $sourceProject->id, 'status' => AnalysisStatus::Completed]);

        $project = new Project(['name' => '比較']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_company_id = $company->id;
        $project->save();

        $analysis = Analysis::factory()->create([
            'project_id' => $project->id,
            'status' => AnalysisStatus::Completed,
            'source_analysis_id' => $sourceAnalysis->id,
        ]);

        $this->addSite($analysis, true, 0, 'self', []);
        $this->addSite($analysis, false, 1, 'competitor', []);

        return $analysis;
    }

    private function addSite(Analysis $analysis, bool $isPrimary, int $displayOrder, string $name, array $axes): WebsiteAnalysis
    {
        $website = Website::factory()->create([
            'project_id' => $analysis->project_id,
            'is_primary' => $isPrimary,
            'display_order' => $displayOrder,
            'name' => $name,
            'url' => "https://{$name}.example.com",
        ]);
        $wa = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $wa->id,
            'status' => 'success',
            'axes' => $axes,
        ]);

        return $wa;
    }

    private function attachPptx(Analysis $analysis, string $pptxBytes, string $originalFilename = '営業資料.pptx'): AnalysisAttachment
    {
        $path = "attachments/{$analysis->id}/test.pptx";
        Storage::disk('analysis')->put($path, $pptxBytes);

        return AnalysisAttachment::factory()->create([
            'analysis_id' => $analysis->id,
            'original_filename' => $originalFilename,
            'storage_path' => $path,
            'extension' => 'pptx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ]);
    }

    /**
     * show.blade.phpの「営業資料に差し込む」導線は、既存の比較PDFレポート行
     * (format=pdf, status=completed)の中に並べて出すため、画面表示系のテスト
     * ではこの行を用意する必要がある。
     */
    private function makeCompletedPdfReport(Analysis $analysis): Report
    {
        $path = "reports/{$analysis->id}/admin-comparison-report.pdf";
        Storage::disk('analysis')->put($path, 'pdf-bytes');

        return Report::factory()->create([
            'analysis_id' => $analysis->id,
            'format' => ReportFormat::Pdf->value,
            'storage_path' => $path,
            'status' => ReportGenerationStatus::Completed->value,
            'generated_at' => now(),
        ]);
    }

    private function attachPdf(Analysis $analysis): AnalysisAttachment
    {
        $path = "attachments/{$analysis->id}/test.pdf";
        Storage::disk('analysis')->put($path, 'pdf-bytes');

        return AnalysisAttachment::factory()->create([
            'analysis_id' => $analysis->id,
            'original_filename' => '営業資料.pdf',
            'storage_path' => $path,
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
        ]);
    }

    /**
     * @param  list<string>  $slideTexts
     */
    private function makeMinimalDeck(array $slideTexts, int $sldSzCx = 12192000, int $sldSzCy = 6858000): string
    {
        $path = tempnam(sys_get_temp_dir(), 'feature-deck').'.pptx';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('ppt/theme/theme1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="F"/>');
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldMaster xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>');
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $this->rels([['Id' => 'rId1', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme', 'Target' => '../theme/theme1.xml']]));
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldLayout xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>');
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $this->rels([['Id' => 'rId1', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster', 'Target' => '../slideMasters/slideMaster1.xml']]));

        $overrides = [
            '/ppt/presentation.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml',
            '/ppt/slideMasters/slideMaster1.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml',
            '/ppt/slideLayouts/slideLayout1.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml',
            '/ppt/theme/theme1.xml' => 'application/vnd.openxmlformats-officedocument.theme+xml',
        ];
        $presRels = [['Id' => 'rId1', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster', 'Target' => 'slideMasters/slideMaster1.xml']];
        $sldIds = [];
        $rid = 2;
        $sid = 256;

        foreach ($slideTexts as $i => $text) {
            $n = $i + 1;
            $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>'.htmlspecialchars($text, ENT_QUOTES | ENT_XML1).'</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>';
            $zip->addFromString("ppt/slides/slide{$n}.xml", $xml);
            $zip->addFromString("ppt/slides/_rels/slide{$n}.xml.rels", $this->rels([['Id' => 'rId1', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout', 'Target' => '../slideLayouts/slideLayout1.xml']]));
            $overrides["/ppt/slides/slide{$n}.xml"] = 'application/vnd.openxmlformats-officedocument.presentationml.slide+xml';
            $rId = 'rId'.$rid;
            $presRels[] = ['Id' => $rId, 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide', 'Target' => "slides/slide{$n}.xml"];
            $sldIds[] = ['id' => $sid, 'rId' => $rId];
            $rid++;
            $sid++;
        }

        $zip->addFromString('ppt/_rels/presentation.xml.rels', $this->rels($presRels));
        $sldIdListXml = implode('', array_map(fn ($e) => '<p:sldId id="'.$e['id'].'" r:id="'.$e['rId'].'"/>', $sldIds));
        $zip->addFromString('ppt/presentation.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst><p:sldIdLst>'.$sldIdListXml.'</p:sldIdLst><p:sldSz cx="'.$sldSzCx.'" cy="'.$sldSzCy.'"/><p:notesSz cx="6858000" cy="9144000"/></p:presentation>');

        $typesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'.implode('', array_map(fn ($p, $t) => '<Override PartName="'.$p.'" ContentType="'.$t.'"/>', array_keys($overrides), $overrides)).'</Types>';
        $zip->addFromString('[Content_Types].xml', $typesXml);
        $zip->addFromString('_rels/.rels', $this->rels([['Id' => 'rId1', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument', 'Target' => 'ppt/presentation.xml']]));

        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function rels(array $relationships): string
    {
        $body = implode('', array_map(fn ($r) => '<Relationship Id="'.$r['Id'].'" Type="'.$r['Type'].'" Target="'.$r['Target'].'"/>', $relationships));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$body.'</Relationships>';
    }

    // ------------------------------------------------------------------
    // 正常系。
    // ------------------------------------------------------------------

    public function test_admin_can_download_a_pptx_with_the_comparison_slide_inserted(): void
    {
        $analysis = $this->makeComparisonAnalysis();
        $deck = $this->makeMinimalDeck(['内容1', '参照元']);
        $this->attachPptx($analysis, $deck, '御提案資料.pptx');

        $response = $this->asAdmin()->get(route('admin.analyses.comparison-report.pptx-insert', $analysis->id, false));

        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('比較ページ差し込み', rawurldecode((string) $response->headers->get('Content-Disposition')));

        $bytes = $response->streamedContent();
        $tmp = tempnam(sys_get_temp_dir(), 'downloaded').'.pptx';
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $presentationXml = $zip->getFromName('ppt/presentation.xml');
        // "<p:sldId "(空白始まり)で絞る ―― "<p:sldIdLst>"(一覧の開始タグ自体)を
        // 誤って1件と数えないようにするため。
        preg_match_all('/<p:sldId\s/', $presentationXml, $m);
        $this->assertCount(3, $m[0], '元の2枚+差し込み1枚=3枚になっていること');
        $zip->close();
        @unlink($tmp);
    }

    public function test_the_analysis_show_page_shows_the_insert_link_when_a_pptx_is_attached(): void
    {
        $analysis = $this->makeComparisonAnalysis();
        $this->makeCompletedPdfReport($analysis);
        $this->attachPptx($analysis, $this->makeMinimalDeck(['内容1', '参照元']));

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee(route('admin.analyses.comparison-report.pptx-insert', $analysis->id, false), false);
        // 比較PDFの既存のダウンロード導線も残っていること。
        $response->assertSee(route('admin.analyses.comparison-report.download', $analysis->id, false), false);
    }

    // ------------------------------------------------------------------
    // エラー経路(想定内の中止): 参照元ページが無い・サイズ不一致。
    // ------------------------------------------------------------------

    public function test_it_redirects_with_a_message_when_no_reference_page_exists(): void
    {
        $analysis = $this->makeComparisonAnalysis();
        $this->attachPptx($analysis, $this->makeMinimalDeck(['内容1', '内容2']));

        $response = $this->asAdmin()->get(route('admin.analyses.comparison-report.pptx-insert', $analysis->id, false));

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertStringContainsString('参照元', (string) session('status'));
    }

    public function test_it_redirects_with_a_message_when_slide_size_does_not_match(): void
    {
        $analysis = $this->makeComparisonAnalysis();
        $this->attachPptx($analysis, $this->makeMinimalDeck(['内容1', '参照元'], 9144000, 6858000));

        $response = $this->asAdmin()->get(route('admin.analyses.comparison-report.pptx-insert', $analysis->id, false));

        $response->assertRedirect();
        $this->assertStringContainsString('スライドサイズ', (string) session('status'));
    }

    // ------------------------------------------------------------------
    // 404: 添付なし/PDF/DOCX/source_analysis_idがnull。
    // ------------------------------------------------------------------

    public function test_it_is_not_found_when_there_is_no_attachment(): void
    {
        $analysis = $this->makeComparisonAnalysis();

        $this->asAdmin()->get(route('admin.analyses.comparison-report.pptx-insert', $analysis->id, false))->assertNotFound();
    }

    public function test_it_is_not_found_when_the_attachment_is_pdf(): void
    {
        $analysis = $this->makeComparisonAnalysis();
        $this->attachPdf($analysis);

        $this->asAdmin()->get(route('admin.analyses.comparison-report.pptx-insert', $analysis->id, false))->assertNotFound();
    }

    public function test_it_is_not_found_for_a_regular_lead_analysis(): void
    {
        $analysis = $this->makeLeadAnalysis();

        $this->asAdmin()->get(route('admin.analyses.comparison-report.pptx-insert', $analysis->id, false))->assertNotFound();
    }

    public function test_the_show_page_hides_the_insert_link_and_shows_a_reason_when_there_is_no_pptx(): void
    {
        $analysis = $this->makeComparisonAnalysis();
        $this->makeCompletedPdfReport($analysis);
        $this->attachPdf($analysis);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertDontSee(route('admin.analyses.comparison-report.pptx-insert', $analysis->id, false), false);
        $response->assertSee('営業資料(PPTX)をアップロードすると');
    }

    // ------------------------------------------------------------------
    // 回帰確認: 既存の比較PDFダウンロードが変わらず動くこと。
    // ------------------------------------------------------------------

    public function test_existing_comparison_pdf_download_route_is_unaffected(): void
    {
        $analysis = $this->makeComparisonAnalysis();

        // レポート行が無い状態でも、既存エンドポイントの404挙動は
        // このBGの変更と無関係にそのまま(回帰確認)。
        $this->asAdmin()->get(route('admin.analyses.comparison-report.download', $analysis->id, false))->assertNotFound();
    }
}
