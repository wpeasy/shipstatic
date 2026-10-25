# Security Patterns — ShipStatic

> **Read this before working on any REST controller, the deploy layer
> (`PackageDeployer` / `UnzipScript` / the Connector: `ConnectorScript`,
> `ConnectorClient`, `templates/connector.php.txt`), the transports
> (`FtpTransport` / `SftpTransport` / `ConnectorTransport`), URL fetching
> (`UrlSafety` / `PageRenderer`), credential storage (`Crypto`), or anything
> that renders stored HTML on the client.**
>
> These are this plugin's real, non-negotiable security rules. Each maps to an
> actual call site in `src/`. When you add a feature, check it against every
> rule below and keep this file accurate (it's the reference a future audit
> grades the code against — a wrong example is worse than none).

Namespace `WPEasy\ShipStatic`, REST namespace `shipstatic/v1`, option/hook/constant prefix
`shipstatic_`/`SHIPSTATIC_`.

---

## 0. Authorization model: admin-only, uniform

Every REST route in `src/REST/*` registers `permission_callback => [self::class, 'can_manage']`, and **`can_manage()` is `current_user_can('manage_options')`**. There are **no** `wp_ajax_*`, `wp_ajax_nopriv_*`, `admin_post_*`, or `__return_true` endpoints, and no public liveness probe. The admin page (`Admin\Menu`) is gated on `manage_options` and re-checks it in `render_page()`. Lockdown adds two unauthenticated *request handlers* that are not endpoints (the unlock link and the 204 check request) — see §11 for their rules.

**Rules for new surfaces:**
- A new REST route is `manage_options` by default. If you need anything lower-privileged, that is a design change — justify it, and then the IDOR/ownership rules below apply.
- This plugin operates on **site-level** state (options, the rendered site, destinations) — there is no per-post/per-user object model, so there is currently no IDOR surface. If you ever read/write a request-supplied `post_id`/`user_id`/attachment id, you MUST check `current_user_can('edit_post', $id)` (ownership) **inside the handler**, not just the route capability. A `(?P<id>\d+)` path token is resolved before `permission_callback`; body params are not — re-assert in the handler.
- REST relies on the `wp_rest` nonce (`Admin\Menu` localizes it as `shipStaticData.nonce`, the client sends `X-WP-Nonce`). Don't add a parallel auth path that skips it.

---

## 1. SSRF guard for user-supplied URLs (`Support\UrlSafety`)

SSRF risk is asymmetric: a remote browser can't reach `127.0.0.1` or the cloud-metadata endpoint `169.254.169.254`, but the WordPress server can. The plugin fetches one **user-supplied** URL server-side — a destination's public URL — in three places: POSTed to trigger the deploy helper (`PackageDeployer::deploy()`), every Connector request (`Deploy\ConnectorClient::send()`), and GET/HEAD by the server probe (`Sync\ServerProbe`, `GET /sync/server-check`).

**Rule:** any server-side fetch of a URL that originated from a request MUST go through `UrlSafety`:

```php
use WPEasy\ShipStatic\Support\UrlSafety;

// Validates scheme (http/https), resolves the host to IP(s), and rejects
// private/reserved/link-local ranges. Loopback (127/8, ::1) is intentionally
// allowed so *.local dev destinations keep working. Then forces redirection=0
// and rejects a 3xx (so a public URL can't 302 to an internal address).
$response = UrlSafety::guarded_post($url, ['timeout' => 120, 'body' => $body]);
if (is_wp_error($response)) { /* blocked, redirected, or transport error */ }
```

