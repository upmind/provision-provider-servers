<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\Vultr;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Helper;
use Upmind\ProvisionProviders\Servers\Data\CreateParams;
use Upmind\ProvisionProviders\Servers\Data\ResizeParams;
use Upmind\ProvisionProviders\Servers\Data\ServerInfoResult;
use Upmind\ProvisionProviders\Servers\Vultr\Data\Configuration;

class ApiClient
{
    private const REGION_CACHE_DURATION = 60 * 60 * 24; // 24 hours
    private const ISO_CACHE_DURATION = 60 * 60 * 24; // 24 hours
    private const PLAN_CACHE_DURATION = 60 * 60 * 24; // 24 hours

    protected Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param string $endpoint API endpoint
     * @param mixed[] $query Query params
     * @param mixed[] $data Body params
     * @param string $method Request method type
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    private function apiCall(string $endpoint, array $query = [], array $data = [], string $method = 'GET'): object|bool
    {
        try {
            $requestParams = [];

            if (!empty($query)) {
                $requestParams[RequestOptions::QUERY] = $query;
            }

            if (!empty($data)) {
                $requestParams[RequestOptions::FORM_PARAMS] = $data;
            }

            $response = $this->client->request($method, $endpoint, $requestParams);

            // Check for 204 No Content
            if ($response->getStatusCode() == 204) {
                return true;
            }

            $result = $response->getBody()->getContents();
            $response->getBody()->close();

            if ($result === '') {
                $this->throwError('Unknown Provider API Error', ['response' => $response]);
            }

            $parsedResult = json_decode($result);

            if (empty($parsedResult)) {
                $this->throwError('Unknown Provider API Error', ['response' => $response]);
            }

            return $parsedResult;
        } catch (ConnectException $e) {
            $errorMessage = 'Provider API connection failed';
            if (Str::contains($e->getMessage(), ['timeout', 'timed out'])) {
                $errorMessage = 'Provider API request timeout';
            }

            $this->throwError($errorMessage, [], [], $e);
        }
    }

    /**
     * @param string $instanceId Vultr Instance ID
     */
    public function getInstance(string $instanceId): object
    {
        $instanceId = Str::trim($instanceId);
        $endpoint = "instances/{$instanceId}";
        $result = $this->apiCall($endpoint);

        return $result->instance;
    }

