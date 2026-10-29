<?php

namespace Omnidns\Cloudflare;

use Cloudflare\API\Adapter\Adapter;
use Cloudflare\API\Adapter\ResponseException;
use Cloudflare\API\Endpoints\DNS;
use Cloudflare\API\Endpoints\EndpointException;
use Cloudflare\API\Endpoints\Zones;
use Omnidns\Capabilities;
use Omnidns\DnsInterface;
use Omnidns\DomainsInterface;
use Omnidns\Exception\InvalidConfigException;
use Omnidns\Exception\ProviderException;
use Omnidns\Exception\UnreachableException;
use Omnidns\Http\Json;
use Omnidns\Http\Redaction;
use Omnidns\Model\DomainInfo;
use Omnidns\Model\DomainName;
use Omnidns\Model\Record;

/**
 * Cloudflare's API v4: a zone's records through cloudflare/sdk (its DNS and
 * Zones endpoints), each with its id and "proxied" kept in its options so an
 * update leaves the proxy as it was; the Registrar's domains of an account,
 * read through the same adapter. Cloudflare's API neither checks nor
 * registers a domain, and a zone's name servers are Cloudflare's own: no
 * AvailabilityInterface, RegistrarInterface, NameserversInterface here.
 */
final class CloudflareProvider implements DnsInterface, DomainsInterface
{
    private const PAGE = 100;

    /** @var array<string, string> */
    private array $zoneIds = [];

    public function __construct(private readonly Adapter $adapter, private readonly ?string $accountId = null)
    {
    }

    public function getName(): string
    {
        return 'cloudflare';
    }

    public function getTitle(): string
    {
        return 'Cloudflare';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(domains: null !== $this->accountId, dns: true, recordTypes: Record::TYPES, terms: [
            'https://www.cloudflare.com/terms/',
            'https://developers.cloudflare.com/fundamentals/api/reference/limits/',
        ]);
    }

    public function records(string $zone, ?string $type = null, ?string $name = null): array
    {
        $zone = DomainName::normalize($zone);
        $id = $this->zoneId($zone);
        $fqdn = null === $name ? '' : (new Record('A', $name, '0.0.0.0'))->fqdn($zone);
        $records = [];
        for ($page = 1; ; ++$page) {
            $answer = $this->call(fn () => (new DNS($this->adapter))->listRecords($id, null === $type ? '' : strtoupper($type), $fqdn, '', $page, self::PAGE), "the records of $zone");
            foreach (Json::list(self::array($answer), 'result') as $data) {
                if (null !== $record = self::record($data, $zone)) {
                    $records[] = $record;
                }
            }
            if ($page >= (Json::int(self::array($answer), 'result_info', 'total_pages') ?? 1)) {
                return $records;
            }
        }
    }

    public function add(string $zone, Record $record): Record
    {
        $zone = DomainName::normalize($zone);
        $id = $this->zoneId($zone);
        $dns = new DNS($this->adapter);
        $this->call(fn () => $dns->addRecord($id, $record->type, $record->fqdn($zone), self::dataOf($record) ? '' : $record->content, $record->ttl ?? 0, (bool) ($record->options['proxied'] ?? false), null === $record->priority ? '' : (string) $record->priority, self::dataOf($record)), "a record of $zone");

        return self::record(self::array($dns->getBody())['result'] ?? null, $zone) ?? $record;
    }

    public function update(string $zone, Record $current, Record $new): Record
    {
        $zone = DomainName::normalize($zone);
        $found = $this->find($zone, $current);
        $details = array_filter([
            'type' => $new->type,
            'name' => $new->fqdn($zone),
            'content' => self::dataOf($new) ? null : $new->content,
            'data' => self::dataOf($new) ?: null,
            'ttl' => $new->ttl ?? 1,
            'priority' => $new->priority,
            // The proxy stays as it was unless the new record says otherwise.
            'proxied' => \in_array($new->type, ['A', 'AAAA', 'CNAME'], true) ? (bool) ($new->options['proxied'] ?? $found->options['proxied'] ?? false) : null,
        ], static fn ($v) => null !== $v);
        $answer = $this->call(fn () => (new DNS($this->adapter))->updateRecordDetails($this->zoneId($zone), (string) $found->id, $details), "a record of $zone");

        return self::record(self::array($answer)['result'] ?? null, $zone) ?? $new->withId($found->id);
    }

    public function delete(string $zone, Record $record): void
    {
        $zone = DomainName::normalize($zone);
        $found = $this->find($zone, $record);
        $this->call(fn () => (new DNS($this->adapter))->deleteRecord($this->zoneId($zone), (string) $found->id), "a record of $zone");
    }

    public function domains(): array
    {
        $domains = [];
        foreach (Json::list($this->get('accounts/'.$this->account().'/registrar/domains', 'the Registrar\'s domains'), 'result') as $data) {
            if (null !== $name = Json::string($data, 'name') ?? Json::string($data, 'id')) {
                $domains[] = $name;
            }
        }

        return $domains;
    }

