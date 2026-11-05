<?php

declare(strict_types=1);

$root = dirname(__DIR__);

if (!is_file($root.'/vendor/autoload.php')) {
    http_response_code(500);
    exit('Dependencies are missing. Run "composer install" or upload the release ZIP, which includes them.');
}

require $root.'/vendor/autoload.php';

Csv2Excel\Web\App::fromProjectRoot($root)->run();