    /**
     * @param CreateParams $params Server creation parameters object
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function createInstance(CreateParams $params): string
    {
        $endpoint = 'instances';

        $data = [
            'region' => $params->location,
            'plan' => $params->size,
            'os_id' => (int)$params->image,
            'label' => $params->label,
            'hostname' => $params->label
        ];

        $result = $this->apiCall($endpoint, [], $data, 'POST');

        if (empty($result->instance->id)) {
            $this->throwError("Instance creation failed", ['result_data' => $result]);
        }

        return $result->instance->id;
    }

    /**
     * @param string $instanceId Vultr instance ID string
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function deleteInstance(string $instanceId): bool
    {
        $instanceId = Str::trim($instanceId);
        $endpoint = "instances/{$instanceId}";

        $result = $this->apiCall($endpoint, [], [], 'DELETE');

        if ($result !== true) {
            $this->throwError("Unable to delete instance", ['result_data' => $result]);
        }

        return true;
    }

    /**
     * @param string $instanceId Vultr instance ID string
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function startInstance(string $instanceId): bool
    {
        $instanceId = Str::trim($instanceId);
        $endpoint = "instances/{$instanceId}/start";

        $result = $this->apiCall($endpoint, [], [], 'POST');

        if ($result !== true) {
            $this->throwError("Unable to start instance", ['result_data' => $result]);
        }

        return true;
    }

    /**
     * @param string $instanceId Vultr instance ID string
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function haltInstance(string $instanceId): bool
    {
        $instanceId = Str::trim($instanceId);
        $endpoint = "instances/{$instanceId}/halt";

        $result = $this->apiCall($endpoint, [], [], 'POST');

        if ($result !== true) {
            $this->throwError("Unable to halt instance", ['result_data' => $result]);
        }

        return true;
    }

    /**
     * @param string $instanceId Vultr instance ID string
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function rebootInstance(string $instanceId): bool
    {
        $instanceId = Str::trim($instanceId);
        $endpoint = "instances/{$instanceId}/reboot";

        $result = $this->apiCall($endpoint, [], [], 'POST');

        if ($result !== true) {
            $this->throwError("Unable to reboot instance", ['result_data' => $result]);
        }

        return true;
    }

    /**
     * @param string $instanceId Vultr instance ID string
     * @param string|int $os Vultr operating system ID or name
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function reinstallInstance(string $instanceId, string|int $os): string
    {
        // WE MAY NEED TO CHECK IF THE OPERATING SYSTEM IS NOT CHANGING AND USE THE REINSTALL API METHOD IN THAT CASE
        $instanceId = Str::trim($instanceId);
        $endpoint = "instances/{$instanceId}";

        $osInfo = $this->getOperatingSystem($os);
        $data = [
            'os_id' => $osInfo->id
        ];

        $result = $this->apiCall($endpoint, [], $data, 'PATCH');

        if (empty($result->instance)) {
            $this->throwError("Unable to reinstall instance", ['result_data' => $result]);
        }

        return $result->instance->id;
    }

    /**
     * @param string $instanceId Vultr instance ID string
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getAvailableInstanceUpgradePlans(string $instanceId): array
    {
        $instanceId = Str::trim($instanceId);
        $endpoint = "instances/{$instanceId}/upgrades";

        $query = [
            'type' => 'plans'
        ];

        $result = $this->apiCall($endpoint, $query);

        if (empty($result->upgrades)) {
            $this->throwError("Unable to get available instance upgrade plans", ['result_data' => $result]);
        }

        return $result->upgrades->plans;
    }

    /**
     * @param string $instanceId Vultr instance ID string
     * @param string $planId Vultr plan ID string
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function upgradeInstancePlan(string $instanceId, string $planId): string
    {
        $instanceId = Str::trim($instanceId);
        $planId = Str::trim($planId);
        $endpoint = "instances/{$instanceId}";

        $data = [
            'plan' => $planId
        ];

        $result = $this->apiCall($endpoint, [], $data, 'PATCH');

        if (empty($result->instance)) {
            $this->throwError("Unable to upgrade instance plan", ['result_data' => $result]);
        }

        return $result->instance->id;
    }

    /**
     * @param string $instanceId Vultr instance ID string
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getInstanceIsoStatus(string $instanceId): object
    {
        $instanceId = Str::trim($instanceId);
        $endpoint = "instances/{$instanceId}/iso";

        $result = $this->apiCall($endpoint);

        if (empty($result->iso_status)) {
            $this->throwError("Unable to determine instance ISO status", ['result_data' => $result]);
        }

        return $result->iso_status;
    }

    /**
     * @param string $instanceId Vultr instance ID string
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function attachRecoveryIso(string $instanceId): bool
    {
        $instanceId = Str::trim($instanceId);
        $endpoint = "instances/{$instanceId}/iso/attach";

        $iso = $this->getRecoveryIso();
        $data = [
            'iso_id' => $iso->id
        ];

        $result = $this->apiCall($endpoint, [], $data, 'POST');

        if (empty($result->iso_status->iso_id) || $result->iso_status->iso_id !== $iso->id) {
            $this->throwError("Unable to attach recovery ISO", ['result_data' => $result]);
        }

        return true;
    }

    /**
     * @param string $instanceId Vultr instance ID string
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function detachIsoFromInstance(string $instanceId): object
    {
        $instanceId = Str::trim($instanceId);
        $endpoint = "instances/{$instanceId}/iso/detach";

        $result = $this->apiCall($endpoint, [], [], 'POST');

        if (empty($result->iso_status)) {
            $this->throwError("Unable to detach ISO from instance", ['result_data' => $result]);
        }

        return $result->iso_status;
    } 

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getAllRegions(): array
    {
        $endpoint = 'regions';
        $result = Cache::remember('vultr_regions', self::REGION_CACHE_DURATION, $this->apiCall($endpoint));

        if (empty($result->regions)) {
            $this->throwError("No regions were returned by the provider", ['result_data' => $result]);
        }

        return $result->regions;
    }

    /**
     * @param string $regionCode Vultr region abbreviation code
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getRegion(string $regionCode): object
    {
        $regions = $this->getAllRegions();
        foreach ($regions as $region) {
            if ($region->id == $regionCode) {
                return $region;
            }
        }

        $this->throwError("Region {$regionCode} was not found", ['region' => $regionCode]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getAllOperatingSystems(): array
    {
        $endpoint = 'os';
        $result = $this->apiCall($endpoint);

        if (empty($result->os)) {
            $this->throwError("No operating systems were returned by the provider", ['result_data' => $result]);
        }

        return $result->os;
    }

    /**
     * @param string|int $os Vultr operating system ID or name
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getOperatingSystem(string|int $os): object
    {
        $osList = $this->getAllOperatingSystems();
        foreach ($osList as $osData) {
            if (is_int($os)) {
                if ($osData->id == $os) {
                    return $osData;
                }
            } else {
                if ($osData->name == $os) {
                    return $osData;
                }
            }
        }

        $this->throwError("Operating System {$os} was not found", ['os' => $os]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getAllPlans(): array
    {
        $endpoint = 'plans';
        $query = [
            'type' => 'vc2'
        ];
        $result = Cache::remember('vultr_plans', self::PLAN_CACHE_DURATION, $this->apiCall($endpoint, $query));

        if (empty($result->regions)) {
            $this->throwError("No plans were returned by the provider", ['result_data' => $result]);
        }

        return $result->plans;
    }

    /**
     * @param string $planId Vultr plan ID string
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getPlan(string $planId): object
    {
        $plans = $this->getAllPlans();
        foreach ($plans as $plan) {
            if ($plan->id == $planId) {
                return $plan;
            }
        }

        $this->throwError("Plan {$planId} was not found", ['plan_id' => $planId]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getPublicIsos(): array
    {
        $endpoint = 'iso-public';
        $result = Cache::remember('vultr_public_isos', self::ISO_CACHE_DURATION, $this->apiCall($endpoint));

        if (empty($result->public_isos)) {
            $this->throwError("No public ISOs were returned by the provider", ['result_data' => $result]);
        }

        return $result->public_isos;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getRecoveryIso(): object
    {
        $recoveryIsoName = 'SystemRescue';
        $isos = $this->getPublicIsos();
        foreach ($isos as $iso) {
            if ($iso->name == $recoveryIsoName) {
                return $iso;
            }
        }

        $this->throwError("A Recovery ISO was not found");
    }

    /**
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function throwError(string $message, array $data = [], array $debug = [], ?Throwable $e = null): void
    {
        throw ProvisionFunctionError::create($message, $e)
            ->withData($data)
            ->withDebug($debug);
    }
}