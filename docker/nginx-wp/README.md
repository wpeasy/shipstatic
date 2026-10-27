# nginx test host

WordPress on nginx + PHP-FPM + MariaDB, laid out like a typical nginx VPS, for
trying ShipStatic's nginx workflows ("This server", Move WordPress into /wp,
Lockdown, Connector). Not part of the plugin; never shipped.

| Address | Served from |
|---|---|
| http://site.localhost:8090/ | `/var/www/html` (WordPress, or the static site after the move) = `./www/html` |
| http://site.localhost:8090/wp/ | `/var/www/html/wp` (WordPress after "Move WordPress into /wp") |
| http://static.localhost:8090/ | `/var/www/static` = `./www/static` (two-domain layout) |

`*.localhost` reaches this Mac without editing `/etc/hosts`. Inside the
containers the same addresses reach nginx over loopback, so ShipStatic can
render pages by requesting its own site.

## Start

```sh
cp .env.example .env     # then set the passwords and your UID/GID (id -u / id -g)
docker compose up -d
set -a; . ./.env; set +a
docker compose run --rm cli wp core download
docker compose run --rm cli wp config create --dbname=wordpress --dbuser=wordpress \
  --dbpass="$DB_PASSWORD" --dbhost=db
docker compose run --rm cli wp core install --url=http://site.localhost:8090 \
  --title="nginx test" --admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASSWORD" \
  --admin_email="$ADMIN_EMAIL" --skip-email
docker compose run --rm cli wp rewrite structure '/%postname%/'
docker compose run --rm cli wp plugin install /dist/shipstatic-<ver>.zip --activate
```

The PHP container starts PHP-FPM directly: the WordPress image's own
entrypoint would copy a fresh WordPress into `/var/www/html` whenever it
found none there — which is the state after the move.

After the move, run WP-CLI from the new folder:
`docker compose run --rm -w /var/www/html/wp cli wp …`

## nginx

The server block is `nginx/site.conf` (the folder is mounted, so edits are
seen); after editing, `docker compose exec web nginx -s reload`.

It already has the `location /wp/` block WordPress needs after "Move
WordPress into /wp". Without it, every WordPress address under `/wp/` that
isn't a real file — pretty links and the REST API, so the ShipStatic
dashboard too — falls through to the root's `/index.php`, which the move took
away, and returns 404. Comment it out to see what a client would hit.

## Reset

```sh
docker compose down -v && rm -rf www
```
