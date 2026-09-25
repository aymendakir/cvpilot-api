<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id(); $table->json('data'); $table->timestamps();
        });
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id(); $table->string('name', 120); $table->string('email', 254);
            $table->string('topic', 30); $table->text('message');
            $table->string('status', 20)->default('new')->index(); $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('support_messages'); Schema::dropIfExists('site_settings');
    }
};
