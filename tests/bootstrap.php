<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

// Serving the workbench testing site copies workbench/.env into the testbench
// skeleton as .env, and an interrupted `testbench serve` leaves it behind.
// Tests that boot the skeleton would then load those values and fail on
// asserted defaults, so remove the leftover before the suite runs. The next
// testbench CLI invocation recreates it from workbench/.env(.example).
$skeletonEnv = __DIR__.'/../vendor/orchestra/testbench-core/laravel/.env';
if (is_file($skeletonEnv)) {
    // The path is a compile-time constant relative to this file — no user input.
    // nosemgrep: php.lang.security.unlink-use.unlink-use
    unlink($skeletonEnv);
}
