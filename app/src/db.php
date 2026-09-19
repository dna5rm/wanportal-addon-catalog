<?php
/**
 * src/db.php: SQLite bootstrap and query helpers for the wanportal
 * service catalog sidecar.
 *
 * The database is one SQLite file owned by the web user at runtime:
 *   - container: /var/lib/catalog/catalog.sqlite (host bind of data/)
 *   - host dev:  <addon>/data/catalog.sqlite
 * overridable with CATALOG_DB. Schema and seed rows are created lazily
 * on first open, so a fresh data/ volume self-initializes; every step
 * is idempotent and safe to re-run on concurrent first requests.
 *
 * Rules baked in:
 *   - foreign keys ON on every connection: family delete cascades its
 *     skus (the UI confirms with name + count first), parent_sku keeps
 *     ON UPDATE CASCADE so a SKU rename re-points children's parent_sku,
 *     and category/class/owner references are plain: deleting a lookup
 *     a SKU still uses is rejected, not nulled
 *   - catalog_backup() copies the live file to catalog.sqlite.bak
 *     before every mutating request is applied
 *   - no token and no credential is ever stored in the sqlite file
 */

if (defined('CATALOG_DB_LOADED')) {
    return;
}
define('CATALOG_DB_LOADED', '1');

/**
 * Resolve the sqlite file path: CATALOG_DB env first, then the
 * container mount, then the host dev location next to the app.
 */
function catalog_db_path(): string
{
    $env = getenv('CATALOG_DB');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    if (is_dir('/var/lib/catalog')) {
        return '/var/lib/catalog/catalog.sqlite';
    }
    return __DIR__ . '/../../data/catalog.sqlite';
}

/** Shared PDO connection (bootstrap + seed on first open). */
function catalog_db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }
    $path = catalog_db_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $db = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    if (!is_file($path)) {
        @chmod($path, 0640);
    }
    catalog_bootstrap($db);
    return $db;
}

