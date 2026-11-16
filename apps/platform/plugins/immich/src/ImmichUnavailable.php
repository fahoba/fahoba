<?php

declare(strict_types=1);

namespace Fahoba\Immich;

use RuntimeException;

/** Immich did not answer as expected; the listing stays empty for a minute. */
final class ImmichUnavailable extends RuntimeException {}
