<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relevance_model_artifacts', function (Blueprint $table) {
            $table->id();
            $table->string('index');
            $table->string('algo', 32);
            $table->string('model_version', 40);
            $table->json('metrics')->nullable();
            $table->json('manifest')->nullable();
            $table->string('status', 16)->default('pending'); // pending, active, rejected
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->index(['index', 'algo', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relevance_model_artifacts');
    }
};
