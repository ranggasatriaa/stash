<?php

namespace Database\Factories;

use App\Models\User;
use App\Stash\Enums\MetadataStatus;
use App\Stash\Enums\ReadingStatus;
use App\Stash\Enums\ResourceType;
use App\Stash\Models\Resource;
use App\Stash\Support\UrlNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Resource>
 */
class ResourceFactory extends Factory
{
    protected $model = Resource::class;

    public function definition(): array
    {
        $url = 'https://'.fake()->domainName().'/'.fake()->slug(3);
        $n = UrlNormalizer::normalize($url);

        return [
            'user_id' => User::factory(),
            'type' => ResourceType::Link,
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(10),
            'original_url' => $url,
            'normalized_url' => $n['normalized'],
            'normalized_url_hash' => $n['hash'],
            'source_host' => $n['host'],
            'metadata_status' => MetadataStatus::Ready,
            'metadata' => [],
            'manual_fields' => [],
        ];
    }

    public function article(string $status = 'unread', bool $readLater = false): static
    {
        return $this->state(fn () => [
            'type' => ResourceType::Article,
            'reading_status' => ReadingStatus::from($status),
            'read_at' => $status === 'read' ? now() : null,
            'read_later' => $readLater,
        ]);
    }

    public function forUrl(string $url): static
    {
        $n = UrlNormalizer::normalize($url);

        return $this->state(fn () => [
            'original_url' => $url, 'normalized_url' => $n['normalized'], 'normalized_url_hash' => $n['hash'], 'source_host' => $n['host'],
        ]);
    }
}
