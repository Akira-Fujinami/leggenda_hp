<?php

namespace App\Services\BrandWheel;

use App\Services\BrandWheel\Data\BrandWheelAnalysisInput;
use App\Services\BrandWheel\Data\BrandWheelAnalysisOutcome;

/**
 * ブランド・ホイール(6軸)分析Providerの抽象化。AiAnalysisProviderと同じ
 * 形だが、完全に別のインターフェースとして独立させている(既存のAI分析
 * (スコアリング結果の要約)とは無関係な別サブシステムのため)。
 */
interface BrandWheelAnalysisProvider
{
    /**
     * @throws BrandWheelAnalysisException
     */
    public function analyze(BrandWheelAnalysisInput $input): BrandWheelAnalysisOutcome;

    public function name(): string;

    /**
     * このProviderが実際に使うモデル名。mockはnull。呼び出し前(analyze()を
     * 呼ぶ前)にわかる必要がある ―― Jobがinput_hashへ含めるため
     * (2026-07-29の指摘: config/brand_wheel.phpの内容やモデルが変わっても
     * 同じ入力に対して古い結果が再利用され続けるのを防ぐ)。
     */
    public function model(): ?string;

    /**
     * このProviderが使うプロンプトのバージョン。mockはnull。model()と同じ理由で
     * analyze()を呼ぶ前にわかる必要がある。
     */
    public function promptVersion(): ?string;
}
