<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\Virtfusion;

use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Arr;
use Throwable;
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
use Upmind\ProvisionProviders\Servers\Data\VncConnection;
use Upmind\ProvisionProviders\Servers\Virtfusion\Data\Configuration;

class Provider extends Category implements ProviderInterface
{
    protected Configuration $configuration;
    protected ?ApiClient $apiClient = null;

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
            ->setName('Virtfusion')
            ->setDescription('Deploy and manage Virtfusion virtual servers')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/virtfusion-logo.svg');
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
        if (!Arr::has($params, 'size')) {
            $this->errorResult('size field is required!');
        }

        try {
            $serverId = $this->api()->create($params);

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
     * @param string|int $serverId
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function getServerInfoResult($serverId): ServerInfoResult
    {
        return $this->api()->getServerInfo($serverId);
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
            $server = $this->api()->retrieveServer((int)$params->instance_id);

            if (!$server['owner']['admin'] && !empty($server['owner']['extRelationId'])) {
                // return sso login url
                $auth = $this->api()->getAuthenticationTokens($server['owner']['extRelationId'], $server['id']);

                return ConnectionResult::create()
                    ->setMessage('Login URL generated')
                    ->setType(ConnectionResult::TYPE_REDIRECT)
                    ->setRedirectUrl(sprintf('https://%s/%s', $this->configuration->hostname, ltrim($auth['endpoint_complete'], '/')));
            }

            $vnc = $this->api()->getVNC($params->instance_id);

            return ConnectionResult::create()
                ->setMessage('VNC connection enabled')
                ->setType(ConnectionResult::TYPE_VNC)
                ->setVncConnection(
                    VncConnection::create()
                        ->setHost($vnc['hostname'] ?: $vnc['ip'])
                        ->setPort($vnc['port'])
                        ->setPassword($vnc['password'])
                );
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
        try {
            $this->api()->resetPassword($params->instance_id);

            return $this->getServerInfoResult($params->instance_id)->setMessage('Root password has been updated');
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
    public function resize(ResizeParams $params): ServerInfoResult
    {
        if (!Arr::has($params, 'size')) {
            $this->errorResult('Size field is required!');
        }

        try {
            $response = $this->api()->changePackage($params->instance_id, $params->size);

            $info = $this->getServerInfoResult($params->instance_id)
                ->setMessage('Server is resizing');
            return $info->setData(array_merge($info->getData(), ['response_data' => $response]));
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
    public function reinstall(ReinstallParams $params): ServerInfoResult
    {
        try {
            if (!is_numeric($params->image)) {
                $image = $this->api()->getServerImage($params->instance_id, $params->image);
                $imageId = $image['id'];
            } else {
                $imageId = (int)$params->image;
            }

            $info = $this->api()->getServerInfo($params->instance_id);

            $sshKeyIds = $this->api()->getUserSshKeyIds((int)$info->customer_identifier);
            $this->api()->buildServer((int)$params->instance_id, $info['hostname'] ?? null, $imageId, $sshKeyIds);

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
            $info = $this->getServerInfoResult($params->instance_id);

            if ($info->state == 'failed') {
                $this->errorResult('Virtual server is not operational.');
            }

            $this->api()->power($params->instance_id, 'restart');

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

            if ($info->state == 'failed') {
                $this->errorResult('Virtual server is not operational.');
            }

            $this->api()->power($params->instance_id, 'shutdown');

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

            if ($info->state == 'failed') {
                $this->errorResult('Virtual server is not operational.');
            }

            $this->api()->power($params->instance_id, 'boot');

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
        try {
            $info = $this->getServerInfoResult($params->instance_id);

            if ($info->suspended) {
                return $info->setMessage('Virtual server already suspended');
            }

            $this->api()->suspend($params->instance_id);

            return $info->setMessage('Server suspending')->setSuspended(true);
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
    public function unsuspend(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            $info = $this->getServerInfoResult($params->instance_id);

            if (!$info->suspended) {
                return $info->setMessage('Virtual server already unsuspended');
            }

            $this->api()->unsuspend($params->instance_id);

            return $info->setMessage('Server un-suspending')->setSuspended(false);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
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

            $this->api()->destroy($params->instance_id);

            return EmptyResult::create()->setMessage('Server is deleting');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @return ApiClient
     */
    protected function api(): ApiClient
    {
        return $this->apiClient ??= new ApiClient(
            $this->configuration,
            $this->getGuzzleHandlerStack()
        );
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
