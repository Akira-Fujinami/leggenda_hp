<?php

namespace Tests\Concerns;

use ZipArchive;

/**
 * 依頼BI: 実物の営業資料は持ち込めない(社外秘のため)ため、有効な最小限の
 * PPTX(スライドサイズ・「参照元」ページの有無を選べる)を自作して検証に使う。
 * 依頼BG/BHのAdminComparisonPptxInserterTest/AdminComparisonPptxInsertTestが
 * 個別に持っていたのと同じ組み立てロジックを、BIで追加するテスト向けに
 * 共通化した(既存2ファイルの実装はそのまま、影響範囲を広げないため)。
 */
trait MakesTestPptxDecks
{
    /**
     * @param  list<string>  $slideTexts  各スライドの本文
     */
    protected function makeMinimalPptxBytes(array $slideTexts, int $sldSzCx = 12192000, int $sldSzCy = 6858000): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test-deck').'.pptx';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('ppt/theme/theme1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="F"/>');
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldMaster xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>');
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $this->pptxRels([['Id' => 'rId1', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme', 'Target' => '../theme/theme1.xml']]));
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldLayout xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>');
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $this->pptxRels([['Id' => 'rId1', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster', 'Target' => '../slideMasters/slideMaster1.xml']]));

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
            $zip->addFromString("ppt/slides/_rels/slide{$n}.xml.rels", $this->pptxRels([['Id' => 'rId1', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout', 'Target' => '../slideLayouts/slideLayout1.xml']]));
            $overrides["/ppt/slides/slide{$n}.xml"] = 'application/vnd.openxmlformats-officedocument.presentationml.slide+xml';
            $rId = 'rId'.$rid;
            $presRels[] = ['Id' => $rId, 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide', 'Target' => "slides/slide{$n}.xml"];
            $sldIds[] = ['id' => $sid, 'rId' => $rId];
            $rid++;
            $sid++;
        }

        $zip->addFromString('ppt/_rels/presentation.xml.rels', $this->pptxRels($presRels));
        $sldIdListXml = implode('', array_map(fn ($e) => '<p:sldId id="'.$e['id'].'" r:id="'.$e['rId'].'"/>', $sldIds));
        $zip->addFromString('ppt/presentation.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst><p:sldIdLst>'.$sldIdListXml.'</p:sldIdLst><p:sldSz cx="'.$sldSzCx.'" cy="'.$sldSzCy.'"/><p:notesSz cx="6858000" cy="9144000"/></p:presentation>');

        $typesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'.implode('', array_map(fn ($p, $t) => '<Override PartName="'.$p.'" ContentType="'.$t.'"/>', array_keys($overrides), $overrides)).'</Types>';
        $zip->addFromString('[Content_Types].xml', $typesXml);
        $zip->addFromString('_rels/.rels', $this->pptxRels([['Id' => 'rId1', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument', 'Target' => 'ppt/presentation.xml']]));

        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * @param  list<array{Id: string, Type: string, Target: string}>  $relationships
     */
    private function pptxRels(array $relationships): string
    {
        $body = implode('', array_map(
            fn (array $r) => '<Relationship Id="'.$r['Id'].'" Type="'.$r['Type'].'" Target="'.$r['Target'].'"/>',
            $relationships,
        ));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$body.'</Relationships>';
    }
}
