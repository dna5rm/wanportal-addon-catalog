# wanportal-addon-catalog

A generic service catalog for network operators: SKUs, lookup tables,
parent/child links, one SQLite file. Plain PHP, no framework, no
database server. Runs as a sidecar behind the wanportal Apache proxy
at /catalog/.

Anyone can read the catalog. Adding and editing needs a signed-in
portal session: the pages send the wanportal JWT in the Authorization
header and api.php validates it against the portal session API before
every write.

## Pages

- index.php: the listing, an indented tree of SKUs with a filter box
- sku.php: one SKU's detail plus the create/edit form
- admin.php: CRUD for the lookup tables (families, categories,
  classes, owners)
- api.php: the JSON API the pages above call
- data/catalog.sqlite: the whole dataset, mode 640

SKU ids are computed from the family slug: FAMILY-PRODUCT[-VARIANT].
Renaming a SKU rewrites its id and its children's parent_sku, and the
old id stops resolving.

## Run it

Define the service in the portal compose override. Build and start from
the portal directory, not this one:

    cd /path/to/wanportal
    docker compose up --build -d wanportal-addon-catalog
    docker exec wanportal /usr/sbin/httpd -k graceful

The core Apache proxies /catalog/ to the container (see
conf/addons-proxy.conf); the sidecar publishes no host port:

    curl -f http://127.0.0.1:3385/catalog/
    docker exec wanportal-addon-catalog curl -sf http://localhost/health

Create a host `data/` directory and mount it at `/var/lib/catalog`.
The container apache user must be able to write it.

Do not run `docker compose up` from this directory: it conflicts with
the portal project name and creates a stray default network.

## Data and backups

data/ binds to /var/lib/catalog in the container and holds one SQLite
file, created and seeded on first open. Before every successful write
api.php copies the live file to catalog.sqlite.bak; if a change goes
wrong, copy the .bak back over catalog.sqlite. Deleting a family
removes every SKU in it (the UI makes you type the family name and
shows the count first), and the .bak is the only undo.

## Operator notes

- The host app/ tree is the source of truth. The container only picks
  up edits through an image rebuild or docker cp, and a compose
  recreation reverts it to the image, so re-copy anything you cp'd
  after one.
- Single-file edit, no rebuild wanted: chmod 644 the file on the
  host, docker cp it to the matching path under
  /var/www/localhost/htdocs/catalog/, then confirm it landed inside
  the container.
- SPEC.md is the design contract. Keep it current when behavior
  changes.