<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relevance_search_logs', function (Blueprint $table) {
            $table->id();
            $table->string('search_id', 36)->unique();
            $table->string('index');
            $table->string('query_norm');
            $table->string('query_hash', 64);
            $table->string('filters_hash', 64)->nullable();
            $table->string('profile')->nullable();
            $table->string('variant')->nullable();
            $table->integer('total')->default(0);
            $table->float('latency_ms')->default(0.0);
            $table->json('stages')->nullable();
            $table->json('degraded')->nullable();
            $table->string('user_ref')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['index', 'created_at']);
            $table->index(['query_hash', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relevance_search_logs');
    }
};
