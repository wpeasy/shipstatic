# Lockdown — implementation plan

> Status: **phases 1–3 implemented** (2026-10-25), branch `feature/lockdown`. Tested on nginx + PHP-FPM and on Apache (both in Local). LiteSpeed / OpenLiteSpeed untested.
> Implementation notes where it differs from the text below:
> - IP matching in `.htaccess` uses `Cidr::regex()` (REMOTE_ADDR regexes) on Apache **and** LiteSpeed, not `expr -R`: one code path, and LiteSpeed's `expr` support is unknown. Checked against 64,000 random addresses; IPv6 prefixes must be multiples of 16. Known gap: the IPv4-compatible `::a.b.c.d` form (only in `::/96`) isn't matched (fails closed).
> - Turning off with `.user.ini` in use leaves the gate as a **stub** for 10 minutes (§9): PHP caches `.user.ini` per worker and a missing `auto_prepend_file` is a fatal error. Manual recovery says to empty the gate, not delete it. `SHIPSTATIC_LOCKDOWN_OFF` (§9 step 4) is implemented in the gate (honoured on the mu-plugin run only).
> - The key handling (§5) and the check request (§8) live in `Guard`. The check answers 204 with an `X-ShipStatic-Address` header, so the probe can say "the server's own address is allowed" when it can't verify.
> - The render token is added to the site's own requests through `http_request_args` (which covers core's cron spawn too, so no `cron_request` filter), and stripped on a cross-host redirect via `requests-requests.before_redirect` (§14.4 answered: the HTTP library does keep headers across redirects).
> - Open questions §14.1 and §14.2: the admin email is on by default with a switch; key-added entries don't expire.
> - `/.well-known/` (§3.5): `.htaccess` matches `THE_REQUEST` (it survives WordPress's rewrite to index.php, which re-runs the rules) and only plain paths — no dot segments, no %-escapes, no query — because `/.well-known/x/../../wp-login.php` otherwise got through (found in testing). The gate has **no** `.well-known` exception: certificate files are static and never reach PHP, and REQUEST_URI is the raw request.
> - Not done yet: Check flagging exports that reference the WordPress origin (§7, phase 4). No AI ability at all (§10.6).
> Read `SECURITY_PATTERNS.md` first: this feature writes server config and executable PHP into the
> site, so it becomes one of the highest-impact surfaces in the plugin (see §11).

## 1. Why

In the intended ShipStatic setup, the WordPress site has no public purpose. It exists so editors can
build content in a CMS; the public site is the static export, which needs no maintenance and can't be
hacked through site code. The WordPress site is then the only thing an attacker can reach, so it
should answer only to its editors.

**Lockdown** locks the WordPress site to a list of allowed IP addresses. Everyone else is sent to the
static site. An **unlock link** (`?unlock=<key>`) adds the visitor's current IP to the list, so an
editor whose IP has changed can get back in.

The goal is that a stranger's request never runs PHP: no login page, no `xmlrpc.php`, no REST API, and
no vulnerable plugin's PHP file opened directly. A check inside WordPress can't stop that last one, so
the main lock has to sit in front of PHP (§4).

## 2. Decisions taken

| Question | Decision |
|---|---|
| Scope | The **whole site**, not just `wp-admin` / `wp-login.php`. |
| What blocked visitors see | A **302 redirect** to the same path on the static site (§7); a 403 when no destination has a URL. |
| What the unlock key does | It **adds the visitor's IP** to the allowed list (and sets the unlock cookie). |
| Unlock cookie lifetime | **12 hours.** |
| Where it lives | A new **Lockdown** top-level tab in the dashboard, after Schedule. |

## 3. Who gets in

A request passes when **any** of these is true. Everything else is redirected (or gets a 403).

1. **Its IP is on the allowed list.** Entries are single addresses or CIDR ranges, IPv4 or IPv6.
2. **It carries the unlock key** (`unlock=<key>` in the query string). WordPress then adds the IP (§5).
3. **It carries a valid unlock cookie** (`shipstatic_unlock`, 12 hours). This covers an IP that changes
   during a session, such as mobile data or rotating IPv6 addresses.
4. **It's one of the site's own requests**, carrying the existing render token header
   (`PageRenderer::TOKEN_HEADER`, `X-BS-Render-Token`) — see §6.
5. **It's a certificate renewal:** the path starts with `/.well-known/`. This path never runs PHP.

WP-CLI is never affected; it doesn't go through the web server.

## 4. The layers, per server

Every layer applies the same rules from §3, generated from one source of truth: the
`shipstatic_lockdown` option. Whatever layers apply to the server are written automatically when the
setting changes.

| Server | In front of PHP (main lock) | Inside WordPress (safety net) |
|---|---|---|
| Apache, LiteSpeed (incl. OpenLiteSpeed) | `.htaccess` block (§4.1) — blocks every file, PHP or not | mu-plugin (§4.3) |
| Nginx + PHP-FPM, and other FastCGI hosts | `.user.ini` → `auto_prepend_file` gate (§4.2) — runs before **every** PHP file | mu-plugin |
| Anything else (IIS, `.user.ini` disabled) | — | mu-plugin only, with a warning in the tab |

Server detection uses WordPress's own globals (`$is_apache` — true for LiteSpeed too — and `$is_nginx`,
`$is_iis7`), plus `SERVER_SOFTWARE` (which contains "LiteSpeed") and `php_sapi_name()`. `.user.ini` is
only read by CGI/FPM SAPIs, never by `apache2handler`. Detection isn't trusted on its own: a **probe**
(§8) confirms that a layer is actually applied. Nginx in front of Apache is common on cPanel and Plesk,
where `.htaccess` works but the IP Apache sees may be wrong.

