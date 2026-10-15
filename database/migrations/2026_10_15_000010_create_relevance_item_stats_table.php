<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relevance_item_stats', function (Blueprint $table) {
            $table->string('index');
            $table->string('item_id');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('purchases')->default(0);
            $table->float('popularity_score')->default(0.0);
            $table->timestamps();

            $table->primary(['index', 'item_id']);
            $table->index(['index', 'popularity_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relevance_item_stats');
    }
};
