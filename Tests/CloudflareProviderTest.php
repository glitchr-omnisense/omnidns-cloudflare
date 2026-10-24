<?php

namespace Omnidns\Cloudflare\Tests;

use Cloudflare\API\Auth\None;
use Omnidns\Cloudflare\CloudflareFactory;
use Omnidns\Cloudflare\CloudflareProvider;
use Omnidns\Exception\InvalidConfigException;
use Omnidns\Exception\NotSupportedException;
use Omnidns\Exception\ProviderException;
use Omnidns\Model\Record;
use Omnidns\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Cloudflare's answers recorded, written from its API v4 reference (no
 * token at hand: not captured), each call checked as cloudflare/sdk makes
 * it through its adapter.
 */
final class CloudflareProviderTest extends TestCase
{
    private RecordingAdapter $adapter;

    public function testTheZonesRecordsPageAfterPage(): void
    {
        $provider = $this->provider([
            'GET zones?page=1&per_page=20&match=all&name=example.org' => self::ok([['id' => 'zone-1', 'name' => 'example.org', 'status' => 'active', 'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com']]]),
            'GET zones/zone-1/dns_records?page=1&per_page=100&match=all' => self::ok([
                ['id' => 'r1', 'type' => 'A', 'name' => 'example.org', 'content' => '192.0.2.1', 'proxied' => true, 'ttl' => 1],
                ['id' => 'r2', 'type' => 'MX', 'name' => 'example.org', 'content' => 'mx.example.net', 'priority' => 10, 'proxied' => false, 'ttl' => 3600],
            ], 2),
            'GET zones/zone-1/dns_records?page=2&per_page=100&match=all' => self::ok([
                ['id' => 'r3', 'type' => 'SRV', 'name' => '_sip._tcp.example.org', 'content' => '5 5060 sip.example.org', 'priority' => 10, 'data' => ['priority' => 10, 'weight' => 5, 'port' => 5060, 'target' => 'sip.example.org'], 'ttl' => 300],
                ['id' => 'r4', 'type' => 'HTTPS', 'name' => 'example.org', 'content' => '1 . alpn="h3,h2"', 'ttl' => 300],
            ], 2),
        ]);
        $records = $provider->records('example.org');

