<?php
/**
 * api.php: JSON API for the catalog pages, fetched from index.php,
 * sku.php and admin.php.
 *
 * GET is public: listing rows, one SKU's detail, the lookup tables,
 * and family cascade counts. POST/PATCH/DELETE require the wanportal
 * bearer token (Authorization header) validated live against the
 * portal session API. Any signed-in user may write; is_admin is not
 * required. Every mutating request copies the live sqlite file to
 * catalog.sqlite.bak before the write is applied.
 *
 * Endpoints (resource + optional id query param; JSON bodies):
 *   GET    ?resource=skus                       -> {ok, skus}
 *   GET    ?resource=sku&id=FAMILY-PRODUCT      -> {ok, sku, children}
 *   GET    ?resource=lookups                    -> {ok, families, categories, classes, owners}
 *   GET    ?resource=family&id=                 -> {ok, family, sku_count}
 *   POST   ?resource=sku                        -> {ok, sku}            (201)
 *   PATCH  ?resource=sku&id=                    -> {ok, sku, renamed}   (rename recomputes the id)
 *   DELETE ?resource=sku&id=                    -> {ok}
 *   DELETE ?resource=family&id=                 -> {ok, deleted_skus}   (cascades skus)
 *   POST/PATCH/DELETE ?resource=families|categories|classes|owners [&id=]
 *           -> {ok, row} / {ok} (family delete cascades; the other lookups
 *              answer 409 while any sku still references them)
 *
 * SKU ids are computed FAMILY-PRODUCT[-VARIANT] from the family slug.
 * A rename updates the row id and, via ON UPDATE CASCADE, every
 * child's parent_sku. The old id simply stops resolving afterwards.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

set_exception_handler(static function (Throwable $e): void {
    error_log('catalog api: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Internal error; see the container logs.']);
    exit;
});

/** JSON response + exit. */
function json_out(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
}

/** Parsed JSON request body, or []. */
function json_body(): array
{
    $raw = file_get_contents('php://input');
    $parsed = json_decode($raw === false ? '' : $raw, true);
    return is_array($parsed) ? $parsed : [];
}

/** Optional text field: trimmed, length-capped, null when empty. */
function catalog_trim_field(array $in, string $key, int $max): ?string
{
    if (!array_key_exists($key, $in)) {
        return null;
    }
    $v = trim((string)$in[$key]);
    if ($v === '') {
        return null;
    }
    return substr($v, 0, $max);
}

/**
 * Optional integer lookup reference from JSON input: null stays null,
 * a value must exist in the table, anything else is a 400 exit.
 */
function catalog_lookup_ref(PDO $db, string $table, array $in, string $key): ?int
{
    if (!array_key_exists($key, $in)) {
        return null;
    }
    $raw = $in[$key];
    if ($raw === null || $raw === '') {
        return null;
    }
    $i = filter_var($raw, FILTER_VALIDATE_INT);
    if ($i === false) {
        json_out(400, ['ok' => false, 'error' => "{$key} must be an integer or null"]);
    }
    $st = $db->prepare("SELECT 1 FROM {$table} WHERE id = ?");
    $st->execute([$i]);
    if (!$st->fetch()) {
        json_out(400, ['ok' => false, 'error' => "{$key} does not exist"]);
    }
    return $i;
}

/** lands_on must be YYYY-MM-DD or empty; returns the stored value. */
function catalog_lands_on(array $in): ?string
{
    $lands = trim((string)($in['lands_on'] ?? ''));
    if ($lands !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $lands)) {
        json_out(400, ['ok' => false, 'error' => 'lands_on must be YYYY-MM-DD or empty']);
    }
    return $lands !== '' ? $lands : null;
}

/** doc_url must be a valid URL or empty; returns the stored value. */
function catalog_doc_url(array $in): ?string
{
    $url = trim((string)($in['doc_url'] ?? ''));
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
        json_out(400, ['ok' => false, 'error' => 'doc_url must be a valid URL or empty']);
    }
    return $url !== '' ? $url : null;
}

/** parent_sku from JSON input, validated against the id after this write. */
function catalog_parent_field(PDO $db, string $futureId, array $in): ?string
{
    if (!array_key_exists('parent_sku', $in)) {
        return null;
    }
    $parentId = trim((string)($in['parent_sku'] ?? ''));
    if ($parentId === '') {
        return null;
    }
    if (!catalog_parent_ok($db, $futureId, $parentId)) {
        json_out(400, ['ok' => false, 'error' => 'parent_sku must exist and cannot be self or a cycle']);
    }
    return $parentId;
}

/* ------------------------------------------------------------------ */

$db = catalog_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = (string)($_GET['resource'] ?? '');
$idParam = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$in = $method === 'GET' ? [] : json_body();

