<?php

namespace Omnidns\Cloudflare;

use Cloudflare\API\Adapter\Adapter;
use Cloudflare\API\Adapter\Guzzle;
use Cloudflare\API\Auth\APIToken;
use Omnidns\Config;
use Omnidns\ProviderFactory;
use Omnidns\ProviderInterface;

/**
 * Cloudflare, from its options:
 *
 *   token       an API token (required) with Zone:Read and DNS:Edit on the zones it manages, and
 *               Account Registrar:Read for the Registrar's domains
 *   account_id  the account whose Registrar domains are read (DomainsInterface); empty: no domains
 *   base_uri    https://api.cloudflare.com/client/v4/
 *
 * cloudflare/sdk sends the calls, through its Guzzle adapter - or the adapter given to this factory
 * (a test's, a proxy's).
 */
final class CloudflareFactory extends ProviderFactory
{
    public function __construct(private readonly ?Adapter $adapter = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnidns.factory_name' => 'cloudflare',
            'omnidns.factory_title' => 'Cloudflare',
            'omnidns.required_options' => ['token'],
            'account_id' => '',
            'base_uri' => 'https://api.cloudflare.com/client/v4/',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        return new CloudflareProvider($this->adapter ?? new Guzzle(new APIToken((string) $c->string('token')), $c->string('base_uri')), $c->string('account_id'));
    }
}
