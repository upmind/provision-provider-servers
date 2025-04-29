<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * @property-read string $instance_id Server instance identifier
 * @property-read string|null $application Application name
 * @property-read array<string,mixed>|null $application_params Application parameters
 */
class GetConnectionParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'instance_id' => ['required', 'string'],
            'application' => ['nullable', 'string'],
            'application_params' => ['nullable', 'array'],
        ]);
    }
}
