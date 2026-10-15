<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relevance_interactions', function (Blueprint $table) {
            $table->id();
            $table->string('index');
            $table->string('tenant_id')->nullable();
            $table->string('user_ref')->nullable();
            $table->string('anon_ref')->nullable();
            $table->string('item_id');
            $table->string('type', 24);
            $table->float('weight')->nullable();
            $table->float('value')->nullable();
            $table->string('session_id', 64)->nullable();
            $table->string('search_id', 36)->nullable();
            $table->unsignedSmallInteger('position')->nullable();
            $table->string('placement')->nullable();
            $table->string('variant')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['index', 'user_ref', 'occurred_at']);
            $table->index(['index', 'item_id', 'occurred_at']);
            $table->index(['index', 'type', 'occurred_at']);
            $table->index('search_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relevance_interactions');
    }
};
