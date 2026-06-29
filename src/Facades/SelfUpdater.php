<?php

namespace Snawbar\SelfUpdater\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Snawbar\SelfUpdater\SelfUpdater
 */
class SelfUpdater extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Snawbar\SelfUpdater\SelfUpdater::class;
    }
}
