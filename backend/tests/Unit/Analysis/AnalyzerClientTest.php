<?php

namespace Tests\Unit\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Exceptions\Analysis\AnalysisException;
use App\Services\Analysis\AnalyzerClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AnalyzerClientのエラー分類の回帰テスト。
 * 502/504やRenderのプロキシが返す非JSON応答は、analyzerアプリ自身が
 * 返す正常なJSONエラー(401/429/503等)とは別のANALYZER_GATEWAY_ERRORへ
 * 分類する(2026-07-25の障害調査で判明した分類不足の修正)。
 */
class AnalyzerClientTest extends TestCase
{
    private function client(): AnalyzerClient
    {
        return app(AnalyzerClient::class);
    }

    public function test_502_is_classified_as_gateway_error(): void
    {
        Http::fake(['*/analyze/lighthouse' => Http::response('<html>Bad Gateway</html>', 502, ['Content-Type' => 'text/html'])]);

        try {
            $this->client()->lighthouse('https://example.com');
            $this->fail('Expected AnalysisException');
        } catch (AnalysisException $e) {
            $this->assertSame(AnalysisErrorCode::AnalyzerGatewayError, $e->errorCode);
        }
    }

    public function test_504_is_classified_as_gateway_error(): void
    {
        Http::fake(['*/analyze/render' => Http::response('<html>Gateway Timeout</html>', 504, ['Content-Type' => 'text/html'])]);

        try {
            $this->client()->render('https://example.com');
            $this->fail('Expected AnalysisException');
        } catch (AnalysisException $e) {
            $this->assertSame(AnalysisErrorCode::AnalyzerGatewayError, $e->errorCode);
        }
    }

    public function test_non_json_body_on_200_is_classified_as_gateway_error(): void
    {
        // Renderのプロキシがステータス200のままHTML等を返す壊れたケースを想定。
        Http::fake(['*/analyze/technology' => Http::response('not json', 200, ['Content-Type' => 'text/plain'])]);

        try {
            $this->client()->technology('https://example.com');
            $this->fail('Expected AnalysisException');
        } catch (AnalysisException $e) {
            $this->assertSame(AnalysisErrorCode::AnalyzerGatewayError, $e->errorCode);
        }
    }

    public function test_401_is_still_classified_as_auth_failed_not_gateway_error(): void
    {
        Http::fake(['*/analyze/lighthouse' => Http::response(['success' => false, 'error' => ['code' => 'UNAUTHORIZED']], 401)]);

        try {
            $this->client()->lighthouse('https://example.com');
            $this->fail('Expected AnalysisException');
        } catch (AnalysisException $e) {
            $this->assertSame(AnalysisErrorCode::AnalyzerAuthFailed, $e->errorCode);
        }
    }

    public function test_a_normal_json_error_body_is_still_classified_using_the_error_code_field(): void
    {
        Http::fake(['*/analyze/render' => Http::response([
            'success' => false,
            'data' => null,
            'error' => ['code' => 'RENDER_FAILED', 'message' => 'timed out'],
        ], 500)]);

        try {
            $this->client()->render('https://example.com');
            $this->fail('Expected AnalysisException');
        } catch (AnalysisException $e) {
            $this->assertSame(AnalysisErrorCode::RenderFailed, $e->errorCode);
        }
    }

    /**
     * analyzerがOOM kill・クラッシュ・再起動の途中でTCP接続を確立済みのまま
     * 切断した場合("connection reset by peer"/"socket hang up"相当、Guzzleの
     * RequestException(レスポンス無し))について、当初は
     * Illuminate\Http\Client\ConnectionException(接続確立自体の失敗のみを
     * ラップする狭いもの)では捕捉できず、UNKNOWN_ERRORへ丸められてしまう
     * 不具合を疑い専用コードを追加しようとしたが、実際にはLaravelの
     * PendingRequest::marshalRequestExceptionWithoutResponse()がこの
     * ケースも含めて全てConnectionExceptionへ正規化済みであることが
     * vendorソース確認で判明した(2026-07-25 Analyzer OOM調査)。
     * この回帰テストは、その正規化に実際に乗って
     * ANALYZER_UNAVAILABLE(UNKNOWN_ERRORではない)に分類されることを確認する。
     */
    public function test_a_mid_request_connection_reset_is_classified_as_analyzer_unavailable_not_an_unknown_error(): void
    {
        Http::fake(['*/analyze/technology' => function () {
            throw new RequestException('cURL error 56: Recv failure: Connection reset by peer', new Request('POST', '/analyze/technology'));
        }]);

        try {
            $this->client()->technology('https://example.com');
            $this->fail('Expected AnalysisException');
        } catch (AnalysisException $e) {
            $this->assertSame(AnalysisErrorCode::AnalyzerUnavailable, $e->errorCode);
            $this->assertTrue($e->errorCode->isRetryable());
        }
    }
}
