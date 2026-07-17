<?php

namespace App\Services\Analysis;

/**
 * `analysis` disk(config/filesystems.php)上のディレクトリレイアウトを一箇所に集約する。
 * パスはAnalysis/WebsiteAnalysisの数値ID(DBで存在確認済み)のみから組み立てるため、
 * 利用者入力によるパストラバーサルの余地はない。
 *
 * レイアウト: analyses/{analysis_id}/websites/{website_analysis_id}/{raw,screenshots,metadata}/
 */
class AnalysisStoragePaths
{
    public function rawDir(int $analysisId, int $websiteAnalysisId): string
    {
        return $this->base($analysisId, $websiteAnalysisId).'/raw';
    }

    public function screenshotsDir(int $analysisId, int $websiteAnalysisId): string
    {
        return $this->base($analysisId, $websiteAnalysisId).'/screenshots';
    }

    public function metadataDir(int $analysisId, int $websiteAnalysisId): string
    {
        return $this->base($analysisId, $websiteAnalysisId).'/metadata';
    }

    public function rawHtmlPath(int $analysisId, int $websiteAnalysisId, string $filename): string
    {
        return $this->rawDir($analysisId, $websiteAnalysisId).'/'.$filename;
    }

    public function screenshotPath(int $analysisId, int $websiteAnalysisId, string $filename): string
    {
        return $this->screenshotsDir($analysisId, $websiteAnalysisId).'/'.$filename;
    }

    public function metadataPath(int $analysisId, int $websiteAnalysisId, string $filename): string
    {
        return $this->metadataDir($analysisId, $websiteAnalysisId).'/'.$filename;
    }

    private function base(int $analysisId, int $websiteAnalysisId): string
    {
        return "analyses/{$analysisId}/websites/{$websiteAnalysisId}";
    }
}
