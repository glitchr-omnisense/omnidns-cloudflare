# omnidns/cloudflare

Cloudflare for [omniscient/omnidns](https://github.com/glitchr-studio/omnidns), under any PHP
host: the records of a zone Cloudflare serves, and the domains Cloudflare Registrar holds for an
account with their terms.

```php
use Omnidns\Cloudflare\CloudflareFactory;
use Omnidns\Model\Record;
use Omnidns\Registry;

$registry = new Registry([new CloudflareFactory()], [
    'cloudflare' => ['factory' => 'cloudflare', 'options' => ['token' => $apiToken, 'account_id' => $accountId]],
]);
$registry->dns('cloudflare')->add('example.org', new Record('CNAME', 'www', 'example.org', options: ['proxied' => true]));
```

Options: `token` (required: an API token with Zone:Read and DNS:Edit, and Account Registrar:Read
for the domains), `account_id` (the Registrar's domains; empty: no `DomainsInterface`),
`base_uri`.

**Reuse**: it wraps [cloudflare/sdk](https://github.com/cloudflare/cloudflare-php) (2.0, June
2026; community-maintained, a subset of the API, framework-free): its `Zones` and `DNS` endpoints
for the records, its adapter for the Registrar's two reads it does not cover. The SDK sends with
Guzzle; `CloudflareFactory` takes another `Cloudflare\API\Adapter\Adapter`.

- **Records**: 100 a page; TTL 1 ("auto") read as null; `proxied` kept in `Record::$options` and
  left as it was by an update unless the new record says otherwise; a record added is never
  proxied unless asked. SRV and CAA are sent as Cloudflare's structured data, from their zone-file
  value (`10 5 5060 sip.example.org`, `0 issue "letsencrypt.org"`). Types outside omnidns's
  (HTTPS, SVCB...) are left out.
- **Domains**: `accounts/{id}/registrar/domains` - term, auto-renewal, statuses.
- **Not here**: availability, registration, renewal and name servers - Cloudflare's API has no
  call for the first three, and a zone's name servers are Cloudflare's own (the registry's
  `availability()`, `registrar()`, `nameservers()` refuse it).

## Terms

Cloudflare's [terms](https://www.cloudflare.com/terms/) and its API's
[rate limits](https://developers.cloudflare.com/fundamentals/api/reference/limits/) (1,200 calls
per five minutes per user).

## Verified, and not

- Verified on recorded answers, written from Cloudflare's API v4 reference (not captured), as
  cloudflare/sdk makes the calls through its adapter: the records page after page, filtered,
  the zone's id asked once, an addition (MX, CAA as data), an update keeping the proxy, a deletion
  by id, a record or a zone Cloudflare does not hold, an error's message, the Registrar's domains;
  `BareTest`; PHPStan at the highest level.
- **Not verified live**: no Cloudflare token at hand.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Cloudflare, Inc.. It reaches their service on behalf of whoever runs it; its MIT licence covers this
package's code only and grants no right to the service or its data. Whoever installs and runs it
is responsible for complying with Cloudflare's terms, for paying the orders it places, for obtaining what
they require (an account, a key, a contract), and for any use made of what it returns. The authors
publish it free of charge and accept no liability for how it is used (see [LICENSE](LICENSE)).

## Licence

MIT.