### 4.1 `.htaccess` (Apache, LiteSpeed)

A marked block at the **very top** of the site root's `.htaccess`, above `# BEGIN WordPress` and above
LiteSpeed Cache's `# BEGIN LSCACHE`. A page cache that serves files without PHP must still be blocked.

WordPress's `insert_with_markers()` appends when its markers are absent, so we write the file ourselves:
read it, remove any old block, prepend the new one, write atomically. Make a backup first, and refuse
(with a clear message) if the file isn't writable. On each dashboard load the tab checks the block is
still present and still first, because other plugins rewrite `.htaccess`.

Generated from `src/Lockdown/templates/htaccess.txt` (no heredoc; see CLAUDE.md, Plugin Check):

```apache
# BEGIN ShipStatic Lockdown
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{REQUEST_URI} !^/\.well-known/
RewriteCond %{HTTP:X-BS-Render-Token} !^__TOKEN__$
RewriteCond %{QUERY_STRING} !(^|&)unlock=__KEY__(&|$)
RewriteCond %{HTTP_COOKIE} !(^|;\s*)shipstatic_unlock=__COOKIE__
__IP_CONDITIONS__
RewriteRule ^(.*)$ __REDIRECT__$1? [R=302,L]
</IfModule>
# END ShipStatic Lockdown
```

- All the conditions must match for the request to be blocked, so any one exemption lets it through.
- The trailing `?` on the target drops the query string. It's portable, unlike `QSD`, which LiteSpeed
  may not support.
- With no redirect target, the last line is `RewriteRule ^ - [F,L]`.
- **IP conditions:**
  - On **Apache 2.4**, one `RewriteCond expr "! -R '<cidr>'"` per entry. `-R` does real CIDR matching
    for IPv4 and IPv6.
  - On **LiteSpeed**, `expr` support is unverified, so we fall back to `RewriteCond %{REMOTE_ADDR} !<regex>`.
    Exact addresses are fine; IPv4 ranges on octet boundaries are fine. IPv6 /64 regexes have to account
    for `::` compression, so they need care and testing (§14).
- **OpenLiteSpeed** may only read `.htaccess` rewrites at restart (unless `autoLoadHtaccess` is on), so
  a changed list might not apply straight away. The probe (§8) detects this, and the mu-plugin still
  applies the change meanwhile.

### 4.2 `.user.ini` gate (nginx / PHP-FPM)

PHP can't edit or reload nginx's config, but it can write `.user.ini` in the site root:

```ini
; BEGIN ShipStatic Lockdown
auto_prepend_file = "/abs/path/wp-content/shipstatic-lockdown.php"
; END ShipStatic Lockdown
```

PHP then runs the **gate** before every PHP file: WordPress, `xmlrpc.php`, and any plugin file opened
directly. The gate is a self-contained file generated from `src/Lockdown/templates/gate.php.txt`. It
doesn't need WordPress loaded, and it holds only what it needs:

