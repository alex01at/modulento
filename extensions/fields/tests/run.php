<?php

declare(strict_types=1);

// Checks of the "fields" extension through the real kernel and router, on the
// core's in-memory SQLite database:
//   php extensions/fields/tests/run.php
// Exit code 0 means every check passed.

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

$failures = 0;
$checks = 0;

function check(string $label, bool $condition): void
{
    global $failures, $checks;
    $checks++;
    // Printed at the end: output here would count as "headers sent".
    if (!$condition) {
        $failures++;
        $GLOBALS['failed'][] = $label;
    }
}

require $root . '/tests/support/http.php';

// The tables of the extension in SQLite's dialect, a provider, and only "fields" enabled.
$pdo->exec("CREATE TABLE x_fields_type (id INTEGER PRIMARY KEY, key_name TEXT UNIQUE, position INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE x_fields_type_label (type_id INTEGER REFERENCES x_fields_type (id) ON DELETE CASCADE, locale TEXT, label TEXT, PRIMARY KEY (type_id, locale))");
$pdo->exec("CREATE TABLE x_fields_field (id INTEGER PRIMARY KEY, type_id INTEGER REFERENCES x_fields_type (id) ON DELETE CASCADE, key_name TEXT, kind TEXT, required INTEGER, filterable INTEGER, sortable INTEGER, in_card INTEGER, is_price INTEGER, position INTEGER, options TEXT, UNIQUE (type_id, key_name))");
$pdo->exec("CREATE TABLE x_fields_field_label (field_id INTEGER REFERENCES x_fields_field (id) ON DELETE CASCADE, locale TEXT, label TEXT, PRIMARY KEY (field_id, locale))");
$pdo->exec("CREATE TABLE x_fields_value (offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, field_id INTEGER REFERENCES x_fields_field (id) ON DELETE CASCADE, locale TEXT DEFAULT '', value_text TEXT, value_num NUMERIC, PRIMARY KEY (offer_id, field_id, locale))");
$pdo->exec("DELETE FROM extension");
$pdo->exec("INSERT INTO extension VALUES ('fields', '0.1.0', 1)");
$pdo->exec("INSERT INTO provider (id, account_id, type, status, name, slug, created_at, updated_at) VALUES (1, 2, 'person', 'approved', 'Hotel Edelweiss', 'edelweiss', '2026-01-01', '2026-01-01')");

$admin = 3; $editor = 2;
$post = fn (string $path, array $data, int|false|null $who = 3) => request($pdo, $config, 'POST', $path, $who, $data);
$get = fn (string $path, int|false|null $who = null) => request($pdo, $config, 'GET', $path, $who);

// --- The builder
$r = $post('/admin/fields/new', ['label' => ['de' => 'Hotel', 'en' => 'Hotel']]);
check('builder: a new type is saved and sent to its page', $r['status'] === 302 && (int) $pdo->query('SELECT COUNT(*) FROM x_fields_type')->fetchColumn() === 1);
check('builder: the key comes from the name', $pdo->query('SELECT key_name FROM x_fields_type')->fetchColumn() === 'hotel');
$r = $post('/admin/fields/new', ['label' => ['de' => '', 'en' => 'Only English']]);
check('builder: a type without a name in the default language is refused', (int) $pdo->query('SELECT COUNT(*) FROM x_fields_type')->fetchColumn() === 1 && str_contains($r['body'], 'Standardsprache'));

$field = fn (array $data) => $post('/admin/fields/1/field/new', $data);
$field(['label' => ['de' => 'Sterne', 'en' => 'Stars'], 'kind' => 'select', 'required' => '1', 'filterable' => '1', 'in_card' => '1', 'position' => 10,
    'options' => [['value' => '', 'label' => ['de' => 'Drei', 'en' => 'Three']], ['value' => '', 'label' => ['de' => 'Vier', 'en' => 'Four']], ['value' => '', 'label' => ['de' => '', 'en' => '']]]]);
