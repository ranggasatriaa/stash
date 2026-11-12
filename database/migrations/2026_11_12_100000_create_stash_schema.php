<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Core Stash schema. PostgreSQL is required: the migration relies on
 * pg_trgm, generated tsvector columns and CHECK constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->default('UTC');
            $table->jsonb('preferences')->default('{}');
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('color', 16)->nullable();
            $table->string('icon', 40)->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'name']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('normalized_name', 64);
            $table->string('color', 16)->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'normalized_name']);
        });

        Schema::create('resources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->text('personal_notes')->nullable();
            $table->text('original_url')->nullable();
            $table->text('normalized_url')->nullable();
            $table->char('normalized_url_hash', 64)->nullable();
            $table->string('source_host', 255)->nullable();
            $table->foreignUlid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->boolean('read_later')->default(false);
            $table->string('reading_status', 8)->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->string('metadata_status', 16)->default('none');
            $table->string('metadata_error', 255)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->jsonb('manual_fields')->default('[]');
            $table->text('tag_names')->default('');
            $table->timestampTz('archived_at')->nullable();
            $table->softDeletesTz();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->index(['user_id', 'created_at', 'id']);
            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'project_id']);
            $table->index(['user_id', 'normalized_url_hash']);
            $table->index(['user_id', 'archived_at']);
            $table->index(['user_id', 'deleted_at']);
            $table->index(['user_id', 'metadata_status']);
        });

        DB::statement("ALTER TABLE resources ADD CONSTRAINT resources_type_check CHECK (type IN ('link','image','article'))");
        DB::statement("ALTER TABLE resources ADD CONSTRAINT resources_reading_check CHECK (
            (type = 'article' AND reading_status IN ('unread','read'))
            OR (type <> 'article' AND reading_status IS NULL AND read_later = false AND read_at IS NULL)
        )");
        DB::statement("ALTER TABLE resources ADD CONSTRAINT resources_read_at_check CHECK (
            (reading_status = 'read' AND read_at IS NOT NULL) OR (reading_status IS DISTINCT FROM 'read' AND read_at IS NULL)
        )");
        DB::statement("ALTER TABLE resources ADD CONSTRAINT resources_url_check CHECK (type = 'image' OR original_url IS NOT NULL)");
        DB::statement("ALTER TABLE resources ADD CONSTRAINT resources_metadata_status_check CHECK (metadata_status IN ('none','queued','processing','ready','failed'))");
        DB::statement('ALTER TABLE resources ADD CONSTRAINT resources_version_check CHECK (version >= 1)');

        // Lower-cased document used for substring + trigram (fuzzy) matching.
        DB::statement("ALTER TABLE resources ADD COLUMN search_document text GENERATED ALWAYS AS (
            lower(coalesce(title, '') || ' ' || coalesce(tag_names, '') || ' ' || coalesce(description, '') || ' ' || coalesce(original_url, ''))
        ) STORED");

        // Weighted full-text vector: title + tags (A), description (B), URL words (C), notes (D).
        DB::statement("ALTER TABLE resources ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
            setweight(to_tsvector('simple'::regconfig, coalesce(title, '')), 'A')
            || setweight(to_tsvector('simple'::regconfig, coalesce(tag_names, '')), 'A')
            || setweight(to_tsvector('simple'::regconfig, coalesce(description, '')), 'B')
            || setweight(to_tsvector('simple'::regconfig, regexp_replace(coalesce(original_url, ''), '[^[:alnum:]]+', ' ', 'g')), 'C')
            || setweight(to_tsvector('simple'::regconfig, coalesce(personal_notes, '')), 'D')
        ) STORED");

        DB::statement('CREATE INDEX resources_search_vector_idx ON resources USING gin (search_vector)');
        DB::statement('CREATE INDEX resources_search_document_trgm_idx ON resources USING gin (search_document gin_trgm_ops)');
        DB::statement('CREATE INDEX resources_title_prefix_idx ON resources (user_id, lower(title) text_pattern_ops)');
        DB::statement('CREATE INDEX resources_title_sort_idx ON resources (user_id, lower(title), id)');
        DB::statement('CREATE INDEX resources_read_later_idx ON resources (user_id, created_at) WHERE read_later = true');

        Schema::create('resource_tag', function (Blueprint $table) {
            $table->foreignUlid('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->foreignUlid('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['resource_id', 'tag_id']);
            $table->index(['tag_id', 'resource_id']);
        });

        DB::statement('CREATE INDEX tags_normalized_name_trgm_idx ON tags USING gin (normalized_name gin_trgm_ops)');

        Schema::create('resource_files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->string('disk', 32);
            $table->string('storage_key', 512);
            $table->string('mime_type', 64);
            $table->string('extension', 8);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('checksum_sha256', 64);
            $table->string('status', 16)->default('ready');
            $table->string('error', 255)->nullable();
            $table->timestampsTz();

            $table->unique(['resource_id', 'role']);
            $table->index(['user_id', 'checksum_sha256']);
            $table->index(['user_id', 'role']);
        });

        DB::statement("ALTER TABLE resource_files ADD CONSTRAINT resource_files_role_check CHECK (role IN ('original','preview','thumbnail'))");
        DB::statement("ALTER TABLE resource_files ADD CONSTRAINT resource_files_status_check CHECK (status IN ('queued','processing','ready','failed'))");

        Schema::create('saved_views', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('section', 32)->default('all');
            $table->jsonb('filters')->default('{}');
            $table->string('sort', 16)->default('newest');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();

            $table->index(['user_id', 'position']);
        });

        Schema::create('ai_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('resource_id')->nullable()->constrained('resources')->nullOnDelete();
            $table->string('task', 32);
            $table->string('provider', 32);
            $table->string('model', 64);
            $table->string('prompt_version', 16);
            $table->string('status', 16);
            $table->string('source_kind', 16);
            $table->char('input_hash', 64);
            $table->unsignedInteger('input_chars');
            $table->text('input_text')->nullable();
            $table->unsignedInteger('reserved_tokens')->default(0);
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->boolean('usage_estimated')->default(false);
            $table->boolean('cached')->default(false);
            $table->jsonb('result')->nullable();
            $table->string('error_code', 48)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('idempotency_key', 255)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'input_hash', 'model', 'prompt_version']);
            $table->index(['resource_id', 'created_at']);
        });

        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_check CHECK (status IN ('queued','running','succeeded','failed'))");

        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('actor_type', 16);
            $table->string('actor_id', 64)->nullable();
            $table->string('operation', 64);
            $table->string('entity_type', 32)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->jsonb('changes')->default('{}');
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 64);
            $table->string('key', 255);
            $table->char('request_hash', 64);
            $table->string('method', 8);
            $table->string('path', 255);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->string('resource_ref', 64)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->unique(['user_id', 'scope', 'key']);
            $table->index('expires_at');
        });

        Schema::create('data_exports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('format', 16);
            $table->boolean('include_trash')->default(false);
            $table->string('status', 16)->default('queued');
            $table->string('disk', 32)->nullable();
            $table->string('storage_key', 512)->nullable();
            $table->string('filename', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->jsonb('stats')->default('{}');
            $table->string('error', 255)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE data_exports ADD CONSTRAINT data_exports_format_check CHECK (format IN ('json','zip','obsidian'))");

        Schema::create('data_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('format', 16);
            $table->string('status', 16)->default('uploaded');
            $table->string('disk', 32);
            $table->string('storage_key', 512);
            $table->string('original_filename', 255);
            $table->unsignedBigInteger('size_bytes');
            $table->string('collision_strategy', 16)->default('skip');
            $table->jsonb('preview')->nullable();
            $table->jsonb('report')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_imports');
        Schema::dropIfExists('data_exports');
        Schema::dropIfExists('idempotency_records');
        Schema::dropIfExists('activity_events');
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('saved_views');
        Schema::dropIfExists('resource_files');
        Schema::dropIfExists('resource_tag');
        Schema::dropIfExists('resources');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('projects');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'preferences']);
        });
    }
};
