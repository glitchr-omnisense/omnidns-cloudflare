# Changelog

## [Unreleased]

### Fixed

- Without `account_id`, `$registry->domains('cloudflare')` is a `NotSupportedException` (the
  registry asks `capabilities()`, omnisense/omnidns), as the README says, where it gave the provider
  and its `domains()` threw `InvalidConfigException`. Docs only here; the test is this package's.
