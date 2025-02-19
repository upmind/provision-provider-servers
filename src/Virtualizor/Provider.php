<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\Virtualizor;

use Illuminate\Support\Arr;
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
use Upmind\ProvisionProviders\Servers\Virtualizor\Data\Configuration;

/**
 * Deploy and manage Virtualizor virtual servers using KVM, Xen, OpenVZ, Proxmox, Virtuozzo, LXC and more.
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
            ->setName('Virtualizor')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/virtualizor-logo.png')
            ->setDescription('Deploy and manage Virtualizor virtual servers using KVM,'
                . ' Xen, OpenVZ, Proxmox, Virtuozzo, LXC and more');
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
        if (is_numeric($params->size)) {
            $planId = $params->size;
            $planName = null;
        } else {
            $planId = null;
            $planName = $params->size;
        }

        if (is_numeric($params->image)) {
            $osId = $params->image;
            $osName = null;
        } else {
            $osId = null;
            $osName = $params->image;
        }

        if ($this->configuration->location_type === Configuration::LOCATION_TYPE_SERVER_GROUP) {
            if (is_numeric($params->location)) {
                $serverGroupId = $params->location;
                $serverGroupName = null;
            } else {
                $serverGroupId = null;
                $serverGroupName = $params->location;
            }
            $serverId = null;
            $serverName = null;
            $serverLocation = null;
        } elseif ($this->configuration->location_type === Configuration::LOCATION_TYPE_SERVER) {
            $serverGroupId = null;
            $serverGroupName = null;
            if (is_numeric($params->location)) {
                $serverId = $params->location;
                $serverName = null;
            } else {
                $serverId = null;
                $serverName = $params->location;
            }
            $serverLocation = null;
        } else {
            $serverGroupId = null;
            $serverGroupName = null;
            $serverId = null;
            $serverName = null;
            $serverLocation = $params->location;
        }

        $virtualizationType = $params->virtualization_type
            ?? $this->configuration->default_virtualization_type
            ?? 'kvm';

        $plan = $this->api()->getPlan($planId, $planName, $virtualizationType);
        $template = $this->api()->getOsTemplate($osId, $osName);

        if ($serverGroupId || $serverGroupName) {
            $serverGroup = $this->api()->getServerGroup($serverGroupId, $serverGroupName);
        } else {
            $server = $this->api()->getServer($serverId, $serverName, $serverLocation);
        }

        $data = $this->api()->createVirtualServer(
            $plan['virt'],
            $plan['plid'],
            $template['osid'],
            $serverGroup['sgid'] ?? null,
            $server['serid'] ?? null,
            $params->label,
            $params->email,
            $params->root_password
        );

        return $this->getServerInfoResult($data['vpsid'])
            ->setMessage('Virtual server creating')
            ->setState('Creating');
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
        return $this->getServerInfoResult($params->instance_id);
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
        $url = $this->api()->getSsoUrl($params->instance_id);

        return ConnectionResult::create()
            ->setMessage('Control panel URL generated')
            ->setType(ConnectionResult::TYPE_REDIRECT)
            ->setRedirectUrl($url);
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
        $data = $this->api()->changeVirtualServerRootPass($params->instance_id, $params->root_password);

        return $this->getServerInfoResult($params->instance_id)
            ->setMessage($data['done_msg'] ?? 'Root password changed');
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
        if (is_numeric($params->size)) {
            $planId = $params->size;
            $planName = null;
        } else {
            $planId = null;
            $planName = $params->size;
        }

        $info = $this->getServerInfoResult($params->instance_id);

        if ($info->state === 'On' && !$params->resize_running) {
            $this->errorResult('Resize not available while server is running');
        }

        $plan = $this->api()->getPlan($planId, $planName, $info->virtualization_type);
        $data = $this->api()->changeVirtualServerPlan($params->instance_id, $plan['plid']);

        return $info->setMessage($data['done_msg'] ?? 'Virtual server plan updated')
            ->setSize($plan['plan_name']);
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
        if (is_numeric($params->image)) {
            $osId = $params->image;
            $osName = null;
        } else {
            $osId = null;
            $osName = $params->image;
        }

        $allInfo = $this->api()->getAllVirtualServerInfo($params->instance_id); // this gives us everything, woo hoo!
        $info = $this->allInfoToServerInfoResult($allInfo);

        $template = $this->api()->getOsTemplate($osId, $osName);

        $data = $this->api()->rebuildVirtualServer($params->instance_id, $template['osid'], $allInfo['vps']['serid']);

        return $info->setMessage($data['done_msg'] ?? 'Virtual server reinstalling')
            ->setImage($template['name'])
            ->setState('Rebuilding');
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
        $info = $this->getServerInfoResult($params->instance_id);

        $data = $this->api()->runVirtualServerAction($params->instance_id, 'restart');

        return $info->setMessage($data['done_msg'] ?? 'Virtual server restarting')
            ->setState('Restarting');
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
        $info = $this->getServerInfoResult($params->instance_id);

        if ($info->state === 'Off') {
            return $info->setMessage('Virtual server already off');
        }

        $data = $this->api()->runVirtualServerAction($params->instance_id, 'stop');
        // $data = $this->api()->runVirtualServerAction($params->instance_id, 'poweroff');

        return $info->setMessage($data['done_msg'] ?? 'Virtual server stopping')
            ->setState('Stopping');
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
        $info = $this->getServerInfoResult($params->instance_id);

        if ($info->state === 'On') {
            return $info->setMessage('Virtual server already on');
        }

        $data = $this->api()->runVirtualServerAction($params->instance_id, 'start');

        return $info->setMessage($data['done_msg'] ?? 'Virtual server starting')
            ->setState('Starting');
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
        $this->api()->suspendVirtualServer($params->instance_id);

        return $this->getServerInfoResult($params->instance_id)
            ->setSuspended(true);
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
        $this->api()->unsuspendVirtualServer($params->instance_id);

        return $this->getServerInfoResult($params->instance_id)
            ->setSuspended(false);
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
        $this->api()->deleteVirtualServer($params->instance_id);

        return EmptyResult::create()->setMessage('Virtual server deleted');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function getServerInfoResult($serverId): ServerInfoResult
    {
        // $info = $this->api()->getVirtualServer($serverId); // this is kinda pointless compared to editvs
        // $state = $this->api()->getVirtualServerStatus($serverId); // this isn't returning `status` for some reason
        $allInfo = $this->api()->getAllVirtualServerInfo($serverId); // this gives us everything, woo hoo!

        return $this->allInfoToServerInfoResult($allInfo);
    }

    /**
     * @param array $allInfo Data returned from Virtualizor\ApiClient::getAllVirtualServerInfo()
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function allInfoToServerInfoResult(array $allInfo): ServerInfoResult
    {
        $vps = $allInfo['vps'];
        $plan = $allInfo['plans'][$vps['plid']] ?? $this->api()->getPlan($vps['plid'], null, null, false);
        $server = $allInfo['servers'][$vps['serid']] ?? $this->api()->getServer($vps['serid']);

        if ($this->configuration->location_type === Configuration::LOCATION_TYPE_SERVER) {
            $location = $server['server_name'];
        } else {
            $location = ApiClient::locationJsonToString($server['location']);
        }

        return ServerInfoResult::create()
            ->setInstanceId($vps['vpsid'])
            ->setState(ApiClient::statusNumberToString($vps['stats']['status'] ?? null))
            ->setSuspended(boolval($vps['suspended']))
            ->setLabel(sprintf('%s [%s]', $vps['hostname'], $vps['vps_name']))
            ->setHostname($vps['hostname'])
            ->setSize($plan['plan_name'] ?? 'Custom')
            ->setImage($vps['os_name'])
            ->setLocation($location)
            ->setNode($server['server_name'])
            ->setVirtualizationType($vps['virt'])
            ->setIpAddress(Arr::first($vps['ips']));
    }

    /**
     * Get a Guzzle HTTP client instance.
     */
    protected function api(): ApiClient
    {
        return $this->apiClient ??= new ApiClient(
            $this->configuration,
            $this->getGuzzleHandlerStack()
        );
    }
}
