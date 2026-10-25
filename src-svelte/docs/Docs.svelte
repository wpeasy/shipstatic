<script lang="ts">
  const version = (window as unknown as { shipStaticData?: { version?: string } }).shipStaticData?.version ?? '';

  const sections = [
    { id: 'why', label: 'Built for Bricks' },
    { id: 'features', label: 'Features' },
    { id: 'how', label: 'How it works' },
    { id: 'search', label: 'Site search' },
    { id: 'schedule', label: 'Scheduled sync' },
    { id: 'lockdown', label: 'Lockdown' },
    { id: 'connector', label: 'Connector' },
    { id: 'cloudflare', label: 'Cloudflare' },
    { id: 'github', label: 'GitHub Pages' },
    { id: 'issues', label: 'Common issues' },
    { id: 'troubleshooting', label: 'Troubleshooting' },
    { id: 'cli', label: 'CLI & reference' },
  ];

  const features = [
    {
      title: 'Static generation',
      body: 'Renders each page through a normal request and captures the final HTML — builder-agnostic, so it works with Bricks, Gutenberg and anything else the theme outputs.',
    },
    {
      title: 'Page discovery',
      body: '“Only linked pages” crawls from the home page following internal links (orphaned/builder URLs stay out). “All published” additionally includes every published post and taxonomy archive. A generated sitemap also seeds discovery.',
    },
    {
      title: 'Multiple destinations',
      body: 'Push to any number of destinations, each enabled independently and each deploying via FTP / FTPS / SFTP, the Connector, GitHub Pages or Cloudflare. SFTP verifies the server host key (trust-on-first-use). Credentials and pairing secrets are encrypted at rest (AES-256-GCM).',
    },
    {
      title: 'Connector (HTTPS) — no FTP account',
      body: 'Pair a destination, upload one small PHP file to its web root, and every sync from then on travels to that file over signed HTTPS as a single package. The file holds no password (only a fingerprint of a secret that stays on your site), writes only inside its own folder, and refuses anything a server could execute. Re-pair to rotate, Revoke to disconnect.',
    },
    {
      title: 'Cloudflare — no web host at all',
      body: 'Publish straight to Cloudflare’s global network as a static Worker, with just an account ID and an API token. Each sync sends Cloudflare the full file list, uploads only what it doesn’t already have, then switches the whole site over in one step — removed pages disappear automatically. Cache headers are published for you; Cloudflare handles compression.',
    },
    {
      title: 'GitHub Pages — publish as git commits',
      body: 'Publish to a branch of your GitHub repository and let GitHub Pages serve it. Each sync is one commit containing only the files that changed — removed pages are deleted, an unchanged site makes no commit, and every deploy is in your repository’s history to inspect or revert.',
    },
    {
      title: 'Fast package deploy',
      body: 'Instead of one slow FTP transfer per file, it zips the changed files + a one-shot helper, uploads both, and extracts server-side over HTTPS — collapsing hundreds of round trips into one. Falls back to per-file uploads automatically when a host can’t run it.',
    },
    {
      title: 'Per-destination replacements',
      body: 'Text (plain or rich HTML), Media (pick a page, then swap any of its images — responsive srcset variants rebuilt automatically), Links (rewrite <a>/button targets) and Videos (swap embeds/local files) — all included, no limits. Media and Videos are per-page; each destination gets its own set, grouped in a one-open-at-a-time accordion.',
    },
    {
      title: 'Sitemaps, robots.txt & favicon',
      body: 'A sitemap index + per-type sitemaps and a robots.txt are generated and uploaded, with their URLs rewritten to each destination’s domain. A /favicon.ico is shipped from your real favicon, the WordPress Site Icon, or a generated default.',
    },
    {
      title: 'Site search',
      body: 'Search boxes keep working on the static site. Each sync builds a search index for each destination — with that destination’s own text and addresses — and a small script searches it in the visitor’s browser, results as you type. No server, no outside service. Includes a fully styleable ShipStatic Search element for Bricks and a [shipstatic_search] shortcode.',
    },
    {
      title: 'Scheduled sync',
      body: 'Process and Sync automatically every hour, day, week or month — to all destinations (including ones added later) or the ones you switch on. The Schedule tab shows the next run and how the last one went.',
    },
    {
      title: 'Single-page sync',
      body: 'Push just one changed page (plus any new pages it links to, so you never publish a dead link) from a draggable floating button on the front end and inside the Bricks editor — where it saves the editor first.',
    },
    {
      title: 'Export ZIP (manual deploy)',
      body: 'Every destination also has a Manual deploy section: "Export ZIP" packages the current render into a downloadable zip you upload and extract yourself — no server access needed. Uses that destination’s configured replacements, same as a real Sync, and never touches its sync stats since nothing is uploaded. Includes gzip .gz files, sitemap.xml and robots.txt.',
    },
    {
      title: 'gzip + server config',
      body: 'Pre-compresses text assets and uploads a managed .htaccess that serves them with the right headers. nginx serves the output as-is; an optional tuning snippet is provided, and "Check server" verifies what the destination is actually doing.',
    },
  ];
</script>

