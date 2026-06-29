<?php

namespace Snawbar\SelfUpdater\Commands;

use Illuminate\Console\Command;

class SelfUpdaterCommand extends Command
{
    public $signature = 'self-updater';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