- the IP list, already parsed;
- the SHA-256 of the key;
- the SHA-256 of the render token;
- the cookie value;
- the redirect target;
- the path of any existing `auto_prepend_file`, to chain to (§4.2.1).

It never holds the key or the token in plain text, and never holds labels (§11).

- **Delay:** PHP caches `.user.ini` for `user_ini.cache_ttl` (300 s by default), so changes can take up
  to 5 minutes. The tab says so. The mu-plugin applies the change at once in the meantime.
- **Static files** (uploads, theme CSS) stay reachable on nginx. That's harmless here: they're public
  on the static site anyway.
- **Hosts that disable `.user.ini`:** the probe shows the gate isn't running, and the tab says the
  site is protected by the WordPress layer only.

#### 4.2.1 Chaining (Wordfence and others)

Wordfence's firewall uses `auto_prepend_file` too. When one is already set (`ini_get('auto_prepend_file')`
and/or an existing `.user.ini` line), the gate stores that path and `include`s it **after** deciding to
let the request through. Turning Lockdown off puts the original line back. We never silently replace
someone else's prepend.

#### 4.2.2 Optional nginx snippet

For people who manage their own server, the tab offers a copy-paste snippet for the `server {}` block.
`set` and `return` inside `if` are safe at server level:

```nginx
set $shipstatic_locked 1;
if ($remote_addr = 203.0.113.7)           { set $shipstatic_locked 0; }
if ($arg_unlock = "KEY")                  { set $shipstatic_locked 0; }
if ($cookie_shipstatic_unlock = "COOKIE") { set $shipstatic_locked 0; }
if ($http_x_bs_render_token = "TOKEN")    { set $shipstatic_locked 0; }
if ($uri ~ "^/\.well-known/")             { set $shipstatic_locked 0; }
if ($shipstatic_locked) { return 302 https://static.example.com$request_uri; }
```

For CIDR ranges, a `geo` block in the `http {}` context is the alternative. The tab must say plainly
that the snippet holds the list **as it was when copied**. An IP added later (including by the unlock
key) won't pass nginx until the snippet is re-pasted and nginx reloaded. Most people should rely on
the gate.

### 4.3 mu-plugin (every server)

`wp-content/mu-plugins/shipstatic-lockdown.php` is a few lines: if ShipStatic is installed, it
`require`s the same gate file. That makes it the safety net wherever the server layers don't apply,
or haven't applied yet. mu-plugins load before regular plugins, so a blocked request stops before any
other plugin's code runs. Only files opened directly bypass it, which is what §4.1 and §4.2 cover.

If ShipStatic's folder is renamed or deleted, the mu-plugin does nothing (it fails open). That's a
deliberate recovery path (§9).

## 5. The unlock key

- **Generated** when Lockdown is first set up: `wp_generate_password(32, false, false)` (letters and
  digits, about 190 bits). It's shown as a full link, `https://<site>/wp-admin/?unlock=<key>`, with a
  Copy button. A **Generate a new link** button (a `ConfirmButton`) replaces it, which invalidates the
  old link and every unlock cookie.
- **Stored** plain in the `shipstatic_lockdown` option (not autoloaded). It has to be: `.htaccess` can
  only match it literally, and admins need to see the link again. The generated gate holds only its hash.
- **What a key request does.** The web server layers just let it through. `Lockdown\Guard` (inside
  WordPress, on `init`) then:
  1. compares the key with `hash_equals()`;
  2. adds `REMOTE_ADDR` to the list — an IPv6 address as its /64 — labelled "Added with unlock link",
     with the time and `via: key` (no duplicates);
  3. rewrites every layer;
  4. sets the unlock cookie;
  5. emails the site admin: "<ip> was added to the Lockdown list with the unlock link" (see §14);
  6. redirects (302) to the same URL without `unlock`, keeping the key out of history and links.
  A wrong key changes nothing; the request is simply blocked like any other.
- **The cookie:** `shipstatic_unlock`, `Max-Age` 43200 (12 h), `Secure` (on HTTPS), `HttpOnly`,
  `SameSite=Lax`, path `/`. Its value is `hash_hmac('sha256', $key, wp_salt('auth'))`.
  - **Limitation:** `.htaccess` can only match a fixed value, so the server layers can't check the
    12 hours themselves; the browser enforces the lifetime. The cookie can't be read by scripts, and
    generating a new link invalidates every cookie at once. Document this in SECURITY_PATTERNS.
