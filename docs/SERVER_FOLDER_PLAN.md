# Server folder destination — plan

**Status:** proposed (2026-10-26). Decisions taken: direct write (no Connector); the main path is a
**single domain** with WordPress in `/wp`; a subdomain for WordPress is the "most secure" variant.
The earlier idea of a static folder *inside* WordPress (`example.com/site/`) is dropped.

## Goal

Make the whole setup possible for a less experienced user on an ordinary cPanel account: one
domain, no subdomains or document-root changes, no FTP account, Connector or third-party service.
The public sees the static site; WordPress is locked to editors (Lockdown).

## Layouts

### A. Single domain — the guided default

```
public_html/          ← example.com          (static site; cPanel's default document root)
├── index.html, about/, …
└── wp/               ← example.com/wp/       (WordPress, Lockdown on)
```

- cPanel: nothing to change. Softaculous / WP Toolkit: install WordPress with Directory = `wp`.
  AutoSSL already covers the one domain.
- WordPress's address is `https://example.com/wp`; the export is published to `https://example.com/`.
  Rendering already drops the home sub-path (`Url::remap_site_path()`), so `/wp/about/` is exported
  as `/about/`, and Lockdown's gate already sends a blocked `/wp/about/` to `/about/`.
- The folder name is the user's choice (`wp` by default); Lockdown hides it either way.

### B. WordPress on a subdomain — the "most secure" variant

```
public_html/
├── static/           ← example.com          (main domain's document root changed to here)
└── wp/               ← wp.example.com        (WordPress)
```

Same deploy method, folder = the sibling `static/`. Different origins (see Security). Needs a
subdomain and, on the main domain, a document-root change (newer cPanel only).

### Existing sites already at the root → "Move WordPress into /wp"

The usual client starting point (WordPress installed at the domain root). The wizard offers to
**move WordPress into `/wp`** and then publishes the static site at the root — see §8. A "static in
front, same URLs" mode is deliberately not planned: page caches (LiteSpeed Cache, Cloudflare) can
mix the two versions up, and if `.htaccess` is lost the public silently gets live WordPress.

## Security: one domain = one origin

