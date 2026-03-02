<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\HetznerRobot\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Hetzner Robot configuration.
 *
 * @property-read string $api_login Login field found in API Login Credentials
 * @property-read string $api_password Password field found in API Login Credentials
 * @property-read bool|null $test Use Hetzner Robot API test environment
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'api_login' => ['required', 'string'],
            'api_password' => ['required', 'string', 'min:3'],
            'test' => ['nullable', 'boolean']
        ]);
    }
}
