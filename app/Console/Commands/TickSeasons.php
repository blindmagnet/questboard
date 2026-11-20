<?php

namespace App\Console\Commands;

use App\Domain\Seasons\SeasonManager;
use App\Jobs\PublishOutboxJob;
use Illuminate\Console\Command;

class TickSeasons extends Command
{
    protected $signature = 'seasons:tick';

    protected $description = 'Activate due seasons and close expired ones';

    public function handle(SeasonManager $seasons): int
    {
        $result = $seasons->tick();
        if ($result['closed'] > 0) {
            PublishOutboxJob::trigger();
        }
        $this->info("Activated {$result['activated']}, closed {$result['closed']}.");

        return self::SUCCESS;
    }
}
