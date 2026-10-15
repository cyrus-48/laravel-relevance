<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relevance_experiments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('index')->default('*');
            $table->string('type', 32)->default('search_profile'); // search_profile, placement, interleaving
            $table->json('variants'); // e.g. {"control": "products.default", "treatment": "products.v8"}
            $table->json('split'); // e.g. [50, 50]
            $table->string('status', 16)->default('running'); // draft, running, paused, completed
            $table->string('salt')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->timestamps();

            $table->index(['index', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relevance_experiments');
    }
};
