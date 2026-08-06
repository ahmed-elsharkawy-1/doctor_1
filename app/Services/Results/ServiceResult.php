<?php

namespace App\Services\Results;

/**
 * Base for service result objects. A service returns one of these rather than
 * an array, so the shape of a payload lives in one testable class and the
 * controller stays a three-line orchestration.
 */
abstract class ServiceResult
{
    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;
}
