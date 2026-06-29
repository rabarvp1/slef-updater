<?php

namespace Snawbar\SelfUpdater;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Snawbar\SelfUpdater\Commands\SelfUpdaterCommand;

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
            ->hasCommand(SelfUpdaterCommand::class);
    }
}
