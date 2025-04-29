<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\OnApp;

use GuzzleHttp\Exception\ClientException;
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
use Upmind\ProvisionProviders\Servers\OnApp\Data\Configuration;

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
            ->setName('OnApp')
            ->setDescription('Deploy and manage OnApp virtual servers')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/onapp-logo@2x.png');
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
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function getServerInfoResult($serverId): ServerInfoResult
    {
        $info = $this->api()->getServerInfo($serverId);

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
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
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
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function resize(ResizeParams $params): ServerInfoResult
    {
        try {
            $info = $this->getServerInfoResult($params->instance_id);

            if ($info->state === 'On') {
                $this->errorResult('Resize not available while server is running');
            }

            $this->api()->resize($params->instance_id, $params);

            return $this->getServerInfoResult($params->instance_id)->setMessage('Server is resizing');
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
            $this->api()->rebuildServer($params->instance_id, $params->image);

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
            $this->api()->reboot($params->instance_id);

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
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function powerOn(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            $info = $this->getServerInfoResult($params->instance_id);

            if ($info->state === 'On') {
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
        if (($e instanceof ClientException) && $e->hasResponse()) {
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

            $errorResultMessage = $errorMessage ?? null;

            // If errorMessage was not set, and error result message is still null, set the reason.
            if ($errorResultMessage === null) {
                $errorResultMessage = $reason;
            }

            $this->errorResult(
                sprintf('Provider API error: %s', $errorResultMessage),
                [],
                ['response_data' => $responseData ?? null],
                $e
            );
        }

        throw $e;
    }
}
