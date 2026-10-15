<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relevance_eval_runs', function (Blueprint $table) {
            $table->id();
            $table->string('index');
            $table->string('judgment_set', 32);
            $table->string('profile');
            $table->string('baseline_profile')->nullable();
            $table->json('metrics'); // ndcg_10, mrr, recall_50, map, zero_result_rate
            $table->json('diff')->nullable(); // win/loss list compared to baseline
            $table->boolean('passed')->default(true);
            $table->timestamps();

            $table->index(['index', 'judgment_set', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relevance_eval_runs');
    }
};
