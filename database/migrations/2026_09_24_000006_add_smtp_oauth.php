<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('mail_settings', function (Blueprint $table) {
            $table->string('auth_mode', 20)->default('password');
            $table->string('oauth_tenant')->default('common');
            $table->string('oauth_client_id')->nullable();
            $table->text('oauth_client_secret')->nullable();
            $table->text('oauth_refresh_token')->nullable();
            $table->text('oauth_access_token')->nullable();
            $table->timestamp('oauth_expires_at')->nullable();
        });
    }

    public function down(): void {
        Schema::table('mail_settings', fn (Blueprint $table) => $table->dropColumn([
            'auth_mode', 'oauth_tenant', 'oauth_client_id', 'oauth_client_secret',
            'oauth_refresh_token', 'oauth_access_token', 'oauth_expires_at',
        ]));
    }
};
