/*
 * catalog.js: shared browser helpers for the wanportal catalog
 * sidecar pages (index.php, sku.php, admin.php).
 *
 * The token: the same key the SPA's session.js uses. sessionStorage
 * 'wanportal.jwt', tab-scoped (a same-origin embed frame reads the
 * same storage). It only ever rides the Authorization header to
 * api.php and /cgi-bin/api/session; it is never logged and never
 * written into the DOM.
 *
 * Honesty rule (mirrors the SPA): "signed out" and "portal
 * unreachable" stay separate. A dead session API is never reported
 * as signed out, so editing can be disabled for the right reason.
 *
 * Listing filters persist like the SPA's listingFilter.js: one
 * localStorage key 'wanportal-filter-<page>' holding {q}, read back
 * only when q is a string, wrapped against broken storage.
 */
(function () {
    'use strict';

    var TOKEN_KEY = 'wanportal.jwt';

    function getToken() {
        try {
            return sessionStorage.getItem(TOKEN_KEY);
        } catch (e) {
            return null;
        }
    }

    /* An expired token is dropped so the next call goes out bare. */
    function clearToken() {
        try {
            sessionStorage.removeItem(TOKEN_KEY);
        } catch (e) { /* storage may be off; nothing to clean */ }
    }

    function sessionState() {
        var token = getToken();
        if (!token) {
            return Promise.resolve({ state: 'signed-out', username: null });
        }
        return fetch('/cgi-bin/api/session', {
            credentials: 'omit',
            headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + token }
        }).then(function (res) {
            if (res.status === 401) {
                clearToken();
                return { state: 'signed-out', username: null };
            }
            if (!res.ok) {
                return { state: 'unavailable', username: null };
            }
            return res.json().then(function (claims) {
                if (claims && claims.status === 'success') {
                    return { state: 'ok', username: claims.username || '' };
                }
                return { state: 'unavailable', username: null };
            }, function () {
                return { state: 'unavailable', username: null };
            });
        }, function () {
            return { state: 'unavailable', username: null };
        });
    }

    /* Check the portal session and flip body classes accordingly:
     *   authed            -> .needs-auth controls show
     *   signed-out        -> #auth-note suggests signing in
     *   auth-unavailable  -> #auth-note says the portal is unreachable
     * Resolves with {state, username} and fires 'catalog:auth'. */
    function initAuth() {
        return sessionState().then(function (s) {
            document.body.classList.add(s.state === 'ok' ? 'authed' : 'signed-out');
            if (s.state === 'unavailable') {
                document.body.classList.add('auth-unavailable');
            }
            document.body.setAttribute('data-username', s.username || '');
            var note = document.getElementById('auth-note');
            if (note) {
                if (s.state === 'ok') {
                    note.textContent = '';
                } else if (s.state === 'signed-out') {
                    note.textContent = 'Sign in on the portal to add or edit.';
                } else {
                    note.textContent = 'Portal session unavailable \u2014 editing disabled (not the same as signed out).';
                }
            }
            document.dispatchEvent(new CustomEvent('catalog:auth', { detail: s }));
            return s;
        });
    }

    /* api.php wrapper: Bearer rides along when a token is stored; the
     * server re-validates every mutation anyway. Throws Error with
     * .status and .payload on any non-ok answer. */
    function api(resource, opts) {
        opts = opts || {};
        var url = 'api.php?resource=' + encodeURIComponent(resource);
        if (opts.id) {
            url += '&id=' + encodeURIComponent(opts.id);
        }
        var headers = { 'Accept': 'application/json' };
        var hasBody = opts.body !== undefined && opts.body !== null;
        if (hasBody) {
            headers['Content-Type'] = 'application/json';
        }
        var token = getToken();
        if (token) {
            headers['Authorization'] = 'Bearer ' + token;
        }
        return fetch(url, {
            method: opts.method || 'GET',
            credentials: 'omit',
            headers: headers,
            body: hasBody ? JSON.stringify(opts.body) : null
        }).then(function (res) {
            return res.json().catch(function () { return null; }).then(function (j) {
                if (res.ok && j && j.ok) {
                    return j;
                }
                var err = new Error((j && j.error) || ('HTTP ' + res.status));
                err.status = res.status;
                err.payload = j;
                throw err;
            });
        }, function (transport) {
            var err = new Error('network error: ' + ((transport && transport.message) || 'request failed'));
            err.status = 0;
            throw err;
        });
    }

    /* --- toast --- */
    function toast(msg, isErr) {
        var box = document.querySelector('.toastbox');
        if (!box) {
            box = document.createElement('div');
            box.className = 'toastbox';
            document.body.appendChild(box);
        }
        var line = document.createElement('div');
        line.className = 'toastline' + (isErr ? ' err' : '');
        line.textContent = msg;
        box.appendChild(line);
        setTimeout(function () { line.classList.add('gone'); }, 3800);
        setTimeout(function () { line.remove(); }, 4600);
    }

    /* --- confirm dialog (optional type-to-confirm, like the family
     * delete: name + cascade count are shown before enabling) --- */
    function confirmDialog(opts) {
        return new Promise(function (resolve) {
            var done = false;
            var dlg = document.createElement('dialog');
            dlg.className = 'catdlg';

            var h = document.createElement('h3');
            h.textContent = opts.title || 'confirm';
            dlg.appendChild(h);

            var body = document.createElement('div');
            body.className = 'dlg-body';
            body.innerHTML = opts.bodyHtml || '';
            dlg.appendChild(body);

            var input = null;
            if (opts.requireText) {
                var wrap = document.createElement('div');
                wrap.className = 'dlg-require';
                var label = document.createElement('label');
                label.textContent = 'Type "' + opts.requireText + '" to confirm:';
                input = document.createElement('input');
                input.className = 'form-control form-control-sm';
                input.setAttribute('autocomplete', 'off');
                input.setAttribute('spellcheck', 'false');
                wrap.appendChild(label);
                wrap.appendChild(input);
                dlg.appendChild(wrap);
            }

            var row = document.createElement('div');
            row.className = 'dlg-row';
            var cancel = document.createElement('button');
            cancel.className = 'btn';
            cancel.type = 'button';
            cancel.textContent = 'cancel';
            var ok = document.createElement('button');
            ok.className = 'btn' + (opts.danger ? ' btn-outline-danger' : '');
            ok.type = 'button';
            ok.textContent = opts.confirmLabel || 'confirm';
            row.appendChild(cancel);
            row.appendChild(ok);
            dlg.appendChild(row);
            document.body.appendChild(dlg);

            function close(val) {
                if (done) return;
                done = true;
                dlg.close();
                dlg.remove();
                resolve(val);
            }
            cancel.addEventListener('click', function () { close(false); });
            ok.addEventListener('click', function () { close(true); });
            dlg.addEventListener('cancel', function () { close(false); });
            if (input) {
                ok.disabled = true;
                input.addEventListener('input', function () {
                    ok.disabled = input.value !== opts.requireText;
                });
                input.focus();
            }
            dlg.showModal();
        });
    }

    /* --- listing filter persistence (SPA listingFilter.js contract) --- */
    function loadFilter(page) {
        try {
            var raw = localStorage.getItem('wanportal-filter-' + page);
            if (raw === null) {
                return { q: '' };
            }
            var parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object' || typeof parsed.q !== 'string') {
                return { q: '' };
            }
            return { q: parsed.q };
        } catch (e) {
            return { q: '' };
        }
    }

    function saveFilter(page, state) {
        try {
            localStorage.setItem('wanportal-filter-' + page, JSON.stringify({ q: String((state && state.q) || '') }));
        } catch (e) { /* the filter still applies in-page */ }
    }

    window.Catalog = {
        getToken: getToken,
        sessionState: sessionState,
        initAuth: initAuth,
        api: api,
        toast: toast,
        confirmDialog: confirmDialog,
        loadFilter: loadFilter,
        saveFilter: saveFilter
    };
})();