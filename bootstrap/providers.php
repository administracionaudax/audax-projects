<?php

use App\Providers\AppServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\ChatServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\PortalServiceProvider;
use App\Providers\ReportDeliveryServiceProvider;
use App\Providers\ReportsServiceProvider;
use App\Providers\WeekliesServiceProvider;

return [
    AppServiceProvider::class,
    BillingServiceProvider::class,
    ChatServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    ReportsServiceProvider::class,
    PortalServiceProvider::class,
    ReportDeliveryServiceProvider::class,
    WeekliesServiceProvider::class,
];
