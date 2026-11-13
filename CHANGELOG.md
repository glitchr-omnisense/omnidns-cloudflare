# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable version. It requires `omnisense/contracts` `^1.0`, `omnisense/omnidns` `^1.0` with no stability flag, so `composer require omnidns/cloudflare` installs it in a project with Composer's default minimum-stability; the 1.x series follows semantic versioning. A tag `vX.Y.Z` is released by `.github/workflows/release.yml` once the package's CI passes on it.

### Changed
- Each page of a listing counted: `records()` fetch the first page as before (the registry's guard
  counts the operation) and each further page through the provider's guard (`Config::guard()`,
  from the factory, the constructor's last argument `pages`), checked before it is sent and
  counted after under the same operation, in the registry's limiter: a limit reached stops the
  listing before the next page is requested. Needs omnisense/contracts 16c8da1 or later
  (`Config::guard()`; 1568f2c, what the sites' vendors hold, has it).
- No limit per zone declared: the limits page (read again 2026-11-11) states none per zone or per
  record; its 1,200 per 5 minutes is per user (the account's credentials, not a resource a call is
  about), kept counted for the whole application, the stricter form.

### Added

- The usage policy as data: `policy.json` (omnisense/contracts' schema, read on 2026-11-10), named in
  `composer.json` (`extra.omnisense.policy`); the provider implements `DeclaresUsagePolicyInterface`
  and gives it to its `Capabilities`. The factory gives the provider its `Backoff`: before each call
  it waits while the provider asked to, and a 429 the SDK carries is a `UsageLimitReachedException`
  with its Retry-After, holding the next calls.

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
