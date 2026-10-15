<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relevance_item_neighbors', function (Blueprint $table) {
            $table->string('index');
            $table->string('algo', 32);
            $table->string('item_id');
            $table->string('neighbor_id');
            $table->float('score');
            $table->string('model_version', 40);
            $table->timestamps();

            $table->primary(['index', 'algo', 'item_id', 'neighbor_id']);
            $table->index(['index', 'algo', 'item_id', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relevance_item_neighbors');
    }
};
