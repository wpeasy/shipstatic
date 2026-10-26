# Changelog

## v2.3.3 (2026-10-26)

### Fixed
**Destinations on your own computer work again.** A Connector destination on a local development address, such as a Herd or Valet `.test` site, a Local `.local` site or `localhost` on any port, failed Test with "A valid URL was not provided". It now connects. Addresses on your network and other private addresses are still blocked.