- **Who holds the key** gets permanent access (it adds their IP). That's the chosen trade-off, so the
  tab makes it visible: every key-added entry shows "via unlock link" and its date, the email in step
  5 tells the admin each time, and removing entries is one click.
- The key is a URL parameter, so it shows up in server access logs. Editors should bookmark the link
  rather than share it in public places; the tab says so.

## 6. Exemptions — the site's own requests

ShipStatic's renders and asset fetches already send the render token (`PageRenderer::request_args()`).
Other requests the site makes to itself don't:

- WordPress's `spawn_cron()` request to `wp-cron.php`, which **scheduled sync relies on**;
- `Scheduler`'s own `wp-cron.php` request;
- Site Health's loopback tests.

Rather than exempting paths (an exempt `wp-cron.php` would load WordPress and every plugin for
strangers), add the token to the site's requests **to itself**:

- `cron_request` filter → add the header to core's cron request args.
- `http_request_args` filter → add the header only when the request URL's host equals `home_url()`'s
  host. This covers Site Health and other plugins' loopbacks. **Check** whether WordPress's HTTP library
  keeps custom headers when it follows a redirect to another host (§14). If it does, strip the header
  on redirect, or set `redirection: 0` for these.

A real system cron that calls `wp-cron.php` with curl would be blocked. The tab's notes say to use WP-CLI
(`wp cron event run --due-now`) or add the server's IP.

## 7. Blocked visitors → the static site

- **Setting:** "Send blocked visitors to" — a `Select` of destinations that have a Destination URL,
  defaulting to the first enabled one, plus "Show a 403 page".
- **Target:** the destination's URL (which includes its sub-path), plus the request path relative to
  the WordPress home path. The query string is dropped.
- **302, not 301,** so browsers don't remember it if the destination changes. It also stops search
  engines indexing the WordPress copy as duplicate content.
- **The target URL is validated** before it goes into `.htaccess` or the gate: `wp_http_validate_url()`,
  http(s) only, no whitespace, newlines or quotes (§11).

**Checked 2026-10-25 on the playground:** the exported pages hold no references to the WordPress
origin. The render's `sitemap.xml` / `robots.txt` do, and `build_deploy_manifest()` replaces them with
the destination's URL; the per-destination deploy copies have none. Phase 4 adds this to Check
permanently: an export that still loads anything from the origin would break for the public once it's
locked, so Check should flag it.

## 8. Turning it on without locking yourself out

1. **Before** the Enable switch can be turned on, the tab shows the unlock link and asks the admin to
   tick "I've saved this link".
2. On enable, the server adds the admin's IP as the server sees it (`REMOTE_ADDR`), labelled "You
   (when enabled)". The tab shows that IP beforehand. When a forwarding header is present
   (`X-Forwarded-For`, `CF-Connecting-IP`, `X-Real-IP`) and differs from it, the tab warns that the
   site is probably behind a proxy or CDN, so the IP it sees isn't the admin's.
   - Forwarding headers are **never** trusted for the lock: anyone can fake them.
3. The enable response also **sets the unlock cookie**, so the admin's browser stays in for 12 hours
   even if the IP check fails.
4. **Browser check:** the dashboard fetches `/?shipstatic_lockdown_check=<random>` with
   `credentials: 'omit'` (no cookie, so only the IP is tested) and `redirect: 'manual'`. An
   `opaqueredirect` or a 403 means the admin's IP isn't being allowed. The tab says so and offers
   **Turn it off again**, which still works through the cookie.
5. **Server probe:** WordPress makes a loopback request **without** the token. A redirect or 403 proves
   a layer is applied. The gate's response carries `X-ShipStatic-Lockdown: gate`, so we can tell the gate
   from `.htaccess`. If the server's own IP is on the list (same office network), the probe can't tell,
   and the tab says "Couldn't verify".

The probe also runs from the **Check** button in the tab, and on each tab load (cached for a few minutes).

## 9. Getting back in

In order of convenience; the tab shows these in a collapsible "If you're locked out" section:

1. The unlock link (adds your IP).
2. WP-CLI: `wp shipstatic lockdown off` or `wp shipstatic lockdown allow <ip>`.
3. FTP / file manager: delete the `# BEGIN ShipStatic Lockdown … # END` block from `.htaccess` and the
   lines from `.user.ini`.
   - Renaming the plugin folder alone does **not** remove these (only the mu-plugin stops working).
   - With `.user.ini`, it can take up to 5 minutes to take effect.
