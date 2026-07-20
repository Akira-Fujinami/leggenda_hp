<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * requested_domain: 正規化前にWebsiteから渡された元のホスト名(www有無や
 * サブドメインを含む、正規化を通す前の値)。
 * scope: normalized_domainがroot_domainかsubdomainかを明示する
 * ("いつ・どの単位で取得したデータか"を後から追跡できるようにするため)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_data_snapshots', function (Blueprint $table) {
            $table->string('requested_domain')->nullable()->after('website_analysis_id');
            $table->string('scope')->nullable()->after('domain');
        });
    }

    public function down(): void
    {
        Schema::table('external_data_snapshots', function (Blueprint $table) {
            $table->dropColumn(['requested_domain', 'scope']);
        });
    }
};
