<?php

namespace App\Console\Commands;

use App\Support\ProductionEnvironmentValidator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * production相当の環境でCORS/Sanctum/Session Cookie関連の設定が揃っているかを
 * 検証する。config読み込み自体(config:cache含む)では例外を投げない設計にしたため、
 * その代わりにこのコマンドをコンテナ起動時(nginx/php-fpm起動前、およびqueue worker
 * 起動前)に実行し、設定漏れがあれば即座にexit 1で起動を失敗させる。
 *
 * config()の値のみを見る(env()はconfig:cache後に信頼できないため使わない)。
 * Secret値(APIキー等)は一切扱わない/出力しない。
 */
#[Signature('app:validate-production-env')]
#[Description('Validate CORS/Sanctum/Session Cookie configuration before serving traffic in production')]
class ValidateProductionEnvironment extends Command
{
    public function handle(): int
    {
        $errors = ProductionEnvironmentValidator::validate();

        if ($errors === []) {
            $this->info('Production environment configuration is valid.');

            return self::SUCCESS;
        }

        foreach ($errors as $error) {
            $this->error($error);
        }

        return self::FAILURE;
    }
}
