<?php
/**
 * admin.php: lookup-table CRUD for the catalog sidecar. Families,
 * categories, classes, owners. Public to view; editing is gated on
 * the portal session by catalog.js.
 *
 * Family delete is the dangerous one: it cascades every SKU in the
 * family (FK ON DELETE CASCADE), so the dialog fetches the live count
 * and requires typing the family slug before the button unlocks. The
 * other lookups refuse to delete while any SKU still references them
 * (api.php answers 409 with the count; the row's del button is
 * disabled ahead of time).
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
$lookups = [];
try {
    $db = catalog_db();
    $lookups = catalog_lookups($db);
} catch (Throwable $e) {
    error_log('catalog admin: ' . $e->getMessage());
    $dbError = 'Catalog database is not reachable. Showing empty tables.';
}

?>
<?php nb_chrome_head(
    'catalog :: admin',
    [
        '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">',
        '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">',
        '<link rel="stylesheet" href="src/catalog.css">',
    ]
); ?>
<?php nb_chrome_topnav('admin'); ?>

<div class="container-fluid">
    <header class="bar">
        <div class="bar-title">
            <h1>admin</h1>
            <span class="auth-note" id="auth-note"></span>
        </div>
        <div class="bar-right">
            <a href="index.php" class="btn"><i class="bi bi-list-ul"></i> catalog</a>
        </div>
    </header>

    <?php if ($dbError !== null): ?>
    <div class="alert alert-warning" role="alert"><?= catalog_h($dbError) ?></div>
    <?php endif; ?>

    <?php foreach (catalog_lookup_tables() as $table): ?>
    <section class="panel" style="margin-bottom: 16px;">
        <div class="bar">
            <div class="bar-title"><h2 style="font-size: 14px; margin: 0;"><?= catalog_h($table) ?></h2></div>
            <div class="bar-right">
                <form class="admin-add needs-auth" data-add="<?= catalog_h($table) ?>">
                    <input type="text" name="slug" placeholder="<?= $table === 'families' ? 'slug [A-Z][A-Z0-9]*' : 'slug' ?>"
                           autocomplete="off" spellcheck="false" required>
                    <input type="text" name="name" placeholder="name" autocomplete="off" required>
                    <input type="number" class="c-sort" name="sort" value="0">
                    <button type="submit" class="btn"><i class="bi bi-plus-lg"></i> add</button>
                </form>
            </div>
        </div>
        <table class="table table-hover" data-table="<?= catalog_h($table) ?>">
            <thead>
                <tr>
                    <th>slug</th><th>name</th><th>sort</th><th>skus</th><th class="needs-auth"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (($lookups[$table] ?? []) as $row): $count = (int)$row['sku_count']; ?>
                <tr data-id="<?= (int)$row['id'] ?>" data-slug="<?= catalog_h((string)$row['slug']) ?>">
                    <td class="c-slug"><code><?= catalog_h((string)$row['slug']) ?></code></td>
                    <td class="c-name"><?= catalog_h((string)$row['name']) ?></td>
                    <td class="c-sort"><?= (int)$row['sort'] ?></td>
                    <td class="c-count"><?= $count > 0 ? (int)$count : '&mdash;' ?></td>
                    <td class="cell-actions needs-auth">
                        <span class="rowbtns">
                            <button type="button" class="btn" data-edit="<?= (int)$row['id'] ?>"><i class="bi bi-pencil"></i> edit</button>
                            <?php if ($table === 'families'): ?>
                            <button type="button" class="btn btn-outline-danger" data-delfamily="<?= (int)$row['id'] ?>"><i class="bi bi-trash"></i> del</button>
                            <?php else: ?>
                            <button type="button" class="btn btn-outline-danger" data-del="<?= (int)$row['id'] ?>"
                                    <?= $count > 0 ? 'disabled title="in use by ' . (int)$count . ' sku(s)"' : '' ?>><i class="bi bi-trash"></i> del</button>
                            <?php endif; ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endforeach; ?>
</div>

<script src="src/catalog.js"></script>
<script>
(function () {
    'use strict';
    Catalog.initAuth();

    /* --- add row --- */
    document.querySelectorAll('form[data-add]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var table = form.getAttribute('data-add');
            var slug = form.elements.slug.value.trim();
            var name = form.elements.name.value.trim();
            var sort = form.elements.sort.value.trim() || '0';
            Catalog.api(table, { method: 'POST', body: { slug: slug, name: name, sort: sort } })
                .then(function () { window.location.reload(); })
                .catch(function (err) { Catalog.toast(err.message, true); });
        });
    });

    /* --- edit in place: swap slug/name/sort cells for inputs --- */
    var editing = null;
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-edit]');
        if (!btn) return;
        if (editing) { editing.restore(); }
        var tr = btn.closest('tr');
        var table = btn.closest('table').getAttribute('data-table');
        var cells = {
            slug: tr.querySelector('.c-slug'),
            name: tr.querySelector('.c-name'),
            sort: tr.querySelector('.c-sort')
        };
        var orig = {
            slug: cells.slug.textContent.trim(),
            name: cells.name.textContent,
            sort: cells.sort.textContent.trim()
        };
        var actions = tr.querySelector('.cell-actions');
        var origActions = actions.innerHTML;

        function restore() {
            cells.slug.innerHTML = '<code>' + orig.slug + '</code>';
            cells.name.textContent = orig.name;
            cells.sort.textContent = orig.sort;
            actions.innerHTML = origActions;
            editing = null;
        }

        cells.slug.innerHTML = '<input class="form-control form-control-sm" value="' + orig.slug.replace(/"/g, '&quot;') + '">';
        cells.name.innerHTML = '<input class="form-control form-control-sm" value="' + orig.name.replace(/"/g, '&quot;') + '">';
        cells.sort.innerHTML = '<input type="number" class="form-control form-control-sm" style="width:70px" value="' + orig.sort + '">';
        actions.innerHTML = '<span class="rowbtns"><button type="button" class="btn" data-save-row>save</button>' +
            '<button type="button" class="btn" data-cancel-row>cancel</button></span>';
        editing = { restore: restore, tr: tr };

        actions.querySelector('[data-save-row]').addEventListener('click', function () {
            var body = {
                slug: cells.slug.querySelector('input').value.trim(),
                name: cells.name.querySelector('input').value.trim(),
                sort: cells.sort.querySelector('input').value.trim() || '0'
            };
            Catalog.api(table, { method: 'PATCH', id: tr.getAttribute('data-id'), body: body })
                .then(function () { window.location.reload(); })
                .catch(function (err) { Catalog.toast(err.message, true); });
        });
        actions.querySelector('[data-cancel-row]').addEventListener('click', restore);
    });

    /* --- delete: families cascade (type-to-confirm with live count),
     * other lookups are blocked while in use --- */
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-del]');
        if (!btn) return;
        var tr = btn.closest('tr');
        var table = btn.closest('table').getAttribute('data-table');
        var id = tr.getAttribute('data-id');
        var slug = tr.getAttribute('data-slug');

        if (table === 'families') {
            Catalog.api('family', { id: id }).then(function (res) {
                var n = res.sku_count;
                Catalog.confirmDialog({
                    title: 'delete family',
                    bodyHtml: 'Delete family <strong>' + slug + '</strong>? ' +
                        '<span class="warnline">This permanently removes ' + n + ' SKU' + (n === 1 ? '' : 's') + '.</span>',
                    requireText: slug,
                    confirmLabel: 'delete family',
                    danger: true
                }).then(function (yes) {
                    if (!yes) return;
                    Catalog.api('families', { method: 'DELETE', id: id })
                        .then(function (out) {
                            Catalog.toast('deleted family ' + slug + ' (' + out.deleted_skus + ' SKUs removed)');
                            window.location.reload();
                        })
                        .catch(function (err) { Catalog.toast(err.message, true); });
                });
            }).catch(function (err) {
                Catalog.toast(err.message, true);
            });
            return;
        }

        var countCell = tr.querySelector('.c-count');
        var inUse = countCell && /^\d+$/.test(countCell.textContent.trim())
            ? parseInt(countCell.textContent.trim(), 10) : 0;
        if (inUse > 0) {
            Catalog.toast('in use by ' + inUse + ' sku(s); re-point them first', true);
            return;
        }
        Catalog.confirmDialog({
            title: 'delete ' + table.replace(/s$/, ''),
            bodyHtml: 'Delete <strong>' + slug + '</strong>?',
            confirmLabel: 'delete',
            danger: true
        }).then(function (yes) {
            if (!yes) return;
            Catalog.api(table, { method: 'DELETE', id: id })
                .then(function () { window.location.reload(); })
                .catch(function (err) { Catalog.toast(err.message, true); });
        });
    });
})();
</script>
<?php nb_chrome_foot(); ?>