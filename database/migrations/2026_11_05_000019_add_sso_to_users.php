<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sso_issuer')->nullable();
            $table->string('sso_subject')->nullable();
            $table->unique(['sso_issuer', 'sso_subject']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['sso_issuer', 'sso_subject']);
            $table->dropColumn(['sso_issuer', 'sso_subject']);
        });
    }
};
