<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\Example;

use GuzzleHttp\Client;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionProviders\Servers\Category;
use Upmind\ProvisionProviders\Servers\Data\ChangeRootPasswordParams;
use Upmind\ProvisionProviders\Servers\Data\CreateParams;
use Upmind\ProvisionProviders\Servers\Data\EmptyResult;
use Upmind\ProvisionProviders\Servers\Data\ReinstallParams;
use Upmind\ProvisionProviders\Servers\Data\ResizeParams;
use Upmind\ProvisionProviders\Servers\Data\ServerIdentifierParams;
use Upmind\ProvisionProviders\Servers\Data\ServerInfoResult;
use Upmind\ProvisionProviders\Servers\Data\ConnectionResult;
use Upmind\ProvisionProviders\Servers\Data\GetConnectionParams;
use Upmind\ProvisionProviders\Servers\Example\Data\Configuration;

/**
 * Empty provider for demonstration purposes.
 */
class Provider extends Category implements ProviderInterface
{
    protected Configuration $configuration;
    protected Client $client;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * @inheritDoc
     */
    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Example Provider')
            // ->setLogoUrl('https://example.com/logo.png')
            ->setDescription('Empty provider for demonstration purposes');
    }

    /**
     * @inheritDoc
     */
    public function create(CreateParams $params): ServerInfoResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function getInfo(ServerIdentifierParams $params): ServerInfoResult
    {
        // $serverData = $this->client()->get(sprintf('servers/' . $params->instance_id));

        return ServerInfoResult::create()
            ->setInstanceId($params->instance_id)
            ->setState('running')
            ->setLabel('Example Server')
            ->setHostname('server.example.com')
            ->setIpAddress('123.123.123.123')
            ->setImage('Ubuntu 20.04')
            ->setSize('large')
            ->setLocation('London');
    }

    /**
     * @inheritDoc
     */
    public function getConnection(GetConnectionParams $params): ConnectionResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function changeRootPassword(ChangeRootPasswordParams $params): ServerInfoResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function resize(ResizeParams $params): ServerInfoResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function reinstall(ReinstallParams $params): ServerInfoResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function reboot(ServerIdentifierParams $params): ServerInfoResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function shutdown(ServerIdentifierParams $params): ServerInfoResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function powerOn(ServerIdentifierParams $params): ServerInfoResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function terminate(ServerIdentifierParams $params): EmptyResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * Get a Guzzle HTTP client instance.
     */
    protected function client(): Client
    {
        return $this->client ??= new Client([
            'handler' => $this->getGuzzleHandlerStack(boolval($this->configuration->debug)),
            'base_uri' => 'https://example.com/api/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->configuration->api_token,
            ],
        ]);
    }
}
