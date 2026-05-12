<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The original migration defined po_type as an enum of goods/services/logistics.
 * PostgreSQL enforces that as CHECK po_terms_templates_po_type_check, which
 * rejects the new "rfq" row from POTermsTemplateSeeder. Extend allowed values.
 */
return new class extends Migration
{
    private const ALLOWED = ['goods', 'services', 'logistics', 'rfq'];

    public function up(): void
    {
        if (!Schema::hasTable('po_terms_templates')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE po_terms_templates DROP CONSTRAINT IF EXISTS po_terms_templates_po_type_check');
            DB::statement("ALTER TABLE po_terms_templates ADD CONSTRAINT po_terms_templates_po_type_check CHECK ((po_type)::text IN ('goods','services','logistics','rfq'))");

            return;
        }

        if ($driver === 'mysql') {
            $enum = implode("','", self::ALLOWED);
            DB::statement("ALTER TABLE po_terms_templates MODIFY po_type ENUM('{$enum}') NOT NULL");

            return;
        }

        if ($driver === 'sqlite') {
            $this->expandSqlitePoTypeCheck();
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('po_terms_templates')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE po_terms_templates DROP CONSTRAINT IF EXISTS po_terms_templates_po_type_check');
            DB::statement("ALTER TABLE po_terms_templates ADD CONSTRAINT po_terms_templates_po_type_check CHECK ((po_type)::text IN ('goods','services','logistics'))");

            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE po_terms_templates MODIFY po_type ENUM('goods','services','logistics') NOT NULL");

            return;
        }

        if ($driver === 'sqlite') {
            $this->shrinkSqlitePoTypeCheck();
        }
    }

    private function expandSqlitePoTypeCheck(): void
    {
        $rows = DB::table('po_terms_templates')->get();
        Schema::drop('po_terms_templates');
        Schema::create('po_terms_templates', function (Blueprint $table) {
            $table->id();
            $table->enum('po_type', ['goods', 'services', 'logistics', 'rfq']);
            $table->longText('content');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['po_type', 'is_active']);
        });
        foreach ($rows as $row) {
            DB::table('po_terms_templates')->insert((array) $row);
        }
    }

    private function shrinkSqlitePoTypeCheck(): void
    {
        $rows = DB::table('po_terms_templates')->whereIn('po_type', ['goods', 'services', 'logistics'])->get();
        Schema::drop('po_terms_templates');
        Schema::create('po_terms_templates', function (Blueprint $table) {
            $table->id();
            $table->enum('po_type', ['goods', 'services', 'logistics']);
            $table->longText('content');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['po_type', 'is_active']);
        });
        foreach ($rows as $row) {
            DB::table('po_terms_templates')->insert((array) $row);
        }
    }
};
