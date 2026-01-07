<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->unique(); // Internal tracking ID
            $table->foreignId('fee_rule_id')->nullable()->constrained()->nullOnDelete();

            // Who bears/paid the fee
            $table->nullableMorphs('fee_bearer');

            // What the fee was applied to (Order, Payment, Invoice, etc.)
            $table->nullableMorphs('feeable');

            $table->decimal('transaction_amount', 15, 4);
            $table->decimal('fee_amount', 15, 4);
            $table->string('currency', 3)->default('PHP');
            $table->string('status')->default('pending');
            $table->string('fee_type'); // markup, commission, convenience
            $table->string('reference_number')->nullable(); // External reference
            $table->json('metadata')->nullable(); // Additional data
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['fee_bearer_type', 'fee_bearer_id', 'applied_at']);
            $table->index(['transaction_id']);
            $table->index(['reference_number']);
            $table->index(['status', 'applied_at']);
            $table->index(['fee_type', 'applied_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_transactions');
    }
};
