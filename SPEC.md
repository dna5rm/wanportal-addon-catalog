# wanportal-addon-catalog v1

Generic service catalog sidecar. Public read, portal-login write.
Everything ships generic: example.com only, no org names, no PII.

## Layout
- Host tree: /srv/addons/wanportal-addon-catalog/
- The image copies app/ to /var/www/localhost/htdocs/catalog/
- Health: GET /health returns JSON and touches nothing (no DB, no
  portal call), so the compose healthcheck only proves Apache serves
- SQLite: data/ on the host binds to /var/lib/catalog in the
  container. The file is catalog.sqlite, mode 640, writable by the
  apache user; the entrypoint preps the dir (mkdir, chmod 770 when it
  can write)
- Backup: api.php copies the live file to catalog.sqlite.bak before
  every successful mutating request. The .bak is the only undo

## Auth
- GET listing, detail, lookups: public
- POST/PATCH/DELETE (and HTML form posts): Authorization Bearer with
  the wanportal JWT
- Validation: GET http://wanportal/cgi-bin/api/session with that
  Bearer. 2xx and authenticated means allow, any signed-in user, not
  only is_admin
- The token is never written to sqlite

## SKU id
FAMILY-PRODUCT or FAMILY-PRODUCT-VARIANT.
- FAMILY: the families table slug, [A-Z][A-Z0-9]*
- PRODUCT / VARIANT: [A-Z0-9]+, uppercase
Computed, unique. A rename updates the id and every child's
parent_sku; the old id then 404s.

## Schema
families(id, slug, name, sort)
categories(id, slug, name, sort)
classes(id, slug, name, sort) seed: exists, poc, legacy, retired
owners(id, slug, name, sort) seed: management, architecture, engineering, support, operations
skus(id TEXT PK, family_id, product, variant NULL, category_id, summary, lands_on, consumer, class_id, owner_id, form_fields, not_this, doc_url, parent_sku NULL FK skus ON UPDATE CASCADE ON DELETE SET NULL)

Family delete cascades its SKUs. The UI confirms with the family name
and the live SKU count before it fires.

parent_sku: self and cycles rejected. N-level tree. The listing
renders depth-first, children indented. A filter match on a child
keeps its ancestors visible even when the parent row text misses.

## Pages (PHP)
- index.php: listing table. SKU (links to doc_url when set, else
  sku.php?id=), category, summary, lands_on, consumer, class, owner.
  Children indented. Filter box. Logged in: add, edit, delete, inline
  doc url
- sku.php?id=: detail, plus the edit form when authed
- admin.php: lookup CRUD
- api.php: JSON the pages fetch

## Chrome
app/src/chrome.php provides head/topnav/foot under wanportal_addon_*,
nb_chrome_*, chrome_* and bare aliases, all function_exists-guarded so
any caller can require it as-is. Topnav: Home + Catalog + Admin, no
other sidecar links. Footer: wanportal · catalog sidecar. embed=1
hides the topnav; theme tokens and the postMessage/storage theme
follow stay. No target=_blank anywhere.

## Docker
Alpine 3.21, apache, php84-sqlite3, php84-curl, php84-session,
php84-json, curl. USER apache. Authorization header passthrough
(SetEnvIf). Alias /health to the static probe.

## Wire (live; gitignored where appropriate)
- /srv/wanportal/docker-compose.override.yml: service
  wanportal-addon-catalog, data volume bind, no host port, netops
  network, healthcheck curl localhost/health
- conf/addons-proxy.conf: Location /catalog/ + ProxyPass
- same lines mirrored into conf/addons-proxy.conf.example
- htdocs/config.json: menu entry Catalog, href /catalog/

## Seed
About 16 generic rows (MAN-L3, WAN-MPLS, DC-VLAN, VIP-LB, the FW-VSYS
parent and child, and more), example.com language, no vendor or
customer names. Seeded once so operator deletions stick.

## Verify
php -l on touched files. Build and up from /srv/wanportal, never from
the addon dir. curl http://127.0.0.1:3385/catalog/ for 200, /health on
the container, no Fatal in any page body, data-theme present, no
target=_blank, nothing in the output that names a real org.