    public function domain(string $domain): DomainInfo
    {
        $domain = DomainName::normalize($domain);
        $data = Json::get($this->get('accounts/'.$this->account().'/registrar/domains/'.$domain, "the domain $domain"), 'result');
        $auto = Json::get($data, 'auto_renew');

        return new DomainInfo($domain, Json::date($data, 'expires_at'), \is_bool($auto) ? $auto : null, array_values(array_filter(array_map('trim', explode(',', (string) Json::string($data, 'registry_statuses'))))), null, Json::date($data, 'created_at'));
    }

    private function find(string $zone, Record $record): Record
    {
        if (null !== $record->id) {
            return $record;
        }

        return $record->findIn($this->records($zone, $record->type, $record->name)) ?? throw ProviderException::answered('Cloudflare', "a record of $zone", 'no such record');
    }

    private function zoneId(string $zone): string
    {
        return $this->zoneIds[$zone] ??= $this->call(fn () => (new Zones($this->adapter))->getZoneID($zone), "the zone $zone");
    }

    private function account(): string
    {
        return $this->accountId ?? throw new InvalidConfigException('The "cloudflare" provider needs an account_id to read the Registrar\'s domains.');
    }

    /** @return array<mixed> */
    private function get(string $uri, string $what): array
    {
        $response = $this->call(fn () => $this->adapter->get($uri), $what);

        return self::array(json_decode((string) $response->getBody(), true));
    }

    /**
     * Cloudflare's own structured content: SRV and CAA are written as data, from the zone-file value
     * ("10 5 5060 sip.example.org", "0 issue \"letsencrypt.org\"").
     *
     * @return array<string, mixed>
     */
    private static function dataOf(Record $record): array
    {
        $parts = preg_split('~\s+~', trim($record->content), 4) ?: [];
        if ('SRV' === $record->type && 4 === \count($parts)) {
            return ['priority' => (int) $parts[0], 'weight' => (int) $parts[1], 'port' => (int) $parts[2], 'target' => $parts[3]];
        }
        if ('CAA' === $record->type && preg_match('~^(\d+)\s+(\S+)\s+"?(.*?)"?$~', trim($record->content), $m)) {
            return ['flags' => (int) $m[1], 'tag' => $m[2], 'value' => $m[3]];
        }

        return [];
    }

    private static function record(mixed $data, string $zone): ?Record
    {
        $type = Json::string($data, 'type');
        if (null === $type || !\in_array($type, Record::TYPES, true)) {
            return null;
        }
        $ttl = Json::int($data, 'ttl');
        $content = (string) Json::string($data, 'content');
        if ('SRV' === $type && null !== Json::get($data, 'data', 'target')) {
            $content = implode(' ', [Json::int($data, 'data', 'priority'), Json::int($data, 'data', 'weight'), Json::int($data, 'data', 'port'), Json::string($data, 'data', 'target')]);
        }
        $proxied = Json::get($data, 'proxied');

        return new Record($type, Record::relative((string) Json::string($data, 'name'), $zone), $content, 1 === $ttl ? null : $ttl, 'MX' === $type ? Json::int($data, 'priority') : null, Json::string($data, 'id'), \is_bool($proxied) ? ['proxied' => $proxied] : []);
    }

    /** @return array<mixed> */
    private static function array(mixed $value): array
    {
        return \is_array($value) ? $value : (\is_object($value) ? self::array(json_decode((string) json_encode($value), true)) : []);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    private function call(\Closure $call, string $what): mixed
    {
        try {
            return $call();
        } catch (ResponseException $e) {
            $previous = $e->getPrevious();
            $status = $previous instanceof \GuzzleHttp\Exception\RequestException ? $previous->getResponse()?->getStatusCode() : null;
            if (null === $status && $previous instanceof \GuzzleHttp\Exception\GuzzleException) {
                throw self::unreachable($e, $previous, $what);
            }

            // No trace argument: the closure there holds this provider and its adapter, the token in it.
            throw Redaction::withoutArguments(ProviderException::answered('Cloudflare', $what, null === $status ? $e->getMessage() : "$status ({$e->getMessage()})", $status ?? 0));
        } catch (EndpointException $e) {
            throw Redaction::withoutArguments(ProviderException::answered('Cloudflare', $what, $e->getMessage()));
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            // Guzzle's ConnectException, which the SDK's adapter lets through (it is not a RequestException).
            throw self::unreachable($e, $e, $what);
        }
    }

    /**
     * Not reached: the message and the exception with the request's token masked, the previous a
     * copy without the request it carried, no trace keeping arguments.
     */
    private static function unreachable(\Throwable $e, \Throwable $guzzle, string $what): UnreachableException
    {
        $request = $guzzle instanceof \GuzzleHttp\Exception\RequestException || $guzzle instanceof \GuzzleHttp\Exception\ConnectException ? $guzzle->getRequest() : null;
        $secrets = null === $request ? [] : Redaction::secrets((string) $request->getUri(), ['headers' => $request->getHeaders()]);

        return Redaction::withoutArguments(new UnreachableException(\sprintf('Cloudflare could not be reached for %s: %s', $what, Redaction::text($e->getMessage(), $secrets)), 0, Redaction::copy($e, $secrets)));
    }
}
