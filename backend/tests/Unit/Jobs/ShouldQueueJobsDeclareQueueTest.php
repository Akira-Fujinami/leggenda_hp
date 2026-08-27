<?php

namespace Tests\Unit\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionClass;
use Tests\TestCase;

/**
 * 依頼AE-2(2026-08-27): 「気をつける」では3回目が起きるため、仕組みで防ぐ。
 *
 * 事故の経緯: GenerateAdminComparisonReportJob(依頼AC、2026-08-27)に
 * キュー指定が一切無く(クラス自身にも、唯一のディスパッチ元
 * FinalizeAnalysisJob側にも)、Laravelの既定キュー名`default`へ積まれた。
 * ワーカーが監視するキュー一覧(analysis, external-api, analysis-heavy, ai,
 * reports, notifications)に`default`が含まれていないため、このJobは
 * 永久に実行されなかった。`failed_jobs`にも載らず、ログにも何も出ない
 * ―― `jobs`テーブルを直接見るまで誰も気づけない事故だった
 * (2026-08-17のid=1338も同じ形の事故と推定される、2回目)。
 *
 * 【検査対象範囲】app/Jobs配下すべての具象クラス(抽象クラスを除く)のうち、
 * ShouldQueueを実装するもの。BaseWebsiteAnalysisJob等の抽象基底クラスを
 * 継承する具象クラスも対象に含まれる(継承関係はReflectionで解決するため、
 * 抽象クラス自身にキューが無くても具象側で解決できていれば良い)。
 *
 * 【合格条件、いずれかを満たせば良い】
 * 1. クラス自身のコンストラクタが`$this->onQueue('xxx')`を呼んでいる
 *    (依頼AE-1でGenerateAdminComparisonReportJobに採用した方式 ―― こちらを
 *    主とする、依頼者指定)。`public $queue = 'xxx';`という単純な
 *    プロパティ宣言は採用していない ―― Illuminate\Bus\Queueableトレイトが
 *    既に$queueプロパティ(既定値null)を持つため、クラス側で異なる既定値を
 *    プロパティとして再宣言するとPHPの「トレイトとの定義非互換」致命的
 *    エラーになる(実機で確認済み、GenerateAdminComparisonReportJobの
 *    docblock参照)。
 * 2. リポジトリ全体(app/配下)を検索して、このクラスへの`::dispatch(`
 *    呼び出し箇所が1箇所以上見つかり、かつその**すべて**が同じ文の中で
 *    `->onQueue(...)`を伴っている(既存26箇所超の慣習、依頼Y-5で確認済みの方式)。
 *
 * 条件2により、「文脈によってキューを出し分けるJob」(呼び出し箇所ごとに
 * 異なるキューを指定する)も許容する ―― クラス自身が単一の固定キューを
 * 持つことを強制しない(依頼者の注意事項)。呼び出し箇所が0件(まだどこからも
 * dispatchされていないJob)は、検証しようがないため不合格として扱う
 * (新しいJobを追加してdispatchを書き忘れた場合にテストで検知できるようにする
 * ため、意図的に厳しくしている)。
 */
class ShouldQueueJobsDeclareQueueTest extends TestCase
{
    private const JOBS_DIRECTORY = __DIR__.'/../../../app/Jobs';

    private const APP_DIRECTORY = __DIR__.'/../../../app';

    public function test_every_shouldqueue_job_under_app_jobs_has_a_queue_declared_somewhere(): void
    {
        $jobClasses = $this->discoverShouldQueueJobClasses();

        // スキャン自体が壊れていないことの確認(0件は「何も検査していない」
        // というテスト自体の不具合を意味するため、falseの安心感を防ぐ)。
        $this->assertNotEmpty($jobClasses, self::JOBS_DIRECTORY.' 配下にShouldQueueを実装するJobクラスが1つも見つかりませんでした(スキャン自体の不具合の可能性があります)。');

        // app/配下全ファイルの内容を1回だけ読み込み、Jobクラスの数だけ
        // ディレクトリ丸ごとを再走査しない(11クラス×app全体で数十秒かかっていた
        // ため、実測に基づき修正)。
        $appFileContents = $this->readAllPhpFilesUnder(self::APP_DIRECTORY);

        $failures = [];

        foreach ($jobClasses as $class) {
            $classQueue = $this->classConstructorQueue($class, $appFileContents);
            if ($classQueue !== null && $classQueue !== 'default') {
                continue;
            }

            $dispatchSiteStatuses = $this->findDispatchOnQueueStatuses($class, $appFileContents);
            if ($dispatchSiteStatuses !== [] && ! in_array(false, $dispatchSiteStatuses, true)) {
                continue;
            }

            $failures[] = sprintf(
                '%s (コンストラクタでのonQueue()=%s、::dispatch(呼び出し箇所%d件中%d件がonQueue未指定)',
                $class,
                $classQueue ?? 'なし',
                count($dispatchSiteStatuses),
                count(array_filter($dispatchSiteStatuses, fn (bool $ok) => ! $ok)),
            );
        }

        $this->assertSame(
            [],
            $failures,
            "以下のJobクラスは、キューが未指定(class既定のqueueがnull/'default'、かつ全てのdispatch呼び出し箇所でonQueueが確認できない)のため、`default`キューへ積まれて永久に実行されない恐れがあります:\n".implode("\n", $failures),
        );
    }

