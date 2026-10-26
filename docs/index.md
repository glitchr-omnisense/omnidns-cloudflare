# omnidns/cloudflare

Cloudflare for [omnisense/omnidns](https://github.com/glitchr-omnisense/omnidns): the records of a
zone Cloudflare serves, and the domains Cloudflare Registrar holds for an account with their
terms - Cloudflare's API v4 (`https://api.cloudflare.com/client/v4/`), through
[cloudflare/sdk](https://github.com/cloudflare/cloudflare-php) (community-maintained,
framework-free): its `Zones` and `DNS` endpoints for the records, its adapter for the two
Registrar reads it does not cover. The SDK sends with Guzzle.

| Operation | Cloudflare API v4 |
|---|---|
| the zone's id | `Zones::getZoneID($zone)` (once per zone and provider object) |
| `records()` | `DNS::listRecords()` (`GET zones/{id}/dns_records`), 100 a page |
| `add()` | `DNS::addRecord()` |
| `update()` | `DNS::updateRecordDetails()` |
| `delete()` | `DNS::deleteRecord()` |
| `domains()`, `domain()` | `GET accounts/{account_id}/registrar/domains[/{name}]` |

Cloudflare's API neither checks nor registers a domain, and a zone's name servers are
Cloudflare's own: no availability, registration, renewal or name servers here.

## Installation

```sh
composer require omnidns/cloudflare
```

Requires PHP 8.2, `omnisense/omnidns` (1.x), `cloudflare/sdk` (^1.4 or ^2.0) and
`psr/http-message`.

## Credentials

An **API token** with Zone:Read and DNS:Edit on the zones it manages, and Account Registrar:Read
for the Registrar's domains; the **account id** whose Registrar domains are read.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `token` | string | - | yes | the API token (the SDK's `APIToken`) |
| `account_id` | string | none | no | the Registrar's domains; none: `capabilities()->domains` false |
| `base_uri` | string | `https://api.cloudflare.com/client/v4/` | no | given to the SDK's Guzzle adapter |

`CloudflareFactory(?Cloudflare\API\Adapter\Adapter $adapter = null)`: another adapter (a test's, a
proxy's) in place of the SDK's Guzzle one; the token and `base_uri` are then the adapter's
business.

## Capabilities

| Contract | Supported |
|---|---|
| `DnsInterface` | yes; HTTPS, SVCB and the other types outside omnidns's left out |
| `DomainsInterface` | with `account_id` (read only) |
| Availability, registrar, name servers | no: the registry refuses them (`NotSupportedException`) |

Terms: Cloudflare's terms, the API's limits (1,200 calls per five minutes per user).

> [!WARNING]
> Known defect: the class implements `DomainsInterface` whatever its options. Without
> `account_id`, `$registry->domains('cloudflare')` returns it and `domains()` throws
> `InvalidConfigException` (`The "cloudflare" provider needs an account_id to read the Registrar's
> domains.`, checked 2026-10-26), where the README says there is then no `DomainsInterface` (the
> registry's `NotSupportedException`). Check `capabilities()->domains` first.

## Behaviour

**Records.** Filtered by type and by full name (`www.example.org`), 100 a page until the last of
`result_info.total_pages`. Each record: its id, its name made relative to the zone, its content,
its TTL (1, Cloudflare's "auto", read as `null`), an MX's priority, `proxied` kept in
`Record::$options`. An SRV with structured `data` is read as its zone-file value (`priority weight
port target`).

**Add.** `addRecord()` with the type, the full name, the content, the TTL (0 when `null`),
`proxied` from `options['proxied']` (false unless asked), an MX's priority; SRV and CAA as
Cloudflare's structured data, from their zone-file value (`10 5 5060 sip.example.org`, `0 issue
"letsencrypt.org"`). The answer's record (with its id) is returned.

**Update.** The record found (by id, or by content among the zone's records of that name and
type), then `updateRecordDetails()` with type, full name, content (or data), TTL (1 when `null`),
priority, and for A, AAAA and CNAME `proxied`: the new record's, else as it was.

**Delete.** By id, found the same way.

**Domains.** The Registrar's list (`name`, else `id`); one domain's `expires_at`, `auto_renew`,
`registry_statuses` split on commas, `created_at`; no name servers.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnidns\Cloudflare\CloudflareFactory;
use Omnidns\Model\Record;
use Omnidns\Registry;

$registry = new Registry([new CloudflareFactory()], [
    'cloudflare' => ['factory' => 'cloudflare', 'options' => ['token' => getenv('CLOUDFLARE_API_TOKEN'), 'account_id' => getenv('CLOUDFLARE_ACCOUNT_ID')]],
]);

$dns = $registry->dns('cloudflare');
$www = $dns->add('example.org', new Record('CNAME', 'www', 'example.org', options: ['proxied' => true]));
$dns->update('example.org', $www, new Record('CNAME', 'www', 'example.net'));   // still proxied
$dns->add('example.org', new Record('CAA', '', '0 issue "letsencrypt.org"'));

foreach ($registry->domains('cloudflare')->domains() as $name) {
    echo $name, ' ', $registry->domains('cloudflare')->domain($name)->expiresAt?->format('Y-m-d'), "\n";
}
```

Not run live (no token).

## Errors

- An answer with an error status (the SDK's `ResponseException` over Guzzle's): `ProviderException`
  `Cloudflare answered <status> (<message>) for <what>.`, the status as its code.
- No response (Guzzle could not connect): `UnreachableException`.
- The SDK's `EndpointException` (a zone not found by name...): `ProviderException` with its
  message.
- A record not found by content: `ProviderException` `no such record`.

## Verified, and not

`Tests/CloudflareProviderTest.php`, on recorded answers written from Cloudflare's API v4
reference (not captured), as cloudflare/sdk makes the calls through a recording adapter: the
records page after page, filtered, the zone's id asked once, an addition (MX, CAA as data), an
update keeping the proxy, a deletion by id, a record or a zone Cloudflare does not hold, an error's
message, the Registrar's domains. Green in the family's harness on 2026-10-26. **Not run live**:
no Cloudflare token at hand.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Cloudflare, Inc. Whoever runs it complies with Cloudflare's
[terms](https://www.cloudflare.com/terms/) and its API's [rate
limits](https://developers.cloudflare.com/fundamentals/api/reference/limits/); see the README's
Responsibility section.
