<?php
/**
 * sku.php: SKU detail, and the edit form once the visitor is signed
 * in (create mode when called without ?id=).
 *
 * The detail render is public. The edit form is in the markup but
 * hidden until catalog.js confirms a signed-in portal session; save
 * and delete go through api.php with the Bearer token. The id is
 * computed FAMILY-PRODUCT[-VARIANT] server-side. A product, variant
 * or family change renames the row (old id then 404s), and children's
 * parent_sku follows via the FK cascade.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
if (!headers_sent()) {
    header('Cache-Control: no-store');
}

require_once __DIR__ . '/src/chrome.php';
require_once __DIR__ . '/src/db.php';

$embedMode = isset($_GET['embed']);
$skuId = trim((string)($_GET['id'] ?? ''));
$notFound = false;
$row = null;
$children = [];
try {
    $db = catalog_db();
    if ($skuId !== '') {
        $row = catalog_sku_get($db, $skuId);
        $notFound = $row === null;
        if ($row) {
            $children = catalog_sku_children($db, $skuId);
        }
    }
} catch (Throwable $e) {
    error_log('catalog sku: ' . $e->getMessage());
    $row = null;
    $dbError = 'Catalog database is not reachable.';
}
$notFound = $skuId !== '' && $row === null && $dbError === null;
$isCreate = $skuId === '';

if ($notFound) {
    http_response_code(404);
}

?>
<?php nb_chrome_head(
    'catalog :: ' . ($notFound ? 'sku not found' : ($isCreate ? 'new sku' : $skuId)),
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
            <h1>sku</h1>
            <span class="sku-id"><?= $notFound ? 'not found' : catalog_h($isCreate ? 'new' : $skuId) ?></span>
            <span class="auth-note" id="auth-note"></span>
        </div>
        <div class="bar-right">
            <a href="index.php" class="btn"><i class="bi bi-list-ul"></i> catalog</a>
            <?php if (!$isCreate && !$notFound): ?>
            <button type="button" class="btn btn-outline-danger needs-auth" id="sku-delete">
                <i class="bi bi-trash"></i> delete
            </button>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($notFound): ?>
        <div class="panel">
            <p><strong>sku not found.</strong> Renamed SKUs stop resolving under their old id.</p>
            <p class="muted">Nothing here matches <code><?= catalog_h($skuId) ?></code>. <a href="index.php">Back to the catalog</a>.</p>
        </div>
    <?php elseif ($dbError !== null): ?>
        <div class="alert alert-warning" role="alert"><?= catalog_h($dbError) ?></div>
    <?php else: ?>

    <?php if ($row): ?>
    <section class="panel">
        <dl class="detail">
            <dt>id</dt><dd><strong><?= catalog_h($row['id']) ?></strong></dd>
            <dt>family</dt><dd><?= catalog_h((string)$row['family_name']) ?> <span class="muted">(<?= catalog_h((string)$row['family_slug']) ?>)</span></dd>
            <dt>category</dt><dd><?= catalog_h((string)($row['category_name'] ?? '')) ?></dd>
            <dt>product</dt><dd><?= catalog_h((string)$row['product']) ?></dd>
            <dt>variant</dt><dd><?= catalog_h((string)($row['variant'] ?? '')) ?></dd>
            <dt>lands on</dt><dd><?= catalog_h((string)($row['lands_on'] ?? '')) ?></dd>
            <dt>consumer</dt><dd><?= catalog_h((string)($row['consumer'] ?? '')) ?></dd>
            <dt>class</dt><dd><?php $cls = (string)($row['class_name'] ?? ''); if ($cls !== ''): ?><span class="chip <?= catalog_h($cls) ?>"><?= catalog_h($cls) ?></span><?php endif; ?></dd>
            <dt>owner</dt><dd><?= catalog_h((string)($row['owner_name'] ?? '')) ?></dd>
            <dt>parent</dt><dd><?php $parent = (string)($row['parent_sku'] ?? ''); if ($parent !== ''): ?><a href="sku.php?id=<?= rawurlencode($parent) ?>"><?= catalog_h($parent) ?></a><?php else: ?><span class="muted">&mdash;</span><?php endif; ?></dd>
            <dt>children</dt><dd><?php if ($children): ?><?php foreach ($children as $i => $c): ?><?php if ($i > 0): ?>, <?php endif; ?><a href="sku.php?id=<?= rawurlencode((string)$c['id']) ?>"><?= catalog_h($c['id']) ?></a><?php endforeach; ?><?php else: ?><span class="muted">&mdash;</span><?php endif; ?></dd>
            <dt>doc url</dt><dd><?php $doc = trim((string)($row['doc_url'] ?? '')); if ($doc !== ''): ?><a href="<?= catalog_h($doc) ?>"><?= catalog_h($doc) ?></a><?php else: ?><span class="muted">&mdash;</span><?php endif; ?></dd>
            <dt>summary</dt><dd class="wide"><?= catalog_h((string)($row['summary'] ?? '')) ?></dd>
            <dt>form fields</dt><dd class="wide"><pre><?= catalog_h((string)($row['form_fields'] ?? '')) ?></pre></dd>
            <dt>not this</dt><dd class="wide"><pre><?= catalog_h((string)($row['not_this'] ?? '')) ?></pre></dd>
        </dl>
    </section>
    <?php endif; ?>

    <section class="panel needs-auth" id="edit-panel">
        <h2 style="font-size: 15px; margin: 0 0 4px;"><?= $isCreate ? 'new sku' : 'edit sku' ?></h2>
        <form id="sku-form" autocomplete="off">
            <div class="form-grid">
                <div class="field">
                    <label class="flabel" for="f_family">family</label>
                    <select id="f_family" class="form-select form-select-sm" required></select>
                </div>
                <div class="field">
                    <label class="flabel" for="f_product">product <span class="muted">([A-Z0-9]+)</span></label>
                    <input type="text" id="f_product" class="form-control form-control-sm" required maxlength="32">
                </div>
                <div class="field">
                    <label class="flabel" for="f_variant">variant <span class="muted">(optional, [A-Z0-9]+)</span></label>
                    <input type="text" id="f_variant" class="form-control form-control-sm" maxlength="32">
                </div>
                <div class="field">
                    <label class="flabel" for="f_category">category</label>
                    <select id="f_category" class="form-select form-select-sm"></select>
                </div>
                <div class="field">
                    <label class="flabel" for="f_class">class</label>
                    <select id="f_class" class="form-select form-select-sm"></select>
                </div>
                <div class="field">
                    <label class="flabel" for="f_owner">owner</label>
                    <select id="f_owner" class="form-select form-select-sm"></select>
                </div>
                <div class="field">
                    <label class="flabel" for="f_lands">lands on <span class="muted">(device / platform)</span></label>
                    <input type="text" id="f_lands" class="form-control form-control-sm" placeholder="shared firewall pair">
                </div>
                <div class="field">
                    <label class="flabel" for="f_consumer">consumer</label>
                    <input type="text" id="f_consumer" class="form-control form-control-sm" maxlength="200">
                </div>
                <div class="field span2">
                    <label class="flabel" for="f_summary">summary</label>
                    <input type="text" id="f_summary" class="form-control form-control-sm" maxlength="500">
                </div>
                <div class="field span2">
                    <label class="flabel" for="f_parent">parent sku <span class="muted">(optional, no self/cycles)</span></label>
                    <select id="f_parent" class="form-select form-select-sm"></select>
                </div>
                <div class="field span2">
                    <label class="flabel" for="f_docurl">doc url</label>
                    <input type="text" id="f_docurl" class="form-control form-control-sm" maxlength="500" placeholder="https://docs.example.com/&hellip;">
                </div>
                <div class="field span2">
                    <label class="flabel" for="f_fields">form fields <span class="muted">(JSON array of field labels)</span></label>
                    <textarea id="f_fields" class="form-control form-control-sm" maxlength="4000" placeholder="[&quot;site&quot;,&quot;vlan_id&quot;]"></textarea>
                </div>
                <div class="field span2">
                    <label class="flabel" for="f_notthis">not this</label>
                    <textarea id="f_notthis" class="form-control form-control-sm" maxlength="4000"></textarea>
                </div>
            </div>
            <div class="idpreview">id will be <strong id="id-preview"><?= catalog_h($isCreate ? 'FAMILY-PRODUCT' : $skuId) ?></strong></div>
            <div class="errline" id="form-error"></div>
            <div style="margin-top: 10px; display: flex; gap: 8px;">
                <button type="submit" class="btn"><?= $isCreate ? 'create' : 'save' ?></button>
                <a href="index.php" class="btn">cancel</a>
            </div>
        </form>
    </section>

    <?php endif; ?>
</div>

<?php if (!$notFound): ?>
<script type="application/json" id="sku-data"><?= json_encode([
    'mode' => $isCreate ? 'create' : 'edit',
    'sku' => $row,
]) ?></script>
<?php endif; ?>

<script src="src/catalog.js"></script>
<script>
(function () {
    'use strict';
    var dataEl = document.getElementById('sku-data');
    var data = dataEl ? JSON.parse(dataEl.textContent) : { mode: 'edit', sku: null };
    var isCreate = data.mode === 'create';
    var sku = data.sku;

    Catalog.initAuth();

    /* Load lookups + the sku list (both public), then fill the form. */
    Promise.all([
        Catalog.api('lookups'),
        Catalog.api('skus')
    ]).then(function (results) {
        var lookups = results[0];
        var skus = results[1].skus;

        function fillSelect(sel, rows, keepEmpty) {
            sel.innerHTML = '';
            if (keepEmpty) {
                var none = document.createElement('option');
                none.value = '';
                none.textContent = '\u2014';
                sel.appendChild(none);
            }
            rows.forEach(function (r) {
                var opt = document.createElement('option');
                opt.value = r.id;
                opt.textContent = r.name + ' (' + r.slug + ')';
                sel.appendChild(opt);
            });
        }
        fillSelect(document.getElementById('f_family'), lookups.families, false);
        fillSelect(document.getElementById('f_category'), lookups.categories, true);
        fillSelect(document.getElementById('f_class'), lookups.classes, true);
        fillSelect(document.getElementById('f_owner'), lookups.owners, true);

        /* parent select: every sku except self and self's descendants */
        var parentSel = document.getElementById('f_parent');
        fillSelect(parentSel, [], true);
        var exclude = {};
        if (sku) {
            exclude[sku.id] = true;
            var grew = true;
            while (grew) {
                grew = false;
                skus.forEach(function (s) {
                    if (s.parent_sku && exclude[s.parent_sku] && !exclude[s.id]) {
                        exclude[s.id] = true;
                        grew = true;
                    }
                });
            }
        }
        skus.forEach(function (s) {
            if (exclude[s.id]) return;
            var opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.id;
            parentSel.appendChild(opt);
        });

        if (sku) {
            document.getElementById('f_family').value = String(sku.family_id);
            document.getElementById('f_product').value = sku.product || '';
            document.getElementById('f_variant').value = sku.variant || '';
            document.getElementById('f_category').value = sku.category_id ? String(sku.category_id) : '';
            document.getElementById('f_class').value = sku.class_id ? String(sku.class_id) : '';
            document.getElementById('f_owner').value = sku.owner_id ? String(sku.owner_id) : '';
            document.getElementById('f_lands').value = sku.lands_on || '';
            document.getElementById('f_consumer').value = sku.consumer || '';
            document.getElementById('f_summary').value = sku.summary || '';
            document.getElementById('f_parent').value = sku.parent_sku || '';
            document.getElementById('f_docurl').value = sku.doc_url || '';
            document.getElementById('f_fields').value = sku.form_fields || '';
            document.getElementById('f_notthis').value = sku.not_this || '';
        }

        /* live id preview: FAMILY-PRODUCT[-VARIANT] */
        var famSel = document.getElementById('f_family');
        var prodIn = document.getElementById('f_product');
        var varIn = document.getElementById('f_variant');
        function preview() {
            var famSlug = (famSel.options[famSel.selectedIndex] || { text: '' }).text.split(' ')[0] || 'FAMILY';
            var p = (prodIn.value.trim().toUpperCase() || 'PRODUCT').replace(/[^A-Z0-9]/g, '');
            var v = varIn.value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
            document.getElementById('id-preview').textContent = famSlug + '-' + p + (v ? '-' + v : '');
        }
        famSel.addEventListener('change', preview);
        prodIn.addEventListener('input', preview);
        varIn.addEventListener('input', preview);
        preview();
    }).catch(function (err) {
        Catalog.toast('could not load lookups: ' + err.message, true);
    });

    function showFormError(msg) {
        var el = document.getElementById('form-error');
        el.textContent = msg;
        el.classList.add('show');
    }

    document.getElementById('sku-form').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var body = {
            family_id: parseInt(document.getElementById('f_family').value, 10),
            product: document.getElementById('f_product').value.trim().toUpperCase(),
            variant: document.getElementById('f_variant').value.trim().toUpperCase(),
            category_id: document.getElementById('f_category').value || null,
            class_id: document.getElementById('f_class').value || null,
            owner_id: document.getElementById('f_owner').value || null,
            lands_on: document.getElementById('f_lands').value.trim(),
            consumer: document.getElementById('f_consumer').value.trim(),
            summary: document.getElementById('f_summary').value.trim(),
            parent_sku: document.getElementById('f_parent').value || null,
            doc_url: document.getElementById('f_docurl').value.trim(),
            form_fields: document.getElementById('f_fields').value.trim(),
            not_this: document.getElementById('f_notthis').value.trim()
        };
        var call = isCreate
            ? Catalog.api('sku', { method: 'POST', body: body })
            : Catalog.api('sku', { method: 'PATCH', id: sku.id, body: body });
        call.then(function (res) {
            var newId = res.sku ? res.sku.id : null;
            Catalog.toast(isCreate ? 'created ' + newId : 'saved ' + (res.renamed ? sku.id + ' \u2192 ' + newId : newId || sku.id));
            if (isCreate) {
                window.location.href = 'sku.php?id=' + encodeURIComponent(newId);
            } else if (res.renamed && newId) {
                /* old id is gone: replace so back/forward stays sane */
                window.location.replace('sku.php?id=' + encodeURIComponent(newId));
            } else {
                window.location.reload();
            }
        }).catch(function (err) {
            showFormError(err.message);
        });
    });

    var del = document.getElementById('sku-delete');
    if (del) {
        del.addEventListener('click', function () {
            Catalog.confirmDialog({
                title: 'delete sku',
                bodyHtml: 'Delete <strong>' + sku.id + '</strong>? Children keep their place but lose this parent.',
                confirmLabel: 'delete',
                danger: true
            }).then(function (yes) {
                if (!yes) return;
                Catalog.api('sku', { method: 'DELETE', id: sku.id })
                    .then(function () { window.location.href = 'index.php'; })
                    .catch(function (err) { Catalog.toast(err.message, true); });
            });
        });
    }
})();
</script>
<?php nb_chrome_foot(); ?>