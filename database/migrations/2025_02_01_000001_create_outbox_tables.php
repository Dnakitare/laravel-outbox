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
            $table->uuid('transaction_id')->index();
            $table->uuid('correlation_id')->index();
            $table->unsignedInteger('sequence_number');
            $table->string('type'); // 'event' or 'job'
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->string('message_type');
            $table->longText('payload');
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->json('processing_history')->nullable();
            $table->timestamps();

            $table->index(['status', 'attempts']);
            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index(['created_at']);
            $table->index(['sequence_number']);
        });

        Schema::create('outbox_dead_letter', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('original_message_id')->index();
            $table->uuid('transaction_id')->index();
            $table->uuid('correlation_id')->index();
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->string('message_type');
            $table->longText('payload');
            $table->text('error');
            $table->text('stack_trace');
            $table->json('metadata');
            $table->timestamp('failed_at');
            $table->timestamps();

            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index(['created_at']);
            $table->index(['failed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_dead_letter');
        Schema::dropIfExists('outbox_messages');
    }
};
