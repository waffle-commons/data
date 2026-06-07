<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

use InvalidArgumentException;

use function preg_match;
use function rawurlencode;
use function sprintf;
use function trim;

/**
 * Builds and validates the *only* two collection paths a Firestore document may
 * live under, per the persistence design's path-isolation rule:
 *
 *  - public:  `/artifacts/{appId}/public/data/{collection}`
 *  - private: `/artifacts/{appId}/users/{userId}/{collection}`
 *
 * Root collections are forbidden by construction — there is no factory that
 * produces one. Every path segment is validated against a strict whitelist and
 * percent-encoded, so a crafted id or collection name cannot inject extra path
 * segments (e.g. `../` traversal or a smuggled `/users/{other}`).
 */
final readonly class FirestoreScope
{
    private const string SEGMENT_PATTERN = '/^[A-Za-z0-9_-]+$/';

    private function __construct(
        public string $path,
    ) {}

    /**
     * Scope to the shared, app-wide public collection.
     *
     * @throws InvalidArgumentException When an identifier is blank or malformed.
     */
    public static function public(string $appId, string $collection): self
    {
        return new self(sprintf(
            'artifacts/%s/public/data/%s',
            self::segment($appId, 'appId'),
            self::segment($collection, 'collection'),
        ));
    }

    /**
     * Scope to a single user's private collection.
     *
     * @throws InvalidArgumentException When an identifier is blank or malformed.
     */
    public static function private(string $appId, string $userId, string $collection): self
    {
        return new self(sprintf(
            'artifacts/%s/users/%s/%s',
            self::segment($appId, 'appId'),
            self::segment($userId, 'userId'),
            self::segment($collection, 'collection'),
        ));
    }

    /**
     * Validate a single path segment and return it percent-encoded.
     *
     * @throws InvalidArgumentException When $value is blank or contains anything
     *                                  outside the safe segment whitelist.
     */
    private static function segment(string $value, string $label): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new InvalidArgumentException(sprintf('Firestore path segment "%s" must not be blank.', $label));
        }

        if (preg_match(self::SEGMENT_PATTERN, $trimmed) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Firestore path segment "%s" contains illegal characters; only [A-Za-z0-9_-] are allowed.',
                $label,
            ));
        }

        return rawurlencode($trimmed);
    }
}
