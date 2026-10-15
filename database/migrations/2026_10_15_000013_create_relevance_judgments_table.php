<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relevance_judgments', function (Blueprint $table) {
            $table->id();
            $table->string('index');
            $table->string('judgment_set', 32)->default('default');
            $table->string('query');
            $table->string('doc_id');
            $table->unsignedTinyInteger('grade')->default(0); // 0 = irrelevant, 1 = relevant, 2 = highly relevant, 3 = perfect
            $table->string('source', 32)->nullable(); // editor, click_model, llm
            $table->string('judged_by')->nullable();
            $table->timestamps();

            $table->unique(['index', 'judgment_set', 'query', 'doc_id']);
            $table->index(['index', 'judgment_set', 'query']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relevance_judgments');
    }
};
