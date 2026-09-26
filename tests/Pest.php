<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Pest
|--------------------------------------------------------------------------
| Los tests de Feature usan PostgreSQL real (base *_test, ver .env.testing) y se
| envuelven en RefreshDatabase. La guarda contra bases que no sean de test está
| en Tests\TestCase::setUp().
*/

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(Tests\TestCase::class)->in('Unit');
