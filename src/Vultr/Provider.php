<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\Vultr;

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
use Upmind\ProvisionProviders\Servers\Vultr\Data\Configuration;

/**
 * Empty provider for demonstration purposes.
 */
class Provider extends Category implements ProviderInterface
{
    protected Configuration $configuration;
    protected ApiClient|null $apiClient = null;

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
            ->setName('Vultr')
            ->setLogoUrl('https://www.vultr.com/media/logo_onwhite.svg')
            ->setDescription('Deploy and manage Vultr Cloud Compute virtual servers');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function create(CreateParams $params): ServerInfoResult
    {
        $this->errorResult('Not implemented');
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
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getConnection(ServerIdentifierParams $params): ConnectionResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function changeRootPassword(ChangeRootPasswordParams $params): ServerInfoResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function resize(ResizeParams $params): ServerInfoResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function reinstall(ReinstallParams $params): ServerInfoResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function reboot(ServerIdentifierParams $params): ServerInfoResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function shutdown(ServerIdentifierParams $params): ServerInfoResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function powerOn(ServerIdentifierParams $params): ServerInfoResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function terminate(ServerIdentifierParams $params): EmptyResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function suspend(ServerIdentifierParams $params): ServerInfoResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function unsuspend(ServerIdentifierParams $params): ServerInfoResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function attachRecoveryIso(ServerIdentifierParams $params): ServerInfoResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function detachRecoveryIso(ServerIdentifierParams $params): ServerInfoResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function getServerInfoResult($serverId): ServerInfoResult
    {
        $server = $this->api()->getInstance($serverId);

        // Format ServerInfoResult using server data
        return ServerInfoResult::create()
            ->setInstanceId($server->id)
            ->setState($server->power_status)
            ->setLabel($server->label)
            ->setHostname($server->hostname)
            ->setIpAddress($server->main_ip)
            ->setImage($server->os)
            ->setSize('large') // Need to parse the plan
            ->setLocation('London'); // Need to fetch the region
    }

    /**
     * Get a Guzzle HTTP client instance.
     */
    protected function api(): ApiClient
    {
        if (is_a($this->apiClient, ApiClient::class)) {
            return $this->apiClient;
        }

        $client = new Client([
            'handler' => $this->getGuzzleHandlerStack(),
            'base_uri' => 'https://api.vultr.com/v2/',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->configuration->api_token,
            ],
        ]);

        $this->apiClient = new ApiClient($client);

        return $this->apiClient;
    }
}