- `UrlSafety::is_safe_url($url)` is the validator; `guarded_post()` / `guarded_get()` (GET, or HEAD via `$args['method']`) are the validator + fetch with `redirection => 0`, both delegating to one private `guarded_request()`. Use them for any new fetch of a user URL — never a bare `wp_remote_*`.
- **`ServerProbe` is read-only and blind by design:** it reads response *headers* only (`Server`, `Cache-Control`, `Content-Encoding`), fetches with `decompress => false` + `limit_response_size`, and never returns a body to the caller. It uses `PackageDeployer::sslverify()` for TLS (rule 2 applies). Keep it that way — a probe that echoed bodies would turn the admin-only SSRF residual into a read primitive.
- **Why redirects-off matters:** `is_safe_url()` resolves the host once, then `wp_remote_*` does its own DNS lookup at fetch time (DNS-rebinding TOCTOU) and follows up to 5 redirects by default. `redirection => 0` + 3xx-reject closes both.
- **Known limitation / TODO:** the resolve-then-fetch gap is not fully closed (no IP pinning). Pin the resolved IP via a one-shot `http_api_curl` / `CURLOPT_RESOLVE` filter if this fetch is ever exposed below `manage_options`. Today it's admin-only and the helper response body is never returned to the caller (blind), so the residual risk is low.
- **`PageRenderer` is exempt by design:** it fetches the *site's own* pages/assets (home URL + same-origin crawl from `AssetExtractor`, which only emits same-origin links), so it is not a user-supplied-URL surface and it legitimately needs `redirection => 5` to crawl. Do not route it through `guarded_post`. If you ever make the renderer follow author-supplied off-site URLs, that changes and the guard applies. **Same-origin is not the same as safe for the local filesystem, though** — the crawled *paths* are author-controlled; see rule 10.

---

## 2. TLS verification (`sslverify`)

**Never disable `sslverify` for a public host.** It is relaxed in exactly one situation — a **local/dev host that cannot hold a public CA certificate** — and the host test is exact:

```php
// PackageDeployer::sslverify() and PageRenderer::should_verify_ssl()
$local = UrlSafety::is_dev_host($host);   // localhost, *.local, *.test, or a loopback IP
return apply_filters('shipstatic_package_sslverify', !$local, $url);
```

- `UrlSafety::is_dev_host()` uses **exact** matching. Do NOT reintroduce `strpos($host, '127.0.0.1') === 0` — that prefix test also matched `127.0.0.1.attacker.com` (a real public host an attacker can register), silently disabling TLS for it. This was a real audit finding.
- `.local` / `.test` are reserved special-use TLDs — never publicly resolvable or certifiable — so relaxing TLS for them is safe. Anything else verifies.
- The `shipstatic_*_sslverify` filters exist for genuinely broken dev certs; do not use them to paper over a real cert problem on a public destination.

---

## 3. The remote deploy helper (`Deploy\UnzipScript`) — keep it hardened

`PackageDeployer` uploads a generated one-shot PHP helper to the destination web root and triggers it over HTTPS to extract the package server-side. This is the highest-impact surface in the plugin. The generated helper MUST keep all of:

- **128-bit random token** (`bin2hex(random_bytes(16))`) compared with **`hash_equals()`** (constant-time). Never `==`.
- **Expiry** (`time() > $EXPIRES`) — refuses to run, and self-deletes, after the TTL.
- **Zip-slip guard** — reject entries that are empty, absolute (`/…`), contain `../`, or contain `:` (Windows drive), after normalising `\` → `/`. Applies to extracted paths AND the `deletes` list.
- **Self-delete** on success and on expiry; does NOT self-delete on a bad token (the plugin cleans up over FTP instead, so a probe can't make it erase itself).

Don't add an entry-name or `deletes` path to a filesystem operation without running it through the `$safe()` normaliser first. The package is built locally and the helper only extracts what we uploaded — never accept a caller-supplied archive.

---

## 3b. The Connector receiver (`Deploy\ConnectorScript` → `templates/connector.php.txt`) — a persistent helper, so a stricter jail

The Connector transport replaces FTP with a **user-installed, persistent** PHP receiver (`bs-connector-<16hex>.php`) in the destination web root, driven by `Deploy\ConnectorClient` over signed HTTPS. Because it stays on the destination, it is the highest-impact surface in the plugin and carries every `UnzipScript` guarantee plus:

**Pairing / secrets**
- One receiver = one (source site, destination) pairing: `Destinations::pair_connector()` mints a 16-hex id + 256-bit secret. The secret is stored `Crypto`-encrypted in the destination (`connectorSecret`, `secret + internal` Schema flags) and **the file embeds only `sha256(secret)`** — a host that serves the file as text leaks nothing reusable. The raw secret is returned exactly once, to the pair/download flow.
- `connectorId` / `connectorSecret` / `connectorVersion` are `internal` Schema fields: `Destination::apply()` skips them, so **REST input can never set or clear a pairing** — only `pair_connector()` / `revoke_connector()` (and `set_connector()` they call) write them. Keep it that way.
- Download is `GET /connector/download?token=…`: a 10-minute, **single-use transient** issued by `POST /destinations/{id}/connector/pair`, same `manage_options` + `wp_rest`-nonce auth as `/export/download`; the handler also re-checks the destination's *current* id matches the token's before streaming, so a rotated/revoked pairing can't be downloaded. Re-pairing (rotate) or revoking makes the old file fail auth (`paired_elsewhere` → the client's `ConnectorAuthException`).
- Pairing refuses a non-`https://` Destination URL unless `UrlSafety::is_dev_host()`, and runs the URL through `is_safe_url()`.

