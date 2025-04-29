<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\SolusVM;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Upmind\ProvisionBase\Helper;
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
use Upmind\ProvisionProviders\Servers\SolusVM\ApiClient as SolusVMClient;
use Upmind\ProvisionProviders\Servers\SolusVM\Data\Configuration;

class Provider extends Category implements ProviderInterface
{
    protected Configuration $configuration;
    protected ApiClient $apiClient;
    protected array $cache = [];

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
            ->setName('SolusVM v1')
            ->setDescription('Deploy and manage SolusVM v1 virtual servers')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/solusvm-logo@2x.png');
    }

    /**
     * @inheritDoc
     */
    public function create(CreateParams $params): ServerInfoResult
    {
        $virtualizationType = strtolower(
            $params->virtualization_type
                ?? $this->configuration->default_virtualization_type
        );

        $templateId = $this->findTemplateId($virtualizationType, $params->image);
        $plan = $this->findPlan($virtualizationType, $params->size);
        $password = $params->root_password ?? Helper::generatePassword(15);

        if ($this->configuration->location_type === Configuration::LOCATION_TYPE_NODE_GROUP) {
            $nodeGroupId = $this->findNodeGroupId($params->location);
        }

        if ($this->configuration->location_type === Configuration::LOCATION_TYPE_NODE) {
            $node = $params->location;
        }

        if ($this->configuration->single_server_owner) {
            $username = $this->configuration->server_owner_username;
        } else {
            $username = $params->customer_identifier ?: $this->api()->createCustomer($params->email);
        }

        $serverId = $this->api()->createServer(
            $virtualizationType,
            $username,
            $params->label,
            $plan['name'],
            $templateId,
            $password,
            $nodeGroupId ?? null,
            $node ?? null
        );

        return $this->getServerInfoResult($serverId)
            ->setCustomerIdentifier($username)
            ->setState('creating')
            ->setMessage('Server created');
    }

    /**
     * @inheritDoc
     */
    public function getInfo(ServerIdentifierParams $params): ServerInfoResult
    {
        return $this->getServerInfoResult($params->instance_id)->setMessage('Server info obtained');
    }

    /**
     * @inheritDoc
     */
    public function getConnection(GetConnectionParams $params): ConnectionResult
    {
        $session = $this->api()->createConsoleSession($params->instance_id);

        return ConnectionResult::create()
            ->setType(ConnectionResult::TYPE_SSH)
            ->setCommand(sprintf(
                'ssh %s@%s -p %s',
                $session['consoleusername'],
                $session['consoleip'],
                $session['consoleport']
            ))
            ->setPassword($session['consolepassword'])
            ->setExpiresAt(Carbon::now()->addSeconds($session['sessionexpire'])->format('Y-m-d H:i:s'))
            ->setMessage(sprintf(
                'Serial console session %s',
                $session['created'] === 'success' ? 'started' : 'ongoing'
            ));
    }

    /**
     * @inheritDoc
     */
    public function changeRootPassword(ChangeRootPasswordParams $params): ServerInfoResult
    {
        $this->api()->changeRootPassword($params->instance_id, $params->root_password);

        return $this->getServerInfoResult($params->instance_id)->setMessage('Root password changed');
    }

    /**
     * @inheritDoc
     */
    public function resize(ResizeParams $params): ServerInfoResult
    {
        $info = $this->getServerInfoResult($params->instance_id);

        if (!$params->resize_running && $info->state === 'online') {
            throw $this->errorResult('Resize not available while server is running');
        }

        $plan = $this->findPlan($info->virtualization_type, $params->size);

        $this->api()->changeServerPlan($params->instance_id, $plan['name']);

        return $info->setSize($plan['name'])
            ->setMessage('Server plan changed');
    }

    /**
     * @inheritDoc
     */
    public function reinstall(ReinstallParams $params): ServerInfoResult
    {
        $info = $this->getServerInfoResult($params->instance_id);
        $templateId = $this->findTemplateId($info->virtualization_type, $params->image);

        $this->api()->rebuildServer($params->instance_id, $templateId);

        return $info->setImage($params->image)
            ->setMessage('Server rebuilding with fresh image/template');
    }

    /**
     * @inheritDoc
     */
    public function reboot(ServerIdentifierParams $params): ServerInfoResult
    {
        $this->api()->rebootServer($params->instance_id);

        return $this->getServerInfoResult($params->instance_id)
            ->setMessage('Server rebooting')
            ->setState('rebooting');
    }

    /**
     * @inheritDoc
     */
    public function shutdown(ServerIdentifierParams $params): ServerInfoResult
    {
        $info = $this->getServerInfoResult($params->instance_id);

        if ($info->state === 'offline') {
            return $info->setMessage('Server already offline');
        }

        $this->api()->shutdownServer($params->instance_id);

        return $info->setMessage('Server shutting down')
            ->setState('shutting_down');
    }

    /**
     * @inheritDoc
     */
    public function powerOn(ServerIdentifierParams $params): ServerInfoResult
    {
        $info = $this->getServerInfoResult($params->instance_id);

        if ($info->state === 'online') {
            return $info->setMessage('Server already online');
        }

        $this->api()->bootServer($params->instance_id);

        return $info->setMessage('Server booting')
            ->setState('booting');
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
        $this->api()->terminateServer($params->instance_id);

        return (new EmptyResult())->setMessage('Server terminating');
    }

    /**
     * @param string|int $serverId
     */
    protected function getServerInfoResult($serverId): ServerInfoResult
    {
        $info = $this->api()->getServerInfo($serverId);
        $templates = $this->api()->listTemplates($info['type']);
        if (!empty($info['node'])) {
            $node = $this->api()->getNode($info['node']);

            if ($node['country']) {
                $location = $node['country'];
                if (!empty($node['city'])) {
                    $location = $node['city'] . ', ' . $location;
                }
            }
        }

        $plan = $this->findPlanBySpecs($info['type'], $info['cpus'], $info['memory'], $info['hdd'], false);

        return ServerInfoResult::create()
            ->setInstanceId($info['vserverid'])
            ->setState($info['state'])
            ->setLabel($info['hostname'])
            ->setHostname($info['hostname'])
            ->setIpAddress($info['ipaddress'])
            ->setImage($templates[$info['template']] ?? $info['template'] ?? 'Unknown')
            ->setSize($plan['name'] ?? 'Custom')
            ->setLocation($location ?? 'Unknown')
            ->setNode($info['node'] ?? 'Unknown')
            ->setVirtualizationType($info['type'] ?? 'Unknown');
    }

    /**
     * Find plan by name or id.
     *
     * @param string $virtualizationType
     * @param string $plan Plan name or id
     * @param bool $orFail
     *
     * @return mixed[]|null Plan data
     */
    protected function findPlan(string $virtualizationType, ?string $plan, bool $orFail = true): ?array
    {
        if (empty($plan)) {
            throw $this->errorResult('Size parameter is required');
        }

        $plans = $this->cache['plans'][$virtualizationType] ??= $this->api()->listPlans($virtualizationType);

        // First try to find by id, then by name
        foreach (['id', 'name'] as $attribute) {
            $planData = Arr::first($plans, function ($planData) use ($plan, $attribute) {
                return $planData[$attribute] === $plan;
            });

            if ($planData) {
                return $planData;
            }
        }

        if ($orFail) {
            throw $this->errorResult('Server size/plan not found');
        }

        return null;
    }

    protected function findPlanBySpecs(
        string $virtualizationType,
        string $cpus,
        string $ram,
        string $disk,
        bool $orFail = true
    ): ?array {
        $plans = $this->cache['plans'][$virtualizationType] ??= $this->api()->listPlans($virtualizationType);

        $planData = Arr::first($plans, function ($planData) use ($cpus, $ram, $disk) {
            return $planData['cpus'] === $cpus
                && $planData['ram'] === $ram
                && $planData['disk'] === $disk;
        });

        if ($planData) {
            return $planData;
        }

        if ($orFail) {
            throw $this->errorResult('Server size/plan not found');
        }

        return null;
    }

    protected function findTemplateId(?string $virtualizationType, string $template, bool $orFail = true): ?string
    {
        $templates = $this->cache['templates'][$virtualizationType]
            ??= $this->api()->listTemplates($virtualizationType);

        if (isset($templates[$template])) {
            // if $template is already an id, return it
            return $template;
        }

        if (in_array($template, $templates, true)) {
            // if $template is a label, return the id
            return array_search($template, $templates, true);
        }

        if ($orFail) {
            throw $this->errorResult('Server image/template not found');
        }

        return null;
    }

    protected function findTemplateLabel(?string $virtualizationType, string $template, bool $orFail = true): ?string
    {
        $templates = $this->cache['templates'][$virtualizationType]
            ??= $this->api()->listTemplates($virtualizationType);

        if (isset($templates[$template])) {
            // if $template is an id, return the label
            return $templates[$template];
        }

        if (in_array($template, $templates, true)) {
            // if $template is already a label, return it
            return $template;
        }

        if ($orFail) {
            throw $this->errorResult('Server image/template not found');
        }

        return null;
    }

    protected function findNodeGroupId($nodeGroup, bool $orFail = true): ?int
    {
        $nodeGroups = $this->cache['node_groups'] ??= $this->api()->listNodeGroups();

        if (isset($nodeGroups[$nodeGroup])) {
            // if $nodeGroup is an id, return it
            return (int)$nodeGroup;
        }

        if (in_array($nodeGroup, $nodeGroups, true)) {
            // if $nodeGroup is a label, return the id
            return array_search($nodeGroup, $nodeGroups, true);
        }

        if ($orFail) {
            throw $this->errorResult('Node group not found');
        }

        return null;
    }

    protected function findNodeGroupName($nodeGroup, bool $orFail = true): ?string
    {
        $nodeGroups = $this->cache['node_groups'] ??= $this->api()->listNodeGroups();

        if (isset($nodeGroups[$nodeGroup])) {
            // if $nodeGroup is an id, return the label
            return $nodeGroups[$nodeGroup];
        }

        if (in_array($nodeGroup, $nodeGroups, true)) {
            // if $nodeGroup is already a label, return it
            return $nodeGroup;
        }

        if ($orFail) {
            throw $this->errorResult('Node group not found');
        }

        return null;
    }

    protected function api(): SolusVMClient
    {
        return $this->apiClient ??= new ApiClient(
            $this->configuration,
            $this->getGuzzleHandlerStack(!!$this->configuration->debug)
        );
    }
}
