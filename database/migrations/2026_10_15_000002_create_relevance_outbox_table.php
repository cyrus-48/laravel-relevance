<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relevance_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('index');
            $table->string('doc_id');
            $table->string('op', 16); // upsert, delete
            $table->unsignedBigInteger('version');
            $table->string('tenant_id')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('status', 16)->default('pending'); // pending, processing, done, dead
            $table->timestamp('available_at')->useCurrent();
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at', 'id']);
            $table->index(['index', 'doc_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relevance_outbox');
    }
};
