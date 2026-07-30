<?php

namespace App\Services\BrandWheel;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * ヘキサゴンSVGをPNGにラスタライズする。rsvg-convert(backend Dockerイメージの
 * base stageに導入済み、librsvg2-bin)をSymfony\Processで直接呼び出す ――
 * Imagick拡張(未導入・policy.xml絡みで不安定)やヘッドレスブラウザは使わない。
 * Analyzerサービス(Node.js/Playwright、OOM歴あり)には一切触れない。
 *
 * --background-colorで不透過の背景色を焼き込む ―― 透過PNGにすると、
 * Outlook/Apple Mail等のダークモード自動反転後に判読不能になるため
 * (2026-07-30の指摘)。ダークモード版は作らずライトモード固定のパレットを使う。
 *
 * rsvg-convert自体が使えない/失敗した場合は例外を投げずnullを返す ――
 * 画像はメール本文の補助情報であり、無くても内容(HTMLの表)だけで伝わる
 * 設計のため、画像生成の失敗がメール送信全体をブロックしてはならない。
 */
class BrandWheelHexagonRenderer
{
    /** SVGのviewBox(380x316)に対する2倍解像度。 */
    private const int WIDTH_PX = 760;

    private const int HEIGHT_PX = 632;

    private const string BACKGROUND_COLOR = '#fcfcfb';

    public function renderPng(string $svg): ?string
    {
        $process = new Process([
            'rsvg-convert',
            '-w', (string) self::WIDTH_PX,
            '-h', (string) self::HEIGHT_PX,
            '--background-color', self::BACKGROUND_COLOR,
        ]);
        $process->setInput($svg);
        $process->setTimeout(10);

        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::error('Brand wheel hexagon rasterization threw an exception', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $process->isSuccessful()) {
            Log::error('Brand wheel hexagon rasterization failed', [
                'exit_code' => $process->getExitCode(),
                'stderr' => $process->getErrorOutput(),
            ]);

            return null;
        }

        $png = $process->getOutput();

        return $png !== '' ? $png : null;
    }
}
