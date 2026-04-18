<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id');
            $table->uuid('correlation_id');
            $table->unsignedInteger('sequence_number');
            $table->string('type', 16);
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->string('message_type');
            $table->longText('payload');
            $table->string('payload_hash', 64);
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->json('history')->nullable();
            $table->timestamps();

            // Worker claim path: status + available_at is the hot index.
            $table->index(['status', 'available_at', 'sequence_number'], 'outbox_claim_idx');
            $table->index(['transaction_id']);
            $table->index(['correlation_id']);
            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index(['processed_at']);
            $table->index(['created_at']);
        });

        Schema::create('outbox_dead_letter', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('original_message_id');
            $table->uuid('transaction_id');
            $table->uuid('correlation_id');
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->string('message_type');
            $table->longText('payload');
            $table->string('payload_hash', 64);
            $table->text('error');
            $table->longText('stack_trace');
            $table->json('metadata')->nullable();
            $table->timestamp('failed_at');
            $table->timestamps();

            $table->index(['original_message_id']);
            $table->index(['transaction_id']);
            $table->index(['correlation_id']);
            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index(['failed_at']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_dead_letter');
        Schema::dropIfExists('outbox_messages');
    }
};
