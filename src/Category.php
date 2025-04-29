<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers;

use Upmind\ProvisionBase\Provider\BaseCategory;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionProviders\Servers\Data\ChangeRootPasswordParams;
use Upmind\ProvisionProviders\Servers\Data\CreateParams;
use Upmind\ProvisionProviders\Servers\Data\EmptyResult;
use Upmind\ProvisionProviders\Servers\Data\ReinstallParams;
use Upmind\ProvisionProviders\Servers\Data\ResizeParams;
use Upmind\ProvisionProviders\Servers\Data\ServerIdentifierParams;
use Upmind\ProvisionProviders\Servers\Data\ServerInfoResult;
use Upmind\ProvisionProviders\Servers\Data\ConnectionResult;
use Upmind\ProvisionProviders\Servers\Data\GetConnectionParams;

abstract class Category extends BaseCategory
{
    public static function aboutCategory(): AboutData
    {
        return AboutData::create()
            ->setName('Servers')
            ->setDescription('Create and manage servers on popular Cloud provider networks')
            ->setIcon('server');
    }

    /**
     * Create and boot a new server.
     */
    abstract public function create(CreateParams $params): ServerInfoResult;

    /**
     * Get information about a server such as its label, current state (running/rebooting etc),
     * image (e.g., ubuntu), size and region)
     */
    abstract public function getInfo(ServerIdentifierParams $params): ServerInfoResult;

    /**
     * Get a command to connect to a server.
     */
    abstract public function getConnection(GetConnectionParams $params): ConnectionResult;

    /**
     * Update the root password used to SSH into a server.
     */
    abstract public function changeRootPassword(ChangeRootPasswordParams $params): ServerInfoResult;

    /**
     * Redeploy an existing server with a different resource allocation.
     */
    abstract public function resize(ResizeParams $params): ServerInfoResult;

    /**
     * Reinstall (wipe/reset) an existing server server using a particular image.
     */
    abstract public function reinstall(ReinstallParams $params): ServerInfoResult;

    /**
     * Reboot (shutdown then power-on) a running server.
     */
    abstract public function reboot(ServerIdentifierParams $params): ServerInfoResult;

    /**
     * Shut down a running server.
     */
    abstract public function shutdown(ServerIdentifierParams $params): ServerInfoResult;

    /**
     * Boot a powered-off server.
     */
    abstract public function powerOn(ServerIdentifierParams $params): ServerInfoResult;

    /**
     * Suspend a server.
     */
    abstract public function suspend(ServerIdentifierParams $params): ServerInfoResult;

    /**
     * Unsuspend a server.
     */
    abstract public function unsuspend(ServerIdentifierParams $params): ServerInfoResult;

    /**
     * Attach recovery ISO.
     */
    abstract public function attachRecoveryIso(ServerIdentifierParams $params): ServerInfoResult;

    /**
     * Detach recovery ISO.
     */
    abstract public function detachRecoveryIso(ServerIdentifierParams $params): ServerInfoResult;

    /**
     * Terminate (delete) an existing server.
     */
    abstract public function terminate(ServerIdentifierParams $params): EmptyResult;
}
