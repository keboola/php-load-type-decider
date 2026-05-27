<?php

declare(strict_types=1);

namespace Keboola\Package\LoadTypeDecider;

/**
 * Concrete load types the decider can resolve to. Intentionally has no `AUTO`
 * member — {@see LoadTypeDecider::decide()} always returns a concrete choice.
 *
 * The string values mirror the connection app's
 * `Keboola\Storage\Workspace\Load\Request\LoadType` so callers can map between
 * the two with `AppLoadType::from($decided->value)` without this dependency-free
 * library having to know about the app enum.
 */
enum LoadType: string
{
    case COPY = 'COPY';
    case CLONE = 'CLONE';
    case VIEW = 'VIEW';
}