4. `define('SHIPSTATIC_LOCKDOWN_OFF', true);` in `wp-config.php`. Only the mu-plugin can honour it:
   the `.user.ini` gate runs before `wp-config.php` is loaded, and `.htaccess` runs before PHP at all.
   So it helps only where the mu-plugin is the sole layer, or after step 3. When a request then gets
   through, WordPress removes the remaining layers itself.

**Deactivation** removes every layer: the `.htaccess` block, the `.user.ini` lines (restoring any
chained prepend), the gate file and the mu-plugin. It also sets `enabled` to false, so reactivating
never re-locks silently. **Uninstall** also deletes the option.

## 10. Source-side integration

### 10.1 Storage

`shipstatic_lockdown` (not autoloaded):

```php
[
  'enabled'  => false,
  'key'      => '',            // §5
  'entries'  => [              // max 50
    ['cidr' => '203.0.113.7/32', 'label' => 'Alan – office', 'added' => 1793000000, 'via' => 'enable|key|manual|cli'],
  ],
  'redirect' => 'dest:<id>' | '403',
  'prepend'  => '',            // chained auto_prepend_file (§4.2.1)
]
```

### 10.2 Classes (`src/Lockdown/`)

| Class | Job |
|---|---|
| `Settings` | Reads and writes the option; validates entries (`Cidr::parse()`), labels, redirect choice. |
| `Cidr` | Parses and normalises with `inet_pton()`; `contains($cidr, $ip)`; regex form for LiteSpeed. |
| `Writer` | Regenerates every layer for this server: `.htaccess`, `.user.ini`, gate, mu-plugin; removes them all on off/deactivate. Atomic writes, backups. |
| `Guard` | Inside WordPress: the unlock key (§5), the cookie, the check request (§8). |
| `Probe` | Server detection + the loopback probe (§8). |
| `templates/htaccess.txt`, `gate.php.txt`, `mu-plugin.php.txt`, `user-ini.txt` | Placeholder templates filled by `strtr()`. |

`PageRenderer` gains the `cron_request` / `http_request_args` filters (§6).

### 10.3 REST (all `manage_options` + REST nonce, `LockdownController`)

- `GET /lockdown` — settings (key included, admins only), your IP, proxy warning, server and layer
  status, last probe.
- `POST /lockdown` — enabled, entries, redirect. Turning it on sets the unlock cookie in the response.
- `POST /lockdown/key` — generate a new link.
- `POST /lockdown/probe` — run the server probe.

### 10.4 Dashboard (`LockdownPanel.svelte`, new top tab `lockdown`)

ab-ui throughout:

- **Status** (`Status`): which layers are active and verified.
- **Enable** (`Switch`), gated by "I've saved this link".
- **Your IP** with the proxy warning (`Alert`).
- **Allowed addresses** (`Table`): address, label, added, via, remove (`ConfirmButton`). An Add row
  takes an IP or CIDR range and a label.
- **Unlock link** (`Input` read-only + Copy + generate new).
- **Send blocked visitors to** (`Select`).
- **Nginx snippet** (collapsible, nginx only).
- **If you're locked out** (`Collapsible`, §9).

Strings go in `I18n::lockdown()`.

### 10.5 WP-CLI

`wp shipstatic lockdown status | on [--allow=<ip>] | off | allow <ip|cidr> [--label=<text>] | remove <ip|cidr> | link [--regenerate]`.

`on` from the CLI has no browser IP, so it requires at least one `--allow` entry or an existing list.

### 10.6 Abilities (AI / MCP)

A **read-only** status ability at most. No ability can turn Lockdown on or off or change the list: an
agent locking the site, or opening it to an arbitrary IP, is too high-impact.

## 11. Security notes (new SECURITY_PATTERNS section)

This feature writes settings data into `.htaccess` (a config file the server executes) and into
generated PHP. Injection here means server-config injection or code execution, so:

- **Only validated values reach generated files.**
  - IPs are parsed with `inet_pton()` and re-serialised: never the raw input.
  - The key and cookie are letters, digits and hex only.
  - The redirect URL is validated (§7) and must contain no whitespace, quotes or newlines.
  - Labels **never** go into generated files; they live only in the option.
