<?php

declare(strict_types=1);

namespace App\Demo;

/** A person of the timeline demo (the action log accepts any user object with an id). */
final readonly class DemoPerson
{
    public function __construct(private int $id, private string $displayName)
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }
}