$field(['label' => ['de' => 'Preis pro Nacht', 'en' => 'Price per night'], 'kind' => 'number', 'is_price' => '1', 'filterable' => '1', 'sortable' => '1', 'in_card' => '1', 'position' => 20]);
$field(['label' => ['de' => 'Frei ab', 'en' => 'Free from'], 'kind' => 'date', 'filterable' => '1', 'sortable' => '1', 'position' => 30]);
$field(['label' => ['de' => 'WLAN', 'en' => 'Wi-Fi'], 'kind' => 'checkbox', 'filterable' => '1', 'position' => 40]);
$field(['label' => ['de' => 'Lage', 'en' => 'Location'], 'kind' => 'text', 'filterable' => '1', 'position' => 50]);
$field(['label' => ['de' => 'Beschreibung', 'en' => 'About'], 'kind' => 'textarea', 'filterable' => '1', 'sortable' => '1', 'position' => 60]);
check('builder: six fields were made', (int) $pdo->query('SELECT COUNT(*) FROM x_fields_field')->fetchColumn() === 6);
check('builder: field keys are letters and digits', $pdo->query("SELECT group_concat(key_name) FROM (SELECT key_name FROM x_fields_field ORDER BY id)")->fetchColumn() === 'sterne,preispronacht,freiab,wlan,lage,beschreibung');
check('builder: a select keeps its options with made-up values', json_decode((string) $pdo->query("SELECT options FROM x_fields_field WHERE key_name = 'sterne'")->fetchColumn(), true) === [
    ['value' => 'drei', 'labels' => ['de' => 'Drei', 'en' => 'Three']], ['value' => 'vier', 'labels' => ['de' => 'Vier', 'en' => 'Four']]]);
check('builder: a long text cannot be filtered and only numbers and dates can be sorted', (int) $pdo->query("SELECT filterable + sortable FROM x_fields_field WHERE key_name = 'beschreibung'")->fetchColumn() === 0);
$r = $field(['label' => ['de' => 'Leer', 'en' => ''], 'kind' => 'select', 'options' => []]);
check('builder: a choice field needs a choice', (int) $pdo->query('SELECT COUNT(*) FROM x_fields_field')->fetchColumn() === 6 && str_contains($r['body'], 'mindestens eine'));
$r = $get('/admin/fields', $admin);
check('builder: the list shows the type', $r['status'] === 200 && str_contains($r['body'], 'Hotel'));
$r = $get('/admin/fields/1', $admin);
check('builder: the type page lists its fields', $r['status'] === 200 && str_contains($r['body'], 'Preis pro Nacht') && str_contains($r['body'], 'Sterne'));
$r = $get('/admin/fields/1/field/1', $admin);
check('builder: the field page shows its options', $r['status'] === 200 && str_contains($r['body'], 'value="Drei"'));
check('builder: not for those without the permission', $get('/admin/fields', $editor)['status'] === 403);
$post('/admin/fields/1/field/2', ['label' => ['de' => 'Preis pro Nacht', 'en' => 'Price per night'], 'kind' => 'text', 'position' => 20, 'is_price' => '1', 'filterable' => '1', 'sortable' => '1', 'in_card' => '1']);
check('builder: the kind of a field cannot change, nor can its filter vanish', $pdo->query("SELECT kind FROM x_fields_field WHERE id = 2")->fetchColumn() === 'number');

// --- Offers of the type
$app = function () use ($pdo, $config) {
    $a = new Modulento\Core\App($config, $pdo);
    $a->translator->load($config['app']['root'] . '/core/lang', 'core');
    Modulento\Core\Kernel::registerCore($a);
    $a->extensions->loadEnabled($a);
    return $a;
};
check('the type is registered as an offer type', $app()->offers->type('fields.hotel') instanceof Modulento\Fields\CustomOfferType);
$once = $app();
check('the type\'s name is known to the translator', $once->translator->trans($once->offers->type('fields.hotel')->labelKey(), [], 'de') === 'Hotel');

$base = ['type' => 'fields.hotel', 'text' => ['de' => ['title' => 'Edelweiss', 'summary' => 'Am See', 'description' => 'Ein Haus']]];
$offerForm = fn (array $cf, array $extra = []) => $post('/account/offers/new', $extra + $base + ['cf' => $cf], $editor);

