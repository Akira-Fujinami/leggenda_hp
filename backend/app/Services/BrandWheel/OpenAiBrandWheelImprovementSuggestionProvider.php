<?php

namespace App\Services\BrandWheel;

use App\Services\BrandWheel\Data\BrandWheelImprovementSuggestionInput;
use App\Services\BrandWheel\Data\BrandWheelImprovementSuggestionOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Chat Completions APIを呼び出す、改善提案(page6)AIの実Provider。
 * OpenAiBrandWheelAnalysisProviderと同じリトライ・エラー分類方針・
 * services.brand_wheel_ai.*設定を再利用する(新しい環境変数は追加しない ――
 * 同じ「ブランド・ホイール」サブシステムの一部であるため)。
 *
 * 既存の改善提案(BrandWheelImprovementFocusComposer、グループ差バー＋証拠
 * カード)はAIを一切使わない決定的ロジックのまま維持し、このProviderは
 * その上に追加する「ワンポイント」「詳細提言パラグラフ」のみを生成する
 * (依頼者指定「情報が足りないので追加してください、という一般論から
 * 脱却する」への対応 ―― Quick Win判定・差別化vsギャップ埋め・実行難易度は
 * 企業ごとの文脈判断が要るため、決定的ルールだけでは実現できない)。
 *
 * 入力はBrandWheelImprovementSuggestionInputFactoryが組み立てた、既に
 * evidence実在検証・実行難易度タグ付けが済んだグラウンディング済みデータの
 * みで、AIはこの中に無い事実を作り出さないよう明示的に指示される。
 */
class OpenAiBrandWheelImprovementSuggestionProvider implements BrandWheelImprovementSuggestionProvider
{
    /**
     * v1(2026-08-17): 初版。
     *
     * v2(2026-08-18): クライアントレビュー対応。出力を単一段落(recommendation)
     * から構造化フィールド(reason/recommended_contents/mid_term_action/
     * quick_win/implementation_difficulty/candidate_impact/gap_closing/
     * differentiation_opportunities)へ拡張し、「情報が不足しているので
     * 追加してください」という一般論の禁止・Gap Closing(競合にあり自社に
     * 無い情報を補う)とDifferentiation(自社・競合とも手薄な領域で自社が
     * 先に充実させる)を明示的に区別して内部判断させる指示を追加した。
     * 出力構造が変わるため、v1の結果は再利用しない(このJobはanalysis_idの
     * unique制約により1回しか生成しない設計のため、再利用ロジック自体が
     * 存在しない ―― 既存のGenerateBrandWheelAnalysisJobのようなinput_hash
     * 再利用キャッシュはこの改善提案には無い)。
     *
     * v3(2026-08-19): クライアントレビュー対応その2。(1)実際の生成文に
     * 「既存の社内資料を活用することで迅速に対応可能です」等、サイト分析
     * だけでは分からない社内事情(資料の有無・実施工数・担当部署・社内調整の
     * 難易度・社員インタビューの要否・応募数への効果)を断定する表現が
     * 残っていたため、断定禁止の対象を具体的に列挙し、推奨する条件付き表現
     * ("〜できる場合" 等)を明示した。(2) mid_term_actionの選定を、AIが
     * self_unmatched_items×competitor_unmatched_itemsを自分で突き合わせる
     * のではなく、PHP側で事前計算したmutually_unmatched_items(自社・競合とも
     * 未充足の項目)から選ばせるよう変更し、「1テーマ(関連項目は最大2件)に
     * まとめる」「候補が無ければ無理に埋めない」ことを明示した
     * (BrandWheelImprovementSuggestionInputFactory参照)。
     *
     * v4(2026-08-20): 差別化テーマ選定ロジックの改善。従来はmutually_
     * unmatched_items(自社・競合とも未充足の項目)の中から選ぶだけだったが、
     * これだけでは「両社とも書いていない」という消極的な理由でしかテーマを
     * 選べず、実例(社会貢献活動・知名度・評判)で自社らしさとの接続が薄い
     * テーマが選ばれることがあった(依頼者指摘)。自社が既に確認できている
     * 強み(self_confirmed_items)・軸別スコア(self_category_scores)・
     * キーメッセージ(self_key_message)・ポジティブな印象
     * (self_positive_impression)・Core Value根拠(self_core_value_evidence、
     * あれば)を新たに入力へ追加し、「Brand Fit(自社の既存パーパス・価値観・
     * 事業内容との関連性)」「Candidate Relevance(候補者の判断材料としての
     * 意味)」「Evidence Expandability(既存情報を起点に広げられる可能性)」の
     * 3軸で候補を評価したうえで1テーマに絞るよう指示した。関連性が低い
     * 候補しかない場合は無理に選ばずmid_term_action=nullにすることも明示。
     * 出力スキーマ自体は変更しない(mid_term_actionは引き続き単一テキスト、
     * テーマ→理由→広げ方を2〜3文に収める)。
     *
     * v5(2026-08-22): 出力構造・判定ロジックは変えず、自由記述
     * (one_point/reason/recommended_contents/mid_term_action)の文体指示
     * のみを追加した(依頼者指定「やさしく、寄り添うトーン」への改修、
     * OpenAiBrandWheelAnalysisProviderのv9と同時対応)。プロンプト冒頭に
     * 【読み手について】【文章の書き方】を挿入した(この提供者には
     * 【最重要】見出しが無いため、他の指示より前=プロンプト冒頭に置く)。
     */
    public const string PROMPT_VERSION = 'v5';