        self::assertSame([['A', '', '192.0.2.1', null, null, 'r1'], ['MX', '', 'mx.example.net', 3600, 10, 'r2'], ['SRV', '_sip._tcp', '10 5 5060 sip.example.org', 300, null, 'r3']], array_map(static fn (Record $r) => [$r->type, $r->name, $r->content, $r->ttl, $r->priority, $r->id], $records), 'TTL 1 is "auto"; HTTPS left out');
        self::assertSame(['proxied' => true], $records[0]->options);
    }

    public function testRecordsOfOneNameAndType(): void
    {
        $provider = $this->provider([
            'GET zones?page=1&per_page=20&match=all&name=example.org' => self::ok([['id' => 'zone-1']]),
            'GET zones/zone-1/dns_records?page=1&per_page=100&match=all&type=TXT&name=_dmarc.example.org' => self::ok([['id' => 'r5', 'type' => 'TXT', 'name' => '_dmarc.example.org', 'content' => '"v=DMARC1; p=none"', 'ttl' => 1]]),
        ]);

        self::assertSame('_dmarc', $provider->records('example.org', 'txt', '_dmarc')[0]->name);
        $provider->records('example.org', 'TXT', '_dmarc');
        self::assertCount(1, array_filter($this->adapter->calls, static fn (array $c) => str_starts_with($c['call'], 'GET zones?')), 'the zone\'s id asked once');
    }

    public function testARecordAdded(): void
    {
        $provider = $this->provider([
            'GET zones?page=1&per_page=20&match=all&name=example.org' => self::ok([['id' => 'zone-1']]),
            'POST zones/zone-1/dns_records' => self::ok(['id' => 'r6', 'type' => 'MX', 'name' => 'example.org', 'content' => 'mx.example.net', 'priority' => 10, 'proxied' => false, 'ttl' => 1]),
        ]);
        $record = $provider->add('example.org', new Record('MX', '', 'mx.example.net', priority: 10));

        self::assertSame('r6', $record->id);
        self::assertSame(['type' => 'MX', 'name' => 'example.org', 'content' => 'mx.example.net', 'proxied' => false, 'priority' => 10], $this->adapter->calls[1]['data'], 'never proxied unless asked');
    }

    public function testACaaRecordAddedAsData(): void
    {
        $provider = $this->provider([
            'GET zones?page=1&per_page=20&match=all&name=example.org' => self::ok([['id' => 'zone-1']]),
            'POST zones/zone-1/dns_records' => self::ok(['id' => 'r7', 'type' => 'CAA', 'name' => 'example.org', 'content' => '0 issue "letsencrypt.org"', 'ttl' => 300]),
        ]);
        $provider->add('example.org', new Record('CAA', '', '0 issue "letsencrypt.org"', 300));

        self::assertSame(['type' => 'CAA', 'name' => 'example.org', 'content' => '', 'proxied' => false, 'ttl' => 300, 'data' => ['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org']], $this->adapter->calls[1]['data']);
    }

    public function testAnUpdateKeepsTheProxy(): void
    {
        $provider = $this->provider([
            'GET zones?page=1&per_page=20&match=all&name=example.org' => self::ok([['id' => 'zone-1']]),
            'GET zones/zone-1/dns_records?page=1&per_page=100&match=all&type=A&name=example.org' => self::ok([['id' => 'r1', 'type' => 'A', 'name' => 'example.org', 'content' => '192.0.2.1', 'proxied' => true, 'ttl' => 1]]),
            'PUT zones/zone-1/dns_records/r1' => self::ok(['id' => 'r1', 'type' => 'A', 'name' => 'example.org', 'content' => '192.0.2.2', 'proxied' => true, 'ttl' => 1]),
        ]);
        $record = $provider->update('example.org', new Record('A', '', '192.0.2.1'), new Record('A', '', '192.0.2.2'));

        self::assertSame(['r1', '192.0.2.2', ['proxied' => true]], [$record->id, $record->content, $record->options]);
        self::assertSame(['type' => 'A', 'name' => 'example.org', 'content' => '192.0.2.2', 'ttl' => 1, 'proxied' => true], $this->adapter->calls[2]['data']);
    }

    public function testADeletionByIdAndARecordCloudflareDoesNotHold(): void
    {
        $provider = $this->provider([
            'GET zones?page=1&per_page=20&match=all&name=example.org' => self::ok([['id' => 'zone-1']]),
            'DELETE zones/zone-1/dns_records/r2' => self::ok(['id' => 'r2']),
            'GET zones/zone-1/dns_records?page=1&per_page=100&match=all&type=A&name=example.org' => self::ok([]),
        ]);
        $provider->delete('example.org', new Record('MX', '', 'mx.example.net', id: 'r2'));
        self::assertSame('DELETE zones/zone-1/dns_records/r2', $this->adapter->calls[1]['call']);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Cloudflare answered no such record for a record of example.org.');
        $provider->delete('example.org', new Record('A', '', '192.0.2.9'));
    }

    public function testAZoneCloudflareDoesNotServe(): void
    {
        $provider = $this->provider(['GET zones?page=1&per_page=20&match=all&name=example.org' => self::ok([])]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Cloudflare answered Could not find zones with specified name. for the zone example.org.');
        $provider->records('example.org');
    }

    public function testAnErrorCloudflareAnswers(): void
    {
        $provider = $this->provider(['GET zones?page=1&per_page=20&match=all&name=example.org' => [403, ['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']], 'messages' => [], 'result' => null]]]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Cloudflare answered 403 (Authentication error) for the zone example.org.');
        $provider->records('example.org');
    }

    public function testTheRegistrarsDomains(): void
    {
        $provider = $this->provider([
            'GET accounts/acc-1/registrar/domains' => self::ok([['id' => 'ea95132c15732412d22c1476fa83f27a', 'name' => 'example.org', 'expires_at' => '2027-03-01T22:47:20Z', 'auto_renew' => true, 'locked' => true]]),
            'GET accounts/acc-1/registrar/domains/example.org' => self::ok(['name' => 'example.org', 'created_at' => '2020-03-01T22:47:20Z', 'expires_at' => '2027-03-01T22:47:20Z', 'auto_renew' => true, 'registry_statuses' => 'clienttransferprohibited, clientdeleteprohibited']),
        ], 'acc-1');

        self::assertSame(['example.org'], $provider->domains());
        $info = $provider->domain('example.org');
        self::assertSame(['2027-03-01', true, ['clienttransferprohibited', 'clientdeleteprohibited']], [$info->expiresAt?->format('Y-m-d'), $info->autoRenew, $info->status]);
    }

    public function testNoAccountNoDomains(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->provider([])->domains();
    }

    public function testADnsHostAndNoRegistrar(): void
    {
        $adapter = new RecordingAdapter(new None(), '');
        $registry = new Registry([new CloudflareFactory($adapter)], ['cf' => ['factory' => 'cloudflare', 'options' => ['token' => 't']]]);

        self::assertSame(['dns'], $registry->get('cf')->capabilities()->contracts());
        $this->expectException(NotSupportedException::class);
        $registry->availability('cf');
    }

    /** @param array<string, mixed> $routes */
    private function provider(array $routes, ?string $accountId = null): CloudflareProvider
    {
        $this->adapter = new RecordingAdapter(new None(), '');
        $this->adapter->routes = $routes;

        return new CloudflareProvider($this->adapter, $accountId);
    }

    /** @return array<string, mixed> */
    private static function ok(mixed $result, int $pages = 1): array
    {
        return ['success' => true, 'errors' => [], 'messages' => [], 'result' => $result, 'result_info' => ['page' => 1, 'per_page' => 100, 'total_pages' => $pages, 'count' => \is_array($result) ? \count($result) : 1, 'total_count' => \is_array($result) ? \count($result) : 1]];
    }
}
