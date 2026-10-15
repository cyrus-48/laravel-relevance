<?php

declare(strict_types=1);

namespace Relevance\Search\Profiles;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProfileRepository
{
    /** @var array<string, array<string, mixed>> */
    private array $configProfiles;

    /**
     * @param array<string, array<string, mixed>> $configProfiles
     */
    public function __construct(array $configProfiles = [])
    {
        $this->configProfiles = $configProfiles;
    }

    /**
     * @param ProfileDefinition|string $profileOrName
     * @param array<string, mixed>|null $config
     */
    public function register(ProfileDefinition|string $profileOrName, ?array $config = null): self
    {
        if ($profileOrName instanceof ProfileDefinition) {
            $this->configProfiles[$profileOrName->name] = $profileOrName->toArray();
        } elseif ($config !== null) {
            $this->configProfiles[$profileOrName] = $config;
        }

        return $this;
    }

    public function resolve(string $name, string $defaultIndex = 'default'): ProfileDefinition
    {
        $config = $this->resolveConfig($name);

        if ($config === null) {
            return new ProfileDefinition(
                name: $name,
                index: $defaultIndex,
            );
        }

        if (!isset($config['index'])) {
            $config['index'] = $defaultIndex;
        }

        return ProfileDefinition::fromArray($name, $config);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveConfig(string $name): ?array
    {
        $raw = $this->loadRawConfig($name);
        if ($raw === null) {
            return null;
        }

        if (isset($raw['extends']) && is_string($raw['extends']) && $raw['extends'] !== $name) {
            $parent = $this->resolveConfig($raw['extends']);
            if ($parent !== null) {
                // Merge parent with child (child overrides parent)
                $merged = array_replace_recursive($parent, $raw);
                unset($merged['extends']);
                return $merged;
            }
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRawConfig(string $name): ?array
    {
        // 1. Check in-memory/config profiles
        if (isset($this->configProfiles[$name])) {
            return $this->configProfiles[$name];
        }

        // 2. Check DB profiles if table exists
        try {
            if (Schema::hasTable('relevance_profiles')) {
                $row = DB::table('relevance_profiles')
                    ->where('name', $name)
                    ->where('is_active', true)
                    ->orderByDesc('version')
                    ->first();

                if ($row !== null && isset($row->config)) {
                    /** @var array<string, mixed> $decoded */
                    $decoded = is_string($row->config) ? json_decode($row->config, true) : (array) $row->config;
                    if (isset($row->version)) {
                        $decoded['version'] = (int) $row->version;
                    }
                    return $decoded;
                }
            }
        } catch (\Throwable) {
            // DB not available or table not migrated yet
        }

        return null;
    }
}
