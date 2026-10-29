# Changelog

## [Unreleased]

### Fixed

- A refusal or an error Cloudflare answers (`ProviderException`) keeps no argument in its trace: the
  closure there held the provider and its adapter, the token in it.
- Guzzle's `ConnectException` (no connection: a host not resolved, refused), which cloudflare/sdk's
  adapter lets through, is an `UnreachableException`: it escaped the provider as it was.
- An `UnreachableException` no longer carries the token: its previous carried the request whose
  `Authorization` header held it, and every trace kept it in its arguments. The previous is a copy
  (the same class and code, the token masked in its message, no request), no trace of the chain
  keeps its arguments. Needs omnisense/omnidns's `Http\Redaction`.
- Without `account_id`, `$registry->domains('cloudflare')` is a `NotSupportedException` (the
  registry asks `capabilities()`, omnisense/omnidns), as the README says, where it gave the provider
  and its `domains()` threw `InvalidConfigException`. Docs only here; the test is this package's.
