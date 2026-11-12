<?php

/*
 * Router for PHP's built-in server with production-like upload limits:
 *   php -d upload_max_filesize=12M -d post_max_size=14M -S 127.0.0.1:8000 -t public scripts/dev-router.php
 * (`php artisan serve` cannot pass -d flags to the child server.)
 */
chdir(__DIR__.'/../public');

return require __DIR__.'/../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
