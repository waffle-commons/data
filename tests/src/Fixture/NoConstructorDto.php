<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

/**
 * DTO without a constructor, used to verify the hydrator rejects targets it
 * cannot map.
 */
final class NoConstructorDto {}
