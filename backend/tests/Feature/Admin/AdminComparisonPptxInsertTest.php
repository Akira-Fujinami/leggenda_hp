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
        // 依頼DE: このファイルの既存のテストは、求職者が知りたい情報のスライドが入る前提で枚数・順番を確かめている。既定は入れないので、ここではtrueにする。
        config(['admin_comparison_pptx.survey_comparison_enabled' => true]);
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
            // 依頼CJ-1: 「比較かどうか」はkindで判定するようになったため、
            // source_analysis_idと一緒にkindも明示的に設定する。
            'kind' => \App\Enums\AnalysisKind::AdminComparison,
        ]);

        $this->addSite($analysis, true, 0, 'self', []);
        $this->addSite($analysis, false, 1, 'competitor', []);

        return $analysis;
    }

    /**
     * 依頼CJ-2(2026-10-01): 無料診断を経由しない比較(起点のProject/Analysisが
     * 存在しない、source_analysis_idはnullのまま)。
     */
    private function makeStandaloneComparisonAnalysis(): Analysis
    {
        $company = LeadCompany::factory()->create();

        $project = new Project(['name' => '比較']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_company_id = $company->id;
        $project->save();

        $analysis = Analysis::factory()->create([
            'project_id' => $project->id,
            'status' => AnalysisStatus::Completed,
            'kind' => \App\Enums\AnalysisKind::AdminComparison,
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
    /**
     * 依頼BS-3(2026-09-11): tempnam()が拡張子無しで予約した実体を、
     * rename()でそのまま最終パスとして使い回す(依頼BJ-3/BR-3と同じ方式)。
     * `tempnam(...).'.ext'`のように別パスへ書き込むと、tempnam()自身が
     * 作った拡張子無しの実体が/tmpに残り続ける。
     */
    private function reservedTempPath(string $prefix, string $extension): string
    {
        $reserved = tempnam(sys_get_temp_dir(), $prefix);
        $path = $reserved.'.'.$extension;
        rename($reserved, $path);

        return $path;
    }

    private function makeMinimalDeck(array $slideTexts, int $sldSzCx = 12192000, int $sldSzCy = 6858000): string
    {
        $path = $this->reservedTempPath('feature-deck', 'pptx');

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
        $tmp = $this->reservedTempPath('downloaded', 'pptx');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $presentationXml = $zip->getFromName('ppt/presentation.xml');
        // "<p:sldId "(空白始まり)で絞る ―― "<p:sldIdLst>"(一覧の開始タグ自体)を
        // 誤って1件と数えないようにするため。
        preg_match_all('/<p:sldId\s/', $presentationXml, $m);
        // 依頼CB-4: 差し込みは説明→比較→足りないもの→階層図の4枚になった。
        // 依頼CL-4(2026-10-05): 「求職者が知りたい情報と、自社サイト」(CL-2)を
        // 加えた5枚になった。
        $this->assertCount(7, $m[0], '元の2枚+差し込み5枚(説明+比較+足りないもの+求職者が知りたい情報+階層図)=7枚になっていること');
        $zip->close();
        @unlink($tmp);
    }

    /**
     * 依頼CT-2: PPTXのダウンロードでは、保存済みの「枝ごとの情報」のファイルを読むだけ。AIを呼ばず、外部へ通信しない。
     */
    public function test_the_download_reads_the_stored_branch_survey_result_without_calling_the_ai_or_the_network(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake();
        app()->instance(\App\Services\BranchSurveyItems\BranchSurveyItemsProviderFactory::class, new class extends \App\Services\BranchSurveyItems\BranchSurveyItemsProviderFactory
        {
            public function make(): \App\Services\BranchSurveyItems\BranchSurveyItemsProvider
            {
                throw new \RuntimeException('ダウンロードでAIの設定を作ってはいけない');
            }
        });
        $analysis = $this->makeComparisonAnalysis();
        $self = $analysis->websiteAnalyses()->whereHas('website', fn ($q) => $q->where('is_primary', true))->firstOrFail();
        app(\App\Services\BranchSurveyItems\BranchSurveyItemsStore::class)->write($analysis->id, $self->id, ['status' => 'done', 'top' => null, 'branches' => [], 'job_failed' => 'x']);
        $this->attachPptx($analysis, $this->makeMinimalDeck(['内容1', '参照元']));

        $response = $this->asAdmin()->get(route('admin.analyses.comparison-report.pptx-insert', $analysis->id, false));

        $response->assertOk();
        $response->streamedContent();
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    /**
     * 依頼CJ-2(2026-10-01): 無料診断を経由しない比較(起点なし)でも、
     * 差し込みダウンロードは無改修で動く(kind=AdminComparisonのみを見る
     * ため、source_analysis_idの有無に依存しない)。
     */
    public function test_admin_can_download_a_pptx_with_the_comparison_slide_inserted_for_a_standalone_comparison(): void
    {
        $analysis = $this->makeStandaloneComparisonAnalysis();
        $this->assertNull($analysis->source_analysis_id);
        $deck = $this->makeMinimalDeck(['内容1', '参照元']);
        $this->attachPptx($analysis, $deck, '御提案資料.pptx');

        $response = $this->asAdmin()->get(route('admin.analyses.comparison-report.pptx-insert', $analysis->id, false));

        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
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
        // 依頼BI-3: 実際の寸法(cm)を文言に出すこと(依頼者指定)。
        $this->assertStringContainsString('4:3', (string) session('status'));
        $this->assertStringContainsString('cm', (string) session('status'));
    }

    /**
     * 依頼BK: ダウンロード時の経路でも、許容差(既定1200EMU)以内のずれは
     * 弾かれず、差し込みが成功すること。比較作成フォーム・詳細画面の
     * 添付欄と同じ判定になること(3経路すべての確認、依頼BK指定)。
     */
    public function test_a_deck_1200_emu_off_still_downloads_successfully(): void
    {
        $analysis = $this->makeComparisonAnalysis();
        $this->attachPptx($analysis, $this->makeMinimalDeck(['内容1', '参照元'], 12192000 - 1200, 6858000 - 1200));

        $response = $this->asAdmin()->get(route('admin.analyses.comparison-report.pptx-insert', $analysis->id, false));

        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
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
        // 依頼BW-1(2026-09-11): この案内文言は「レポート」節から
        // 「いまやること」パネル(②のアップロード欄)へ移った。
        $response->assertSee('営業資料(PPTX)を添付すると');
    }

    // ------------------------------------------------------------------
    // 依頼CX: サイトの第一印象(社内用・下書き)。PPTXのダウンロードでは、保存済みのファイルを読むだけ(AIも通信も無い)。
    // ------------------------------------------------------------------

    public function test_the_download_adds_the_first_impression_slide_after_the_hierarchy_slide_from_the_saved_file_without_the_ai_or_the_network(): void
    {
        config(['site_first_impression.enabled' => true]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake();
        app()->instance(\App\Services\SiteFirstImpression\SiteFirstImpressionProviderFactory::class, new class extends \App\Services\SiteFirstImpression\SiteFirstImpressionProviderFactory
        {
            public function make(): \App\Services\SiteFirstImpression\SiteFirstImpressionProvider
            {
                throw new \RuntimeException('ダウンロードでAIの設定を作ってはいけない');
            }
        });
        $analysis = $this->makeComparisonAnalysis();
        app(\App\Services\SiteFirstImpression\SiteFirstImpressionStore::class)->write($analysis->id, [
            'status' => 'done', 'generated_at' => now()->toIso8601String(),
            'page' => [
                'company_name' => '自社テスト',
                'good' => [['title' => '良い点の見出しです', 'body' => '良い点の本文です。', 'evidence' => [['id' => 'corp-nav-1', 'text' => 'x']]]],
                'bad' => [['title' => '悪い点の見出しです', 'body' => '悪い点の本文です。', 'evidence' => [['id' => 'corp-nav-1', 'text' => 'x']]]],
                'sources' => [['label' => 'コーポレートTOP', 'url' => 'https://corp.example.com/'], ['label' => '採用TOP', 'url' => 'https://self.example.com/recruit/']],
            ],
        ]);
        $this->attachPptx($analysis, $this->makeMinimalDeck(['内容1', '参照元']));

        $response = $this->asAdmin()->get(route('admin.analyses.comparison-report.pptx-insert', $analysis->id, false));

        $response->assertOk();
        $tmp = $this->reservedTempPath('downloaded', 'pptx');
        file_put_contents($tmp, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        preg_match_all('/<p:sldId\s/', (string) $zip->getFromName('ppt/presentation.xml'), $m);
        $this->assertCount(8, $m[0], '元の2枚 + 差し込み5枚 + 第一印象1枚(階層図の次)');
        // 最後に差し込んだ1枚(第一印象)が、階層図の次にある。
        $slides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $n) === 1) {
                $slides[(int) $n[1]] = html_entity_decode((string) $zip->getFromName($name), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        $zip->close();
        @unlink($tmp);
        $found = array_keys(array_filter($slides, fn (string $xml) => str_contains($xml, 'サイトの第一印象（求職者目線）')));
        $this->assertCount(1, $found);
        $this->assertStringContainsString('良い点の見出しです', $slides[$found[0]]);
        $this->assertStringNotContainsString('<p:pic', $slides[$found[0]], '画像はスライドに入れない');
        \Illuminate\Support\Facades\Http::assertNothingSent();

        // 無効のとき・ページが作られなかったときは、入れない(7枚のまま)。
        config(['site_first_impression.enabled' => false]);
        $off = $this->asAdmin()->get(route('admin.analyses.comparison-report.pptx-insert', $analysis->id, false));
        $tmp = $this->reservedTempPath('downloaded', 'pptx');
        file_put_contents($tmp, $off->streamedContent());
        $zip = new ZipArchive;
        $zip->open($tmp);
        preg_match_all('/<p:sldId\s/', (string) $zip->getFromName('ppt/presentation.xml'), $m);
        $zip->close();
        @unlink($tmp);
        $this->assertCount(7, $m[0]);
    }

    // ------------------------------------------------------------------
    // 依頼CU: 強み・弱みの一文(AI)。PPTXのダウンロードでは、保存済みのファイルを読むだけ。
    // ------------------------------------------------------------------

    /**
     * @return array{0: Analysis, 1: string} 比較(自社と競合が読み取れている)と、強みの一文
     */
    private function makeComparisonWithCommentary(): array
    {
        $analysis = $this->makeComparisonAnalysis();
        $axes = fn (array $keys) => [['axis_key' => 'will_activity', 'matched_sub_elements' => array_map(fn (string $k) => ['key' => $k, 'evidence' => "{$k}の根拠"], $keys), 'discarded_sub_elements' => [], 'claimed_sub_element_count' => count($keys), 'state' => 'partial']];
        foreach ($analysis->websiteAnalyses()->with('website')->get() as $wa) {
            BrandWheelAnalysisResult::query()->where('website_analysis_id', $wa->id)->delete();
            BrandWheelAnalysisResult::factory()->create([
                'analysis_id' => $analysis->id,
                'website_analysis_id' => $wa->id,
                'status' => 'success',
                'axes' => $axes($wa->website->is_primary ? ['purpose', 'business_expansion'] : ['business_expansion']),
            ]);
        }
        $sentence = 'AIが書いた弱みの一文です。';
        app(\App\Services\StrengthWeaknessSentence\StrengthWeaknessSentenceStore::class)->write($analysis->id, [
            'status' => 'done', 'skipped' => null,
            'strength' => null,
            'weakness' => ['sentence' => $sentence, 'quote' => '引用の言葉をここに入れます'],
            'rejected' => ['strength' => ['quote_bad_ending'], 'weakness' => []],
        ]);
        $this->attachPptx($analysis, $this->makeMinimalDeck(['内容1', '参照元']));

        return [$analysis, $sentence];
    }

    /** ダウンロードしたPPTXの全スライドのテキスト(XMLの実体参照は戻す)。 */
    private function downloadedSlideText(\Illuminate\Testing\TestResponse $response): string
    {
        $tmp = $this->reservedTempPath('downloaded', 'pptx');
        file_put_contents($tmp, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $text = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide\d+\.xml$#', $name) === 1) {
                $text .= html_entity_decode((string) $zip->getFromName($name), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        $zip->close();
        @unlink($tmp);

        return $text;
    }

    public function test_the_download_uses_the_stored_ai_sentence_and_adds_the_note_without_calling_the_ai_or_the_network(): void
    {
        // 依頼CUAの一節の文を有効にしていても、出力経路から外してあるので、出ない(依頼CV)。
        config(['strength_weakness_sentence.enabled' => true, 'admin_comparison_pptx.strength_weakness_detail.enabled' => true]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake();
        app()->instance(\App\Services\StrengthWeaknessSentence\StrengthWeaknessSentenceProviderFactory::class, new class extends \App\Services\StrengthWeaknessSentence\StrengthWeaknessSentenceProviderFactory
        {
            public function make(): \App\Services\StrengthWeaknessSentence\StrengthWeaknessSentenceProvider
            {
                throw new \RuntimeException('ダウンロードでAIの設定を作ってはいけない');
            }
        });
        [$analysis, $sentence] = $this->makeComparisonWithCommentary();

        $response = $this->asAdmin()->get(route('admin.analyses.comparison-report.pptx-insert', $analysis->id, false));

        $response->assertOk();
        $text = $this->downloadedSlideText($response);
        $this->assertStringContainsString($sentence, $text);
        $this->assertStringContainsString((string) config('strength_weakness_sentence.note'), $text);
        // 確認で落ちた側(強み)は、点数から作ったCT-1の文のまま。機械の一節の文(依頼CUA)は出ない。
        $this->assertStringContainsString('強み：', $text);
        $this->assertStringNotContainsString((string) config('admin_comparison_pptx.strength_weakness_detail.note'), $text);
        $this->assertStringNotContainsString('と記載しています', $text);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_when_disabled_the_download_ignores_the_stored_sentence_and_shows_no_note(): void
    {
        config(['strength_weakness_sentence.enabled' => false, 'admin_comparison_pptx.strength_weakness_detail.enabled' => true]);
        [$analysis, $sentence] = $this->makeComparisonWithCommentary();

        $response = $this->asAdmin()->get(route('admin.analyses.comparison-report.pptx-insert', $analysis->id, false));

        $text = $this->downloadedSlideText($response);
        $this->assertStringNotContainsString($sentence, $text);
        $this->assertStringNotContainsString((string) config('admin_comparison_pptx.strength_weakness_detail.note'), $text);
        $this->assertStringNotContainsString((string) config('strength_weakness_sentence.note'), $text);
        $this->assertStringContainsString('強み：', $text);
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
