<?php

declare(strict_types=1);

// Usage: php tests/browser/seed.php
// One-time fixture for scenario.py's catalogue-dependent checks (the
// unread badge, the media picker): enables the freelancer extension (it
// must already be at extensions/freelancer, as for tests/run.php), gives
// the admin account a provider profile and one published offer, uploads
// one picture to the media library, and sends that offer one message so
// the admin account has an unread message waiting. Safe to run twice.

use Modulento\Core\Kernel;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$email = $argv[1] ?? 'vis@example.test';
$app = Kernel::boot($root, web: false);

if (!in_array('freelancer', $app->extensions->enabledIds(), true)) {
    $app->extensions->enable('freelancer');
    // Re-boot: the offer type is only registered once per App instance.
    $app = Kernel::boot($root, web: false);
}

$adminId = (int) $app->db->query('SELECT id FROM account WHERE email = ' . $app->db->quote($email))->fetchColumn();
if ($adminId === 0) {
    fwrite(STDERR, "No account for {$email}\n");
    exit(1);
}

$provider = $app->providers->findByAccount($adminId);
if ($provider === null) {
    $provider = $app->providers->save($adminId, [
        'type' => 'private', 'name' => 'Admin Anbieter', 'legal_name' => 'Admin Anbieter',
        'street' => 'Teststr. 1', 'postal_code' => '10115', 'city' => 'Berlin', 'country' => 'DE',
        'contact_email' => '', 'vat_id' => '', 'tax_id' => '', 'company_register' => '', 'phone' => '', 'self_certified' => false,
    ], []);
    $app->providers->setStatus($provider['id'], 'approved', null, null);
}
$providerId = $provider['id'];

$offer = $app->offers->findPublicBySlug('de', 'testangebot');
if ($offer === null) {
    $offerId = $app->offers->save(null, $providerId, 'freelancer.service', null, [
        'de' => ['title' => 'Testangebot', 'summary' => 'Kurzbeschreibung.', 'description' => 'Langtext.'],
    ]);
    $db = $app->db;
    $db->prepare('INSERT INTO x_freelancer_package (offer_id, tier, price, delivery_days, revisions) VALUES (:o, 1, 1000, 1, 1)')->execute(['o' => $offerId]);
    $packageId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO x_freelancer_package_translation (package_id, locale, name, description) VALUES (:p, 'de', 'Basis', 'x')")->execute(['p' => $packageId]);
    $app->offers->setPriceFrom($offerId, 1000);
    $app->offers->setStatus($offerId, 'published', null, null);
} else {
    $offerId = $offer['id'];
}

if ($app->messageSeen->unread($adminId) === 0) {
    $buyerId = $app->db->query("SELECT id FROM account WHERE email = 'browser-buyer@example.test'")->fetchColumn();
    if ($buyerId === false) {
        $buyerId = $app->accounts->create('browser-buyer@example.test', 'correct horse battery staple', 'de', true);
    }
    $app->offerMessages->add($offerId, (int) $buyerId, (int) $buyerId, 'Testnachricht für das Unread-Badge.');
}

if ($app->media->list(1, 1)['total'] === 0) {
    $png = tempnam(sys_get_temp_dir(), 'seed') . '.png';
    $image = imagecreatetruecolor(4, 4);
    imagefill($image, 0, 0, imagecolorallocate($image, 100, 100, 200));
    imagepng($image, $png);
    imagedestroy($image);
    $error = $app->media->add(['tmp_name' => $png, 'error' => UPLOAD_ERR_OK], 'Testbild');
    unlink($png);
    if ($error !== null) {
        fwrite(STDERR, "Media seed failed: {$error}\n");
        exit(1);
    }
}

echo "Seeded: provider {$providerId}, offer {$offerId} (testangebot), unread " . $app->messageSeen->unread($adminId) . ", media " . $app->media->list(1, 1)['total'] . "\n";