/* ------------------------------------------------------------------ *
 * GET: all public (listing, detail, lookups, family counts)
 * ------------------------------------------------------------------ */
if ($method === 'GET') {
    if ($resource === 'skus') {
        json_out(200, ['ok' => true, 'skus' => catalog_skus_all($db)]);
    }
    if ($resource === 'sku' && $idParam !== '') {
        $sku = catalog_sku_get($db, $idParam);
        if (!$sku) {
            json_out(404, ['ok' => false, 'error' => 'sku not found']);
        }
        json_out(200, ['ok' => true, 'sku' => $sku, 'children' => catalog_sku_children($db, $idParam)]);
    }
    if ($resource === 'lookups') {
        json_out(200, ['ok' => true] + catalog_lookups($db));
    }
    if ($resource === 'family' && $idParam !== '') {
        $st = $db->prepare('SELECT * FROM families WHERE id = ? OR slug = ?');
        $st->execute([$idParam, $idParam]);
        $family = $st->fetch();
        if (!$family) {
            json_out(404, ['ok' => false, 'error' => 'family not found']);
        }
        json_out(200, [
            'ok' => true,
            'family' => $family,
            'sku_count' => catalog_family_count($db, (int)$family['id']),
        ]);
    }
    json_out(404, ['ok' => false, 'error' => 'unknown resource']);
}

/* ------------------------------------------------------------------ *
 * Everything below mutates: bearer required, a backup is taken before
 * each write, family delete cascades, in-use lookups refuse deletion.
 * ------------------------------------------------------------------ */
catalog_require_writer();

/* ---- SKU create --------------------------------------------------- */
if ($method === 'POST' && $resource === 'sku') {
    $familyId = filter_var($in['family_id'] ?? null, FILTER_VALIDATE_INT);
    if ($familyId === false || $familyId === null) {
        json_out(400, ['ok' => false, 'error' => 'family is required']);
    }
    $st = $db->prepare('SELECT * FROM families WHERE id = ?');
    $st->execute([$familyId]);
    $family = $st->fetch();
    if (!$family) {
        json_out(400, ['ok' => false, 'error' => 'family not found']);
    }

    $product = catalog_norm_part((string)($in['product'] ?? ''));
    if (!catalog_valid_part($product)) {
        json_out(400, ['ok' => false, 'error' => 'product must be uppercase [A-Z0-9]+']);
    }
    $variant = catalog_norm_part((string)($in['variant'] ?? ''));
    if ($variant === '') {
        $variant = null;
    } elseif (!catalog_valid_part($variant)) {
        json_out(400, ['ok' => false, 'error' => 'variant must be uppercase [A-Z0-9]+']);
    }

    $skuId = catalog_compute_sku_id($family['slug'], $product, $variant);
    $dup = $db->prepare('SELECT 1 FROM skus WHERE id = ?');
    $dup->execute([$skuId]);
    if ($dup->fetch()) {
        json_out(409, ['ok' => false, 'error' => "sku {$skuId} already exists"]);
    }

    $parentId = catalog_parent_field($db, $skuId, $in);
    $catId = catalog_lookup_ref($db, 'categories', $in, 'category_id');
    $clsId = catalog_lookup_ref($db, 'classes', $in, 'class_id');
    $ownId = catalog_lookup_ref($db, 'owners', $in, 'owner_id');
    $lands = catalog_lands_on($in);
    $docUrl = catalog_doc_url($in);

    catalog_backup();
    $st = $db->prepare(
        'INSERT INTO skus (id, family_id, product, variant, category_id, summary, lands_on, consumer, class_id, owner_id, form_fields, not_this, doc_url, parent_sku)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $skuId,
        $familyId,
        $product,
        $variant,
        $catId,
        catalog_trim_field($in, 'summary', 500) ?? '',
        $lands,
        catalog_trim_field($in, 'consumer', 200),
        $clsId,
        $ownId,
        catalog_trim_field($in, 'form_fields', 4000),
        catalog_trim_field($in, 'not_this', 4000),
        $docUrl,
        $parentId,
    ]);
    json_out(201, ['ok' => true, 'sku' => catalog_sku_get($db, $skuId)]);
}

