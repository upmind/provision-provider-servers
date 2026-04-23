<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\TwentyI;

use ErrorException;
use Illuminate\Support\Arr;
use Throwable;
use TwentyI\API\CurlException;
use TwentyI\API\HTTPException;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
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
use Upmind\ProvisionProviders\Servers\TwentyI\Data\Configuration;

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
            ->setName('20i')
            ->setDescription('Deploy and manage 20i private virtual servers')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/20i-logo@2x.png');
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
    public function getConnection(ServerIdentifierParams $params): ConnectionResult
    {
        try {
            $vnc = $this->api()->getVNC($params->instance_id);

            return ConnectionResult::create()
                ->setMessage('SSH command generated')
                ->setType(ConnectionResult::TYPE_SSH)
                ->setCommand(sprintf('ssh root@%s', $vnc['ip_address']))
                ->setPassword($vnc['password']);
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

            if ($info->state === 'shut off') {
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

            if ($info->state === 'running') {
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
        try {
            $info = $this->getServerInfoResult($params->instance_id);

            if ($info->suspended) {
                $this->errorResult('Virtual server already off');
            }

            $this->api()->suspend($params->instance_id);

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
    public function unsuspend(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            $info = $this->getServerInfoResult($params->instance_id);

            if (!$info->suspended) {
                return $info->setMessage('Virtual server already on');
            }

            $this->api()->unsuspend($params->instance_id);

            return $info->setMessage('Server is booting')->setState('Stopping');
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
        $this->errorResult('Operation not supported');
    }


    /**
     * @return ApiClient
     */
    protected function api(): ApiClient
    {
        return $this->apiClient ??= new ApiClient(
            $this->configuration,
            $this->getLogger()
        );
    }


    /**
     * Wrap StackCP reseller api exceptions in a ProvisionFunctionError with the
     * given message and data, if appropriate. Otherwise re-throws original error.
     *
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function handleException(Throwable $e, ?string $errorMessage = null, array $data = [], array $debug = []): void
    {
        $errorMessage = $errorMessage ?? 'StackCP API request failed';

        if ($this->exceptionIs401($e)) {
            $errorMessage = 'API authentication error';
        }

        if ($this->exceptionIs404($e)) {
            $errorMessage .= ' (not found)';
        }

        if ($this->exceptionIs409($e)) {
            $errorMessage .= ' (conflict)';
        }

        if ($this->exceptionIsTimeout($e)) {
            $errorMessage .= ' (request timed out)';
        }

        if ($e instanceof HTTPException) {
            if (!empty($e->decodedBody->error->message)) {
                $errorMessage .= ': ' . $e->decodedBody->error->message;
            }

            $data['request_url'] = $e->fullURL;
            $data['response_data'] = $e->decodedBody;
        }

        if ($e instanceof ProvisionFunctionError) {
            // merge any additional error data / debug data
            $data = array_merge($e->getData(), $data);
            $debug = array_merge($e->getDebug(), $debug);

            $e = $e->withData($data)
                ->withDebug($debug);
        }

        if ($this->shouldWrapException($e)) {
            throw (new ProvisionFunctionError($errorMessage, 0, $e))
                ->withData($data)
                ->withDebug($debug);
        }

        throw $e;
    }

    /**
     * Determine whether the given exception should be wrapped in a
     * ProvisionFunctionError.
     */
    protected function shouldWrapException(Throwable $e): bool
    {
        return $e instanceof HTTPException
            || $this->exceptionIs401($e)
            || $this->exceptionIs404($e)
            || $this->exceptionIs409($e)
            || $this->exceptionIsTimeout($e);
    }

    /**
     * Determine whether the given exception was thrown due to a 401 response
     * from the stack cp api.
     */
    protected function exceptionIs401(Throwable $e): bool
    {
        return $e instanceof HTTPException
            && preg_match('/(^|[^\d])401([^\d]|$)/', $e->getMessage());
    }

    /**
     * Determine whether the given exception was thrown due to a 404 response
     * from the stack cp api.
     */
    protected function exceptionIs404(Throwable $e): bool
    {
        return $e instanceof ErrorException
            && preg_match('/(^|[^\d])404([^\d]|$)/', $e->getMessage());
    }

    /**
     * Determine whether the given exception was thrown due to a 409 response
     * from the stack cp api.
     */
    protected function exceptionIs409(Throwable $e): bool
    {
        return $e instanceof HTTPException
            && preg_match('/(^|[^\d])409([^\d]|$)/', $e->getMessage());
    }

    /**
     * Determine whether the given exception was thrown due to a request timeout.
     */
    protected function exceptionIsTimeout(Throwable $e): bool
    {
        return $e instanceof CurlException
            && preg_match('/(^|[^\w])timed out([^\w]|$)/i', $e->getMessage());
    }

}
