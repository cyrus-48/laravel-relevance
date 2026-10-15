<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relevance_index_generations', function (Blueprint $table) {
            $table->id();
            $table->string('index');
            $table->unsignedInteger('generation');
            $table->string('physical_name')->unique();
            $table->string('embedding_fingerprint')->nullable();
            $table->string('schema_hash', 64)->nullable();
            $table->string('state', 20)->default('building'); // building, active, retired, failed
            $table->json('meta')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['index', 'generation']);
            $table->index(['index', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relevance_index_generations');
    }
};
