<?php
declare(strict_types=1);

/** A failed route operation; $data is merged into the JSON error response (step, output, apex_conflict…). */
class RouteException extends RuntimeException
{
    public function __construct(string $message, int $code = 400, public array $data = [])
    {
        parent::__construct($message, $code);
    }
}
