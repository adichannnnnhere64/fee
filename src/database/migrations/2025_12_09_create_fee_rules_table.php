<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_rules', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('entity');
            $table->enum('item_type', ['product', 'service']);
            $table->enum('fee_type', ['markup', 'commission', 'convenience']);
            $table->decimal('value', 10, 4);
            $table->integer('calculation_type')->default(1);
            $table->boolean('is_active')->default(true);
            $table->boolean('apply_to_existing_entity')->default(false)->after('is_global');
            $table->boolean('is_global')->default(false);
            $table->timestamp('effective_from')->nullable();
            /* $table->timestamp('effective_to')->nullable(); */
            $table->timestamps();

            $table->index(['entity_type', 'entity_id', 'item_type', 'is_active']);
            $table->index(['is_global', 'item_type', 'is_active']);
            $table->index(['effective_from', 'effective_to']);
        });

        Schema::create('fee_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fee_rule_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('entity');
            $table->string('action'); // created, updated, deactivated
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            /* $table->index(['entity_type', 'entity_id']); */
            $table->index(['fee_rule_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_histories');
        Schema::dropIfExists('fee_rules');
    }
};
