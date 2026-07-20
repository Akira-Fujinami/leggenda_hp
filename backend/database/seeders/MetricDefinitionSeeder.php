<?php

namespace Database\Seeders;

use App\Models\CategoryDefinition;
use App\Models\MetricDefinition;
use Illuminate\Database\Seeder;

/**
 * 評価項目のマスタ。各カテゴリ内の項目は「相対配点(points)」で定義し、
 * カテゴリの配点(CategoryDefinition.weight)にちょうど収まるよう
 * 自動的にスケーリングする ―― 手計算による端数ズレを避けるため。
 * 端数は最後の項目に寄せて、カテゴリ合計が必ず一致するようにする。
 *
 * ここに挙げた項目はPhase 3仕様書(section 10)の代表的な部分集合であり、
 * 全項目を網羅しているわけではない。scoring_type/not_found_policyの
 * バリエーションを一通り実データで検証できることを優先した
 * (是非は次PhaseでMetricDefinitionを追加登録することで拡張可能)。
 */
class MetricDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->categories() as $categoryKey => $metrics) {
            $category = CategoryDefinition::query()->where('key', $categoryKey)->first();

            if ($category === null) {
                throw new \RuntimeException("CategoryDefinition '{$categoryKey}' が見つかりません。先にCategoryDefinitionSeederを実行してください。");
            }