    /**
     * @return list<class-string>
     */
    private function discoverShouldQueueJobClasses(): array
    {
        $classes = [];

        foreach ($this->phpFilesUnder(self::JOBS_DIRECTORY) as $file) {
            $class = $this->classNameFromFile($file);

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->implementsInterface(ShouldQueue::class)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * クラス自身のコンストラクタ内(または継承元も含めたクラス本体全体)に
     * `$this->onQueue('xxx')`の呼び出しがあれば、その文字列を返す。ソース
     * コードの正規表現走査であり、実行時のリフレクションではない
     * (理由はクラスdocblock参照)。
     *
     * @param  array<string, string>  $appFileContents  ファイルパス => 内容
     */
    private function classConstructorQueue(string $class, array $appFileContents): ?string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $contents = $file !== false ? ($appFileContents[$file] ?? null) : null;

        if ($contents === null) {
            return null;
        }

        if (preg_match('/\$this->onQueue\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * このクラスへの`::dispatch(`呼び出し箇所(app/配下、`self::dispatch(`/
     * `static::dispatch(`による自己ディスパッチは定義ファイル内でのみ検出)
     * それぞれについて、同じ文の中に`->onQueue(`があるかを返す。
     *
     * 「同じ文」は、マッチ位置から次のセミコロンまでの範囲とする ――
     * このリポジトリのdispatch呼び出しは全て単純なメソッドチェーンの一文で
     * あり、引数に文字列リテラルとしてセミコロンを含むものが無いことを
     * 目視確認済み(括弧の対応を厳密に解析するより単純で、この前提下では
     * 同等に正確)。
     *
     * @param  array<string, string>  $appFileContents  ファイルパス => 内容
     * @return list<bool>
     */
    private function findDispatchOnQueueStatuses(string $class, array $appFileContents): array
    {
        $shortName = preg_quote(class_basename($class), '/');
        $definingFile = (new ReflectionClass($class))->getFileName();
        $statuses = [];

        foreach ($appFileContents as $file => $contents) {
            $patterns = [$shortName.'::dispatch\s*\('];
            if ($file === $definingFile) {
                $patterns[] = '(?:self|static)::dispatch\s*\(';
            }

            if (! preg_match_all('/'.implode('|', $patterns).'/', $contents, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$matchText, $offset]) {
                $semicolonPos = strpos($contents, ';', $offset);
                $statement = $semicolonPos !== false
                    ? substr($contents, $offset, $semicolonPos - $offset)
                    : substr($contents, $offset);

                $statuses[] = str_contains($statement, '->onQueue(');
            }
        }

        return $statuses;
    }

    /**
     * @return array<string, string>  ファイルパス => 内容
     */
    private function readAllPhpFilesUnder(string $directory): array
    {
        $contents = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                // getPathname()はディレクトリ引数に含めた".."をそのまま
                // 保持するため、realpath()で正規化してから使う
                // (classNameFromFile()側のprefix比較や、ReflectionClass::
                // getFileName()との照合を安定させるため)。
                $path = (string) realpath($fileInfo->getPathname());
                $contents[$path] = (string) file_get_contents($path);
            }
        }

        return $contents;
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $directory): array
    {
        return array_keys($this->readAllPhpFilesUnder($directory));
    }

    private function classNameFromFile(string $file): ?string
    {
        $relative = str_replace('\\', '/', substr($file, strlen(realpath(self::APP_DIRECTORY.'/..')) + 1));

        if (! str_starts_with($relative, 'app/')) {
            return null;
        }

        $withoutExtension = substr($relative, 0, -4); // ".php"を除く
        $class = 'App'.str_replace('/', '\\', substr($withoutExtension, 3)); // "app"を除く

        return $class;
    }
}
