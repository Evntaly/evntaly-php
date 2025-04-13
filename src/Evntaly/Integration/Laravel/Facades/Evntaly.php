<?php

namespace Evntaly\Integration\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

class Evntaly extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'evntaly';
    }
}
