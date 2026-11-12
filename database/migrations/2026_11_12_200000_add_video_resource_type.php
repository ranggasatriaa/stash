<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE resources DROP CONSTRAINT resources_type_check');
        DB::statement("ALTER TABLE resources ADD CONSTRAINT resources_type_check CHECK (type IN ('link','image','article','video'))");
        DB::statement('ALTER TABLE resources DROP CONSTRAINT resources_url_check');
        DB::statement("ALTER TABLE resources ADD CONSTRAINT resources_url_check CHECK (type IN ('image','video') OR original_url IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM resources WHERE type = 'video'");
        DB::statement('ALTER TABLE resources DROP CONSTRAINT resources_type_check');
        DB::statement("ALTER TABLE resources ADD CONSTRAINT resources_type_check CHECK (type IN ('link','image','article'))");
        DB::statement('ALTER TABLE resources DROP CONSTRAINT resources_url_check');
        DB::statement("ALTER TABLE resources ADD CONSTRAINT resources_url_check CHECK (type = 'image' OR original_url IS NOT NULL)");
    }
};
