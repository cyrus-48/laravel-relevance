<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relevance_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('index');
            $table->integer('priority')->default(0);
            $table->string('matcher_type', 32); // exact, contains, regex
            $table->string('matcher_value');
            $table->json('actions'); // array of action specs: pin, bury, exclude, boost, inject_filter, redirect, switch_profile, rewrite
            $table->boolean('enabled')->default(true);
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->timestamps();

            $table->index(['index', 'enabled', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relevance_rules');
    }
};
