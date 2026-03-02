<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\HetznerRobot;

use GuzzleHttp\Client;
use Throwable;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionProviders\Servers\Category;
use Upmind\ProvisionProviders\Servers\Data\ChangeRootPasswordParams;
use Upmind\ProvisionProviders\Servers\Data\CreateParams;
use Upmind\ProvisionProviders\Servers\Data\EmptyResult;
use Upmind\ProvisionProviders\Servers\Data\GetConnectionParams;
use Upmind\ProvisionProviders\Servers\Data\ReinstallParams;
use Upmind\ProvisionProviders\Servers\Data\ResizeParams;
use Upmind\ProvisionProviders\Servers\Data\ServerIdentifierParams;
use Upmind\ProvisionProviders\Servers\Data\ServerInfoResult;
use Upmind\ProvisionProviders\Servers\Data\ConnectionResult;
use Upmind\ProvisionProviders\Servers\HetznerRobot\Data\Configuration;


class Provider extends Category implements ProviderInterface
{
    protected Configuration $configuration;
    protected ?RobotApiClient $robotApiClient = null;

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
            ->setName('Hetzner Robot')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/hetzner-robot-logo.png')
            ->setDescription('Deploy and manage Hetzner Robot servers');
    }

    /**
     * @inheritDoc
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function create(CreateParams $params): ServerInfoResult
    {
        try {
            $transactionId = $this->robotApi()->create($params);

            // Poll for completion
            $maxWaitSeconds = 600;
            $interval = 30;
            $waited = 0;

            $serverId = null;

            while ($waited < $maxWaitSeconds) {
                $serverId = $this->robotApi()->checkTransaction($transactionId);

                if (!empty($serverId)) {
                    break;
                }

                sleep($interval);
                $waited += $interval;
            }

            if ($serverId == null) {

                $info = [
                    'instance_id' => $transactionId,
                    'state' => 'In progress',
                    'label' => $params->image,
                    'image' => $params->image,
                    'size' => $params->size,
                    'location' => $params->location
                ];
                return ServerInfoResult::create($info)->setMessage('Transaction in progress!');
            }

            $this->robotApi()->updateServerName($serverId, $params->label);

            return $this->getServerInfoResult($serverId)->setMessage('Server created successfully!');

        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getInfo(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            return $this->getServerInfoResult($params->instance_id)->setMessage('Server info obtained');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function getServerInfoResult($serverId): ServerInfoResult
    {
        $info = $this->robotApi()->getServerInfo($serverId);

        return ServerInfoResult::create($info);
    }

    /**
     * @inheritDoc
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getConnection(GetConnectionParams $params): ConnectionResult
    {
        try {
            $info = $this->getServerInfoResult($params->instance_id);

            if (!$info->ip_address) {
                $this->errorResult('IP address not found');
            }

            return ConnectionResult::create()
                ->setMessage('SSH command generated')
                ->setType(ConnectionResult::TYPE_SSH)
                ->setCommand(sprintf('ssh root@%s', $info['ip_address']));
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function changeRootPassword(ChangeRootPasswordParams $params): ServerInfoResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @inheritDoc
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function resize(ResizeParams $params): ServerInfoResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @inheritDoc
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function reinstall(ReinstallParams $params): ServerInfoResult
    {
        try {
            $this->robotApi()->rebuildServer($params->instance_id, $params->image);

            return $this->getServerInfoResult($params->instance_id)->setMessage('Server rebuilding with fresh image/template');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function reboot(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            $this->robotApi()->reboot($params->instance_id);

            return $this->getServerInfoResult($params->instance_id)->setMessage('Server is rebooting');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function shutdown(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            $info = $this->getServerInfoResult($params->instance_id);

            if ($info->state === 'off') {
                return $info->setMessage('Virtual server already off');
            }

            $this->robotApi()->togglePower($params->instance_id);

            return $info->setMessage('Server is shutting down')->setState('Stopping');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function powerOn(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            $info = $this->getServerInfoResult($params->instance_id);

            if ($info->state === 'on') {
                return $info->setMessage('Virtual server already on');
            }

            $this->robotApi()->togglePower($params->instance_id);

            return $info->setMessage('Server is booting')->setState('Starting');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function suspend(ServerIdentifierParams $params): ServerInfoResult
    {
        return $this->shutdown($params)->setSuspended(true);
    }

    /**
     * @inheritDoc
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function unsuspend(ServerIdentifierParams $params): ServerInfoResult
    {
        return $this->powerOn($params)->setSuspended(false);
    }


    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function attachRecoveryIso(ServerIdentifierParams $params): ServerInfoResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function detachRecoveryIso(ServerIdentifierParams $params): ServerInfoResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @inheritDoc
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function terminate(ServerIdentifierParams $params): EmptyResult
    {
        try {
            $this->getServerInfoResult($params->instance_id);

            $this->robotApi()->destroy($params->instance_id);

            return EmptyResult::create()->setMessage('Server is deleting');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    protected function robotApi(): RobotApiClient
    {
        if ($this->robotApiClient === null) {
            $this->robotApiClient = new RobotApiClient(
                new Client([
                    'base_uri' => 'https://robot-ws.your-server.de',
                    'headers' => [
                        'User-Agent' => 'Upmind/ProvisionProviders/Servers/HetznerRobotApi',
                    ],
                    'auth' => [$this->configuration->api_login, $this->configuration->api_password],
                    'connect_timeout' => 10,
                    'timeout' => 30,
                    'handler' => $this->getGuzzleHandlerStack(),
                ]),
                $this->configuration,
            );
        }

        return $this->robotApiClient;
    }


    /**
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function handleException(Throwable $e): void
    {
        throw $e;
    }
}
