<?php

declare(strict_types=1);

// Called once a minute by the system cron:
//   * * * * * php /path/to/modulento/bin/cron.php >> /path/to/modulento/var/log/cron.log 2>&1
// It lives outside the web root and refuses any non-CLI call, so nobody
// can trigger scheduled work through a URL.

use Modulento\Core\Kernel;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$app = Kernel::boot($root, web: false);
$failed = false;

foreach ($app->scheduler->runDue($app) as $name => $result) {
    echo date('c') . " {$name}: {$result}\n";
    $failed = $failed || $result !== 'ok';
}

exit($failed ? 1 : 0);
