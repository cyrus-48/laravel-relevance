<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relevance_synonyms', function (Blueprint $table) {
            $table->id();
            $table->string('index');
            $table->string('type', 16); // one_way, multi_way
            $table->string('source')->nullable();
            $table->json('synonyms');
            $table->string('locale', 16)->nullable();
            $table->timestamps();

            $table->index(['index', 'locale']);
            $table->index(['index', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relevance_synonyms');
    }
};