In layout A the static site and wp-admin share the origin `example.com`. Editors often browse the
public site while logged in; a compromised third-party script on a static page (embed, analytics,
chat widget) could then read and act on wp-admin as that editor — path-scoped cookies don't stop a
same-origin `fetch('/wp/wp-admin/…')`. Layout B is a different origin, which the browser isolates.
The wizard says this in one line and recommends B to anyone who can make a subdomain. (Our export
itself adds no third-party script; the risk comes from what the site's own pages embed.)

## 1. Deploy method "This server (folder)" — transport id `folder`

`Transport\FolderTransport implements TransportInterface`, writing with PHP's filesystem.

- **Field `folderPath`:** an absolute path, stored as given but always re-validated at use:
  - inside the account (`realpath()` under the home directory that contains the WordPress root,
    and within `open_basedir` when set);
  - never the WordPress root itself, never inside it (no `wp-content/…`), never a system folder;
  - may be the **parent** of the WordPress root (layout A) — then WordPress's folder is protected
    (below).
- **Protected paths:** in layout A every key whose first segment is WordPress's folder name (`wp/…`)
  is refused by `put`/`mkdir`/`rename`/`delete`, and Runner refuses an export page under it (a
  WordPress page slugged `wp` would otherwise land there; the wizard warns). Also refused anywhere:
  `cgi-bin/`, `.well-known/`.
- **Confinement:** keys joined to the folder, no `..`/NUL/backslash, the parent's `realpath()` must stay
  inside the folder (no writing through a symlink). Same discipline as `Paths::source_file()`.
- **Never a PHP file:** `put()` refuses `.php*`, `.phtml`, `.phar`, `.user.ini` (the export never has
  them; the folder is public and runs PHP).
- **Ownership:** a marker `.shipstatic-folder` (destination id) is written at setup; the transport
  refuses a folder without the matching marker, so a wrong path can't be written into or pruned.
  The folder doesn't have to be empty: prune only deletes files in the pushed manifest, so cPanel's
  own files and hand-added files survive. The wizard asks before replacing an existing `index.html`
  (cPanel's placeholder).
- **In the Runner:** an ordinary delta transport — hashing, holding page / home-last, `.gz` siblings,
  prune and the pushed manifest unchanged. `root_is_served_path('folder') === true` (the folder IS
  the served root). No package/unzip mode (local copies are fast); `needs_host()` false.
- **Test:** folder exists, marker matches, writable, free space, and a probe file fetched through the
  Destination URL (`UrlSafety::guarded_get()`) returns its contents — proves the URL serves this
  folder, the commonest mistake.
- **Taking over an existing static folder** (moving from FTP/Connector to this method, as on
  bricksbits.co): "This folder already holds the static site" writes the marker and rebuilds the
  pushed manifest from the files there by md5 (like `Runner::rebuild_pushed_record()`), so the first
  Sync doesn't rewrite everything.

## 2. The static site's `.htaccess` next to WordPress

In layout A, `public_html/.htaccess` is the parent of WordPress's folder, so what we put there
reaches `/wp/` too.

- `HtaccessBuilder::merge()` already keeps every other line — including cPanel MultiPHP's
  `AddHandler`, which WordPress in `wp/` needs for its PHP version. Keep it that way; add a test.
- It also strips a WordPress rewrite block from the destination file; in layout A that block lives
  in `wp/.htaccess`, untouched. Never touch any file under the protected folder.
- Our `Header`/cache rules must not apply to `/wp/` (WordPress's own files would get a year-long
  immutable cache): wrap them for folder destinations that contain WordPress. `<If "%{REQUEST_URI}
  !~ m#^/wp/#">` on Apache 2.4 — **verify on LiteSpeed**; fallback: `Header unset Cache-Control` rules
  in a small block we add to `wp/.htaccess` via `insert_with_markers()`.
- Our rewrite rules don't reach `/wp/`: WordPress's `wp/.htaccess` has its own `RewriteEngine On`,
  and Lockdown's `InheritDownBefore` only pushes rules *down* from `wp/`.
- `DirectoryIndex index.html index.php` in the root is inherited by `wp/` — harmless (WordPress's
  folder has no `index.html`), but keep `index.php` in it.
- nginx: layout A needs `location /wp/ { try_files $uri $uri/ /wp/index.php?$args; }` beside the
  static root; the snippet shows both blocks.

## 3. Lockdown in layout A

Lockdown's files stay in WordPress's folder (`wp/.htaccess`, `wp/.user.ini`, the gate, mu-plugin),
so they only cover `/wp/`. Nothing to exempt. Its redirect (first enabled destination) goes to the
domain root; the home sub-path is already stripped. One check to add: Lockdown must never be pointed
at the folder that contains WordPress as an exemption, and `remove_all()` must never touch the
static site's `.htaccess`.

## 4. The wizard

Opened from Destinations → "+" → **On this server**, from the Lockdown tab, and offered by the
first-run wizard when layout A or B is detected.

**Detection:** WordPress's address has a sub-path (`/wp`) and the folder above `ABSPATH` is the
document root (`$_SERVER['DOCUMENT_ROOT']`, saved from a web request as Lockdown does) → layout A. A
sibling folder that a different domain serves → layout B (only detectable via the probe; the user
picks it).

1. **Where** — "Publish at https://example.com/ (this domain's main folder)" pre-selected when layout A
   is detected; or "Another folder on this server" (path + URL, layout B). One line on the
   one-domain trade-off with a link to Docs.
2. **Check** — validates the folder, lists what's already there (cPanel placeholder `index.html`?
   replace it), warns about a WordPress page slugged `wp`.
3. **Create** — marker, destination saved, Test (incl. the probe). Failure explains the fix.
4. **Done** — Lockdown: turn it on now (recommended); **Sync now**.

REST (admin-only, §0): `POST /destinations/folder/check` (no side effects), `POST
/destinations/folder` (create). WP-CLI: `wp shipstatic folder create [<path>] [--url=] [--adopt]`,
`test`, `status`.

## 5. Edge cases

- Removing the destination leaves the files (it's the live site); "Delete the static files too"
  deletes only pushed-manifest files, never the protected folder.
- Moving the site: the stored absolute path is re-validated; Test fails with a clear message if
  the folder moved.
- Multisite: refused in v1.
- `robots.txt`/`sitemap.xml` at the domain root: ours (the static site owns the root in layout A).
- Search: unchanged.

## 6. Testing

- Local (Apache): WordPress moved to a `/wp` sub-install of a test site; layout A end to end.
- wp.bricksbits.co (LiteSpeed/cPanel): layout B — adopt `public_html/static`, Sync, compare.
- Docker nginx: layout A with the snippet.
- Confinement: `../`, symlink out of the folder, `wp/x.html`, `x.php`, `.user.ini` keys — refused;
  folder without marker — refused; prune leaves `cgi-bin/`, `.well-known/`, hand-added files.
- `.htaccess`: MultiPHP handler survives a Sync; `/wp/wp-admin/` responses carry no immutable cache.
- Lockdown on: `/wp/…` blocked and redirected, the static root untouched.

## 7. Files

New: `src/Transport/FolderTransport.php`, `src/Deploy/ServerFolder.php` (detect / validate / create /
adopt), routes in `DestinationsController`, `src-svelte/dashboard/FolderWizard.svelte`, CLI `folder`
command. Changed: `Schema` (`folder` enum + `folderPath`), `TransportFactory`, `Runner` (protected
keys, adopt), `HtaccessBuilder`, nginx snippet, `DestinationPanel.svelte`, `SetupWizard.svelte`,
`LockdownPanel.svelte`, `I18n` + 5 locales, Docs, SECURITY_PATTERNS.md (transport confinement,
one-origin note), CLAUDE.md.

## 8. Move WordPress into /wp (added 2026-10-26)

For a site installed at the domain root. `Deploy\WordPressMove`, REST `POST /wordpress-move/check`,
`POST /wordpress-move`, `POST /wordpress-move/undo`; WP-CLI `wp shipstatic move-wordpress
check|run|undo [--yes]`; started from the folder wizard when WordPress is at the document root.

**Pre-flight (all must pass):** WordPress's core folder = its site folder = the document root; home
URL has no path; not multisite; a `wp` file/folder doesn't exist and no post/page/term uses the
slug `wp`; `wp-config.php` is in the WordPress folder (WP can't find it two levels up after the
move) and doesn't define `WP_HOME`, `WP_SITEURL`, `WP_CONTENT_DIR/URL`, `WP_PLUGIN_DIR/URL`,
`UPLOADS` (we can't change those); Lockdown off (its files hold paths); a current render (Process);
every entry to move is writable. Warnings (not blocking): `.user.ini`/`php.ini` with an
`auto_prepend_file` into the WordPress folder (Wordfence), `advanced-cache.php`/`object-cache.php`
drop-ins (cache plugins rewrite them on their next save). The person confirms they have a backup.

**What moves:** only WordPress's own entries — `wp-admin`, `wp-includes`, `wp-content`,
`wp-config.php`, `index.php`, `wp-*.php`, `xmlrpc.php`, `license.txt`, `readme.html`. Anything
else at the root (verification files, `ads.txt`, `.well-known`, `cgi-bin`, other apps) stays and
keeps working for the static site. `.htaccess` and `php.ini` are copied (both folders need them).

**Order (one request, or WP-CLI):**
1. Record the plan (option `shipstatic_wp_move`: entries, old/new paths and URLs, original root
   `.htaccess`) — the DB isn't affected by moving files, so undo always has it.
2. Database: `home`/`siteurl` → `https://example.com/wp`; URLs of this site in content and
   settings get `/wp` (`Support\SerializedReplace`: serialized- and JSON-escape-aware, host
   boundary, idempotent — never a second `/wp`, objects left alone); file paths
   `<root>/wp-content|wp-includes|wp-admin` → `<root>/wp/…` in options (ShipStatic's render
   manifest holds them). ShipStatic's own destination URLs are untouched.
3. Response sent (`fastcgi_finish_request()`), then, as the request's last shutdown function (so
   nothing loads a moved file afterwards): `mkdir wp`, `rename()` each entry (instant, same disk),
   on any failure rename back what moved and revert step 2.
4. `wp/.htaccess`: the WordPress block with `RewriteBase /wp/`; root `.htaccess`: WordPress block
   removed (other lines kept), a holding `index.html` at the root until the first Sync.
5. The browser goes to `https://example.com/wp/wp-login.php?redirect_to=…shipstatic&folder-wizard=1`
   (login cookies are path-scoped, so a new login is expected); the folder wizard then offers
   "Publish at https://example.com/", and Sync reuses the render (paths already rewritten).

**Undo** (`POST /wordpress-move/undo`, CLI `undo`): remove the files the folder destination pushed
into the root (its pushed manifest only), rename the entries back, restore the root `.htaccess`,
revert the database (`/wp` removed by the same replacer in reverse; `home`/`siteurl` restored).
Offered in the destination panel while the move record exists.