/* ---- SKU update (partial; rename recomputes the id) ---------------- */
if ($method === 'PATCH' && $resource === 'sku' && $idParam !== '') {
    $cur = catalog_sku_get($db, $idParam);
    if (!$cur) {
        json_out(404, ['ok' => false, 'error' => 'sku not found']);
    }

    /* Computed id: trio of family/product/variant, provided or current. */
    $newFamilyId = array_key_exists('family_id', $in)
        ? filter_var($in['family_id'], FILTER_VALIDATE_INT)
        : (int)$cur['family_id'];
    if ($newFamilyId === false || $newFamilyId === null) {
        json_out(400, ['ok' => false, 'error' => 'family_id must be an integer']);
    }
    $famSt = $db->prepare('SELECT slug FROM families WHERE id = ?');
    $famSt->execute([$newFamilyId]);
    $famSlug = $famSt->fetchColumn();
    if ($famSlug === false) {
        json_out(400, ['ok' => false, 'error' => 'family not found']);
    }
    $newProduct = array_key_exists('product', $in)
        ? catalog_norm_part((string)$in['product'])
        : (string)$cur['product'];
    if (!catalog_valid_part($newProduct)) {
        json_out(400, ['ok' => false, 'error' => 'product must be uppercase [A-Z0-9]+']);
    }
    if (array_key_exists('variant', $in)) {
        $newVariant = catalog_norm_part((string)$in['variant']);
        if ($newVariant === '') {
            $newVariant = null;
        } elseif (!catalog_valid_part($newVariant)) {
            json_out(400, ['ok' => false, 'error' => 'variant must be uppercase [A-Z0-9]+']);
        }
    } else {
        $newVariant = $cur['variant'];
    }
    $newId = catalog_compute_sku_id((string)$famSlug, $newProduct, $newVariant);

    $set = [];
    $args = [];
    foreach ([
        'summary' => 500,
        'consumer' => 200,
        'form_fields' => 4000,
        'not_this' => 4000,
    ] as $field => $cap) {
        if (array_key_exists($field, $in)) {
            $set[] = "{$field} = ?";
            $args[] = catalog_trim_field($in, $field, $cap) ?? '';
        }
    }
    if (array_key_exists('lands_on', $in)) {
        $set[] = 'lands_on = ?';
        $args[] = catalog_lands_on($in);
    }
    if (array_key_exists('doc_url', $in)) {
        $set[] = 'doc_url = ?';
        $args[] = catalog_doc_url($in);
    }
    foreach (['category_id' => 'categories', 'class_id' => 'classes', 'owner_id' => 'owners'] as $field => $table) {
        if (array_key_exists($field, $in)) {
            $set[] = "{$field} = ?";
            $args[] = catalog_lookup_ref($db, $table, $in, $field);
        }
    }
    if (array_key_exists('parent_sku', $in)) {
        $set[] = 'parent_sku = ?';
        $args[] = catalog_parent_field($db, $newId, $in);
    }
    if ($newId !== $cur['id']) {
        $dup = $db->prepare('SELECT 1 FROM skus WHERE id = ?');
        $dup->execute([$newId]);
        if ($dup->fetch()) {
            json_out(409, ['ok' => false, 'error' => "sku {$newId} already exists"]);
        }
        $set[] = 'id = ?';
        $args[] = $newId;
    }
    $set[] = 'family_id = ?';
    $args[] = $newFamilyId;
    $set[] = 'product = ?';
    $args[] = $newProduct;
    $set[] = 'variant = ?';
    $args[] = $newVariant;

    catalog_backup();
    $args[] = $idParam;
    $db->prepare('UPDATE skus SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
    json_out(200, [
        'ok' => true,
        'sku' => catalog_sku_get($db, $newId),
        'renamed' => $newId !== $cur['id'],
        'old_id' => $newId !== $cur['id'] ? $cur['id'] : null,
    ]);
}

/* ---- SKU delete ----------------------------------------------------- */
if ($method === 'DELETE' && $resource === 'sku' && $idParam !== '') {
    if (!catalog_sku_get($db, $idParam)) {
        json_out(404, ['ok' => false, 'error' => 'sku not found']);
    }
    catalog_backup();
    $db->prepare('DELETE FROM skus WHERE id = ?')->execute([$idParam]);
    json_out(200, ['ok' => true]);
}

/* ---- Family delete: cascades its skus ------------------------------- */
if ($method === 'DELETE' && in_array($resource, ['family', 'families'], true) && $idParam !== '') {
    $st = $db->prepare('SELECT * FROM families WHERE id = ? OR slug = ?');
    $st->execute([$idParam, $idParam]);
    $family = $st->fetch();
    if (!$family) {
        json_out(404, ['ok' => false, 'error' => 'family not found']);
    }
    $count = catalog_family_count($db, (int)$family['id']);
    catalog_backup();
    $db->prepare('DELETE FROM families WHERE id = ?')->execute([(int)$family['id']]);
    json_out(200, ['ok' => true, 'deleted_skus' => $count]);
}

/* ---- Lookup create / update / delete -------------------------------- */
if (in_array($resource, catalog_lookup_tables(), true)) {
    if ($method === 'POST') {
        catalog_lookup_create($db, $resource, $in);
    }
    if ($method === 'PATCH' && $idParam !== '') {
        catalog_lookup_update($db, $resource, $idParam, $in);
    }
    if ($method === 'DELETE' && $idParam !== '') {
        catalog_lookup_delete($db, $resource, $idParam);
    }
    json_out(404, ['ok' => false, 'error' => 'unknown resource']);
}

json_out(404, ['ok' => false, 'error' => 'unknown resource']);

/* ------------------------------------------------------------------ *
 * Lookup mutation helpers
 * ------------------------------------------------------------------ */

/** Validate one lookup row body for create; exits 400 on bad input. */
function catalog_lookup_valid_body(array $in, string $resource): array
{
    $slug = strtoupper(trim((string)($in['slug'] ?? '')));
    $name = trim((string)($in['name'] ?? ''));
    $sort = filter_var($in['sort'] ?? 0, FILTER_VALIDATE_INT);
    if ($resource === 'families') {
        if (!catalog_valid_family_slug($slug)) {
            json_out(400, ['ok' => false, 'error' => 'family slug must match [A-Z][A-Z0-9]*']);
        }
    } elseif (!catalog_valid_lookup_slug(strtolower($slug)) || $slug !== strtolower($slug)) {
        json_out(400, ['ok' => false, 'error' => 'slug must be lowercase [a-z0-9_-]*']);
    }
    if ($name === '' || strlen($name) > 120) {
        json_out(400, ['ok' => false, 'error' => 'name is required (max 120 chars)']);
    }
    if ($sort === false) {
        json_out(400, ['ok' => false, 'error' => 'sort must be an integer']);
    }
    return [$slug, $name, $sort === false ? 0 : $sort];
}

function catalog_lookup_create(PDO $db, string $resource, array $in): never
{
    [$slug, $name, $sort] = catalog_lookup_valid_body($in, $resource);
    $dup = $db->prepare("SELECT 1 FROM {$resource} WHERE slug = ?");
    $dup->execute([$slug]);
    if ($dup->fetch()) {
        json_out(409, ['ok' => false, 'error' => "{$resource} slug {$slug} already exists"]);
    }
    catalog_backup();
    $st = $db->prepare("INSERT INTO {$resource} (slug, name, sort) VALUES (?, ?, ?)");
    $st->execute([$slug, $name, $sort]);
    $newId = (int)$db->lastInsertId();
    $row = $db->prepare("SELECT * FROM {$resource} WHERE id = ?");
    $row->execute([$newId]);
    json_out(201, ['ok' => true, 'row' => $row->fetch()]);
}

function catalog_lookup_update(PDO $db, string $resource, string $idParam, array $in): never
{
    $st = $db->prepare("SELECT * FROM {$resource} WHERE id = ? OR slug = ?");
    $st->execute([$idParam, $idParam]);
    $cur = $st->fetch();
    if (!$cur) {
        json_out(404, ['ok' => false, 'error' => "{$resource} not found"]);
    }
    [$slug, $name, $sort] = catalog_lookup_valid_body([
        'slug' => array_key_exists('slug', $in) ? (string)$in['slug'] : $cur['slug'],
        'name' => array_key_exists('name', $in) ? (string)$in['name'] : $cur['name'],
        'sort' => array_key_exists('sort', $in) ? $in['sort'] : $cur['sort'],
    ], $resource);

    $dup = $db->prepare("SELECT 1 FROM {$resource} WHERE slug = ? AND id <> ?");
    $dup->execute([$slug, $cur['id']]);
    if ($dup->fetch()) {
        json_out(409, ['ok' => false, 'error' => "{$resource} slug {$slug} already exists"]);
    }
    catalog_backup();
    $db->prepare("UPDATE {$resource} SET slug = ?, name = ?, sort = ? WHERE id = ?")
        ->execute([$slug, $name, $sort, $cur['id']]);
    $row = $db->prepare("SELECT * FROM {$resource} WHERE id = ?");
    $row->execute([$cur['id']]);
    json_out(200, ['ok' => true, 'row' => $row->fetch()]);
}

function catalog_lookup_delete(PDO $db, string $resource, string $idParam): never
{
    $st = $db->prepare("SELECT * FROM {$resource} WHERE id = ? OR slug = ?");
    $st->execute([$idParam, $idParam]);
    $row = $st->fetch();
    if (!$row) {
        json_out(404, ['ok' => false, 'error' => "{$resource} not found"]);
    }
    /* Families cascade (SPEC); the other lookups refuse while used. */
    if ($resource !== 'families') {
        $usage = catalog_lookup_usage($db, $resource, (int)$row['id']);
        if ($usage > 0) {
            json_out(409, [
                'ok' => false,
                'error' => "in use by {$usage} sku(s); re-point them first",
                'sku_count' => $usage,
            ]);
        }
    }
    $count = $resource === 'families' ? catalog_family_count($db, (int)$row['id']) : 0;
    catalog_backup();
    $db->prepare("DELETE FROM {$resource} WHERE id = ?")->execute([(int)$row['id']]);
    json_out(200, ['ok' => true, 'deleted_skus' => $count]);
}