**Every request** (`ConnectorClient::send()`)
- Goes through `UrlSafety::guarded_post()` (rule 1) with `sslverify` from `PackageDeployer::sslverify()` (rule 2).
- Carries `X-BS-Id`, `X-BS-Timestamp`, `X-BS-Nonce` (16 bytes), `X-BS-Key` (the raw secret — bearer, checked against the stored hash with `hash_equals`), `X-BS-Signature` = HMAC-SHA256(secret, `id\nts\nnonce\nbody-hash`) and `X-BS-Version`. Body-hash is sha256 of the JSON body, or for multipart uploads sha256 of `meta \n sha256(file)`, so a captured request can't be re-pointed at other files.
- The receiver enforces, in order and failing closed with one generic 403: method POST, id match, ±300 s timestamp window, nonce unseen (stored in `bs-connector-<id>.state.php`, a `<?php exit;` file so it's never web-readable), key hash, HMAC. The nonce is burned *before* any op runs; an unwritable state file is a hard 500, never a silent downgrade. **Lockout:** after 10 failed auths in 10 min, further *failing* requests get 429 instead of 403 — but the lock is only applied on failure, so a fully authenticated request still succeeds and clears the counter. (Before protocol v2 the lock was checked first, which let anyone who knew the URL keep the paired site locked out with unsigned junk.)
- **HTTPS on every request, not just at pairing.** `ConnectorClient::url_is_secure()` (https, or `UrlSafety::is_dev_host()`) is checked by `pair` AND at the top of `ConnectorClient::send()`, because the bearer secret travels in every request — editing the Destination URL to `http://` after pairing must not send it in cleartext.

**Filesystem jail** (`bs_safe` / `bs_target` / `bs_existing` / `bs_write_allowed`)
- Same `$safe()` string rules as `UnzipScript`, **plus** `realpath` containment of the deepest existing ancestor (symlink-escape guard), a refusal to write through a symlink or over a directory, a `..`-segment check, and refusal of names ending in a dot or space (Windows strips them, turning `x.php.` into `x.php`).
- **Executable write deny-list**: any extension segment in `php php3-8 phtml phar phps pht phpt cgi fcgi pl py rb sh bash asp aspx ashx asmx axd cshtml vbhtml jsp jspx cfm cfc shtml shtm stm ini htpasswd config` (so `x.php.txt` is refused too; the IIS/Java/SSI types were added in protocol v3 — an audit finding), `web.config`, `.user.ini`, `php.ini`, and anything named `bs-connector-*` (the receiver can never overwrite itself, its state, or in-flight parts). **`.htaccess` is ALLOW-LISTED, at every depth** (`bs_htaccess_allowed()`, protocol v2): after joining backslash continuations, every directive line must be one of `<IfModule>`/`<Files>`/`<FilesMatch>` (+ closers), `DirectoryIndex`, `ErrorDocument`, `Header`, `Expires*`, `Rewrite{Engine,Base,Cond,Rule}`, `Redirect*`, `Options` — and `RewriteRule` may not carry handler/type flags (`H=`/`T=`), `Options` may only turn features *off* (or allow symlink following). A line outside the list is accepted only if that exact line is already in the file being replaced (the plugin merges into the host's existing `.htaccess` and must write the host's own rules back; a request can never *add* one). `bs_write_allowed()` **fails closed**: any basename equal to `.htaccess` in any case that arrives without its content is refused — both write paths (`chunk` and `extract`) probe by `strtolower(basename())`. (v1 used a deny-list of handler directives and only probed a ROOT `.htaccess` on the `chunk` path, so a nested one skipped the scan — a real audit finding.) **Never go back to a deny-list**, and never add a write path that doesn't pass the content. **Blast radius with a stolen secret: deface the static folder — never code execution, never a read outside it.**
- `get` is allow-listed to `.htaccess` and `.htaccess.shipstatic.bak` (the two files the merge needs). `manifest` is the only listing op: names + size + md5 of regular files under BASE (never contents, never symlinks, never our own files or `.htaccess`).
- `delete` removes files only (+ prunes empty parents, never BASE); `extract` is the `UnzipScript` body with the deny-list applied per entry.
- **`extract` refuses a package with duplicate entry names** (opened with `ZipArchive::CHECKCONS`, plus a case-insensitive name set; protocol v3). Entries are checked by index but streamed by name, and libzip's name lookup returns the *first* match — so a second, harmless `.htaccess` could pass the allow-list while the first, handler-adding one got written (a real audit finding, reproduced). `UnzipScript` does the same. Never read an entry by name unless names are proven unique.

**Client-side handling**
- The client treats *only our JSON* (`{ok:false,error:…}`) 403/429/409 as auth/lock/version errors; an HTML 403/404 (e.g. nginx denying PHP under `uploads/`) is a `ConnectorHostException` — a host problem, never mistaken for a bad pairing. Error strings from the receiver are `sanitize_text_field()`ed before reaching a job message.
- Chunked uploads (`chunk` op, `offset`-idempotent, sha256-verified on the final chunk) size chunks from the receiver's reported `uploadMax`, capped at 4 MB.

**Rules for changes:** any new op must go through `bs_safe` + `bs_write_allowed`/`bs_existing`, never add a read op without an allow-list, never add a "run"/"self-update"/"self-delete" op (a remote code path *is* the vulnerability), and bump `ConnectorScript::VERSION` on any wire change so mismatched files fail with the precise 409 message.

---

## 3c. The Cloudflare client (`Deploy\CloudflareClient`) — fixed host, path-safe ids, write-only token

The Cloudflare transport (`Transport\CloudflareTransport`, `docs/CLOUDFLARE_PLAN.md`) talks only to the Cloudflare v4 API. It is the one outbound client that legitimately uses a bare `wp_remote_request()`:

- **Fixed host.** The base URL is the constant `https://api.cloudflare.com/client/v4` — never derived from settings or request input — so rule 1 (SSRF guard) doesn't apply. Do not make the host configurable; if a self-hosted/proxy endpoint is ever needed, it must go through `UrlSafety` like any user URL.
- **Path-safe ids.** The account id and Worker name are the only user-supplied values that reach a URL, and both are validated twice: `Schema` (`cf_account` = exactly 32 lowercase hex or `''`; `cf_worker` = `[a-z0-9-]`, ≤63, no edge dashes) and again in the client (`valid_account()` / `assert_worker()`) before any request. Never interpolate anything else from settings into a path.
- **TLS always verified** (`sslverify => true` set explicitly, `redirection => 0`). There is no dev-host exception — the host is never local.
- **The API token is a `secret` Schema field** (`cfApiToken`): `Crypto`-encrypted at rest, exposed to the browser only as `{hasValue, fromConstant}`, decrypted only into `connection_config()`. It, and the short-lived upload/completion JWTs, are sent only as `Authorization: Bearer` and are never logged, returned from REST, or included in an exception message (Cloudflare's own error text is `sanitize_text_field()`ed before reaching a job message). The CLI reads it from `CLOUDFLARE_API_TOKEN` (`--token-from-env`) rather than a flag, to keep it out of shell history.
- **Least privilege is documented, not enforced.** We tell users to create a token with only *Account → Workers Scripts → Edit* on one account. Blast radius of a leaked token with that scope: Workers on that account — not DNS, billing or other accounts. Keep the UI/docs wording that recommends it.

**Rules for changes:** no new user-supplied value in an API path without a strict validator in both places; keep the token out of every message; never relax `sslverify`.

## 3d. The GitHub client (`Deploy\GitHubClient`) — fixed host, no redirects, fast-forward only

The GitHub Pages transport (`Transport\GitHubTransport`, `docs/GITHUB_PAGES_PLAN.md`) talks only to the GitHub REST API. Same shape as §3c, plus two git-specific rules:

- **Fixed host.** The base URL is the constant `https://api.github.com`. Do not make it configurable (GitHub Enterprise would need `UrlSafety` like any user URL).
- **Path-safe names.** Owner, repository and branch are the only user-supplied values in a URL; each is validated in `Schema` (`gh_owner` / `gh_repo` / `gh_branch`, invalid → `''` or `gh-pages`) and again in the client (`valid_owner()` / `valid_repo()` / `valid_branch()`), and every path segment is `rawurlencode()`d. Object SHAs from GitHub's own answers pass `sha()` (exactly 40 hex) before entering a path. The folder is one of two literals (`/`, `/docs`).
- **No redirects** (`redirection => 0`, `sslverify => true`). A renamed repository answers 301; following it would send the token on. The client reports the move instead.
- **The token is a `secret` Schema field** (`ghToken`): encrypted, `{hasValue, fromConstant}` to the browser, sent only as `Authorization: Bearer`, never logged or in a message. The CLI reads `GITHUB_TOKEN` (`--token-from-env`).
- **Never force-push.** The branch moves with `force: false`; a non-fast-forward answer becomes `GitHubConflictException` → rebuild on the new head once, then stop. The plugin can't discard anyone's commits.
- **Scope of writes.** Deletions are limited to the chosen folder and skip the keep list; files outside the folder are never touched. Because `/` on a repository's default branch would replace its source code, Test warns when the branch is the default branch.
- **Least privilege is documented, not enforced.** Users are told to create a fine-grained token for one repository with Contents: Read and write (Pages: Read-only optional). Turning Pages on via the API needs Administration: write, so the plugin doesn't offer it.

**Rules for changes:** same as §3c; keep `force: false`; keep deletions folder-scoped.

---

## 4. Credential storage (`Support\Crypto`)

FTP/SFTP passwords are encrypted at rest with **AES-256-GCM** (random 12-byte IV, 16-byte tag verified on decrypt). The key derives from the WordPress salts (`AUTH_KEY` etc.), falling back to `wp_salt('auth')`.

- **Rule:** the key must never fall back to **public-knowledge** material (site URL, plugin slug, a hardcoded string). `wp_salt('auth')` is acceptable because it is secret and per-install. If you change `key()`, keep that property.
- Secrets are decrypted only for the transport layer (`Destination::connection_config()`), never returned to the browser — `for_display()` exposes `hasValue`/`fromConstant`, not the value. Keep it that way.
- `wp-config` constants (`Schema` `constant` fields) override stored values on the primary destination; constant-backed fields are never overwritten by REST input.
- **A saved password never goes to a different server.** Test (`DestinationsController::test`, `ConnectionController::config_from_request`) refuses, or blanks the password, when the request's host differs from the saved one and no password was typed; `Destinations::update()` clears a stored password when the host changes without a new one (`Destinations::same_host()`). Otherwise an admin who can't read `wp-config.php` could recover a `SHIPSTATIC_PASSWORD` by pointing Test at their own server (audit finding). Residual: with a constant password and a non-constant host, a saved host change + Sync still uses the constant — put the host in `wp-config.php` too if that matters.

---

## 5. Transport integrity (MITM)

- **SFTP host key is verified (trust-on-first-use).** `SftpTransport::verify_host_key()` reads `getServerPublicHostKey()` **before** `login()` (so credentials are never sent to an unverified host), records it per `host:port` on first connect, and refuses on a later mismatch (`hash_equals`) — an active MITM presenting a different key is rejected instead of capturing the password. Filter `shipstatic_sftp_verify_host_key` can disable it; don't. Escape hatch for a legitimate key change (server rebuild/migration): the stored key is cleared on connection-target change (`Destinations::update`) and by "Reset sync state" (`SftpTransport::forget_host_key()`). Keep the read-before-login ordering if you refactor `connect()`.
- **Plain FTP** sends credentials and content in cleartext — this is an explicit user transport choice (the form says so). Don't silently "upgrade" or hide the choice.
- **FTPS encrypts but does not authenticate the server**: PHP's `ftp_ssl_connect()` can't verify the certificate or hostname, so an active MITM can capture the password. The form says so and points to SFTP / the Connector. Real verification would need a curl-based FTPS client.
- Remote paths passed to `put`/`delete` derive from crawled URLs — which page content controls (rule 10) — so `FtpTransport::absolute()` / `SftpTransport::absolute()` **throw on any `.`/`..` segment, NUL or backslash** (`Url::has_traversal()`). Keep that check in any new per-file transport; the package helper and Connector have their own `$safe`/`bs_safe`.

---

## 6. Client-side: stored HTML rendering

The only DOM-HTML sink is `{@html row.replace}` in `dashboard/TextReplacements.svelte` (and the `el.innerHTML = value` round-trip in `lib/RichTextEditor.svelte`), used for "rich" text replacements.

- **Safety rests on the server:** `Settings\Destination::sanitize_replacements()` runs **`wp_kses_post()`** on every `rich` replacement on write (and `sanitize_text_field()` on plain). Keep that sanitisation **unconditional** — the client does not re-escape at render time.
- Authorship is `manage_options`-only, so this is not a low-privilege stored-XSS vector today; it would become one if replacement editing were ever opened to a lower capability. Re-evaluate this sink if that happens.
- No other `innerHTML`/`{@html}`/`document.write` exists. There are **no** `postMessage` listeners and **no** `BroadcastChannel` — if you add either, validate `event.origin` AND `event.source` for postMessage, and authenticate BroadcastChannel messages with a per-session nonce (a same-origin channel is not a security boundary). Never `postMessage(payload, '*')`.
- The `wp_rest` nonce lives only in `shipStaticData.nonce` (sent as `X-WP-Nonce`); never render it to the DOM and never put secrets in localStorage.

---

## 7. SQL & output

- Every `$wpdb` query (driver-lock / heartbeat / cancel in `Sync\Runner`) uses `$wpdb->prepare()` with `%s`/`%d` placeholders against the core `{$wpdb->options}` table, or `$wpdb->delete()` with an array. **No string interpolation of values.** Keep it that way; use `%i` for identifiers if a custom table is ever added.
- Escape at output: `esc_html`/`esc_attr`/`esc_url`; `wp_kses_post` for stored HTML. The admin template is fully escaped — keep any new PHP-rendered output escaped at the point of echo.

---

## 8. Sensitive logging

If you add `error_log()` for diagnostics, gate any **user/site content** (URLs with userinfo, credentials, response bodies, file contents, exception messages quoting upstream content) behind `WP_DEBUG`; metadata (provider, lengths, resolved IP, user id) may log unconditionally. On many shared hosts `wp-content/debug.log` is web-reachable.

---

## 9. AI / MCP abilities — same capability bar + opt-in gating (`Abilities\AbilityRegistry`)

The Abilities API exposes `bs/*` abilities to AI agents / MCP clients. An ability is **another authenticated entry point into the same engine** the REST controllers drive, so it carries the **same `manage_options` bar** — never a lower one.

Every ability registers a `permission_callback` that:

```php
'permission_callback' => static function () use ($permission) {
    if (!current_user_can('manage_options')) {
        return false;                       // same bar as every REST route
    }
    if ($permission === 'changes' && !self::changes_enabled()) {
        return false;                       // opt-in: shipstatic_ai_allow_changes
    }
    if ($permission === 'sync' && !self::sync_enabled()) {
        return false;                       // opt-in: shipstatic_ai_allow_sync
    }
    return true;
},
```

**Three tiers, two opt-ins (both default OFF):**
- **Read-only** (always registered): status, method, list pages, page status, link integrity, list destinations, progress. `manage_options` only; no state change.
- **Changes** (`shipstatic_ai_allow_changes`): set discovery mode, include/exclude page(s).
- **Sync** (`shipstatic_ai_allow_sync`): scan, sync, single-page sync, cancel, reset.

**Rules for new abilities:**
- Gate on `manage_options` via the shared `register()` helper — never write a bare `'permission_callback' => '__return_true'` or a lower capability. An ability is not "more trusted" than REST.
- A new ability that **changes local state** takes `'permission' => 'changes'`; one that **renders/pushes/cancels/resets** takes `'permission' => 'sync'`. The action group is **registered only while its toggle is on AND** the `permission_callback` re-checks the toggle (defence in depth — registration and execution are two separate gates; keep both).
- Abilities are thin wrappers over existing `Runner`/`UrlCollector`/`Destinations`/`Editor` seams — they inherit those seams' validation (e.g. `UrlSafety` on deploy, the Free page cap). Never duplicate engine logic in an ability or bypass a seam's checks.
- Never return credentials or secrets from an ability (`list-destinations` returns name/type/enabled only — mirror that). The same "secrets never leave the server" rule as `Destination::for_display()` applies.
- Mark annotations honestly (`readonly`/`destructive`/`idempotent`) — clients use them to decide what to auto-run; a mislabelled `destructive` ability is a safety regression, not just cosmetic.

---

## 10. Crawled paths are attacker-controlled (`Support\Paths::source_file()`, `Support\Url`)

The crawler follows every same-origin URL it finds in rendered pages and CSS — and `AssetExtractor` matches `src=` anywhere in the HTML, *including plain text*. So **any Author, and any commenter whose comment is shown on an exported page, chooses paths the export will try to include.** Before this rule, `<img src="/wp-config.php">` (or the text `src=/wp-config.php` in a comment) mapped straight to the real file on disk and published it to every destination — database credentials and salts in plain text, permanently in GitHub history (audit finding, CRITICAL, 2.2.0 and earlier). `%2e%2e` traversal also escaped the mapped folder because `wp_normalize_path()` doesn't resolve `..`.

**Rules:**
- **`Url::to_relative_path()` returns null for `Url::is_unsafe_path()`**: any `.`/`..` segment, NUL, backslash, a dot-file or dot-folder other than `/.well-known/`, or a server-script/secret type in *any* extension segment (`php…`, `phtml`, `phar`, `cgi`, `asp(x)`, `jsp`, `shtml`, `ini`, `htaccess`, `htpasswd`, `env`, `log`, `sql`, `bak`…). Such a URL never becomes an export key, local or remote.
- **`Paths::source_file()` reads from disk only plain static assets**: the same `is_unsafe_path()` refusal, a static-extension allow-list (`STATIC_EXTENSIONS`: css/js/json/xml/txt/html, images, fonts, media, pdf…), and **`realpath()` containment** of the file inside `realpath()` of the mapped directory. Anything else is fetched over HTTP, so it is published exactly as the web server already serves it publicly — never bytes the web server would refuse.
- **Only this site's URLs are crawled, fetched or rewritten.** `Url::is_internal()` treats every scheme other than http(s) as external and requires the home host *and* port; `UrlRewriter` rewrites only whole references to the source host that start their own URL (never `host.evil.com`, `host:8443`, or a URL inside another URL's query). `PageRenderer` follows redirects only while they stay on the site, so another site's HTML is never saved as a page and the render token (`X-BS-Render-Token`) never goes to another host.
- Never add a second "URL → local file" mapping that skips these checks, and never reintroduce a string-prefix traversal guard on an un-resolved path.
- Residual: an allow-listed file type the web server *denies* (e.g. a `.txt` log in a folder protected by `.htaccess`) is still read from disk if the crawl reaches it. Keep secret files out of the web root, or out of those extensions.

---

## 11. Lockdown writes server config and executable PHP (`src/Lockdown/`, docs/LOCKDOWN_PLAN.md)

Lockdown turns settings into `.htaccess` rules, a `.user.ini` line, a generated PHP gate (`wp-content/shipstatic-lockdown.php`) and an mu-plugin. Injection here is server-config injection or code execution, and a mistake can lock every editor out or take the site down.

**Rules:**
- **Only validated values reach generated files.** Ranges go through `Cidr::parse()`, which rebuilds the text from `inet_pton()` bytes (raw input never reaches a file); IPv6 prefixes must be multiples of 16. The unlock key and render token are letters/digits (`Writer::htaccess_block()` re-checks and throws), the cookie value is hex, the redirect URL is rebuilt by `Settings::redirect_base()` from a strict character set (no `%`, `$`, quotes, whitespace). **Labels never go into a generated file** — they live only in the option.
- **PHP literals are written with `var_export()`** into `templates/*.txt` via `strtr()`; never concatenate a value into PHP source.
- **No plain secrets in the gate:** it holds sha256 hashes of the key, render token and cookie. `.htaccess` must hold them literally (mod_rewrite can only match literals); Apache/LiteSpeed refuse to serve `.ht*`.
- **Forwarding headers are never trusted for access** (`Guard::remote_addr()` is `REMOTE_ADDR` only); `Guard::forwarded_addr()` is a warning in the UI and nothing else.
- **The unlock link** (`?unlock=`) is a GET that changes state with no nonce: the 32-character key *is* the credential, compared with `hash_equals()`. A wrong key changes nothing and gets the same redirect as any blocked request. Every address it adds is labelled `via: key`, and the admin is emailed (default on).
- **The check request** (`?shipstatic_lockdown_check=`) answers 204 with the requester's own address and changes nothing. Keep it that way.
- **The render token is only sent to this site's own host** (`Guard::sign_own_requests()`), and is stripped when a redirect leaves the host (`Guard::unsign_on_redirect()`, because the HTTP library keeps headers across redirects).
- **Writes are atomic** (temp file + `rename`), `.htaccess` is backed up once before the first block, and a failed gate write turns Lockdown back off rather than claiming the site is locked.
- **Never delete the gate while `.user.ini` may still point at it**: PHP caches `.user.ini` per worker (`user_ini.cache_ttl`, 300 s) and a missing `auto_prepend_file` is a fatal error on every request. `Writer::remove_all()` leaves a stub (which still runs any chained prepend) and deletes it after 10 minutes. Manual instructions say to empty the file, not delete it.
- **Exemptions must survive path tricks.** `/.well-known/` is matched on `THE_REQUEST` (unchanged when WordPress's rules rewrite to `index.php` and Apache re-runs the block) and only as a plain path — no dot segments, no `%`-escapes, no query — since `/.well-known/x/../../wp-login.php` resolves to the login page while still *starting* with `/.well-known/`. The PHP gate has no path exemption at all: `REQUEST_URI` is the raw request. Never add a prefix-match exemption on a raw path.
- **An existing `auto_prepend_file` (a firewall) is chained, never dropped**: the gate includes it after letting a request through, and the stub keeps including it.
- **No AI ability can change Lockdown** — turning it on or adding an address is too high-impact for an agent.

---

## Audit checklist for new code

- New REST route → `manage_options` unless justified; nonce intact; ownership check if it touches a request-supplied object id.
- New server-side fetch of a request URL → `UrlSafety::guarded_post()` / `guarded_get()` / `is_safe_url()`, never a bare `wp_remote_*`.
- New outbound call → `sslverify` left at the WP default (verified); relax only via `UrlSafety::is_dev_host()`.
- Touching `UnzipScript` → token + expiry + zip-slip + self-delete all intact.
- Touching the GitHub client → host fixed; owner/repo/branch validated before a path and segments encoded; `redirection => 0`; ref updates `force: false`; deletions folder-scoped.
- Touching the Cloudflare client → host stays the fixed constant; account id / Worker name validated before entering a path; token + JWTs never in a message or REST response; `sslverify` stays `true`.
- Touching the Connector template/client → signature + nonce + timestamp + hash-only secret intact; executable deny-list, `.htaccess` allow-list (every depth, fail closed) and `realpath` jail intact; HTTPS checked before every send; lockout applied only on failure; no new op without an allow-list; `VERSION` bumped on wire or security-check changes.
- New `{@html}` / `innerHTML` → server-sanitise the source with `wp_kses_post`, or escape client-side.
- New code mapping a URL to a file (local or remote) → `Url::is_unsafe_path()` / `has_traversal()` first, `realpath` containment for local reads, static-extension allow-list (rule 10).
- Touching Lockdown → values validated before any generated file (ranges via `Cidr::parse()`, no labels in files, `var_export()` for PHP); hashes not secrets in the gate; forwarding headers never trusted; token only to the own host; the gate never deleted while `.user.ini` may point at it (rule 11).
- New `$wpdb` query → `prepare()` with placeholders.
- New `error_log` of content → gate behind `WP_DEBUG`.
- New AI ability → `manage_options` via `register()`; `'permission' => 'changes'|'sync'` for any state change (registered *and* re-checked behind its opt-in); no secrets returned; annotations honest.