$r = $offerForm(['sterne' => 'drei', 'preispronacht' => 'abc']);
check('offer: a number that is none is refused, the form shows it again', (int) $pdo->query('SELECT COUNT(*) FROM offer')->fetchColumn() === 0 && str_contains($r['body'], 'Bitte prüfe die markierten Felder') && str_contains($r['body'], 'value="abc"'));
$r = $offerForm(['preispronacht' => '80']);
check('offer: a required field left empty is refused', (int) $pdo->query('SELECT COUNT(*) FROM offer')->fetchColumn() === 0 && str_contains($r['body'], 'Pflichtfelder'));
$r = $offerForm(['sterne' => 'fuenf', 'preispronacht' => '80']);
check('offer: a choice that does not exist is refused', (int) $pdo->query('SELECT COUNT(*) FROM offer')->fetchColumn() === 0);
$r = $offerForm(['sterne' => 'drei', 'preispronacht' => '80,5', 'freiab' => '2026-02-30']);
check('offer: a date that does not exist is refused', (int) $pdo->query('SELECT COUNT(*) FROM offer')->fetchColumn() === 0);

$make = function (string $title, array $cf) use ($offerForm, $pdo): int {
    $offerForm($cf, ['text' => ['de' => ['title' => $title, 'summary' => '', 'description' => '']]]);
    $id = (int) $pdo->query('SELECT MAX(id) FROM offer')->fetchColumn();
    $pdo->exec("UPDATE offer SET status = 'published', published_at = '2026-03-0{$id} 00:00:00' WHERE id = {$id}");
    return $id;
};
$a = $make('Edelweiss', ['sterne' => 'drei', 'preispronacht' => '80,5', 'freiab' => '2026-06-01', 'wlan' => '1', 'lage' => ['de' => 'Am See'], 'beschreibung' => ['de' => "Zeile eins\nZeile zwei"]]);
$b = $make('Alpenblick', ['sterne' => 'vier', 'preispronacht' => '120', 'freiab' => '2026-05-01', 'lage' => ['de' => 'In den Bergen']]);
$c = $make('Talblick', ['sterne' => 'vier', 'preispronacht' => '95']);
check('offer: three offers were saved', $a === 1 && $b === 2 && $c === 3);
check('offer: the price field is the offer\'s price', (int) $pdo->query("SELECT price_from FROM offer WHERE id = 1")->fetchColumn() === 8050);
check('offer: values were stored', (int) $pdo->query('SELECT COUNT(*) FROM x_fields_value WHERE offer_id = 1')->fetchColumn() === 6);

$titles = fn (string $body): array => (function () use ($body) { preg_match_all('#<li>\s*(?:<a[^>]*tabindex[^>]*>.*?</a>\s*)?<a href="/offers/[^"]*"><strong>([^<]+)</strong>#s', $body, $m); return $m[1]; })();
$list = fn (string $query) => $titles($get('/offers' . $query)['body']);

check('list: without a type all are listed', count($list('')) === 3);
check('list: with a single kind of offer there is no choice of kinds', !str_contains($get('/offers')['body'], 'Arten von Angeboten'));
check('list: a type narrows it', count($list('?type=fields.hotel')) === 3);
check('list: filter by a choice', $list('?type=fields.hotel&f[sterne]=vier') === ['Talblick', 'Alpenblick'] || $list('?type=fields.hotel&f[sterne]=vier') === ['Alpenblick', 'Talblick']);
check('list: filter by a price range', $list('?type=fields.hotel&f[preispronacht_min]=90&f[preispronacht_max]=100') === ['Talblick']);
check('list: filter by a yes/no field', $list('?type=fields.hotel&f[wlan]=1') === ['Edelweiss']);
check('list: filter by a date', $list('?type=fields.hotel&f[freiab_from]=2026-05-15') === ['Edelweiss']);
check('list: filter by part of a text', $list('?type=fields.hotel&f[lage]=Berg') === ['Alpenblick']);
check('list: filters combine', $list('?type=fields.hotel&f[sterne]=vier&f[preispronacht_min]=100') === ['Alpenblick']);
check('list: a filter that makes no sense is ignored', count($list('?type=fields.hotel&f[preispronacht_min]=viel&f[sterne]=zwanzig&f[nope]=1')) === 3);
check('list: sort by price, low first', $list('?type=fields.hotel&sort=fld_preispronacht_asc') === ['Edelweiss', 'Talblick', 'Alpenblick']);
check('list: sort by price, high first', $list('?type=fields.hotel&sort=fld_preispronacht_desc') === ['Alpenblick', 'Talblick', 'Edelweiss']);
check('list: offers without a date come last', $list('?type=fields.hotel&sort=fld_freiab_asc') === ['Alpenblick', 'Edelweiss', 'Talblick']);
check('list: a sort of a field that cannot be sorted is the default', count($list('?type=fields.hotel&sort=fld_beschreibung_asc')) === 3);
$page = $get('/offers?type=fields.hotel&f[sterne]=vier')['body'];
check('list: the filter form is shown with what was chosen', str_contains($page, 'name="f[sterne]"') && str_contains($page, 'value="vier" selected') && str_contains($page, 'name="f[preispronacht_min]"'));
check('list: the sorts of the type are offered', str_contains($page, 'value="fld_preispronacht_asc"') && str_contains($page, 'Preis pro Nacht: aufsteigend'));
check('list: cards show the card fields', str_contains($page, 'Sterne: Vier') && str_contains($page, 'Preis pro Nacht: 120'));
check('list: injection through a filter is just a value', count($list("?type=fields.hotel&f[lage]=" . urlencode("x' OR 1=1 --"))) === 0);
check('list: an unknown type is ignored, whatever it contains', count($list("?type=" . urlencode("fields.hotel' OR 1=1"))) === 3);
check('list: a sort that is none is the default', count($list("?type=fields.hotel&sort=" . urlencode("fld_x_asc; DROP TABLE offer"))) === 3);
check('list: the offer table is still there', (int) $pdo->query('SELECT COUNT(*) FROM offer')->fetchColumn() === 3);