- **PHP values are written with `var_export()`** into the gate template, never concatenated.
- **No plain-text secrets in the gate:** it holds the hashes of the key and the token. `.htaccess`
  has to hold them literally, but Apache and LiteSpeed refuse to serve `.ht*` files.
- **Writes are atomic** (temp file + `rename`, `phpcs:ignore` with reason). A backup of `.htaccess` is
  kept before each write. A failed write leaves the old file and reports the error.
- **The key is checked with `hash_equals()`.** A wrong key is just blocked, with no difference in
  response, so nothing can be learned by probing.
- **Forwarding headers are never trusted** for the allow decision.
- **REST is admin-only** like every other route. The public `search-index` route is unaffected, and is
  itself blocked by Lockdown for non-editors.

## 12. Phases

1. **WordPress layer:**
   - storage, `Cidr`, `Guard` (key, cookie, IP add, email, redirect), and the gate file via the
     mu-plugin;
   - `cron_request` / `http_request_args` token filters;
   - REST, the dashboard tab, WP-CLI.

   This works on every server, though it doesn't stop files opened directly.
2. **`.htaccess` layer:** Apache (`expr -R`) and LiteSpeed (regex), top-of-file writer, the probe,
   and the "block moved or removed" check.
3. **`.user.ini` gate:** writer, chaining, the 5-minute note, and the nginx snippet.
4. **Finish:**
   - Check flags exports that reference the WordPress origin (§7);
   - Docs page section, readme FAQ, translations (5 locales), SECURITY_PATTERNS, CLAUDE.md;
   - Plugin Check at 0 errors, including trunk Plugin Check before upload.

## 13. Verification checklist

- [ ] Off by default; with it off, nothing is written anywhere.
- [ ] Enable from an allowed IP: the dashboard keeps working; the browser check passes.
- [ ] From another IP (phone on mobile data): every URL redirects to the same path on the static site,
      including `/wp-login.php`, `/xmlrpc.php`, `/wp-json/`, a plugin PHP file opened directly, and an
      upload. The query string is dropped.
- [ ] "Show a 403 page" gives a 403 instead.
- [ ] The unlock link from the phone:
  - [ ] adds its IP (an IPv6 address as /64), sets the cookie, emails the admin, and redirects without
        the key;
  - [ ] the site then works on the phone;
  - [ ] a wrong key is just redirected.
- [ ] After a new link is generated, the old link and old cookies no longer work.
- [ ] Process, Sync, single-page sync and scheduled sync all work while locked.
- [ ] Site Health's loopback tests pass.
- [ ] `/.well-known/acme-challenge/test` is reachable from a blocked IP.
- [ ] Apache: the block is first in `.htaccess`, above LiteSpeed Cache's block, and a cached page is
      still blocked.
- [ ] LiteSpeed / OpenLiteSpeed: the regex IP match works, including IPv6. Find out whether OLS needs a
      restart.
- [ ] Nginx + PHP-FPM:
  - [ ] the gate runs for a plugin PHP file opened directly;
  - [ ] chaining keeps Wordfence's firewall running;
  - [ ] turning it off restores the original `.user.ini`.
- [ ] Recovery paths in §9 all work, including deactivation removing every layer.
- [ ] A label or URL containing newlines, quotes or `$` can't change the generated files.
- [ ] Plugin Check 0 errors on the release zip.

## 14. Open questions and things to verify

1. **The admin email when the key adds an IP:** on by default (recommended), and should it be
   switchable?
2. **Should key-added entries expire,** for example 30 days after they were added? Rotating IPs make
   the list grow; expiry keeps it short, and the link always gets you back.
3. **LiteSpeed:** does it support `RewriteCond expr` (`-R` CIDR)? If so, the regex fallback isn't
   needed. Does OpenLiteSpeed pick up `.htaccess` changes without a restart?
4. **WordPress's HTTP library:** does it carry custom headers across a cross-host redirect? (§6)
5. **Managed hosts** (Kinsta, WP Engine, SiteGround, Cloudways): which honour `.user.ini`? Several have
   their own IP-allow tools. Where neither works, the tab should point to the host's tool.
6. **wordpress.org review:** writing a mu-plugin and `.user.ini` is done by other listed plugins
   (Wordfence, for example), but both must be explained in the readme and removed on deactivation.
   Confirm nothing in the guidelines objects.
