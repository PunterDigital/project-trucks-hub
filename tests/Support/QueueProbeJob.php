<?php

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/** A queued job for the admin Queue page tests (#4666): succeeds, or fails on its only try. */
class QueueProbeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public bool $fail = false) {}

    public function handle(): void
    {
        if ($this->fail) {
            throw new RuntimeException('Probe failed on purpose');
        }
    }
}
