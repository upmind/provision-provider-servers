<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers;

use Upmind\ProvisionBase\Laravel\ProvisionServiceProvider;
use Upmind\ProvisionProviders\Servers\Category as ServersCategory;

class LaravelServiceProvider extends ProvisionServiceProvider
{
    public function boot()
    {
        $this->bindCategory('servers', ServersCategory::class);

        // $this->bindProvider('servers', 'example', Example\Provider::class);
        $this->bindProvider('servers', 'linode', Linode\Provider::class);
        $this->bindProvider('servers', 'solusvm', SolusVM\Provider::class);
    }
}
