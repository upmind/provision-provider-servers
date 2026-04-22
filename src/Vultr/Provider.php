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
        try {
            $instance_id = $this->api()->createInstance($params);

            return $this->getServerInfoResult($instance_id)
                ->setMessage('Server is being created');
            
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function getInfo(ServerIdentifierParams $params): ServerInfoResult
    {
        return $this->getServerInfoResult($params->instance_id);
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getConnection(ServerIdentifierParams $params): ConnectionResult
    {
        $server = $this->api()->getInstance($params->instance_id);

        return ConnectionResult::create()
            ->setMessage('Control panel URL generated')
            ->setType(ConnectionResult::TYPE_REDIRECT)
            ->setRedirectUrl($server->kvm);
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function changeRootPassword(ChangeRootPasswordParams $params): ServerInfoResult
    {
        //Unable to implement; Process to change root password includes booting server into single-user mode
        //and running a series of commands to change the password. This is not possible with Vultr's API.
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function resize(ResizeParams $params): ServerInfoResult
    {
        //resize up, but not down
        try {
            $available_plans = $this->api()->getAvailableInstanceUpgradePlans($params->instance_id);

            if (in_array($params->size, $available_plans)) {
                $info = $this->getServerInfoResult($params->instance_id);

                // requested size is an available upgrade plan for the instance
                $instance_id = $this->api()->upgradeInstancePlan($params->instance_id, $params->size);
                $message = 'Server is resizing';
            } else {
                // requested size is not an available upgrade plan for the instance
                $instance_id = $params->instance_id;
                $message = 'Requested size is not an available upgrade plan for the instance';
            }

            return $this->getServerInfoResult($instance_id)
                ->setMessage($message);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function reinstall(ReinstallParams $params): ServerInfoResult
    {
        try {
            $logger = $this->getLogger();
            $logger->info('Reinstalling server', [
                'params' => $params
            ]);
                
            $os = intval($params->image) == $params->image ? (int)$params->image : (string)$params->image;//if param is passed as an int val but it's a string, convert it to int here for api use.
            $instance_id = $this->api()->reinstallInstance($params->instance_id, $os);

            return $this->getServerInfoResult($instance_id)
                ->setMessage('Server rebuilding with fresh image');
            
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function reboot(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            $this->api()->rebootInstance($params->instance_id);

            return $this->getServerInfoResult($params->instance_id)
                ->setMessage('Server is rebooting');
                
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function shutdown(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            $info = $this->getServerInfoResult($params->instance_id);

            if ($info->state === 'stopped') {
                return $info->setMessage('Server is already off');
            }

            $this->api()->haltInstance($params->instance_id);

            return $this->getServerInfoResult($params->instance_id)
                ->setMessage('Server is shutting down')
                ->setState('Stopping');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function powerOn(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            $info = $this->getServerInfoResult($params->instance_id);

            if ($info->state === 'running') {
                return $info->setMessage('Server is already running');
            }

            $this->api()->startInstance($params->instance_id);

            return $this->getServerInfoResult($params->instance_id)
                ->setMessage('Server is booting')
                ->setState('Starting');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function terminate(ServerIdentifierParams $params): EmptyResult
    {
        try {
            $this->api()->deleteInstance($params->instance_id);

            return EmptyResult::create()->setMessage('Server permanently deleted');
            
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function suspend(ServerIdentifierParams $params): ServerInfoResult
    {
        return $this->shutdown($params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function unsuspend(ServerIdentifierParams $params): ServerInfoResult
    {
        return $this->powerOn($params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function attachRecoveryIso(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            $this->api()->attachRecoveryIso($params->instance_id);

            return $this->getServerInfoResult($params->instance_id)
                ->setMessage('Attaching recovery ISO to server');
            
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function detachRecoveryIso(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            //get the current attached ISO & recovery ISO
            $recovery_iso = $this->api()->getRecoveryIso();
            $instance_iso = $this->api()->getInstanceIsoStatus($params->instance_id);

            //verify the current attached ISO is the recovery ISO before detaching            
            if ($recovery_iso->id === $instance_iso->iso_id) {
                $this->api()->detachIsoFromInstance($params->instance_id);
                $message = 'Detaching recovery ISO from server';
            } else {
                $message = 'Recovery ISO not attached to server';
            }

            return $this->getServerInfoResult($params->instance_id)
                ->setMessage($message);
                
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @param string $serverId  Instance ID
     * 
     * @return ServerInfoResult
     * 
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function getServerInfoResult(string $serverId): ServerInfoResult
    {
        try {
            $server = $this->api()->getInstance($serverId);
            $region = $this->api()->getRegion($server->region);
            $plan = $this->api()->getPlan($server->plan);

            // Format ServerInfoResult using server data
            return ServerInfoResult::create()
                ->setInstanceId($server->id)
                ->setState($server->power_status)
                ->setLabel($server->label)
                ->setHostname($server->hostname)
                ->setIpAddress($server->main_ip)
                ->setImage($server->os)
                ->setSize($server->plan) // Need to parse the plan - assumption is that plan format will remain, but they explicitly say don't parse the plan for any reason (aka - the format may change w/o notice)
                ->setMemoryMb($plan->ram) // example shows "ram" : 32768,  although documentation says memory size in GB such as 32gb in Bare Metal plans (no reference in List Plans API doc)
                ->setDiskMb($plan->disk * 1024) //example shows "disk": 512; does not specify GB, but 1/2 GB of disk space is unlikely
                ->setCpuCores($plan->vcpu_count)
                ->setLocation("{$region->city}, {$region->country}")
            ;
        } catch (Throwable $e) {
            $this->handleException($e);
        }
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
                'Content-Type' => 'application/json',
            ],
        ]);

        $this->apiClient = new ApiClient($client, $this->getLogger());

        return $this->apiClient;
    }

    /**
     * @return no-return
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function handleException(Throwable $e): void
    {
        if (!$e instanceof ProvisionFunctionError) {
            $e = new ProvisionFunctionError('Unexpected Provider Error', $e->getCode(), $e);
        }

        throw $e;
    }
}