    public function __construct(
        private readonly BrandWheelImprovementSuggestionResponseParser $parser,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function model(): ?string
    {
        return (string) (config('services.brand_wheel_ai.model') ?: config('services.openai.model', 'gpt-4o-mini'));
    }

    public function promptVersion(): ?string
    {
        return self::PROMPT_VERSION;
    }

    public function analyze(BrandWheelImprovementSuggestionInput $input): BrandWheelImprovementSuggestionOutcome
    {
        $apiKey = (string) config('services.openai.api_key');

        if ($apiKey === '') {
            throw new BrandWheelAnalysisException('OPENAI_NOT_CONFIGURED', 'BRAND_WHEEL_AI_PROVIDER=openaiが指定されていますが、OPENAI_API_KEYが設定されていません。');
        }

        $model = $this->model();
        $prompt = $this->buildPrompt($input);

        $response = $this->request($apiKey, $model, $prompt);

        $content = $response['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new BrandWheelAnalysisException('AI_INVALID_RESPONSE', 'AIから有効な応答が返されませんでした。');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new BrandWheelAnalysisException('AI_INVALID_JSON', 'AIの応答をJSONとして解釈できませんでした。');
        }

        $result = $this->parser->parse($decoded, provider: 'openai', model: $model, isMock: false, promptVersion: self::PROMPT_VERSION);

        $usage = $response['usage'] ?? [];

        return new BrandWheelImprovementSuggestionOutcome(
            result: $result,
            usageInputTokens: isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            usageOutputTokens: isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
        );
    }

    private function buildPrompt(BrandWheelImprovementSuggestionInput $input): string
    {
        $facts = json_encode($input->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $forbiddenPhrases = implode('」「', array_merge(
            (array) config('brand_wheel.forbidden_phrases', []),
            (array) config('brand_wheel.assertive_phrases', []),
        ));

        $readerNote = <<<TXT

【読み手について】
このレポートを読むのは、その会社の採用担当者ご本人です。限られた予算と
時間の中で自社の採用サイトを作り、日々更新している方です。書かれていない
項目があるのは、怠慢ではなく、優先順位と制約の結果です。

あなたの役割は、足りないものを指摘することではありません。すでに伝わって
いることを正しく言語化し、そのうえで「次に何を足すと候補者により届くか」を
一緒に考えることです。

TXT;

        $writingStyleNote = <<<TXT

【文章の書き方】
- まず読み取れたことに触れてから、次の一手に進んでください。読み取れなかった
  ことから書き始めないでください。
- 主語を「サイト」ではなく「候補者」に寄せてください。
    ×「〜の記述がありません」
    ○「候補者は〜を知りたいと感じるかもしれません」
- 語尾は提案形にしてください。
    ×「〜すべきです」「〜する必要があります」
    ○「〜から始めてみてはいかがでしょうか」
    ○「〜を添えると、より伝わりやすくなります」
- 断定を避け、「〜可能性があります」「〜かもしれません」を使ってください。
- 会社の呼称は「御社」で統一してください。

TXT;

        $competitorNote = $input->hasCompetitor
            ? '比較サイト(競合)のデータが含まれています。ギャップ埋め(競合にあり自社に無い情報を補う)と、'.
              '差別化(競合も手薄な領域を自社が先に充実させる)の両方の可能性を検討してください。'
            : '比較サイト(競合)のデータはありません。自社のデータのみから、候補者に伝わっていない可能性が'.
              '高い領域を判断してください。';

        $forbiddenNote = <<<TXT
- 以下の語は一切使わないでください: 「{$forbiddenPhrases}」。「魅力が無い/劣っている」という評価を
  示唆するため使用禁止です。
- データに含まれていない事実(社風・具体的な社内制度・実行体制など)を創作しないでください。

【断定禁止(最重要)】
渡されたデータは「サイト上から確認できた/できなかった記述」のみです。以下の社内事情は、
サイトの記述だけからは分からない推測に過ぎません。断定せず、必ず条件付き・可能性の表現にしてください:
- 既存の社内資料・素材の有無
- 実施にかかる工数・期間
- 対応できる担当部署(人事部だけで完結するか等)
- 社内調整の難易度
- 社員インタビュー等の取材が必須かどうか
- 応募数・採用数への効果

推奨する表現(このトーンに揃えてください): 「〜できる場合」「〜の可能性があります」「比較的着手しやすい
と考えられます」「候補者が理解しやすくなる可能性があります」「〜が必要になる可能性があります」。

禁止する断定表現の例(実際に問題視された生成例を含む):
- 「実行難易度も低く、既存の社内資料を活用することで迅速に対応可能です。」
  → 「既存の社内資料やプロジェクト情報を活用できる場合、比較的短期間で着手しやすい施策と考えられます。」
- 「人事部内で対応できます。」
  → 「既存情報を活用できる場合、人事部内でも比較的検討しやすい施策と考えられます。」
- 「この改善によって候補者への魅力が高まります。」
  → 「候補者が企業の強みや具体的な挑戦を理解しやすくなる可能性があります。」
- 「社員インタビューを実施すれば差別化できます。」
  → 「社員や職場の実態を具体的に伝える場合は、社員紹介やインタビューなどのコンテンツも選択肢になります。」
- 「これを実施すれば採用競争力が上がります。」
  → 「候補者が自社で働くイメージを持ちやすくなり、他社との違いを伝えやすくなる可能性があります。」
- 「社会貢献活動を強化することでブランド価値を高めることが可能です。」(「強化する」の実態が
  不明なまま断定している)
  → 「自社・比較サイトとも十分に情報が確認できなかった項目の中では、『社会貢献活動』は御社の
  既存のパーパスとの接続性が高いテーマです。実際に該当する取り組みや成果がある場合、それを
  具体的に紹介することで、既存のパーパスをより具体化できる可能性があります。」
- 「知名度・評判を強化しましょう。」(サイトに書き足すだけで直接改善できるものではない)
- 「両社とも書いていないので差別化できます。」(未掲載であること自体は差別化価値の根拠にならない)
TXT;

        $genericBanNote = <<<TXT
以下のような一般論・誰でも言えるレベルの提言は禁止します:
- 「情報が不足しているため追加しましょう。」
- 「競合より少ないので増やしましょう。」
- 「掲載する情報を増やすことをおすすめします。」
これらは、データの中の具体的な項目名・実行難易度を踏まえた、企業固有の提言に置き換えてください。
「不足の指摘」ではなく「優先順位の判断」を示すことが目的です ―― 何を・なぜ・どの順番で改善すべきかを
判断してください。
TXT;

        $gapDifferentiationNote = <<<TXT
提言は必ず以下の2種類を両方とも検討し、両方とも出力してください(片方だけの提言は不可)。
この2つは役割が異なるため混同しないこと:
- 今すぐ優先して改善すること(Gap Closing、one_point/reason/recommended_contentsに反映):
  競合には該当があり(competitor_matched_items)、自社には無い(self_unmatched_items)情報を
  補う短期改善。競合との差を埋めながら、比較的着手しやすいものを優先してください。
- 中長期の差別化ポイント(Differentiation、mid_term_actionに反映): 「競合が書いていない
  ことを単に書くこと」ではありません。自社がすでに持っている価値・強み・パーパスと自然に
  つながるテーマの中で、競合も十分に伝えられていない領域を深掘りすることです。
  mutually_unmatched_items(自社・競合とも未充足の項目)だけを見て機械的に選ばず、
  self_confirmed_items(自社で確認済みの項目名)・self_category_scores(自社の軸別件数)・
  self_key_message(自社のキーメッセージ)・self_positive_impression(自社のポジティブな印象)・
  self_core_value_evidence(あれば、自社のCore Value根拠)との接続性を必ず考慮してください。

【差別化テーマの選定手順】
STEP1: self_confirmed_items/self_category_scores/self_key_message/self_positive_impression/
  self_core_value_evidenceから、自社が現在確認できている強み・価値観・キーメッセージを整理する。
STEP2: mutually_unmatched_itemsの各項目が、STEP1で整理した自社の既存ブランド文脈と
  どれだけ自然につながるかを評価する。
STEP3: 以下の3軸で各候補の優先度を考える(いずれも断定ではなく評価であることに注意):
  - Brand Fit: 自社の既存のパーパス・価値観・事業内容との関連性
  - Candidate Relevance: 候補者が企業を選ぶ際の判断材料として意味があるか
  - Evidence Expandability: 現在確認できている情報を起点に、追加情報へ自然に広げられる
    可能性があるか(ただし実際に社内情報が存在すると断定しないこと)
STEP4: 3軸を総合し、自社らしさと最も接続するテーマを1つだけ選ぶ。関連する項目であれば
  最大2件までまとめて1つのテーマにしてよいが、複数の独立したテーマを同時に提案しないこと。

【差別化を選ばない/nullにする条件】次のいずれかに該当する場合は、無理にテーマを作らず
mid_term_actionをnullにしてください(「とにかく何か1つ出す」仕様にしないこと):
- mutually_unmatched_itemsが空である
- 自社の既存強み(self_confirmed_items/self_key_message等)との関連性が低い候補しかない
- Brand Fitがどの候補も低いと判断される
- 根拠が弱すぎる

mid_term_actionの文章は、以下の3要素を2〜3文で自然につなげてください(依頼者指定の構成):
1. テーマ(1つだけ、下位要素名で明示する)
2. なぜこのテーマなのか(自社の既存の強み・キーメッセージとの接続を説明する)
3. どう広げるか(実際に該当する実態がある場合の可能性として、条件付きで述べる)
TXT;

        $reasoningSteps = <<<TXT
あなたは採用サイト改善・採用マーケティング・Employer Brandingに精通したプロのHRコンサルタントです。
渡されたデータ(下位要素ごとの該当有無・実行難易度タグ・グループ別優劣)をもとに、以下の順番で
内部的に検討したうえで、最終的な提言のみを出力してください(検討過程そのものは出力しないでください):

1. 自社で不足している項目(self_unmatched_items)を整理する
2. 自社で既に充実している項目(self_matched_items)を整理する
3. 競合で充実している項目(competitor_matched_items、あれば)を整理する
4. 競合でも不足している項目(competitor_unmatched_items、あれば)を整理する
5. 自社と競合の差(group_totals、あれば)を特定する
6. それぞれの不足・差が候補者への情報伝達にどう影響するか(候補者への影響度)を判断する
7. Gap Closing候補(競合にはあり自社には無い項目)を複数作る
8. 各候補の改善効果(候補者への影響度)を評価する(low/medium/high)
9. 各候補の実行難易度を評価する(self_unmatched_itemsのexecution_difficulty/execution_noteを
   根拠にする。断定はせず、あくまで可能性として評価すること)
10. 実行しやすく効果も見込める候補(Quick Win)を特定する ―― 最も改善効果が大きい施策が
    必ずしも最初にやるべき施策とは限らない。実行難易度が高い施策より、既存情報から短期間で
    整理できる可能性が高い施策を優先することがある
11. 下記【差別化テーマの選定手順】に従い、mutually_unmatched_itemsと自社の既存ブランド
    文脈(self_confirmed_items/self_category_scores/self_key_message/
    self_positive_impression/self_core_value_evidence)を突き合わせ、Brand Fit/
    Candidate Relevance/Evidence Expandabilityの3軸で評価したうえで、中長期の
    差別化ポイントを1テーマ(関連項目は最大2件)選ぶ。関連性が低い候補しかなければ
    無理に選ばない
12. 差分の大きさ×候補者への影響×実行難易度×Quick Win性を総合し、最初に着手すべき1つを選ぶ

{$competitorNote}

{$gapDifferentiationNote}
TXT;

        $schema = <<<TXT
{
  "one_point": "string",
  "reason": "string",
  "recommended_contents": ["string"],
  "mid_term_action": "string または null",
  "quick_win": true または false,
  "implementation_difficulty": "low" または "medium" または "high",
  "candidate_impact": "low" または "medium" または "high",
  "gap_closing": ["string"],
  "differentiation_opportunities": ["string"],
  "recommendation": "string",
  "focus_sub_element_keys": ["string"]
}

- one_point: 1文で、最優先で着手すべきアクションを一言で言い切ってください(例:「まずは
  『新しい取り組み』と『競争力・独自性』を、具体的な事例として追加することを推奨します。」)。
  「掲載する情報を増やすことをおすすめします。」のような曖昧な言い回しは禁止です。何を
  追加すべきかは言い切って構いませんが、その先の社内事情(実施可否・工数等)は断定しないこと。
- reason: 最大3文で、なぜその施策を最優先にすべきかを説明してください。競合との比較・
  候補者への影響の観点は実際のデータに基づいて具体的に書き、実行難易度に触れる場合は
  【断定禁止】の指示に従い条件付き表現にしてください。
- recommended_contents: 具体的に追加すべき情報を最大3項目、箇条書きの短いフレーズ(項目名や
  内容の要点、1件20〜30字程度)で挙げてください。data内の実在する下位要素名・具体的な
  内容に基づくものにしてください。
- mid_term_action: 「中長期の差別化ポイント」です。【差別化テーマの選定手順】に従って選んだ
  1テーマ(関連項目は最大2件)について、2〜3文で「1.テーマ→2.なぜこのテーマなのか(自社の
  既存の強み・キーメッセージとの接続)→3.どう広げるか(条件付きの可能性)」の順に述べて
  ください。mutually_unmatched_itemsが空、自社の既存強みとの関連性が低い候補しかない、
  Brand Fitがどれも低い、根拠が弱すぎる ―― のいずれかに該当する場合はnullにしてください
  (無理に埋めないこと)。「〜を強化することでブランド価値を高めることが可能です」「両社とも
  書いていないので差別化できます」のような断定・消極的な理由付けは禁止、「〜との接続性が
  高いテーマです」「実際に該当する取り組みがある場合、〜できる可能性があります」のような
  条件付き表現にしてください。
- quick_win: one_pointで挙げた最優先施策が、既存の社内情報から比較的着手しやすい
  可能性があると判断できる場合はtrue、社員インタビューや他部署の協力等が必要になる
  可能性があり時間がかかると考えられる場合はfalseにしてください(断定ではなく推定)。
- implementation_difficulty / candidate_impact: one_pointで挙げた施策についての
  実行難易度・候補者への影響度を、低/中/高のいずれかで判断してください(推定であり断定ではない)。
- gap_closing: 競合には該当があり自社には無い項目のうち、one_point/reasonで言及したものの
  下位要素キー(例: "project_initiative")を配列で。無ければ空配列。
- differentiation_opportunities: mid_term_actionで言及した下位要素のキーを配列で
  (mutually_unmatched_items由来のキーのみ、最大2件)。mid_term_actionがnullなら空配列。
- recommendation: 後方互換用に、結論→なぜ→具体的にの3〜5文でも記述してください
  (reason/recommended_contentsと重複しても構いません。断定禁止の指示は同様に適用されます)。
- focus_sub_element_keys: 提言全体で言及した下位要素のキーを1〜3件、配列で。
TXT;

        return <<<PROMPT
{$readerNote}{$writingStyleNote}
{$reasoningSteps}
{$forbiddenNote}

{$genericBanNote}

【データ(自社/競合の下位要素ごとの該当有無・実行難易度・グループ別優劣。すべてPHP側で事前検証済みの事実)】
{$facts}

以下のJSON Schemaに厳密に従うJSONオブジェクトのみを出力してください(説明文や前後のテキストは一切不要):
{$schema}
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $apiKey, string $model, string $prompt): array
    {
        $baseUrl = (string) config('services.openai.base_url', 'https://api.openai.com/v1');
        $timeout = (int) config('services.brand_wheel_ai.timeout', 60);
        $maxRetries = (int) config('services.brand_wheel_ai.max_retries', 1);
        $maxOutputTokens = (int) config('services.brand_wheel_ai.max_output_tokens', 2000);
        $temperature = (float) config('services.brand_wheel_ai.temperature', 0.0);

        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            $attempt++;

            try {
                $response = Http::withToken($apiKey)
                    ->baseUrl($baseUrl)
                    ->timeout($timeout)
                    ->post('/chat/completions', [
                        'model' => $model,
                        'messages' => [
                            ['role' => 'user', 'content' => $prompt],
                        ],
                        'response_format' => [
                            'type' => 'json_schema',
                            'json_schema' => [
                                'name' => 'brand_wheel_improvement_suggestion',
                                'strict' => true,
                                'schema' => $this->buildResponseSchema(),
                            ],
                        ],
                        'max_tokens' => $maxOutputTokens,
                        'temperature' => $temperature,
                    ]);
            } catch (ConnectionException $e) {
                $lastException = $e;

                continue;
            }

            if ($response->status() === 401 || $response->status() === 403) {
                throw new BrandWheelAnalysisException('AI_AUTH_FAILED', 'OpenAI APIの認証に失敗しました。', isRetryable: false);
            }

            if ($response->status() === 429) {
                $retryAfter = $response->header('Retry-After');

                throw new BrandWheelAnalysisException(
                    'AI_RATE_LIMITED',
                    'OpenAI APIのレート制限に達しました。',
                    isRetryable: true,
                    retryAfterSeconds: $retryAfter !== null ? (int) $retryAfter : null,
                );
            }

            if ($response->status() === 408 || $response->status() === 504) {
                $lastException = null;

                if ($attempt <= $maxRetries) {
                    continue;
                }

                throw new BrandWheelAnalysisException('AI_TIMEOUT', 'OpenAI APIの呼び出しがタイムアウトしました。', isRetryable: true);
            }

            if (! $response->successful()) {
                throw new BrandWheelAnalysisException('AI_REQUEST_FAILED', 'OpenAI APIの呼び出しに失敗しました(HTTP '.$response->status().')。', isRetryable: true);
            }

            return $response->json() ?? [];
        }

        throw new BrandWheelAnalysisException('AI_UNAVAILABLE', 'OpenAI APIに接続できませんでした。', $lastException, isRetryable: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResponseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'one_point' => ['type' => ['string', 'null']],
                'reason' => ['type' => ['string', 'null']],
                'recommended_contents' => ['type' => 'array', 'items' => ['type' => 'string']],
                'mid_term_action' => ['type' => ['string', 'null']],
                'quick_win' => ['type' => ['boolean', 'null']],
                'implementation_difficulty' => ['type' => ['string', 'null'], 'enum' => ['low', 'medium', 'high', null]],
                'candidate_impact' => ['type' => ['string', 'null'], 'enum' => ['low', 'medium', 'high', null]],
                'gap_closing' => ['type' => 'array', 'items' => ['type' => 'string']],
                'differentiation_opportunities' => ['type' => 'array', 'items' => ['type' => 'string']],
                'recommendation' => ['type' => ['string', 'null']],
                'focus_sub_element_keys' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => [
                'one_point', 'reason', 'recommended_contents', 'mid_term_action', 'quick_win',
                'implementation_difficulty', 'candidate_impact', 'gap_closing', 'differentiation_opportunities',
                'recommendation', 'focus_sub_element_keys',
            ],
            'additionalProperties' => false,
        ];
    }
}