/** Schema + seed, idempotent (CREATE IF NOT EXISTS / OR IGNORE). */
function catalog_bootstrap(PDO $db): void
{
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS families (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    sort INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS categories (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    sort INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS classes (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    sort INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS owners (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    sort INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS skus (
    id          TEXT PRIMARY KEY,
    family_id   INTEGER NOT NULL REFERENCES families(id) ON DELETE CASCADE,
    product     TEXT NOT NULL,
    variant     TEXT,
    category_id INTEGER REFERENCES categories(id),
    summary     TEXT NOT NULL DEFAULT '',
    lands_on    TEXT,
    consumer    TEXT,
    class_id    INTEGER REFERENCES classes(id),
    owner_id    INTEGER REFERENCES owners(id),
    form_fields TEXT,
    not_this    TEXT,
    doc_url     TEXT,
    parent_sku  TEXT REFERENCES skus(id) ON UPDATE CASCADE ON DELETE SET NULL
);
SQL);

    catalog_seed_lookups($db, 'families', [
        ['MAN', 'Management', 1],
        ['WAN', 'Wide Area Network', 2],
        ['DC', 'Data Center', 3],
        ['VIP', 'Virtual IP', 4],
        ['FW', 'Firewall', 5],
        ['NET', 'Network Services', 6],
    ]);
    catalog_seed_lookups($db, 'categories', [
        ['connectivity', 'Connectivity', 1],
        ['security', 'Security', 2],
        ['compute', 'Compute', 3],
        ['delivery', 'Service Delivery', 4],
        ['management', 'Management', 5],
    ]);
    catalog_seed_lookups($db, 'classes', [
        ['exists', 'exists', 1],
        ['poc', 'poc', 2],
        ['legacy', 'legacy', 3],
        ['retired', 'retired', 4],
    ]);
    catalog_seed_lookups($db, 'owners', [
        ['management', 'Management', 1],
        ['architecture', 'Architecture', 2],
        ['engineering', 'Engineering', 3],
        ['support', 'Support', 4],
        ['operations', 'Operations', 5],
    ]);

    /* Generic example rows, example.com language only; seeded once so
     * operator deletions stick. */
    $n = (int)$db->query('SELECT COUNT(*) FROM skus')->fetchColumn();
    if ($n === 0) {
        catalog_seed_skus($db);
    }
}

function catalog_seed_lookups(PDO $db, string $table, array $rows): void
{
    $st = $db->prepare("INSERT OR IGNORE INTO {$table} (slug, name, sort) VALUES (?, ?, ?)");
    foreach ($rows as [$slug, $name, $sort]) {
        $st->execute([$slug, $name, $sort]);
    }
}

/** ~16 generic example SKUs incl. the FW-VSYS parent/child example. */
function catalog_seed_skus(PDO $db): void
{
    $fam = [];
    foreach ($db->query('SELECT id, slug FROM families') as $r) {
        $fam[$r['slug']] = (int)$r['id'];
    }
    $cat = $cls = $own = [];
    foreach (['categories' => &$cat, 'classes' => &$cls, 'owners' => &$own] as $t => $ref) {
        foreach ($db->query("SELECT id, slug FROM {$t}") as $r) {
            $ref[$r['slug']] = (int)$r['id'];
        }
    }
    $doc = static fn (string $s): string => 'https://docs.example.com/catalog/' . $s;

    $rows = [
        // [id, family, product, variant, category, summary, lands_on, consumer, class, owner, form_fields, not_this, doc_url, parent]
        ['MAN-L3', 'MAN', 'L3', null, 'connectivity', 'Routed L3 reachability for out-of-band management', 'OOB management routers', 'network team, NOC', 'exists', 'engineering', '["site","device_count"]', 'Not for in-band access; use the corporate LAN.', $doc('man-l3'), null],
        ['MAN-VPN', 'MAN', 'VPN', null, 'security', 'Split-tunnel VPN for management planes', 'VPN concentrators', 'network team', 'exists', 'engineering', '["justification","duration"]', 'Not for general internet access.', $doc('man-vpn'), null],
        ['WAN-MPLS', 'WAN', 'MPLS', null, 'connectivity', 'Provider MPLS L3VPN transport between sites', 'WAN PE / CE routers', 'branch sites', 'exists', 'architecture', '["site","bandwidth"]', 'Not a direct internet circuit.', $doc('wan-mpls'), null],
        ['WAN-DSL', 'WAN', 'DSL', null, 'connectivity', 'Legacy broadband backup circuit', 'branch CE / DSL modem', 'branch sites', 'legacy', 'operations', '["site"]', 'Backup transport only; not a primary circuit.', $doc('wan-dsl'), null],
        ['WAN-LTE', 'WAN', 'LTE', null, 'connectivity', 'Cellular out-of-band backup', 'cellular OOB modem', 'branch sites', 'poc', 'engineering', '["site"]', 'Not a primary WAN service.', $doc('wan-lte'), null],
        ['DC-VLAN', 'DC', 'VLAN', null, 'connectivity', 'VLAN segment in the data center fabric', 'DC aggregation / core switches', 'infrastructure teams', 'exists', 'engineering', '["site","vlan_id","purpose"]', 'Not for partner connectivity.', $doc('dc-vlan'), null],
        ['DC-VLAN-DMZ', 'DC', 'VLAN', 'DMZ', 'security', 'DMZ VLAN segment for internet-facing tiers', 'DMZ firewall pair', 'platform teams', 'exists', 'engineering', '["tier","purpose"]', 'Not for database tiers.', $doc('dc-vlan-dmz'), 'DC-VLAN'],
        ['DC-VXLAN', 'DC', 'VXLAN', null, 'connectivity', 'EVPN/VXLAN overlay segment', 'leaf-spine fabric', 'infrastructure teams', 'poc', 'architecture', '["vni","purpose"]', 'Not on legacy top-of-rack switches.', $doc('dc-vxlan'), null],
        ['VIP-LB', 'VIP', 'LB', null, 'delivery', 'Virtual IP on the shared load balancers', 'shared load balancer pair', 'application teams', 'exists', 'engineering', '["service_name","ports","health_path"]', 'Not for raw TCP on the shared pair.', $doc('vip-lb'), null],
        ['VIP-LB-HTTPS', 'VIP', 'LB', 'HTTPS', 'delivery', 'HTTPS virtual IP with the shared certificate', 'shared load balancer pair', 'application teams', 'exists', 'engineering', '["hostname","backend_port"]', 'No wildcard hostnames.', $doc('vip-lb-https'), 'VIP-LB'],
        ['FW-VSYS', 'FW', 'VSYS', null, 'security', 'Firewall virtual system (security context)', 'shared firewall pair', 'platform teams', 'exists', 'architecture', '["context_name","interfaces"]', 'Not for rule changes; request FW-RULE.', $doc('fw-vsys'), null],
        ['FW-VSYS-DMZ', 'FW', 'VSYS', 'DMZ', 'security', 'DMZ security context on the shared firewalls', 'shared firewall pair', 'platform teams', 'exists', 'architecture', '["dmz_vlan"]', 'Not for internal-to-internal flows.', $doc('fw-vsys-dmz'), 'FW-VSYS'],
        ['FW-RULE', 'FW', 'RULE', null, 'security', 'Firewall rule request (managed change)', 'shared firewall pair', 'application teams', 'exists', 'operations', '["source","destination","port","ticket_ref"]', 'No any-any rules.', $doc('fw-rule'), null],
        ['NET-DNS', 'NET', 'DNS', null, 'delivery', 'Recursive DNS resolver service', 'recursive DNS resolvers', 'all staff', 'exists', 'operations', '["views"]', 'Not public authoritative DNS.', $doc('net-dns'), null],
        ['NET-DNS-INT', 'NET', 'DNS', 'INT', 'delivery', 'Internal-only resolver view', 'internal DNS resolvers', 'infrastructure teams', 'exists', 'operations', '["zone_list"]', 'Not for guest networks.', $doc('net-dns-int'), 'NET-DNS'],
        ['NET-NTP', 'NET', 'NTP', null, 'delivery', 'Authenticated NTP stratum service', 'NTP stratum hosts', 'all devices', 'exists', 'operations', '["site"]', 'Not a public time source.', $doc('net-ntp'), null],
    ];

    $st = $db->prepare(
        'INSERT INTO skus (id, family_id, product, variant, category_id, summary, lands_on, consumer, class_id, owner_id, form_fields, not_this, doc_url, parent_sku)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($rows as [$id, $famSlug, $product, $variant, $catSlug, $summary, $lands, $consumer, $clsSlug, $ownSlug, $fields, $notThis, $url, $parent]) {
        $st->execute([
            $id,
            $fam[$famSlug] ?? null,
            $product,
            $variant,
            $cat[$catSlug] ?? null,
            $summary,
            $lands,
            $consumer,
            $cls[$clsSlug] ?? null,
            $own[$ownSlug] ?? null,
            $fields,
            $notThis,
            $url,
            $parent,
        ]);
    }
}

/** Copy the live sqlite file to catalog.sqlite.bak (pre-mutation state). */
function catalog_backup(): bool
{
    $path = catalog_db_path();
    if (!is_file($path)) {
        return false;
    }
    clearstatcache(true, $path);
    $ok = @copy($path, $path . '.bak');
    if ($ok) {
        @chmod($path . '.bak', 0640);
    }
    return $ok;
}

/* ---------------------------------------------------------------------
 * Small shared helpers
 * ------------------------------------------------------------------- */

/** HTML-escape shorthand (guarded so pages can also define their own). */
if (!function_exists('catalog_h')) {
    function catalog_h(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

/** Lookup tables managed in admin.php, in render order. */
function catalog_lookup_tables(): array
{
    return ['families', 'categories', 'classes', 'owners'];
}

/** Family slug pattern per SPEC: [A-Z][A-Z0-9]*. */
function catalog_valid_family_slug(string $s): bool
{
    return (bool)preg_match('/^[A-Z][A-Z0-9]*$/', $s);
}

/** PRODUCT / VARIANT pattern per SPEC: [A-Z0-9]+ uppercase. */
function catalog_valid_part(string $s): bool
{
    return (bool)preg_match('/^[A-Z0-9]+$/', $s);
}

function catalog_norm_part(string $s): string
{
    return strtoupper(trim($s));
}

/** Non-family lookup slugs stay lowercase. */
function catalog_valid_lookup_slug(string $s): bool
{
    return (bool)preg_match('/^[a-z0-9][a-z0-9_-]*$/', $s);
}

/** Computed SKU id: FAMILY-PRODUCT[-VARIANT]. */
function catalog_compute_sku_id(string $familySlug, string $product, ?string $variant): string
{
    $id = $familySlug . '-' . $product;
    if ($variant !== null && $variant !== '') {
        $id .= '-' . $variant;
    }
    return $id;
}

/**
 * parent_sku rule: the parent must exist, must not be the SKU itself,
 * and the chain up from it must never reach the SKU (no cycles).
 * $skuId is the id the row will have after this write (rename-aware).
 */
function catalog_parent_ok(PDO $db, string $skuId, ?string $parentId): bool
{
    if ($parentId === null || $parentId === '') {
        return true;
    }
    if ($parentId === $skuId) {
        return false;
    }
    $seen = [$skuId => true];
    $cur = $parentId;
    for ($i = 0; $i < 100; $i++) {
        if (isset($seen[$cur])) {
            return false;
        }
        $seen[$cur] = true;
        $st = $db->prepare('SELECT parent_sku FROM skus WHERE id = ?');
        $st->execute([$cur]);
        $row = $st->fetch();
        if (!$row) {
            return false; // parent must exist
        }
        $cur = $row['parent_sku'];
        if ($cur === null || $cur === '') {
            return true;
        }
    }
    return false; // depth cap hit: treat as a cycle
}

/** All SKUs joined with lookup names, sorted for tree building. */
function catalog_skus_all(PDO $db): array
{
    $sql = 'SELECT s.id, s.family_id, s.product, s.variant, s.category_id, s.summary,
                   s.lands_on, s.consumer, s.class_id, s.owner_id, s.form_fields,
                   s.not_this, s.doc_url, s.parent_sku,
                   f.slug AS family_slug, f.name AS family_name, f.sort AS family_sort,
                   c.name AS category_name, cl.name AS class_name, o.name AS owner_name
            FROM skus s
            JOIN families f ON f.id = s.family_id
            LEFT JOIN categories c ON c.id = s.category_id
            LEFT JOIN classes cl   ON cl.id = s.class_id
            LEFT JOIN owners o     ON o.id = s.owner_id
            ORDER BY f.sort, f.name, s.product, s.variant, s.id';
    return $db->query($sql)->fetchAll();
}

/**
 * DFS over parent_sku: roots first (family order), then each node's
 * children directly after it, depth stamped per row. Cycle-safe: any
 * row not reached from a root starts its own walk at depth 0.
 */
function catalog_tree(array $rows): array
{
    $byParent = [];
    foreach ($rows as $r) {
        $byParent[$r['parent_sku'] ?? ''][] = $r;
    }
    $out = [];
    $seen = [];
    $walk = static function (string $parentKey, int $depth) use (&$walk, &$out, &$seen, $byParent): void {
        foreach ($byParent[$parentKey] ?? [] as $r) {
            if (isset($seen[$r['id']])) {
                continue; // cycle guard
            }
            $seen[$r['id']] = true;
            $r['depth'] = $depth;
            $out[] = $r;
            $walk($r['id'], $depth + 1);
        }
    };
    $walk('', 0);
    foreach ($rows as $r) {
        if (!isset($seen[$r['id']])) {
            // Orphaned by a cycle in stored data: surface it at top level.
            $r['depth'] = 0;
            $out[] = $r;
            $seen[$r['id']] = true;
            $walk($r['id'], 1);
        }
    }
    return $out;
}

/** One SKU with joined names, or null. */
function catalog_sku_get(PDO $db, string $id): ?array
{
    $st = $db->prepare(
        'SELECT s.*, f.slug AS family_slug, f.name AS family_name,
                c.name AS category_name, cl.name AS class_name, o.name AS owner_name
         FROM skus s
         JOIN families f ON f.id = s.family_id
         LEFT JOIN categories c ON c.id = s.category_id
         LEFT JOIN classes cl   ON cl.id = s.class_id
         LEFT JOIN owners o     ON o.id = s.owner_id
         WHERE s.id = ?'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** Direct children of a SKU, sorted. */
function catalog_sku_children(PDO $db, string $id): array
{
    $st = $db->prepare(
        'SELECT id, product, variant, summary FROM skus WHERE parent_sku = ? ORDER BY product, variant, id'
    );
    $st->execute([$id]);
    return $st->fetchAll();
}

/** All four lookup tables with per-row SKU usage counts. */
function catalog_lookups(PDO $db): array
{
    $out = [];
    $col = [
        'families' => 'family_id',
        'categories' => 'category_id',
        'classes' => 'class_id',
        'owners' => 'owner_id',
    ];
    foreach (catalog_lookup_tables() as $table) {
        $c = $col[$table];
        $out[$table] = $db->query(
            "SELECT t.*, (SELECT COUNT(*) FROM skus s WHERE s.{$c} = t.id) AS sku_count
             FROM {$table} t ORDER BY t.sort, t.name"
        )->fetchAll();
    }
    return $out;
}

/** SKU count for one family (cascade preview / delete confirm). */
function catalog_family_count(PDO $db, int $familyId): int
{
    $st = $db->prepare('SELECT COUNT(*) FROM skus WHERE family_id = ?');
    $st->execute([$familyId]);
    return (int)$st->fetchColumn();
}

/** SKU count referencing a category/class/owner (delete guard). */
function catalog_lookup_usage(PDO $db, string $table, int $id): int
{
    $col = ['families' => 'family_id', 'categories' => 'category_id', 'classes' => 'class_id', 'owners' => 'owner_id'][$table]
        ?? throw new InvalidArgumentException('unknown lookup table');
    $st = $db->prepare("SELECT COUNT(*) FROM skus WHERE {$col} = ?");
    $st->execute([$id]);
    return (int)$st->fetchColumn();
}