<div class="ab-ui bs-docs bs-stack bs-stack--lg">
  <header class="bs-stack bs-stack--xs">
    <h1>Documentation{#if version}<span class="bs-docs__ver">v{version}</span>{/if}</h1>
    <p class="bs-docs__lead">How ShipStatic works, plus fixes for the issues you’re most likely to hit.</p>
  </header>

  <div class="bs-docs__layout">
    <nav class="bs-docs__toc" aria-label="Contents">
      <span class="bs-docs__toc-title">On this page</span>
      {#each sections as s}
        <a href={`#${s.id}`}>{s.label}</a>
      {/each}
    </nav>

    <div class="bs-docs__content bs-stack bs-stack--lg">
      <!-- Why the name -->
      <section id="why" class="bs-docs__section bs-stack bs-stack--sm">
        <h2>Built for Bricks</h2>
        <p>
          ShipStatic understands Bricks. Bricks gives elements a new random ID (<code>brxe-…</code>, form field IDs and
          several <code>data-*</code> attributes) every time a page renders. To a generic static exporter every Bricks page
          then looks changed on every run, so the whole site re-uploads and “in sync” never means anything. ShipStatic
          ignores those IDs when comparing renders (the published HTML keeps its real IDs), so only pages whose content
          actually changed are uploaded. It also renders through Bricks’ maintenance / coming-soon mode, and its
          “Sync this page” button works alongside the Bricks editor.
        </p>
        <p>
          <strong>Bricks isn’t required.</strong> Rendering captures whatever your site outputs, so block editor
          (Gutenberg) and Classic Editor content, archives and any theme export the same way. On pages not built with
          Bricks, the Bricks-specific handling simply has nothing to do. Developers using another builder can add its
          per-render noise patterns with the <code>shipstatic_stable_hash_patterns</code> filter.
        </p>
      </section>

      <!-- Features -->
      <section id="features" class="bs-docs__section">
        <h2>Features</h2>
        <div class="bs-docs__grid">
          {#each features as f}
            <div class="bs-docs__feature">
              <h3>{f.title}</h3>
              <p>{f.body}</p>
            </div>
          {/each}
        </div>
      </section>

      <!-- How it works -->
      <section id="how" class="bs-docs__section bs-stack bs-stack--sm">
        <h2>How it works</h2>
        <ol class="bs-docs__steps">
          <li><strong>Discover</strong> the pages to export (home-page crawl and/or all published, plus the sitemap).</li>
          <li><strong>Render</strong> each page through a loopback request and rewrite absolute site URLs to root-relative so the copy works on any domain.</li>
          <li><strong>Collect assets</strong> (CSS, JS, images, fonts) referenced by those pages.</li>
          <li><strong>Transform per destination</strong> — apply that destination’s text/media/link replacements and rewrite sitemap/robots URLs to its domain.</li>
          <li><strong>Deploy</strong> only what changed (delta against the last push) — over FTP/SFTP (as a package with a one-shot helper, or per file) or through the Connector. Cloudflare destinations instead receive the complete file list each time and upload only the files Cloudflare doesn’t already hold, then go live in one step.</li>
        </ol>
        <p class="bs-docs__muted">
          <strong>Check</strong> is a dry run: it renders and catalogues everything (and lists anything that won’t work on a static host) without uploading. <strong>Sync</strong> renders and uploads. <strong>Export ZIP</strong> packages the current render for one destination into a downloadable zip instead of uploading it — for manual deploys, with no effect on that destination’s sync stats. <strong>Reset sync state</strong> forgets every destination’s push record so the next sync re-uploads in full.
        </p>
      </section>

      <!-- Site search -->
      <section id="search" class="bs-docs__section bs-stack bs-stack--sm">
        <h2>Site search</h2>
        <p class="bs-docs__muted">
          WordPress search runs on the server, so on a plain static copy a search box just reloads the home page. Site search fixes that: every sync adds a small search index for each destination, and the search box searches it right in the visitor’s browser — results appear as you type, with the matching words highlighted.
        </p>
        <ol class="bs-docs__steps">
          <li>Open <strong>Settings</strong> (the gear, top right) and turn on <strong>Site search</strong>.</li>
          <li>Press <strong>Process</strong> (pages get the search script), then <strong>Sync</strong>.</li>
          <li>Any search box on your site now works on the static copy: WordPress’s own, Bricks’ Search element, or the <strong>ShipStatic Search</strong> element.</li>
        </ol>
        <h3>The ShipStatic Search element</h3>
        <p class="bs-docs__muted">
          Find it in the Bricks elements panel under <strong>ShipStatic</strong>. Besides the placeholder, number of results, snippets and “no results” text, every part can be styled from Bricks — the field (and its focus state), the leading icon, the input, the button (and hover), the label, the results box, each result (and the hovered or keyboard-selected one), titles, snippets, the highlighted words and the “no results” line. While you edit, the builder shows sample results so you can style them in place. On pages not built with Bricks, use the <code>[shipstatic_search]</code> shortcode.
        </p>
        <h3>Good to know</h3>
        <ul class="bs-docs__list">
          <li><strong>Per destination.</strong> Each destination’s index has its own text replacements and its own addresses, so a site only finds its own wording.</li>
          <li><strong>Main content only.</strong> Menus, headers, footers and forms aren’t indexed, so every page doesn’t match on its menu. Pages marked <code>noindex</code> are left out.</li>
          <li><strong>The live WordPress site is unchanged</strong> — its search box keeps using normal WordPress search.</li>
          <li><strong>Size.</strong> The index is downloaded only when someone clicks into a search box — roughly 120 KB (compressed) for 300 pages. Best for sites up to a few hundred pages.</li>
          <li><strong>Fresh after every sync.</strong> The browser checks for a new index each time, so new pages show up even on hosts that cache for a while (GitHub Pages caches pages for 10 minutes).</li>
        </ul>
      </section>

      <!-- Scheduled sync -->
      <section id="schedule" class="bs-docs__section bs-stack bs-stack--sm">
        <h2>Scheduled sync</h2>
        <p class="bs-docs__muted">
          On the <strong>Schedule</strong> tab, turn on <strong>Enabled</strong>, choose how often (hourly, daily, weekly or monthly), and pick the destinations — <strong>All destinations</strong> (including ones you add later) or just the ones you switch on. Each scheduled run is the same full Process and Sync as the Sync button. It never starts while another sync is running; the tab shows the next run and how the last one went.
        </p>
        <p class="bs-docs__muted">
          Schedules use WP-Cron, which fires when someone visits the site, so on a quiet site a sync may start a little late. For exact timing, have your host’s cron call <code>wp-cron.php</code> every few minutes. With WP-CLI available the sync runs in the background; otherwise WordPress runs it in short steps until it’s done.
        </p>
      </section>

      <!-- Lockdown -->
      <section id="lockdown" class="bs-docs__section bs-stack bs-stack--sm">
        <h2>Lockdown</h2>
        <p class="bs-docs__muted">
          Once your visitors use the static site, your WordPress site only needs to reach its editors. <strong>Lockdown</strong> locks it to a list of IP addresses and sends everyone else to the same page on your static site. A WordPress site no stranger can reach can’t be attacked through its plugins, login page or forms.
        </p>
        <h3>Turning it on</h3>
        <ol class="bs-docs__steps">
          <li>On the <strong>Lockdown</strong> tab, copy the <strong>unlock link</strong> and keep it somewhere safe (a bookmark, a password manager).</li>
          <li>Turn <strong>Lockdown</strong> on. Your current address is added to the list, and the tab checks that a stranger really is turned away.</li>
          <li>Add your team’s addresses, or send them the unlock link: opening it adds the address they’re on.</li>
        </ol>
        <h3>How it locks the site</h3>
        <p class="bs-docs__muted">
          ShipStatic picks the strongest method your server allows, and the tab says which one is working. On Apache and LiteSpeed it adds rules to the top of <code>.htaccess</code>, so strangers are stopped before any PHP runs. On nginx it adds a line to <code>.user.ini</code> that runs a small check before every PHP file (PHP can take up to 5 minutes to notice a change). Everywhere, a small file in <code>wp-content/mu-plugins</code> stops strangers inside WordPress too, before other plugins load.
        </p>
        <p class="bs-docs__muted">
          The site’s own requests still get through, so Process, Sync, scheduled syncs, WP-Cron and Site Health keep working, and so do certificate renewals (<code>/.well-known/</code>). WP-CLI is never affected.
        </p>
        <h3>If you’re locked out</h3>
        <ul class="bs-docs__list">
          <li>Open your <strong>unlock link</strong>. It adds the address you’re on and keeps you in for 12 hours even if your address changes.</li>
          <li>With WP-CLI: <code>wp shipstatic lockdown off</code>, or <code>wp shipstatic lockdown allow &lt;address&gt;</code>.</li>
          <li>Over FTP: replace everything in <code>wp-content/shipstatic-lockdown.php</code> with just <code>&lt;?php</code> (don’t delete it while <code>.user.ini</code> still points to it), then remove the <code>ShipStatic Lockdown</code> lines from <code>.htaccess</code> and <code>.user.ini</code>.</li>
          <li>Deactivating ShipStatic removes every Lockdown file.</li>
        </ul>
        <h3>Good to know</h3>
        <ul class="bs-docs__list">
          <li>Behind a proxy or CDN (Cloudflare, a load balancer) the server may see the proxy’s address instead of yours. The tab warns you when it looks that way; the unlock link still works.</li>
          <li>Anyone with the unlock link can get in. Each address it adds is marked in the list, and the site admin gets an email. <strong>New link</strong> replaces it and signs everyone out of their unlock cookie.</li>
          <li>Nothing on your static site is affected: it never loads anything from the WordPress site.</li>
        </ul>
      </section>

      <!-- Connector -->
      <section id="connector" class="bs-docs__section bs-stack bs-stack--sm">
        <h2>Deploying with the Connector</h2>
        <p class="bs-docs__muted">
          The Connector replaces the FTP account. You upload a single PHP file — the <em>connector</em> — to the folder your static site is served from, once. After that, each sync sends the changed files to that connector over an encrypted, signed connection as one package, which is both simpler to set up and faster than uploading file by file. Each destination uses <strong>either</strong> FTP / SFTP <strong>or</strong> the Connector; pick one under <em>Deploy via</em>.
        </p>

        <h3>Set-up</h3>
        <ol class="bs-docs__steps">
          <li>On the destination, enter the <strong>Destination URL</strong> — the public <code>https://</code> address of the folder the static site is served from — and choose <strong>Connector (HTTPS)</strong> under <em>Deploy via</em>. Press <strong>Save</strong>.</li>
          <li>Press <strong>Pair</strong>. The plugin generates a pairing for this destination only and shows a <strong>Download bs-connector-…php</strong> link. The link works once and expires after ten minutes; if you miss it, press <em>Re-pair</em> for a new one.</li>
          <li>Upload that file, unchanged, to the folder served at the Destination URL — next to where <code>index.html</code> will go. Any method works: your host’s file manager, SFTP, a one-off drag into cPanel.</li>
          <li>Press <strong>Test</strong>. A good result reads “Connector reached — PHP x.y, package deploy available, N GB free”. <strong>Check</strong> and <strong>Sync</strong> then work exactly as they do for FTP destinations, and so does single-page sync.</li>
        </ol>

        <h3>Requirements</h3>
        <ul class="bs-docs__list">
          <li>The destination must run <strong>PHP 7.4 or newer</strong> for that folder. Purely static hosts can’t run the connector — for Cloudflare, use the <a href="#cloudflare">Cloudflare</a> deploy method; for others (Netlify, S3), Export ZIP or their own deploy tooling.</li>
          <li>The Destination URL must be <strong>HTTPS</strong> (the pairing secret travels with every request). <code>*.local</code> / <code>*.test</code> development hosts are exempt.</li>
          <li>If the destination’s PHP has <code>ZipArchive</code>, syncs go up as one package and are extracted there. Without it, files are sent one at a time — same result, slower.</li>
          <li>The folder must be writable by PHP. Test tells you if it isn’t.</li>
        </ul>

        <h3>On nginx</h3>
        <p class="bs-docs__muted">
          nginx has limits PHP can’t see, so the connector can’t detect them. Make sure the destination site has at least:
        </p>
        <ul class="bs-docs__list">
          <li><strong>PHP for the connector file</strong>: a <code>location ~ \.php$</code> block passing to PHP-FPM, as for any PHP site. Without it, Test reports HTTP 405 or 404.</li>
          <li><code>client_max_body_size 2m;</code> or more. ShipStatic uploads in pieces under 1 MB and retries smaller pieces if they’re refused, so the 1 MB default works unless it has been lowered.</li>
          <li><code>fastcgi_read_timeout 300s;</code> in that PHP location, so unpacking a large site doesn’t hit the 60-second default.</li>
          <li>Rules that stop nginx serving <code>.htaccess</code> files and the connector’s in-progress uploads. The nginx snippet on the <strong>Destination Server Configuration</strong> tab includes them.</li>
        </ul>

        <h3>Managing a pairing</h3>
        <ul class="bs-docs__list">
          <li><strong>Re-pair (new file)</strong> — generates a fresh pairing and a new file. The file currently on the destination stops working immediately, so upload the new one before the next sync. Use it if you lose the download, suspect the secret leaked, or moved the site to a new server.</li>
          <li><strong>Revoke</strong> — forgets the pairing on this site. The connector file on the destination then refuses every request; delete it when convenient.</li>
          <li><strong>Rebuild push record</strong> — after <em>Reset sync state</em> or a re-pair, the plugin no longer knows what’s on the destination and would re-upload everything. This asks the connector for an inventory and marks every file that’s already there and identical as done, so the next Sync sends only what really changed. Files it finds that ShipStatic never uploaded are left alone. Needs a current render (Process first).</li>
          <li><strong>Check server</strong> (Destination Server Configuration tab) reads the destination’s exact web server through the connector, even behind a CDN.</li>
        </ul>

        <h3>What the connector can and can’t do</h3>
        <p class="bs-docs__muted">
          The file is made for one destination and one source site. It contains only a fingerprint of the pairing secret, never the secret itself, so nothing reusable leaks even if a host mistakenly serves it as text. Every request must carry a valid signature, a timestamp within five minutes and a never-before-seen nonce; after ten bad requests it refuses further bad ones for ten minutes (correctly signed requests from your site still get through). It only works over HTTPS — your site won’t send it anything if the Destination URL is changed to <code>http://</code>. It only ever writes inside its own folder, never through symlinks, never anything a server could run (<code>.php</code> and the like, including <code>x.php.txt</code>, <code>.user.ini</code>, <code>web.config</code>), and it accepts an <code>.htaccess</code> (in any folder) only if every line is a caching, rewrite, redirect or index/error-page rule — or was already in that file on your server. It can read back only the <code>.htaccess</code> it manages. In short: a leaked secret could at most overwrite your static pages — it can’t run code on your server or read anything else. Delete the file at any time to disconnect.
        </p>
      </section>

      <!-- Cloudflare -->
      <section id="cloudflare" class="bs-docs__section bs-stack bs-stack--sm">
        <h2>Deploying to Cloudflare</h2>
        <p class="bs-docs__muted">
          The Cloudflare deploy method publishes your static site directly to Cloudflare’s global network — no web host, no PHP and no FTP account. Under the hood it is a <em>Cloudflare Worker with static assets</em> (Cloudflare’s current product for static sites, and the successor to Cloudflare Pages for new projects); the Worker runs none of your code, it simply serves the files. Choose <strong>Cloudflare</strong> under <em>Deploy via</em>.
        </p>

        <h3>Set-up</h3>
        <ol class="bs-docs__steps">
          <li>In the Cloudflare dashboard open <strong>Workers &amp; Pages</strong> and copy your <strong>Account ID</strong> (on the right of the overview page).</li>
          <li>Create an API token: <strong>My Profile → API Tokens → Create Token → Custom token</strong>, with the single permission <strong>Account → Workers Scripts → Edit</strong>, limited to that account. Copy the token — Cloudflare shows it only once.</li>
          <li>On the destination, choose <strong>Cloudflare</strong>, then enter the Account ID, the token, and a <strong>Worker name</strong> for this site (lowercase letters, numbers and dashes, e.g. <code>my-site</code>). The Worker doesn’t need to exist; the first sync creates it.</li>
          <li>Press <strong>Test</strong>. A good result reads “Cloudflare token accepted — Worker “my-site” will be served at https://my-site.&lt;you&gt;.workers.dev”. If the Destination URL is empty it is filled with that address. Press <strong>Save</strong>.</li>
          <li><strong>Check</strong> and <strong>Sync</strong> as usual. Single-page sync works too.</li>
        </ol>

        <h3>Using your own domain</h3>
        <p class="bs-docs__muted">
          After the first sync, open the Worker in Cloudflare (<strong>Worker → Settings → Domains &amp; Routes → Add → Custom domain</strong>) and attach your domain — it must be on Cloudflare DNS. Then set the destination’s <strong>Destination URL</strong> to that domain and Sync again, so the sitemap, robots.txt and replacements use it. While the Destination URL is a custom domain, the plugin leaves the <code>workers.dev</code> address switched off so there is no duplicate public copy.
        </p>

        <h3>How a Cloudflare sync differs</h3>
        <ul class="bs-docs__list">
          <li><strong>Whole-site snapshots.</strong> Every sync sends Cloudflare the full list of files with a fingerprint of each; Cloudflare replies with the ones it doesn’t have, only those are uploaded, and the new version replaces the old one in a single step. Visitors never see a half-updated site, so there is no holding page.</li>
          <li><strong>No pruning needed.</strong> A page you remove from the export is simply absent from the next snapshot and stops being served — whether or not “prune” is on.</li>
          <li><strong>No <code>.htaccess</code> or <code>.gz</code> files.</strong> Cloudflare compresses (gzip/brotli) itself. The cache policy (assets cached for a year, HTML always revalidated) is published as Cloudflare <code>_headers</code> rules with each deploy; <strong>Check server</strong> confirms it is in effect.</li>
          <li><strong>Clean URLs.</strong> <code>/about/</code> serves <code>about/index.html</code>, and the export’s <code>404.html</code> (if any) is used for missing pages.</li>
          <li><strong>History.</strong> Every sync, including a single-page sync, creates a new version of the Worker, visible (and restorable) in Cloudflare under <em>Deployments</em>.</li>
        </ul>

        <h3>Limits</h3>
        <ul class="bs-docs__list">
          <li><strong>25 MiB per file.</strong> Larger files (usually videos) are left out of the deploy and listed in the result. Host them elsewhere (Cloudflare R2, YouTube, Vimeo) and point to them with a Videos replacement.</li>
          <li><strong>20,000 files</strong> per site on Cloudflare’s Free plan, 100,000 on paid plans.</li>
          <li><strong>Nothing runs server-side</strong> — forms that post back to WordPress and comments don’t work on the static copy (true of every static host). Search does, with <a href="#search">Site search</a> turned on.</li>
          <li>Cloudflare rate-limits its API; the plugin waits and retries automatically, but a first sync of a very large site can take a few minutes.</li>
        </ul>

        <h3>Security</h3>
        <p class="bs-docs__muted">
          The API token is encrypted at rest like FTP passwords and is never shown again or sent to the browser. Requests go only to <code>api.cloudflare.com</code> over verified HTTPS. Give the token only the <em>Workers Scripts → Edit</em> permission on one account — then a leaked token could change Workers on that account, but nothing else (DNS, billing, other accounts). Roll the token in Cloudflare at any time and paste the new one into the destination.
        </p>
      </section>

      <!-- GitHub Pages -->
      <section id="github" class="bs-docs__section bs-stack bs-stack--sm">
        <h2>Deploying to GitHub Pages</h2>
        <p class="bs-docs__muted">
          The GitHub Pages deploy method commits your static site to a branch of a GitHub repository, and GitHub Pages serves it — no web host, no PHP and no FTP account. Choose <strong>GitHub Pages</strong> under <em>Deploy via</em>.
        </p>

        <h3>Set-up</h3>
        <ol class="bs-docs__steps">
          <li>Create a repository on GitHub with at least one commit (tick <strong>Add a README</strong>). Make it public: GitHub Pages on a private repository needs a paid GitHub plan.</li>
          <li>Create a fine-grained token: <strong>Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token</strong>. Under <em>Repository access</em> choose <strong>Only select repositories</strong> and pick this repository; under <em>Permissions</em> set <strong>Contents</strong> to <strong>Read and write</strong>. Optionally add <strong>Pages: Read-only</strong> so Test can show the Pages status. Copy the token — GitHub shows it only once.</li>
          <li>On the destination, choose <strong>GitHub Pages</strong> and enter the owner (your account or organisation), the repository name and the token. Branch and folder sit under <strong>Advanced</strong>; the defaults (<code>gh-pages</code>, whole branch) suit most sites — see <em>Choosing a branch and folder</em> below.</li>
          <li>Press <strong>Test</strong>. If the Destination URL is empty it is filled with the Pages address. Press <strong>Save</strong>.</li>
          <li><strong>Check</strong> and <strong>Sync</strong> as usual. With the <code>gh-pages</code> branch, GitHub turns Pages on by itself at the first sync. With another branch or the <code>/docs</code> folder, turn it on afterwards in the repository’s <strong>Settings → Pages → Deploy from a branch</strong>.</li>
        </ol>

        <h3>Choosing a branch and folder</h3>
        <ul class="bs-docs__list">
          <li><strong>A repository just for the site</strong> — keep the defaults, <code>gh-pages</code> and <em>Whole branch</em>. GitHub turns Pages on by itself at the first sync.</li>
          <li><strong>The site next to your code</strong> — choose <code>main</code> (or your code branch) with the <em>/docs folder</em>. Only <code>docs/</code> is replaced on each sync, so your source files at the root are left alone.</li>
          <li><strong>A user or organisation site</strong> (a repository named <code>owner.github.io</code>) — these usually publish from <code>main</code>; use it with <em>Whole branch</em> if the repository holds only the site.</li>
          <li>For anything other than <code>gh-pages</code>, pick the same branch and folder in the repository’s <strong>Settings → Pages → Deploy from a branch</strong>.</li>
        </ul>

        <h3>Project sites and the sub-path</h3>
        <p class="bs-docs__muted">
          A repository called <code>my-site</code> is served at <code>https://owner.github.io/my-site/</code> — a sub-path. With that as the <strong>Destination URL</strong> (Test fills it in), the plugin builds links, images and CSS for <code>/my-site/</code> automatically: the sub-path is always taken from the Destination URL. Save, then press <strong>Process</strong>. A repository named <code>owner.github.io</code>, or a custom domain, is served from the root and needs no sub-path. Every destination gets links for its own address, so two project sites in different repositories — or a project site and your own domain — publish correctly from the same render.
        </p>

        <h3>How a GitHub Pages sync works</h3>
        <ul class="bs-docs__list">
          <li><strong>One commit per sync.</strong> The plugin compares your export with the branch and sends only what changed. Removed pages are deleted in the same commit; when nothing changed, no commit is made.</li>
          <li><strong>The branch folder is the site.</strong> Everything in the chosen folder (the whole branch, or <code>docs/</code>) is replaced by the export — except <code>CNAME</code>, README, LICENSE and <code>.github/</code>. Don’t publish to a branch’s root if it holds your source code: use <code>gh-pages</code>, or <code>/docs</code>.</li>
          <li><strong>Never force-pushed.</strong> If someone pushes to the branch during a sync, the plugin rebuilds on top of their commit once, and otherwise stops with a message — it never overwrites history.</li>
          <li><strong>History.</strong> Every sync is a normal commit, so you can see what changed and revert a deploy with git.</li>
          <li><strong>No <code>.htaccess</code> or <code>.gz</code> files.</strong> GitHub compresses files itself and sets its own cache headers (about 10 minutes for everything); custom cache rules can’t be applied. A <code>.nojekyll</code> file is added so GitHub publishes the files as they are.</li>
          <li><strong>Custom domain.</strong> Set it in the repository’s <strong>Settings → Pages</strong>, or enter it as the Destination URL; the plugin keeps the <code>CNAME</code> file GitHub uses.</li>
        </ul>

        <h3>Limits</h3>
        <ul class="bs-docs__list">
          <li><strong>25 MiB per file</strong> (larger files are left out and listed in the result) and <strong>1 GB</strong> per site.</li>
          <li><strong>About 500 uploads an hour.</strong> Text files cost almost nothing, but each image or other binary file is one upload, so a first sync of an image-heavy site may pause (“continues at …”) and carry on by itself. Keep the dashboard open, or let the WP-CLI runner carry on. Later syncs send only what changed.</li>
          <li>GitHub rebuilds a Pages site at most <strong>about 10 times an hour</strong> — many single-page syncs in a row can hit that.</li>
          <li>On a project site, <code>robots.txt</code> has no effect (search engines only read it at the domain root).</li>
          <li><strong>Nothing runs server-side</strong> — forms that post back to WordPress and comments don’t work on the static copy. Search does, with <a href="#search">Site search</a> turned on.</li>
        </ul>

        <h3>Security</h3>
        <p class="bs-docs__muted">
          The token is encrypted at rest like FTP passwords and is never shown again or sent to the browser. Requests go only to <code>api.github.com</code> over verified HTTPS. A fine-grained token limited to one repository with <em>Contents: Read and write</em> can change that repository’s files — nothing else in your account. Regenerate it on GitHub at any time and paste the new one into the destination.
        </p>
      </section>

      <!-- Common issues -->
      <section id="issues" class="bs-docs__section bs-stack bs-stack--sm">
        <h2>Common issues &amp; resolutions</h2>

        <details class="bs-docs__faq">
          <summary>A destination shows “Out of date” right after a successful sync</summary>
          <p>“In sync” is based on a signature of the current render plus that destination’s replacements and URL. If it lingers after upgrading, run <strong>Sync</strong> once more — the first sync on the new logic records the signature, and it’ll read “In sync” from then on (until content or a replacement actually changes).</p>
        </details>

        <details class="bs-docs__faq">
          <summary>Everything shows “Not published / Out of date” after an interrupted run</summary>
          <p>The render manifest is cleared at the start of a full sync and rebuilt as pages render; if a run is killed midway it can be left empty. Just run <strong>Sync</strong> again to rebuild it.</p>
        </details>

        <details class="bs-docs__faq">
          <summary>A destination is deploying per-file instead of as a fast package</summary>
          <p>Package deploy needs <code>ZipArchive</code> on this server, a <strong>Destination URL</strong> set (so the helper can be reached), and a host that can run the uploaded PHP helper. If it failed once it’s disabled for that destination — set the URL and use <strong>Re-test</strong> on the destination to re-enable it.</p>
        </details>

        <details class="bs-docs__faq">
          <summary>Connector Test says “No connector answered” or “Connector file not found”</summary>
          <p>The plugin reached the Destination URL but our file didn’t respond. Check that the file is in the folder served at <em>exactly</em> that URL (open <code>https://your-destination/bs-connector-…php</code> in a browser: the right answer is a small “method” message, not a 404 and not the file’s source). Some hosts refuse to run PHP in certain folders — nginx setups commonly block it under <code>uploads/</code> — so if you see the web server’s own 403 page, move the static site (and the connector) to a folder where PHP runs, or use FTP/SFTP instead.</p>
        </details>

        <details class="bs-docs__faq">
          <summary>Connector says it “rejected this site’s credentials”</summary>
          <p>The file on the destination was paired with a different secret than the one this site holds — usually an old file left in place after a <em>Re-pair</em>, or a file copied from another site. Press <strong>Re-pair (new file)</strong>, upload the new file over the old one, and Test again. If you see “temporarily locked”, wait ten minutes first.</p>
        </details>

        <details class="bs-docs__faq">
          <summary>“The connector file on the destination is outdated”</summary>
          <p>After a plugin update the connector may need a newer version of the file. Re-pair, upload the new file, and you’re done — nothing else on the destination changes.</p>
        </details>

        <details class="bs-docs__faq">
          <summary>Single-page sync says “No destinations are enabled”</summary>
          <p>Turn on <strong>Include in single-page sync</strong> on at least one destination (its toolbar). Only those destinations receive single-page pushes.</p>
        </details>

        <details class="bs-docs__faq">
          <summary>Export ZIP asks me to Process first</summary>
          <p>Like <strong>Check</strong>, Export ZIP only packages the CURRENT render — it never renders for you. If the page list has changed (or nothing’s been rendered yet), click <strong>Process</strong>, then Export ZIP again.</p>
        </details>

        <details class="bs-docs__faq">
          <summary>SFTP fails with “host key has changed”</summary>
          <p>The plugin remembers a server’s SFTP host key on first connect and refuses to continue if it later changes (a man-in-the-middle protection). If the server was legitimately rebuilt or migrated, use <strong>Reset sync state</strong> to clear the stored key, then reconnect. If you didn’t expect a change, investigate before proceeding.</p>
        </details>

        <details class="bs-docs__faq">
          <summary>Forms or other dynamic features don’t work on the static site</summary>
          <p>Expected — static HTML can’t run PHP. The <strong>Check</strong> run lists pages with forms or dynamic endpoints under “Won’t work on static”. Point forms at a third-party/JS service, or keep those pages dynamic. (Pages are still uploaded; it’s a notice, not an error.)</p>
          <p><strong>Search is the exception:</strong> turn on <a href="#search">Site search</a> and search boxes work on the static site too.</p>
        </details>

        <details class="bs-docs__faq">
          <summary>The floating “Sync this page” button isn’t showing</summary>
          <p>Check <strong>Enable sync single button</strong> in the dashboard’s top bar (on by default). It only appears for logged-in admins on real front-end pages and the Bricks editor — never to visitors, never inside the preview iframe, and never in a static render.</p>
        </details>
      </section>

      <!-- Troubleshooting -->
      <section id="troubleshooting" class="bs-docs__section bs-stack bs-stack--sm">
        <h2>Troubleshooting</h2>

        <details class="bs-docs__faq">
          <summary>“The connector file on the destination is outdated”</summary>
          <p>The connector was updated for security (version 2): its <code>.htaccess</code> checks are stricter and a lockout can no longer be held by strangers. Files paired before the update answer every request with this message. Press <strong>Re-pair (new file)</strong> on the destination and upload the new file in place of the old one.</p>
        </details>

        <details class="bs-docs__faq">
          <summary>A sync stalls or never progresses (often on Local)</summary>
          <p>A browser-driven sync holds one PHP worker while its loopback page render needs another. Hosts with very few workers — Local on Windows runs just two — can deadlock. The plugin prefers <strong>WP-CLI</strong> (a separate process) and tries to auto-start it; if it can’t, a banner shows the exact command to run in a terminal. Run that command and the dashboard picks the run back up automatically.</p>
        </details>

        <details class="bs-docs__faq">
          <summary>I changed the destination URL/host — what’s affected?</summary>
          <p>Changing where a destination points clears its push record (so the next sync re-uploads everything), re-probes package deploy, and forgets the stored SFTP host key for re-trust. Sitemap/robots URLs are regenerated for the new domain on the next sync.</p>
        </details>

        <details class="bs-docs__faq">
          <summary>TLS verification and *.local / *.test hosts</summary>
          <p>Certificate verification is relaxed <em>only</em> for <code>localhost</code>, loopback IPs, and <code>.local</code>/<code>.test</code> hosts (which can’t hold a public certificate). It is always enforced for real public destinations.</p>
        </details>

        <details class="bs-docs__faq">
          <summary>I need to re-upload everything from scratch</summary>
          <p>Use <strong>Reset sync state</strong> (bottom of the Destinations tab). It clears every destination’s push record and the local render manifest; the next <strong>Sync</strong> uploads in full.</p>
        </details>

        <details class="bs-docs__faq">
          <summary>Who can use these features?</summary>
          <p>Everything (the dashboard, REST endpoints, the floating button, single-page sync) requires an administrator (<code>manage_options</code>). Lower-privilege users never see or trigger it.</p>
        </details>
      </section>

      <!-- CLI -->
      <section id="cli" class="bs-docs__section bs-stack bs-stack--sm">
        <h2>CLI &amp; reference</h2>
        <p class="bs-docs__muted">Drive a sync from a terminal (useful on low-worker hosts, or for cron):</p>
        <pre class="bs-docs__code">wp shipstatic sync            # render + push to the primary destination
wp shipstatic sync --all      # push to every enabled destination
wp shipstatic sync --dest=ID  # push to one destination
wp shipstatic sync --check    # dry run (render + catalogue, no upload)
wp shipstatic sync --prune    # also remove remote files no longer present

wp shipstatic connector status                                  # pairing state per destination
wp shipstatic connector pair ID --dest-url=https://… --to=DIR   # pair + write the connector file
wp shipstatic connector test ID                                 # ping the connector
wp shipstatic connector rebuild ID                              # rebuild the push record from it
wp shipstatic connector revoke ID                               # disconnect

wp shipstatic cloudflare status                                 # Cloudflare destinations
CLOUDFLARE_API_TOKEN=… wp shipstatic cloudflare set ID --account=… --worker=my-site --token-from-env
wp shipstatic cloudflare test ID                                # check the token + account

wp shipstatic github status                                     # GitHub Pages destinations
GITHUB_TOKEN=… wp shipstatic github set ID --owner=… --repo=my-site --token-from-env
wp shipstatic github test ID                                    # check the token, write access + Pages</pre>
        <ul class="bs-docs__list">
          <li><strong>Cache:</strong> the staging copy lives under <code>wp-content/cache/shipstatic</code> (or uploads if that isn’t writable).</li>
          <li><strong>Server config:</strong> the managed <code>.htaccess</code> is uploaded automatically (Apache/LiteSpeed). nginx has no per-directory config, so it serves the static site with its defaults; the <em>Server config</em> tab has an optional tuning snippet to paste, plus <strong>Check server</strong>, which probes the destination and reports the web server and whether the cache rules are in effect.</li>
          <li><strong>Filters:</strong> behaviour is adjustable via <code>bs_*</code> hooks (e.g. <code>shipstatic_generate_sitemaps</code>, <code>shipstatic_include_favicon</code>, <code>shipstatic_seed_from_sitemap</code>, <code>shipstatic_excluded_post_types</code>; for Cloudflare, <code>shipstatic_cloudflare_headers</code> and <code>shipstatic_cloudflare_redirects</code>).</li>
        </ul>
      </section>
    </div>
  </div>
</div>

<style>
  .bs-docs {
    padding: var(--ab-space-5);
    background: var(--ab-color-bg);
    border-radius: var(--ab-radius-lg);
    max-width: 72rem;
  }

  .bs-docs__ver {
    margin-left: var(--ab-space-3);
    font-size: var(--ab-text-sm);
    font-weight: var(--ab-weight-normal);
    color: var(--ab-color-text-muted);
  }

  .bs-docs__lead {
    color: var(--ab-color-text-muted);
    font-size: var(--ab-text-lg);
  }

  .bs-docs__layout {
    display: grid;
    grid-template-columns: 12rem 1fr;
    gap: var(--ab-space-6, 2rem);
    align-items: start;
  }

  @media (max-width: 720px) {
    .bs-docs__layout {
      grid-template-columns: 1fr;
    }
    .bs-docs__toc {
      position: static !important;
    }
  }

  .bs-docs__toc {
    position: sticky;
    top: 2rem;
    display: flex;
    flex-direction: column;
    gap: var(--ab-space-1);
  }

  .bs-docs__toc-title {
    font-size: var(--ab-text-xs);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--ab-color-text-muted);
    margin-bottom: var(--ab-space-1);
  }

  .bs-docs__toc a {
    font-size: var(--ab-text-sm);
    color: var(--ab-color-text-muted);
    text-decoration: none;
    padding: var(--ab-space-1) 0;
  }

  .bs-docs__toc a:hover {
    color: var(--ab-color-primary);
  }

  .bs-docs__section {
    scroll-margin-top: 2rem;
    padding: var(--ab-space-5);
    background: var(--ab-color-surface);
    border: 1px solid var(--ab-color-border);
    border-radius: var(--ab-radius-lg);
    box-shadow: var(--ab-shadow-sm);
  }

  .bs-docs__section h2 {
    margin: 0 0 var(--ab-space-4);
    font-size: var(--ab-text-lg);
    font-weight: var(--ab-weight-semibold);
  }

  /* Sub-headings inside a prose section (the Connector guide). */
  .bs-docs__section > h3 {
    margin: var(--ab-space-3) 0 0;
    font-size: var(--ab-text-md);
    font-weight: var(--ab-weight-semibold);
  }

  .bs-docs__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: var(--ab-space-4);
  }

  .bs-docs__feature {
    display: flex;
    flex-direction: column;
    gap: var(--ab-space-1);
    padding: var(--ab-space-4);
    border: 1px solid var(--ab-color-border);
    border-radius: var(--ab-radius-md);
    background: var(--ab-color-surface);
  }

  .bs-docs__feature h3 {
    margin: 0;
    font-size: var(--ab-text-sm);
    font-weight: var(--ab-weight-semibold);
    color: var(--ab-color-text);
  }

  .bs-docs__feature p {
    margin: 0;
    font-size: var(--ab-text-sm);
    color: var(--ab-color-text-muted);
    line-height: 1.55;
  }

  .bs-docs__steps,
  .bs-docs__list {
    margin: 0;
    padding-left: var(--ab-space-5);
    display: flex;
    flex-direction: column;
    gap: var(--ab-space-2);
    font-size: var(--ab-text-sm);
    color: var(--ab-color-text-muted);
    line-height: 1.55;
  }

  .bs-docs__muted {
    margin: 0;
    font-size: var(--ab-text-sm);
    color: var(--ab-color-text-muted);
    line-height: 1.55;
  }

  .bs-docs__faq {
    border: 1px solid var(--ab-color-border);
    border-radius: var(--ab-radius-md);
    background: var(--ab-color-surface);
    padding: var(--ab-space-2) var(--ab-space-4);
  }

  .bs-docs__faq summary {
    cursor: pointer;
    font-size: var(--ab-text-sm);
    font-weight: var(--ab-weight-medium);
    color: var(--ab-color-text);
    padding: var(--ab-space-1) 0;
  }

  .bs-docs__faq[open] summary {
    margin-bottom: var(--ab-space-2);
  }

  .bs-docs__faq p {
    margin: 0;
    font-size: var(--ab-text-sm);
    color: var(--ab-color-text-muted);
    line-height: 1.6;
  }

  .bs-docs__code {
    margin: 0;
    padding: var(--ab-space-4);
    border-radius: var(--ab-radius-md);
    background: var(--ab-color-bg);
    color: var(--ab-color-text);
    font-family: var(--ab-font-mono, ui-monospace, monospace);
    font-size: var(--ab-text-xs);
    line-height: 1.7;
    overflow-x: auto;
    white-space: pre;
  }

  code {
    font-family: var(--ab-font-mono, ui-monospace, monospace);
    font-size: 0.92em;
    padding: 0.05em 0.35em;
    border-radius: var(--ab-radius-sm);
    background: var(--ab-color-bg);
  }
</style>
