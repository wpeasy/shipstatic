# Changelog

## v2.4.8 (2026-10-28)

### Improved
**The "This server" wizard starts with a clear choice.** When WordPress sits at the root of its domain, the wizard now first asks where the static site should go: at your domain, with WordPress moved into `/wp`, or on another domain or subdomain, with WordPress left where it is. **Back** always returns to that choice, instead of being greyed out or jumping between the two options.

### Improved
**Clearer help for publishing to another domain.** The wizard now explains what your host needs: a domain or subdomain served from a folder on this server (on cPanel, set under Domains; on a VPS, a second site in nginx or Apache). If the other domain is hosted somewhere else, it points you to Connector or FTP instead.
