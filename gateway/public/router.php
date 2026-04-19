<?php

declare(strict_types=1);

use App\Kernel;

// Router for `php -S` dev server. Without this, paths ending in non-.php
// extensions (e.g. /api/doc.json) get a hard 404 from the CLI server instead
// of routing through Symfony.
//
// Real files under public/ are served as static content via `return false`.
// Everything else boots the Symfony Runtime — this file mirrors public/index.php
// because the Runtime expects the top-level script to return the kernel factory.

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__.$path)) {
    return false;
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
