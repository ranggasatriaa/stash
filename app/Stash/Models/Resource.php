<?php

namespace App\Stash\Models;

use App\Models\User;
use App\Stash\Enums\FileRole;
use App\Stash\Enums\MetadataStatus;
use App\Stash\Enums\ReadingStatus;
use App\Stash\Enums\ResourceType;
use Carbon\CarbonImmutable;
use Database\Factories\ResourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A saved item: link, image or article. Every navigation section is a view
 * over this single table.
 *
 * @property string $id
 * @property int $user_id
 * @property ResourceType $type
 * @property string $title
 * @property string|null $description
 * @property string|null $personal_notes
 * @property string|null $original_url
 * @property string|null $normalized_url
 * @property string|null $normalized_url_hash
 * @property string|null $source_host
 * @property string|null $project_id
 * @property bool $read_later
 * @property ReadingStatus|null $reading_status
 * @property CarbonImmutable|null $read_at
 * @property MetadataStatus $metadata_status
 * @property string|null $metadata_error
 * @property array<string, mixed> $metadata
 * @property list<string> $manual_fields
 * @property string $tag_names
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $deleted_at
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Resource extends Model
{
    /** @use HasFactory<ResourceFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /** Columns read for listings (excludes the large generated search columns). */
    public const LIST_COLUMNS = [
        'id', 'user_id', 'type', 'title', 'description', 'original_url', 'source_host',
        'project_id', 'read_later', 'reading_status', 'read_at', 'metadata_status',
        'metadata_error', 'metadata', 'manual_fields', 'tag_names', 'archived_at',
        'deleted_at', 'version', 'created_at', 'updated_at', 'normalized_url_hash',
    ];

    /** Fields whose manual edits are protected from metadata enrichment. */
    public const ENRICHABLE_FIELDS = ['title', 'description'];

    protected $table = 'resources';

    protected $fillable = [
        'type', 'title', 'description', 'personal_notes', 'original_url', 'normalized_url',
        'normalized_url_hash', 'source_host', 'project_id', 'read_later', 'reading_status',
        'read_at', 'metadata_status', 'metadata_error', 'metadata', 'manual_fields',
        'tag_names', 'archived_at',
    ];

    protected $hidden = ['search_document', 'search_vector'];

    protected $attributes = [
        'metadata' => '{}',
        'manual_fields' => '[]',
        'tag_names' => '',
        'read_later' => false,
        'metadata_status' => 'none',
        'version' => 1,
    ];

    protected static function newFactory(): ResourceFactory
    {
        return ResourceFactory::new();
    }

    protected function casts(): array
    {
        return [
            'type' => ResourceType::class,
            'reading_status' => ReadingStatus::class,
            'metadata_status' => MetadataStatus::class,
            'metadata' => 'array',
            'manual_fields' => 'array',
            'read_later' => 'boolean',
            'read_at' => 'datetime',
            'archived_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'resource_tag')->orderBy('tags.normalized_name');
    }

    /** @return HasMany<ResourceFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(ResourceFile::class);
    }

    /** @return HasMany<AiRun, $this> */
    public function aiRuns(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    public function file(FileRole $role): ?ResourceFile
    {
        $files = $this->relationLoaded('files') ? $this->files : $this->files()->get();

        return $files->first(fn (ResourceFile $f) => $f->role === $role);
    }

    /** Best available image for cards: thumbnail, then preview. */
    public function cardImage(): ?ResourceFile
    {
        foreach ([FileRole::Thumbnail, FileRole::Preview] as $role) {
            $file = $this->file($role);
            if ($file && $file->status === 'ready') {
                return $file;
            }
        }

        return null;
    }

    public function isArticle(): bool
    {
        return $this->type === ResourceType::Article;
    }

    public function isManual(string $field): bool
    {
        return in_array($field, $this->manual_fields ?? [], true);
    }

    /** @param Builder<Resource> $query */
    public function scopeOwnedBy(Builder $query, User|int $user): void
    {
        $query->where('resources.user_id', $user instanceof User ? $user->id : $user);
    }

    /**
     * Not archived. Soft-deleted (trashed) rows are already hidden by the
     * SoftDeletes global scope.
     *
     * @param  Builder<Resource>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('resources.archived_at');
    }
}