$show = $get('/offers/edelweiss')['body'];
check('detail: the fields are shown with their labels', str_contains($show, 'Sterne') && str_contains($show, 'Drei') && str_contains($show, 'Preis pro Nacht') && str_contains($show, '80,5') && str_contains($show, '01.06.2026') && str_contains($show, 'WLAN') && str_contains($show, 'Zeile eins'));
$edit = $get('/account/offers/1', $editor)['body'];
check('edit: the form shows what is stored', str_contains($edit, 'value="80,5"') === false && str_contains($edit, 'value="80.5"') && str_contains($edit, 'value="drei" selected'));
check('edit: a stored checkbox is checked', (bool) preg_match('/name="cf\[wlan\]" type="checkbox" value="1" checked/', $edit));
$r = $post('/account/offers/1', $base + ['cf' => ['sterne' => 'vier', 'preispronacht' => '99']], $editor);
check('edit: saving replaces the values', (int) $pdo->query('SELECT price_from FROM offer WHERE id = 1')->fetchColumn() === 9900 && (int) $pdo->query('SELECT COUNT(*) FROM x_fields_value WHERE offer_id = 1')->fetchColumn() === 2);

// --- The wizard checks the fields on their step
$get('/account/offers/new?type=fields.hotel&step=1', $editor);
$r = request($pdo, $config, 'POST', '/account/offers/wizard', false, ['type' => 'fields.hotel', 'wizard_step' => '5', 'cf' => ['preispronacht' => 'abc']]);
check('wizard: a mistake in the fields shows on their step', $r['status'] === 200 && str_contains($r['body'], 'Bitte prüfe die markierten Felder') && str_contains($r['body'], 'value="abc"'));
$r = request($pdo, $config, 'POST', '/account/offers/wizard', false, ['type' => 'fields.hotel', 'wizard_step' => '5', 'cf' => ['sterne' => 'drei', 'preispronacht' => '70']]);
check('wizard: correct fields go on to the next step', $r['status'] === 302 && $r['body'] === '');

// --- Removing
$r = $post('/admin/fields/1/delete', []);
check('builder: a type with offers cannot be deleted', (int) $pdo->query('SELECT COUNT(*) FROM x_fields_type')->fetchColumn() === 1);
$post('/admin/fields/1/field/3/delete', []);
check('builder: deleting a field removes its values', (int) $pdo->query('SELECT COUNT(*) FROM x_fields_field')->fetchColumn() === 5 && (int) $pdo->query('SELECT COUNT(*) FROM x_fields_value WHERE field_id = 3')->fetchColumn() === 0);
$pdo->exec('DELETE FROM offer');
$post('/admin/fields/1/delete', []);
check('builder: an unused type can be deleted', (int) $pdo->query('SELECT COUNT(*) FROM x_fields_type')->fetchColumn() === 0 && (int) $pdo->query('SELECT COUNT(*) FROM x_fields_field')->fetchColumn() === 0);

foreach ($GLOBALS['failed'] ?? [] as $label) {
    echo "FAIL  {$label}\n";
}
echo $failures === 0 ? "OK ({$checks} checks)\n" : "{$failures} of {$checks} checks failed\n";
exit($failures === 0 ? 0 : 1);
