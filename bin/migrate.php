<?php

declare(strict_types=1);

use Modulento\Core\Support\Database;
use Modulento\Core\Support\Migrator;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$config = require $root . '/config/config.php';

foreach (Migrator::runAll(Database::connect($config['db']), $root) as $source => $filenames) {
    foreach ($filenames as $filename) {
        echo "{$source}: {$filename}\n";
    }
}

echo "Done.\n";
