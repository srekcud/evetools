<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// ClockMock must be registered before any test executes SyncTracker::computeHealth():
// PHP caches the resolution of an unqualified time() call on its first execution, so a
// later register() (e.g. in setUpBeforeClass) is ignored once another test ran that line.
Symfony\Bridge\PhpUnit\ClockMock::register(App\Service\Admin\SyncTracker::class);
