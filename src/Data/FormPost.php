<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Form post data.
 *
 * @property-read string|null $url
 * @property-read array<string,mixed>|null $params
 */
class FormPost extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'url' => ['required', 'url'],
            'params' => ['required', 'array'],
        ]);
    }

    /**
     * @return self $this
     */
    public function setUrl(string $url): self
    {
        $this->setValue('url', $url);
        return $this;
    }

    /**
     * @return self $this
     */
    public function setParams(array $params): self
    {
        $this->setValue('params', $params);
        return $this;
    }
}
