<?php

namespace Snawbar\SelfUpdater;

use Snawbar\SelfUpdater\Commands\CompareDatabasesCommand;
use Snawbar\SelfUpdater\Commands\SelfUpdaterCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SelfUpdaterServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('self-updater')
            ->hasConfigFile()
            ->hasViews()
            ->hasRoute('web')
            ->hasMigration('create_self_updater_table')
            ->hasCommands([
                SelfUpdaterCommand::class,
                CompareDatabasesCommand::class,
            ]);
    }
}
