<?php

namespace ITFlow\Compliance;

use RivetCore\Compliance\CheckInterface;
use RivetCore\Compliance\CheckResult;

/** A compliance check defined by a closure; the closure returns a CheckResult. */
final class CallbackCheck implements CheckInterface
{
    /** @param array<string, list<string>> $controls */
    public function __construct(
        private string $id,
        private string $title,
        private string $category,
        private string $why,
        private array $controls,
        private \Closure $run,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function why(): string
    {
        return $this->why;
    }

    public function controls(): array
    {
        return $this->controls;
    }

    public function run(): CheckResult
    {
        return ($this->run)();
    }
}
