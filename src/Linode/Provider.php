<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\Linode;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Linode\Entity\Image;
use Linode\Entity\Linode;
use Linode\Entity\Linode\Disk;
use Linode\Entity\LinodeType;
use Linode\Entity\Region;
use Linode\Exception\Error;
use Linode\Exception\LinodeException;
use Linode\Internal\Linode\DiskRepository;
use Linode\LinodeClient;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
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
use Upmind\ProvisionProviders\Servers\Linode\Data\Configuration;

class Provider extends Category implements ProviderInterface
{
    /**
     * @var Configuration
     */
    protected $configuration;

    /**
     * @var LinodeClient
     */
    protected $client;

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Linode')
            ->setDescription('Deploy and manage Linode instances')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/linode-logo@2x.png');
    }

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public function create(CreateParams $params): ServerInfoResult
    {
        $image = $this->findImage($params->image);
        $type = $this->findType($params->size);
        $region = $this->findRegion($params->location);

        try {
            $server = $this->api()->linodes()->create([
                'image' => $image->id,
                'type' => $type->id,
                'region' => $region->id,
                'root_pass' => $params->root_password ?: Helper::generatePassword(),
                'label' => $params->label,
                'hypervisor' => $params->virtualization_type, // no longer supported?
            ]);
        } catch (Throwable $e) {
            throw $this->handleException($e, 'Create server');
        }

        return $this->getServerInfo($server);
    }

    public function getInfo(ServerIdentifierParams $params): ServerInfoResult
    {
        return $this->getServerInfo($params->instance_id);
    }

    public function getConnection(GetConnectionParams $params): ConnectionResult
    {
        $server = $this->findServer((int)$params->instance_id);

        return ConnectionResult::create()
            ->setType(ConnectionResult::TYPE_SSH)
            ->setCommand(sprintf('ssh root@%s', Arr::first($server->ipv4)));
    }

    public function reinstall(ReinstallParams $params): ServerInfoResult
    {
        $image = $this->findImage($params->image);

        try {
            $this->api()->linodes()->rebuild((int)$params->instance_id, [
                'image' => $image->id,
                'root_pass' => $params->root_password ?: Helper::generatePassword(),
            ]);
        } catch (Throwable $e) {
            throw $this->handleException($e, 'Rebuild server');
        }

        return $this->getServerInfo($params->instance_id)->setMessage('Server is rebuilding');
    }

    public function resize(ResizeParams $params): ServerInfoResult
    {
        $size = $this->findType($params->size);

        if (!$params->resize_running) {
            if (Linode::STATUS_RUNNING === $this->findServer((int)$params->instance_id)->status) {
                throw $this->errorResult('Resize not available while server is running');
            }
        }

        try {
            $this->api()->linodes()->resize((int)$params->instance_id, $size->id);
        } catch (Throwable $e) {
            throw $this->handleException($e, 'Resize server');
        }

        return $this->getServerInfo($params->instance_id)->setMessage('Server is resizing');
    }

    public function changeRootPassword(ChangeRootPasswordParams $params): ServerInfoResult
    {
        $server = $this->findServer((int)$params->instance_id);

        try {
            /** @var Disk $disk */
            $disk = Arr::first($server->disks->findAll(), function (Disk $disk) {
                return $disk->filesystem !== Disk::FILESYSTEM_SWAP;
            });

            if (!$disk) {
                throw $this->errorResult('No disks available');
            }
        } catch (Throwable $e) {
            throw $this->handleException($e, 'List server disks');
        }

        try {
            $server->disks->resetPassword($disk->id, $params->root_password);
        } catch (Throwable $e) {
            throw $this->handleException($e, 'Update root password');
        }

        return $this->getServerInfo($params->instance_id)->setMessage('Root password changed');
    }

    public function reboot(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            $this->api()->linodes()->reboot((int)$params->instance_id);
        } catch (Throwable $e) {
            throw $this->handleException($e, 'Reboot server');
        }

        return $this->getServerInfo($params->instance_id)->setMessage('Server is rebooting');
    }

    public function shutdown(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            $this->api()->linodes()->shutdown((int)$params->instance_id);
        } catch (Throwable $e) {
            throw $this->handleException($e, 'Shutdown server');
        }

        return $this->getServerInfo($params->instance_id)->setMessage('Server is shutting down');
    }

    public function powerOn(ServerIdentifierParams $params): ServerInfoResult
    {
        try {
            $this->api()->linodes()->boot((int)$params->instance_id);
        } catch (Throwable $e) {
            if (!preg_match('/Linode \d+ already booted/', $e->getMessage())) {
                throw $this->handleException($e, 'Boot server');
            }

            $message = 'Server already running';
        }

        return $this->getServerInfo($params->instance_id)->setMessage($message ?? 'Server is booting');
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

    public function terminate(ServerIdentifierParams $params): EmptyResult
    {
        try {
            $this->api()->linodes()->delete((int)$params->instance_id);
        } catch (Throwable $e) {
            throw $this->handleException($e, 'Delete server');
        }

        return EmptyResult::create()->setMessage('Server permanently deleted');
    }

    /**
     * @param int|Linode $server
     */
    protected function getServerInfo($server): ServerInfoResult
    {
        $server = $server instanceof Linode ? $server : $this->findServer((int)$server);

        return ServerInfoResult::create()
            ->setInstanceId((string)$server->id)
            ->setState($server->status)
            ->setLabel($server->label)
            ->setLocation($server->region)
            ->setSize($server->type)
            ->setIpAddress(Arr::first($server->ipv4))
            ->setHostname(null)
            ->setImage($server->image ?? 'unknown')
            ->setVirtualizationType($server->hypervisor ?? 'unknown')
            ->setCreatedAt(Carbon::parse($server->created)->format('Y-m-d H:i:s'))
            ->setUpdatedAt(Carbon::parse($server->updated)->format('Y-m-d H:i:s'))
            ->setMessage(sprintf('Server is %s', str_replace('_', ' ', $server->status)))
            ->setDebug(['server' => $server->toArray()]);
    }

    /**
     * @throws ProvisionFunctionError
     */
    protected function findServer(int $serverId): Linode
    {
        try {
            return $this->api()->linodes()->find($serverId);
        } catch (Throwable $e) {
            throw $this->handleException($e, 'Get server info');
        }
    }

    /**
     * @throws ProvisionFunctionError
     */
    protected function findImage(string $name): Image
    {
        try {
            if (preg_match('#[a-z]+/[a-z0-9\-\.]+#', $name)) {
                // name appears to be an image id
                try {
                    return $this->api()->images()->find($name);
                } catch (LinodeException $e) {
                    if ($e->getCode() !== 404) {
                        throw $e;
                    }
                }
            }

            // try and match with an image label
            if ($image = $this->api()->images()->findBy(['label' => $name])->current()) {
                return $image;
            }
        } catch (Throwable $e) {
            throw $this->handleException($e, 'Find image');
        }

        throw $this->errorResult('Image not found', ['image' => $name]);
    }

    /**
     * @throws ProvisionFunctionError
     */
    protected function findType(?string $name): LinodeType
    {
        if (empty($name)) {
            throw $this->errorResult('Size parameter is required');
        }

        try {
            if (preg_match('/^[a-z0-9\-]+$/', $name)) {
                // name appears to be a type id
                try {
                    return $this->api()->linodeTypes()->find($name);
                } catch (LinodeException $e) {
                    if ($e->getCode() !== 404) {
                        throw $e;
                    }
                }
            }

            // try and match with a type label
            // filtering by label doesnt work for some reason so we need to loop over all types
            /** @var LinodeType $type */
            foreach ($this->api()->linodeTypes()->findAll() as $type) {
                if ($type->label === $name) {
                    return $type;
                }
            }
        } catch (Throwable $e) {
            throw $this->handleException($e, 'Find type');
        }

        throw $this->errorResult('Type not found', ['type' => $name]);
    }

    /**
     * @throws ProvisionFunctionError
     */
    protected function findRegion(string $id): Region
    {
        try {
            try {
                return $this->api()->regions()->find($id);
            } catch (LinodeException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }
        } catch (Throwable $e) {
            throw $this->handleException($e, 'Find region');
        }

        throw $this->errorResult('Region not found', ['region' => $id]);
    }

    /**
     * @return no-return
     * @throws ProvisionFunctionError
     */
    protected function handleException(
        Throwable $e,
        string $operation = 'Operation',
        array $data = [],
        array $debug = []
    ): void {
        if ($e instanceof ProvisionFunctionError) {
            throw $e->withData(array_merge($e->getData(), $data))
                ->withDebug(array_merge($e->getDebug(), $debug));
        }

        if ($e instanceof LinodeException) {
            if ($e->getErrors()) {
                $data['error_code'] = $e->getCode();
                $data['errors'] = array_map(function (Error $error) {
                    return [
                        'field' => $error->getField(),
                        'reason' => $error->getReason(),
                    ];
                }, $e->getErrors());

                $message = sprintf(
                    '%s failed: [API Error] %s',
                    $operation,
                    ucfirst(preg_replace('/linode(?: \d+)?/i', 'server', $e->getMessage()))
                );
            }
        }

        throw $this->errorResult($message ?? sprintf('%s failed: Unknown error', $operation), $data, $debug, $e);
    }

    protected function api(): LinodeClient
    {
        if ($this->client) {
            return $this->client;
        }

        $client = new Client(['handler' => $this->getGuzzleHandlerStack(!!$this->configuration->debug)]);

        return $this->client = new LinodeClient($this->configuration->access_token, $client);
    }
}
