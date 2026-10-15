<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relevance_experiment_exposures', function (Blueprint $table) {
            $table->id();
            $table->string('experiment_name');
            $table->string('unit_id'); // user_ref or anon_ref
            $table->string('variant');
            $table->string('search_id', 36)->nullable();
            $table->string('placement')->nullable();
            $table->timestamp('exposed_at')->useCurrent();

            $table->index(['experiment_name', 'exposed_at']);
            $table->index(['experiment_name', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relevance_experiment_exposures');
    }
};
