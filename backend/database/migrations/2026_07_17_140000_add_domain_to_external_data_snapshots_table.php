<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * キャッシュキー(provider, operation, normalized_domain, database)での検索を
 * 効率化するため、domain/databaseを専用カラムとして追加する
 * (normalized_data jsonbの中身をスキャンして探すのは非効率かつ壊れやすいため)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_data_snapshots', function (Blueprint $table) {
            $table->string('domain')->nullable()->after('operation');
            $table->string('database', 10)->nullable()->after('domain');
            $table->index(['provider', 'operation', 'domain', 'database']);
        });
    }

    public function down(): void
    {
        Schema::table('external_data_snapshots', function (Blueprint $table) {
            $table->dropIndex(['provider', 'operation', 'domain', 'database']);
            $table->dropColumn(['domain', 'database']);
        });
    }
};
