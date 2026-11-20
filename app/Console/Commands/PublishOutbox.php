<?php

namespace App\Console\Commands;

use App\Domain\Outbox\OutboxPublisher;
use Illuminate\Console\Command;

class PublishOutbox extends Command
{
    protected $signature = 'outbox:publish {--limit=500}';

    protected $description = 'Publish pending outbox events to the queue';

    public function handle(OutboxPublisher $publisher): int
    {
        $count = $publisher->publishPending((int) $this->option('limit'));
        $this->info("Published {$count} event(s).");

        return self::SUCCESS;
    }
}
