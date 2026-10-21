<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use Waffle\Commons\Contracts\Config\ConfigInterface;

/**
 * Config double that answers only `waffle.database.migrations_path`.
 */
final readonly class MigrationsPathConfig implements ConfigInterface
{
    public function __construct(
        private string $migrationsPath,
    ) {}

    #[\Override]
    public function getInt(string $key, ?int $default = null): ?int
    {
        return $default;
    }

    #[\Override]
    public function getString(string $key, ?string $default = null): ?string
    {
        return $key === 'waffle.database.migrations_path' ? $this->migrationsPath : $default;
    }

    /**
     * @param array<array-key, mixed>|null $default
     *
     * @return array<array-key, mixed>|null
     */
    #[\Override]
    public function getArray(string $key, ?array $default = null): ?array
    {
        return $default;
    }

    #[\Override]
    public function getBool(string $key, ?bool $default = null): ?bool
    {
        return $default;
    }
}
