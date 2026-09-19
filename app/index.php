<?php
/**
 * index.php: service catalog listing (public).
 *
 * Server-renders the SKU tree in DFS order (children indented under
 * their parent), one row per SKU: SKU (link to its doc_url when set,
 * else to sku.php?id=), category, summary, lands_on, consumer, class,
 * owner. A filter box narrows the table client-side; matching a child
 * keeps its ancestors visible so the tree keeps its shape, and the
 * filter rides the SPA's 'wanportal-filter-catalog' localStorage key.
 *
 * Editing controls (add, per-row edit/delete, the inline doc-url
 * setter) are in the markup but hidden until catalog.js confirms a
 * signed-in portal session; mutations go through api.php with the
 * Bearer token.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
if (!headers_sent()) {
    header('Cache-Control: no-store');
}

require_once __DIR__ . '/src/chrome.php';
require_once __DIR__ . '/src/db.php';

$embedMode = isset($_GET['embed']);

$dbError = null;
$rows = [];
try {
    $db = catalog_db();
    $rows = catalog_tree(catalog_skus_all($db));
} catch (Throwable $e) {
    error_log('catalog index: ' . $e->getMessage());
    $dbError = 'Catalog database is not reachable. Showing an empty listing.';
}

?>
<?php nb_chrome_head(
    'catalog :: service catalog',
    [
        '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">',
        '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">',
        '<link rel="stylesheet" href="src/catalog.css">',
    ]
); ?>
<?php nb_chrome_topnav('catalog'); ?>

<div class="container-fluid">
    <header class="bar">
        <div class="bar-title">
            <h1>catalog</h1>
            <span class="auth-note" id="auth-note"></span>
        </div>
        <div class="bar-right">
            <input type="text" id="listing-filter" class="form-control form-control-sm"
                   placeholder="filter (matches children too)" autocomplete="off" spellcheck="false">
            <span class="muted" id="filter-count"></span>
            <a href="sku.php" class="btn needs-auth"><i class="bi bi-plus-lg"></i> add sku</a>
        </div>
    </header>

    <?php if ($dbError !== null): ?>
    <div class="alert alert-warning" role="alert"><?= catalog_h($dbError) ?></div>
    <?php endif; ?>

    <div class="row">
        <table id="cat-listing" class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>category</th>
                    <th>summary</th>
                    <th>lands on</th>
                    <th>consumer</th>
                    <th>class</th>
                    <th>owner</th>
                    <th>form fields</th>
                    <th>not this SKU</th>
                    <th class="needs-auth">doc url</th>
                    <th class="needs-auth"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $id = (string)$r['id'];
                $depth = (int)($r['depth'] ?? 0);
                $docUrl = trim((string)($r['doc_url'] ?? ''));
                $href = $docUrl !== '' ? $docUrl : 'sku.php?id=' . rawurlencode($id);
                $class = (string)($r['class_name'] ?? '');
                $search = strtolower(implode(' ', array_map('strval', [
                    $id,
                    $r['family_name'],
                    $r['product'],
                    $r['variant'],
                    $r['category_name'],
                    $r['summary'],
                    $r['lands_on'],
                    $r['consumer'],
                    $r['class_name'],
                    $r['owner_name'],
                    $r['not_this'],
                    $r['form_fields'],
                ])));
                $fieldsShow = (string)($r['form_fields'] ?? '');
                $decoded = json_decode($fieldsShow, true);
                if (is_array($decoded)) {
                    $fieldsShow = implode(', ', array_map('strval', $decoded));
                }
            ?>
                <tr data-id="<?= catalog_h($id) ?>"
                    data-parent="<?= catalog_h((string)($r['parent_sku'] ?? '')) ?>"
                    data-key="<?= catalog_h($search) ?>">
                    <td class="sku-cell">
                        <span class="tree-indent" style="padding-left: <?= $depth * 18 ?>px"></span><?php if ($depth > 0): ?><span class="tree-branch">&#8627;</span><?php endif; ?><a href="<?= catalog_h($href) ?>" <?= $docUrl !== '' ? 'title="doc: ' . catalog_h($docUrl) . '"' : '' ?>><?= catalog_h($id) ?></a>
                    </td>
                    <td><?= catalog_h((string)($r['category_name'] ?? '')) ?></td>
                    <td><?= catalog_h((string)($r['summary'] ?? '')) ?></td>
                    <td class="nowrap lands"><?= catalog_h((string)($r['lands_on'] ?? '')) ?></td>
                    <td><?= catalog_h((string)($r['consumer'] ?? '')) ?></td>
                    <td><?php if ($class !== ''): ?><span class="chip <?= catalog_h($class) ?>"><?= catalog_h($class) ?></span><?php endif; ?></td>
                    <td><?= catalog_h((string)($r['owner_name'] ?? '')) ?></td>
                    <td><?= catalog_h($fieldsShow) ?></td>
                    <td><?= catalog_h((string)($r['not_this'] ?? '')) ?></td>
                    <td class="needs-auth">
                        <span class="docset">
                            <input type="text" data-docinput="<?= catalog_h($id) ?>"
                                   value="<?= catalog_h($docUrl) ?>" placeholder="https://docs.example.com/&hellip;"
                                   autocomplete="off" spellcheck="false">
                            <button type="button" class="btn" data-docsave="<?= catalog_h($id) ?>" title="save doc url">set</button>
                        </span>
                    </td>
                    <td class="needs-auth cell-actions">
                        <span class="rowbtns">
                            <a class="btn" href="sku.php?id=<?= rawurlencode($id) ?>"><i class="bi bi-pencil"></i> edit</a>
                            <button type="button" class="btn btn-outline-danger" data-del="<?= catalog_h($id) ?>"><i class="bi bi-trash"></i> del</button>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted" id="empty-note" style="display:none">no rows match the filter.</p>
    </div>
</div>

<script src="src/catalog.js"></script>
<script>
(function () {
    'use strict';
    Catalog.initAuth();

    /* --- listing filter: match any field, keep ancestors so a child
     * hit stays inside its tree even when the parent text misses --- */
    var input = document.getElementById('listing-filter');
    var count = document.getElementById('filter-count');
    var emptyNote = document.getElementById('empty-note');
    var rows = Array.prototype.slice.call(document.querySelectorAll('#cat-listing tbody tr'));
    var byId = {};
    rows.forEach(function (tr) { byId[tr.getAttribute('data-id')] = tr; });

    function applyFilter() {
        var q = input.value.trim().toLowerCase();
        Catalog.saveFilter('catalog', { q: q });
        if (!q) {
            rows.forEach(function (tr) { tr.hidden = false; });
            count.textContent = rows.length ? String(rows.length) : '';
            emptyNote.style.display = rows.length ? 'none' : 'block';
            return;
        }
        var keep = {};
        rows.forEach(function (tr) {
            if (tr.getAttribute('data-key').indexOf(q) !== -1) {
                keep[tr.getAttribute('data-id')] = true;
                /* walk up: parent chain stays visible */
                var parent = tr.getAttribute('data-parent');
                while (parent && !keep[parent]) {
                    keep[parent] = true;
                    var ptr = byId[parent];
                    parent = ptr ? ptr.getAttribute('data-parent') : '';
                }
            }
        });
        var shown = 0;
        rows.forEach(function (tr) {
            var show = !!keep[tr.getAttribute('data-id')];
            tr.hidden = !show;
            if (show) shown++;
        });
        count.textContent = shown + ' / ' + rows.length;
        emptyNote.style.display = shown ? 'none' : 'block';
    }

    var saved = Catalog.loadFilter('catalog');
    if (saved.q) { input.value = saved.q; }
    input.addEventListener('input', applyFilter);
    applyFilter();

    /* --- inline doc-url set (PATCH {doc_url}) --- */
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-docsave]');
        if (!btn) return;
        var id = btn.getAttribute('data-docsave');
        var field = document.querySelector('[data-docinput="' + id + '"]');
        if (!field) return;
        var url = field.value.trim();
        if (url && !/^https?:\/\//i.test(url)) {
            Catalog.toast('doc url must start with http:// or https://', true);
            return;
        }
        Catalog.api('sku', { method: 'PATCH', id: id, body: { doc_url: url } })
            .then(function () { Catalog.toast('doc url saved for ' + id); })
            .catch(function (err) { Catalog.toast(err.message, true); });
    });

    /* --- delete (DELETE sku) --- */
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-del]');
        if (!btn) return;
        var id = btn.getAttribute('data-del');
        Catalog.confirmDialog({
            title: 'delete sku',
            bodyHtml: 'Delete <strong>' + id + '</strong>? Children keep their place but lose this parent.',
            confirmLabel: 'delete',
            danger: true
        }).then(function (yes) {
            if (!yes) return;
            Catalog.api('sku', { method: 'DELETE', id: id })
                .then(function () {
                    var tr = byId[id];
                    if (tr) { tr.remove(); }
                    Catalog.toast('deleted ' + id);
                    applyFilter();
                })
                .catch(function (err) { Catalog.toast(err.message, true); });
        });
    });
})();
</script>
<?php nb_chrome_foot(); ?>