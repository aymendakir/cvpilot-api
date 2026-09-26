<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('blog_posts', function(Blueprint $t) {
  $t->id(); $t->string('title',180); $t->string('slug',180)->unique(); $t->string('excerpt',320);
  $t->longText('body'); $t->string('author_name',120); $t->string('status',16)->default('draft');
  $t->timestamp('published_at')->nullable(); $t->timestamps(); $t->index(['status','published_at']);
 }); }
 public function down(): void { Schema::dropIfExists('blog_posts'); }
};
