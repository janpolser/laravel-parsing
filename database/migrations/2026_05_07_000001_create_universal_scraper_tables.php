<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
        }

        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->text('site_key')->unique();
            $table->text('domain')->nullable();
            $table->text('base_url')->nullable();
            $table->text('company_name')->nullable();
            $table->text('company_contact')->nullable();
            $table->jsonb('company_emails')->default($this->jsonDefault('[]'));
            $table->jsonb('company_phones')->default($this->jsonDefault('[]'));
            $table->jsonb('company_sites')->default($this->jsonDefault('[]'));
            $table->text('region')->nullable();
            $table->text('city')->nullable();
            $table->text('address')->nullable();
            $table->text('career_url')->nullable();
            $table->text('parse_status')->default('parseable');
            $table->text('status')->default('ready');
            $table->integer('empty_runs')->default(0);
            $table->integer('last_http_status')->nullable();
            $table->timestampTz('last_modified_at')->nullable();
            $table->timestampTz('last_scraped_at')->nullable();
            $table->timestampTz('next_scrape_at')->nullable()->useCurrent();
            $table->integer('fail_count')->default(0);
            $table->timestampsTz();

            $table->index(['status', 'next_scrape_at'], 'sites_next_status_idx');
        });

        Schema::create('vacancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->text('title');
            $table->text('company')->nullable();
            $table->text('location')->nullable();
            $table->text('description');
            $table->jsonb('contacts')->default($this->jsonDefault('[]'));
            $table->double('salary_value')->nullable();
            $table->text('salary_currency')->nullable();
            $table->text('job_type')->nullable();
            $table->text('level')->nullable();
            $table->jsonb('skills')->default($this->jsonDefault('[]'));
            $table->text('posted_at')->nullable();
            $table->text('source_url')->nullable();
            $table->timestampTz('scraped_at');
            $table->timestampTz('first_seen_at')->useCurrent();
            $table->timestampTz('last_seen_at')->useCurrent();
            $table->boolean('is_active')->default(true);
            $table->string('content_hash', 64);
            $table->jsonb('meta')->default($this->jsonDefault('{}'));

            $table->index(['site_id', 'is_active'], 'vacancies_site_active_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX vacancies_site_source_url_idx ON vacancies(site_id, source_url) WHERE source_url IS NOT NULL');
        } else {
            Schema::table('vacancies', function (Blueprint $table) {
                $table->unique(['site_id', 'source_url'], 'vacancies_site_source_url_idx');
            });
        }

        Schema::create('scrape_runs', function (Blueprint $table) {
            $id = $table->uuid('id')->primary();
            if (DB::getDriverName() === 'pgsql') {
                $id->default(DB::raw('gen_random_uuid()'));
            }
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();
            $table->text('status');
            $table->integer('pages_scanned')->default(0);
            $table->integer('jobs_found')->default(0);
            $table->integer('jobs_upserted')->default(0);
            $table->text('error_message')->nullable();
            $table->text('llm_provider')->nullable();
            $table->text('llm_model')->nullable();
        });

        Schema::create('task_outbox', function (Blueprint $table) {
            $table->id();
            $table->text('task_name');
            $table->text('site_key');
            $table->jsonb('payload')->default($this->jsonDefault('{}'));
            $table->text('status')->default('pending');
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->timestampTz('published_at')->nullable();

            $table->index(['status', 'created_at'], 'task_outbox_status_created_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX task_outbox_pending_unique_idx ON task_outbox(task_name, site_key) WHERE published_at IS NULL');
        } else {
            Schema::table('task_outbox', function (Blueprint $table) {
                $table->unique(['task_name', 'site_key'], 'task_outbox_pending_unique_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_outbox');
        Schema::dropIfExists('scrape_runs');
        Schema::dropIfExists('vacancies');
        Schema::dropIfExists('sites');
    }

    private function jsonDefault(string $json): mixed
    {
        return DB::getDriverName() === 'pgsql' ? DB::raw("'{$json}'::jsonb") : $json;
    }
};