            $this->seedCategory($categoryKey, (float) $category->weight, $metrics);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $metrics
     */
    private function seedCategory(string $categoryKey, float $categoryWeight, array $metrics): void
    {
        $totalPoints = array_sum(array_column($metrics, 'points'));
        $scale = $totalPoints > 0 ? $categoryWeight / $totalPoints : 0;

        $runningTotal = 0.0;
        $lastScoredIndex = null;
        foreach ($metrics as $i => $metric) {
            if (($metric['points'] ?? 0) > 0) {
                $lastScoredIndex = $i;
            }
        }

        foreach ($metrics as $i => $metric) {
            $points = $metric['points'];
            unset($metric['points']);

            if ($points <= 0) {
                $maxScore = 0;
            } elseif ($i === $lastScoredIndex) {
                // 端数吸収: これまでの合計との差分をこの最後の項目に寄せる。
                $maxScore = round($categoryWeight - $runningTotal, 2);
            } else {
                $maxScore = round($points * $scale, 2);
                $runningTotal += $maxScore;
            }

            MetricDefinition::query()->updateOrCreate(
                ['key' => $metric['key']],
                $metric + [
                    'category_key' => $categoryKey,
                    'description' => null,
                    'unit' => $metric['unit'] ?? null,
                    'higher_is_better' => $metric['higher_is_better'] ?? true,
                    'minimum_value' => $metric['minimum_value'] ?? null,
                    'target_value' => $metric['target_value'] ?? null,
                    'maximum_value' => $metric['maximum_value'] ?? null,
                    'thresholds' => $metric['thresholds'] ?? null,
                    'is_required' => $metric['is_required'] ?? false,
                    'not_found_policy' => $metric['not_found_policy'] ?? 'zero',
                    'not_found_partial_rate' => $metric['not_found_partial_rate'] ?? null,
                    'recommendation_template' => $metric['recommendation_template'] ?? null,
                    'weight' => $metric['weight'] ?? 1,
                    'max_score' => $maxScore,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function categories(): array
    {
        return [
            'technical_seo' => $this->technicalSeoMetrics(),
            'content' => $this->contentMetrics(),
            'performance' => $this->performanceMetrics(),
            'accessibility' => $this->accessibilityMetrics(),
            'technology' => $this->technologyMetrics(),
            'conversion' => $this->conversionMetrics(),
            'authority' => $this->authorityMetrics(),
        ];
    }

    private function technicalSeoMetrics(): array
    {
        return [
            ['key' => 'https', 'name' => 'HTTPS化', 'value_type' => 'boolean', 'source_type' => 'http', 'scoring_type' => 'boolean', 'points' => 2, 'display_order' => 10, 'recommendation_template' => 'サイト全体をHTTPSで配信してください。'],
            ['key' => 'http_status_ok', 'name' => 'HTTPステータス', 'value_type' => 'boolean', 'source_type' => 'http', 'scoring_type' => 'boolean', 'points' => 2, 'display_order' => 20, 'recommendation_template' => 'ページが正常に応答する(2xx)ようにしてください。'],
            ['key' => 'title_present', 'name' => 'titleタグ', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 2, 'display_order' => 30, 'recommendation_template' => 'ページタイトルを設定してください。'],
            ['key' => 'title_length_optimal', 'name' => 'title文字数', 'value_type' => 'number', 'unit' => 'chars', 'source_type' => 'static_html', 'scoring_type' => 'range', 'points' => 1, 'minimum_value' => 10, 'maximum_value' => 65, 'display_order' => 40, 'recommendation_template' => 'titleは10〜65文字程度に調整してください。'],
            ['key' => 'meta_description_present', 'name' => 'meta description', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 2, 'display_order' => 50, 'recommendation_template' => '検索結果に表示される説明文(meta description)を設定してください。'],
            ['key' => 'meta_description_length_optimal', 'name' => 'meta description文字数', 'value_type' => 'number', 'unit' => 'chars', 'source_type' => 'static_html', 'scoring_type' => 'range', 'points' => 1, 'minimum_value' => 50, 'maximum_value' => 160, 'display_order' => 60, 'recommendation_template' => 'meta descriptionは50〜160文字程度に調整してください。'],
            ['key' => 'h1_single', 'name' => 'H1タグ(1件)', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 2, 'display_order' => 70, 'recommendation_template' => 'ページの主題を示すH1を1つ設定してください。'],
            ['key' => 'canonical_present', 'name' => 'canonicalタグ', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 1, 'display_order' => 80, 'recommendation_template' => 'canonicalタグを設定してください。'],
            ['key' => 'canonical_self_referencing', 'name' => 'canonical自己参照', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 1, 'not_found_policy' => 'exclude', 'display_order' => 90, 'recommendation_template' => 'canonicalが自ページを正しく指すよう修正してください。'],
            ['key' => 'robots_meta_indexable', 'name' => 'robots meta index', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 1, 'display_order' => 100, 'recommendation_template' => '意図せずnoindexになっていないか確認してください。'],
            ['key' => 'viewport_present', 'name' => 'viewport', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 1, 'display_order' => 110, 'recommendation_template' => 'viewportメタタグを設定してください。'],
            ['key' => 'lang_present', 'name' => 'lang属性', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 1, 'display_order' => 120, 'recommendation_template' => 'html要素にlang属性を設定してください。'],
            ['key' => 'favicon_present', 'name' => 'favicon', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 1, 'display_order' => 130, 'recommendation_template' => 'faviconを設定してください。'],
            ['key' => 'robots_fetched', 'name' => 'robots.txt取得', 'value_type' => 'boolean', 'source_type' => 'http', 'scoring_type' => 'boolean', 'points' => 1, 'display_order' => 140],
            ['key' => 'sitemap_fetched', 'name' => 'sitemap.xml取得', 'value_type' => 'boolean', 'source_type' => 'http', 'scoring_type' => 'boolean', 'points' => 1, 'display_order' => 150, 'recommendation_template' => 'sitemap.xmlを設置してください。'],
            ['key' => 'structured_data_present', 'name' => 'JSON-LD構造化データ', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 1, 'not_found_policy' => 'exclude', 'display_order' => 160, 'recommendation_template' => '構造化データ(JSON-LD)の設置を検討してください。'],
            ['key' => 'ogp_present', 'name' => 'OGP基本項目', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 1, 'not_found_policy' => 'exclude', 'display_order' => 170, 'recommendation_template' => 'SNSシェア用にOGPタグ(og:title等)を設定してください。'],
            ['key' => 'redirect_count_low', 'name' => 'リダイレクト回数', 'value_type' => 'number', 'unit' => 'hops', 'source_type' => 'http', 'scoring_type' => 'threshold', 'points' => 1, 'higher_is_better' => false, 'thresholds' => [['min' => 0, 'max' => 0, 'score_rate' => 1], ['min' => 1, 'max' => 1, 'score_rate' => 0.7], ['min' => 2, 'max' => 2, 'score_rate' => 0.3], ['min' => 3, 'max' => 999, 'score_rate' => 0]], 'display_order' => 180, 'recommendation_template' => 'リダイレクトの回数を減らしてください。'],
            ['key' => 'lighthouse_seo_score', 'name' => 'Lighthouse SEOスコア', 'value_type' => 'score', 'unit' => 'pt', 'source_type' => 'lighthouse', 'scoring_type' => 'lighthouse', 'points' => 3, 'display_order' => 190],
        ];
    }

    private function contentMetrics(): array
    {
        return [
            ['key' => 'word_count_sufficient', 'name' => '本文の文字数', 'value_type' => 'number', 'unit' => 'words', 'source_type' => 'static_html', 'scoring_type' => 'linear', 'points' => 4, 'minimum_value' => 0, 'target_value' => 300, 'display_order' => 10, 'recommendation_template' => '本文のコンテンツ量を増やしてください(目安300文字以上)。'],
            ['key' => 'img_alt_coverage', 'name' => '画像alt充足率', 'value_type' => 'percentage', 'unit' => '%', 'source_type' => 'static_html', 'scoring_type' => 'ratio', 'points' => 4, 'display_order' => 20, 'recommendation_template' => '重要な画像に代替テキスト(alt)を設定してください。'],
            ['key' => 'internal_link_sufficient', 'name' => '内部リンク数', 'value_type' => 'number', 'unit' => 'links', 'source_type' => 'static_html', 'scoring_type' => 'linear', 'points' => 3, 'minimum_value' => 0, 'target_value' => 5, 'display_order' => 30, 'recommendation_template' => '関連ページへの内部リンクを増やしてください。'],
            ['key' => 'heading_structure_present', 'name' => '見出し構造', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 2, 'display_order' => 40, 'recommendation_template' => '見出し(H2等)を使ってコンテンツを構造化してください。'],
            ['key' => 'external_link_present', 'name' => '外部リンク数', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 2, 'not_found_policy' => 'exclude', 'display_order' => 50, 'recommendation_template' => '信頼性向上のため、関連する外部サイトへのリンクを検討してください。'],
            ['key' => 'pricing_info_link_present', 'name' => '料金情報リンク', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 1, 'not_found_policy' => 'exclude', 'is_required' => false, 'display_order' => 60, 'recommendation_template' => '料金情報ページへのリンクを設置し、見込み顧客が価格を把握しやすくしてください。'],
            ['key' => 'case_study_or_testimonial_link_present', 'name' => '導入事例・お客様の声リンク', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 1, 'not_found_policy' => 'exclude', 'is_required' => false, 'display_order' => 70, 'recommendation_template' => '導入事例やお客様の声を掲載し、サービスの信頼性を示してください。'],
            // 以下は「事業内容によって有無の是非が分かれる」ため採点対象外(情報表示専用)。
            ['key' => 'company_info_link_present', 'name' => '会社概要リンク', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'not_scored', 'points' => 0, 'display_order' => 80],
            ['key' => 'privacy_policy_link_present', 'name' => 'プライバシーポリシーリンク', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'not_scored', 'points' => 0, 'display_order' => 90],
            ['key' => 'faq_link_present', 'name' => 'FAQ/よくある質問リンク', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'not_scored', 'points' => 0, 'display_order' => 100],
        ];
    }

    private function performanceMetrics(): array
    {
        return [
            ['key' => 'lighthouse_performance', 'name' => 'Lighthouse Performance', 'value_type' => 'score', 'unit' => 'pt', 'source_type' => 'lighthouse', 'scoring_type' => 'lighthouse', 'points' => 6, 'display_order' => 10],
            ['key' => 'fcp', 'name' => 'First Contentful Paint', 'value_type' => 'number', 'unit' => 'ms', 'source_type' => 'lighthouse', 'scoring_type' => 'inverse_linear', 'points' => 2, 'higher_is_better' => false, 'target_value' => 1800, 'maximum_value' => 4000, 'display_order' => 20, 'recommendation_template' => '初回コンテンツ表示までの時間を短縮してください。'],
            ['key' => 'lcp', 'name' => 'Largest Contentful Paint', 'value_type' => 'number', 'unit' => 'ms', 'source_type' => 'lighthouse', 'scoring_type' => 'inverse_linear', 'points' => 3, 'higher_is_better' => false, 'target_value' => 2500, 'maximum_value' => 4500, 'display_order' => 30, 'recommendation_template' => 'ファーストビューの画像・主要リソースを最適化してください。'],
            ['key' => 'cls', 'name' => 'Cumulative Layout Shift', 'value_type' => 'number', 'source_type' => 'lighthouse', 'scoring_type' => 'threshold', 'points' => 2, 'higher_is_better' => false, 'thresholds' => [['min' => 0, 'max' => 0.1, 'score_rate' => 1], ['min' => 0.1, 'max' => 0.25, 'score_rate' => 0.5], ['min' => 0.25, 'max' => 999, 'score_rate' => 0]], 'display_order' => 40, 'recommendation_template' => 'レイアウトのずれ(CLS)を抑えてください。'],
            ['key' => 'speed_index', 'name' => 'Speed Index', 'value_type' => 'number', 'unit' => 'ms', 'source_type' => 'lighthouse', 'scoring_type' => 'inverse_linear', 'points' => 1, 'higher_is_better' => false, 'target_value' => 3400, 'maximum_value' => 5800, 'display_order' => 50],
            ['key' => 'tbt', 'name' => 'Total Blocking Time', 'value_type' => 'number', 'unit' => 'ms', 'source_type' => 'lighthouse', 'scoring_type' => 'inverse_linear', 'points' => 1, 'higher_is_better' => false, 'target_value' => 200, 'maximum_value' => 600, 'display_order' => 60, 'recommendation_template' => 'メインスレッドをブロックするJavaScriptを削減してください。'],
            // リクエスト数・転送量は「多い/少ない」自体を機械的に採点せず、参考情報として表示する。
            ['key' => 'lighthouse_request_count', 'name' => 'リクエスト数', 'value_type' => 'number', 'unit' => 'requests', 'source_type' => 'lighthouse', 'scoring_type' => 'not_scored', 'points' => 0, 'higher_is_better' => false, 'display_order' => 70],
            ['key' => 'lighthouse_transfer_size', 'name' => '転送量', 'value_type' => 'number', 'unit' => 'bytes', 'source_type' => 'lighthouse', 'scoring_type' => 'not_scored', 'points' => 0, 'higher_is_better' => false, 'display_order' => 80],
        ];
    }

    private function accessibilityMetrics(): array
    {
        return [
            ['key' => 'lighthouse_accessibility', 'name' => 'Lighthouse Accessibility', 'value_type' => 'score', 'unit' => 'pt', 'source_type' => 'lighthouse', 'scoring_type' => 'lighthouse', 'points' => 5, 'display_order' => 10],
            ['key' => 'a11y_lang_present', 'name' => 'lang属性(アクセシビリティ)', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 2, 'display_order' => 20],
            ['key' => 'a11y_form_label_present', 'name' => 'form label', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 1, 'not_found_policy' => 'exclude', 'display_order' => 30, 'recommendation_template' => 'フォーム項目にlabelを関連付けてください。'],
            ['key' => 'a11y_button_name_present', 'name' => 'button名前', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 1, 'not_found_policy' => 'exclude', 'display_order' => 40, 'recommendation_template' => 'ボタンに分かりやすいテキスト/aria-labelを設定してください。'],
            ['key' => 'a11y_heading_order_ok', 'name' => '見出し順序', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 1, 'not_found_policy' => 'exclude', 'display_order' => 50, 'recommendation_template' => '見出しレベル(H1→H2→H3)の順序を整えてください。'],
        ];
    }

    private function technologyMetrics(): array
    {
        return [
            ['key' => 'analytics_configured', 'name' => 'アクセス解析の設置', 'value_type' => 'boolean', 'source_type' => 'technology', 'scoring_type' => 'boolean', 'points' => 4, 'display_order' => 10, 'recommendation_template' => 'Google Analytics等のアクセス解析を導入してください。'],
            ['key' => 'lighthouse_best_practices', 'name' => 'Lighthouse Best Practices', 'value_type' => 'score', 'unit' => 'pt', 'source_type' => 'lighthouse', 'scoring_type' => 'lighthouse', 'points' => 6, 'display_order' => 20],
            // 以下は「技術の種類」そのものへの優劣をつけない情報表示専用項目(not_scored)。
            ['key' => 'cms_detected', 'name' => 'CMS検出', 'value_type' => 'text', 'source_type' => 'technology', 'scoring_type' => 'not_scored', 'points' => 0, 'display_order' => 30],
            ['key' => 'ga_detected', 'name' => 'Google Analytics', 'value_type' => 'boolean', 'source_type' => 'technology', 'scoring_type' => 'not_scored', 'points' => 0, 'display_order' => 40],
            ['key' => 'gtm_detected', 'name' => 'Google Tag Manager', 'value_type' => 'boolean', 'source_type' => 'technology', 'scoring_type' => 'not_scored', 'points' => 0, 'display_order' => 50],
            ['key' => 'clarity_detected', 'name' => 'Microsoft Clarity', 'value_type' => 'boolean', 'source_type' => 'technology', 'scoring_type' => 'not_scored', 'points' => 0, 'display_order' => 60],
            ['key' => 'meta_pixel_detected', 'name' => 'Meta Pixel', 'value_type' => 'boolean', 'source_type' => 'technology', 'scoring_type' => 'not_scored', 'points' => 0, 'display_order' => 70],
            ['key' => 'recaptcha_detected', 'name' => 'reCAPTCHA', 'value_type' => 'boolean', 'source_type' => 'technology', 'scoring_type' => 'not_scored', 'points' => 0, 'display_order' => 80],
            ['key' => 'cdn_detected', 'name' => 'CDN利用', 'value_type' => 'boolean', 'source_type' => 'technology', 'scoring_type' => 'not_scored', 'points' => 0, 'display_order' => 90],
        ];
    }

    private function conversionMetrics(): array
    {
        return [
            ['key' => 'form_present', 'name' => 'フォーム有無', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 3, 'display_order' => 10, 'recommendation_template' => '問い合わせフォームを設置してください。'],
            ['key' => 'tel_or_mailto_present', 'name' => '電話/メール導線', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 3, 'display_order' => 20, 'recommendation_template' => '電話番号やメールアドレスへのリンクを設置してください。'],
            ['key' => 'contact_cta_present', 'name' => '問い合わせ導線', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 3, 'display_order' => 30, 'recommendation_template' => '問い合わせへの導線を分かりやすく設置してください。'],
            ['key' => 'reservation_cta_present', 'name' => '予約導線', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 2, 'not_found_policy' => 'exclude', 'is_required' => false, 'display_order' => 40, 'recommendation_template' => '予約機能・予約導線の設置を検討してください。'],
            ['key' => 'document_request_cta_present', 'name' => '資料請求導線', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 2, 'not_found_policy' => 'exclude', 'is_required' => false, 'display_order' => 50, 'recommendation_template' => '資料請求フォームの設置を検討してください。'],
            ['key' => 'sns_link_present', 'name' => 'SNSリンク', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'boolean', 'points' => 1, 'not_found_policy' => 'exclude', 'display_order' => 60, 'recommendation_template' => 'SNSアカウントへのリンクを設置してください。'],
            ['key' => 'cta_count_sufficient', 'name' => 'CTA数', 'value_type' => 'number', 'unit' => 'count', 'source_type' => 'static_html', 'scoring_type' => 'linear', 'points' => 1, 'minimum_value' => 0, 'target_value' => 3, 'display_order' => 70, 'recommendation_template' => '主要な行動喚起(CTA)を増やしてください。'],
            ['key' => 'fixed_cta_present', 'name' => '固定表示CTA', 'value_type' => 'boolean', 'source_type' => 'analyzer', 'scoring_type' => 'boolean', 'points' => 2, 'not_found_policy' => 'exclude', 'is_required' => false, 'display_order' => 80, 'recommendation_template' => '画面に常時表示される問い合わせ・予約導線(固定CTA)の設置を検討してください。'],
            ['key' => 'form_input_burden', 'name' => 'フォーム入力負担(必須項目数)', 'value_type' => 'number', 'unit' => 'fields', 'source_type' => 'static_html', 'scoring_type' => 'inverse_linear', 'points' => 2, 'higher_is_better' => false, 'target_value' => 3, 'maximum_value' => 10, 'not_found_policy' => 'exclude', 'is_required' => false, 'display_order' => 90, 'recommendation_template' => '問い合わせフォームの必須入力項目数を減らし、入力の負担を下げてください。'],
            // 予約手段(自社フォームか外部予約サービスか)は事業内容によって優劣がつくものではないため採点対象外。
            ['key' => 'external_reservation_service_detected', 'name' => '外部予約サービス利用', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'not_scored', 'points' => 0, 'display_order' => 100],
            ['key' => 'recruit_link_present', 'name' => '採用情報リンク', 'value_type' => 'boolean', 'source_type' => 'static_html', 'scoring_type' => 'not_scored', 'points' => 0, 'display_order' => 110],
        ];
    }

    private function authorityMetrics(): array
    {
        return [
            ['key' => 'authority_score', 'name' => 'Authority Score', 'value_type' => 'number', 'source_type' => 'semrush', 'scoring_type' => 'threshold', 'points' => 4, 'thresholds' => [['min' => 0, 'max' => 19, 'score_rate' => 0], ['min' => 20, 'max' => 39, 'score_rate' => 0.4], ['min' => 40, 'max' => 59, 'score_rate' => 0.7], ['min' => 60, 'max' => 100, 'score_rate' => 1]], 'display_order' => 10],
            ['key' => 'organic_traffic_estimate', 'name' => 'オーガニックトラフィック推定', 'value_type' => 'number', 'unit' => 'visits/mo', 'source_type' => 'semrush', 'scoring_type' => 'linear', 'points' => 3, 'minimum_value' => 0, 'target_value' => 1000, 'display_order' => 20],
            ['key' => 'organic_keywords_count', 'name' => 'オーガニックキーワード数', 'value_type' => 'number', 'unit' => 'keywords', 'source_type' => 'semrush', 'scoring_type' => 'linear', 'points' => 3, 'minimum_value' => 0, 'target_value' => 200, 'display_order' => 30],
            ['key' => 'top10_keywords_count', 'name' => '上位10位キーワード数', 'value_type' => 'number', 'unit' => 'keywords', 'source_type' => 'semrush', 'scoring_type' => 'linear', 'points' => 2, 'minimum_value' => 0, 'target_value' => 20, 'display_order' => 40],
            ['key' => 'backlinks_count', 'name' => '被リンク数', 'value_type' => 'number', 'unit' => 'links', 'source_type' => 'semrush', 'scoring_type' => 'linear', 'points' => 2, 'minimum_value' => 0, 'target_value' => 500, 'display_order' => 50],
            ['key' => 'referring_domains_count', 'name' => '参照ドメイン数', 'value_type' => 'number', 'unit' => 'domains', 'source_type' => 'semrush', 'scoring_type' => 'linear', 'points' => 1, 'minimum_value' => 0, 'target_value' => 50, 'display_order' => 60],
            // 情報表示専用(採点対象外)。
            ['key' => 'top3_keywords_count', 'name' => '上位3位キーワード数', 'value_type' => 'number', 'unit' => 'keywords', 'source_type' => 'semrush', 'scoring_type' => 'not_scored', 'points' => 0, 'display_order' => 70],
            ['key' => 'paid_search_present', 'name' => '有料検索有無', 'value_type' => 'boolean', 'source_type' => 'semrush', 'scoring_type' => 'not_scored', 'points' => 0, 'display_order' => 80],
            ['key' => 'competitor_domains_count', 'name' => '競合ドメイン数', 'value_type' => 'number', 'source_type' => 'semrush', 'scoring_type' => 'not_scored', 'points' => 0, 'display_order' => 90],
        ];
    }
}
