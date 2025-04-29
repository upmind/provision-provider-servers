<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\OnApp;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Exception\ClientException;
use Throwable;
use Illuminate\Support\Arr;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Helper;
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
use Upmind\ProvisionProviders\Servers\OnApp\Data\Configuration;

class Provider extends Category implements ProviderInterface
{
    protected Configuration $configuration;
    protected ApiClient $apiClient;

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
            ->setName('OnApp')
            ->setDescription('Deploy and manage OnApp virtual servers')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/onapp-logo@2x.png');
    }

    /**
     * @inheritDoc
     */
    public function create(CreateParams $params): ServerInfoResult
    {
        try {
            $serverId = $this->api()->create($params);

            return $this->getServerInfoResult($serverId)->setMessage('Server created successfully!');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function getInfo(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            return $this->getServerInfoResult($params->instance_id)->setMessage('Server info obtained');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    protected function getServerInfoResult($serverId): ServerInfoResult
    {
        $info = $this->api()->getServerInfo($serverId);

        return ServerInfoResult::create($info);
    }

    /**
     * @inheritDoc
     */
    public function getConnection(GetConnectionParams $params): ConnectionResult
    {
        try {
            $info = $this->getServerInfoResult($params->instance_id);

            if (!$info->ip_address) {
                throw $this->errorResult('IP address not found');
            }

            $password = $this->api()->getPassword($params->instance_id);

            return ConnectionResult::create()
                ->setMessage('SSH command generated')
                ->setType(ConnectionResult::TYPE_SSH)
                ->setCommand(sprintf('ssh root@%s', $info['ip_address']))
                ->setPassword($password);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function changeRootPassword(ChangeRootPasswordParams $params): ServerInfoResult
    {
        try {
            $this->api()->changePassword($params->instance_id, $params->root_password);

            return $this->getServerInfoResult($params->instance_id)->setMessage('Root password changed');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function resize(ResizeParams $params): ServerInfoResult
    {
        try {
            $info = $this->getServerInfoResult($params->instance_id);

            if ($info->state == 'On') {
                throw $this->errorResult('Resize not available while server is running');
            }

            $this->api()->resize($params->instance_id, $params);

            return $this->getServerInfoResult($params->instance_id)->setMessage('Server is resizing');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function reinstall(ReinstallParams $params): ServerInfoResult
    {
        try {
            $this->api()->rebuildServer($params->instance_id, $params->image);

            return $this->getServerInfoResult($params->instance_id)->setMessage('Server rebuilding with fresh image/template');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function reboot(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            $this->api()->reboot($params->instance_id);

            return $this->getServerInfoResult($params->instance_id)->setMessage('Server is rebooting');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function shutdown(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            $info = $this->getServerInfoResult($params->instance_id);

            if ($info->state === 'Off') {
                return $info->setMessage('Virtual server already off');
            }

            $this->api()->shutdown($params->instance_id);

            return $info->setMessage('Server is shutting down')->setState('Stopping');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function powerOn(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            $info = $this->getServerInfoResult($params->instance_id);

            if ($info->state == 'On') {
                return $info->setMessage('Virtual server already on');
            }

            $this->api()->start($params->instance_id);

            return $info->setMessage('Server is booting')->setState('Starting');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function suspend(ServerIdentifierParams $params): ServerInfoResult
    {
        return $this->shutdown($params)
            ->setSuspended(true);
    }

    /**
     * @inheritDoc
     */
    public function unsuspend(ServerIdentifierParams $params): ServerInfoResult
    {
        return $this->powerOn($params)
            ->setSuspended(false);
    }


    /**
     * @inheritDoc
     */
    public function attachRecoveryIso(ServerIdentifierParams $params): ServerInfoResult
    {
        throw $this->errorResult('Operation not supported');
    }

    /**
     * @inheritDoc
     */
    public function detachRecoveryIso(ServerIdentifierParams $params): ServerInfoResult
    {
        throw $this->errorResult('Operation not supported');
    }

    /**
     * @inheritDoc
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

    protected function api(): ApiClient
    {
        return $this->apiClient ??= new ApiClient(
            $this->configuration,
            $this->getGuzzleHandlerStack(boolval($this->configuration->debug))
        );
    }

    /**
     * @return no-return
     * @throws ProvisionFunctionError
     * @throws Throwable
     */
    protected function handleException(Throwable $e): void
    {
        if ($e instanceof ClientException) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $reason = $response->getReasonPhrase();
                $responseBody = $response->getBody()->__toString();
                $responseData = json_decode($responseBody, true);

                $messages = [];
                $errors = $responseData['errors'];
                foreach ($errors as $key => $value) {
                    if (is_array($value)) {
                        $messages[] = $key . ': ' . implode(', ', $value);
                    } else {
                        $messages[] = $value;
                    }
                }

                if ($messages) {
                    $errorMessage = implode(', ', $messages);
                }

                throw $this->errorResult(
                    sprintf('Provider API error: %s', $errorMessage ?? $reason ?? null),
                    [],
                    ['response_data' => $responseData ?? null],
                    $e
                );
            }
        }

        throw $e;
    }
}
