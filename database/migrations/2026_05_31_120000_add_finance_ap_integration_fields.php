<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (! Schema::hasColumn('m_r_f_s', 'finance_ap_case_id')) {
                $table->string('finance_ap_case_id', 64)->nullable()->after('scm_transaction_id');
                $table->index('finance_ap_case_id');
            }
            if (! Schema::hasColumn('m_r_f_s', 'finance_ap_status')) {
                $table->string('finance_ap_status', 50)->nullable()->after('finance_ap_case_id');
            }
        });

        Schema::create('finance_sync_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mrf_id')->nullable()->constrained('m_r_f_s')->nullOnDelete();
            $table->uuid('scm_transaction_id')->nullable();
            $table->string('direction', 16);
            $table->string('event_type', 64);
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('correlation_id', 64)->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['scm_transaction_id', 'direction']);
            $table->index(['mrf_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_sync_events');

        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (Schema::hasColumn('m_r_f_s', 'finance_ap_status')) {
                $table->dropColumn('finance_ap_status');
            }
            if (Schema::hasColumn('m_r_f_s', 'finance_ap_case_id')) {
                $table->dropColumn('finance_ap_case_id');
            }
        });
    }
};
