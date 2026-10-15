<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relevance_embedding_cache', function (Blueprint $table) {
            $table->char('key', 64)->primary(); // sha256(fingerprint + input)
            $table->string('fingerprint');
            $table->json('vector');
            $table->unsignedInteger('tokens')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('fingerprint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relevance_embedding_cache');
    }
};
