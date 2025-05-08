<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\OnApp;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Str;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\Servers\Data\CreateParams;
use Upmind\ProvisionProviders\Servers\Data\ResizeParams;
use Upmind\ProvisionProviders\Servers\OnApp\Data\Configuration;

class ApiClient
{
    protected Configuration $configuration;
    protected Client $client;

    public function __construct(Configuration $configuration, ?HandlerStack $handler = null)
    {
        $this->configuration = $configuration;

        $credentials = base64_encode("{$this->configuration->username}:{$this->configuration->password}");

        $this->client = new Client([
            'base_uri' => sprintf('https://%s/', $configuration->hostname),
            'headers' => [
                'Accept' => 'application/json',
                'Content-type' => 'application/json',
                'Authorization' => ['Basic ' . $credentials],
            ],
            'connect_timeout' => 10,
            // If timeout is empty, ie 0 or null, we set it to 120 seconds
            'timeout' => empty($configuration->timeout) ? 120 : $configuration->timeout,
            'handler' => $handler,
        ]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function makeRequest(
        string  $command,
        ?array  $params = null,
        ?array  $body = null,
        ?string $method = 'GET'
    ): ?array {
        try {
            $requestParams = [];

            if ($params) {
                $requestParams['query'] = $params;
            }

            if ($body) {
                $requestParams['json'] = $body;
            }

            $response = $this->client->request($method, $command, $requestParams);
            $result = $response->getBody()->getContents();

            $response->getBody()->close();

            if ($result === '') {
                return null;
            }

            return $this->parseResponseData($result);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function parseResponseData(string $response): array
    {
        $parsedResult = json_decode($response, true);

        if (!$parsedResult) {
            throw ProvisionFunctionError::create('Unknown Provider API Error')
                ->withData([
                    'response' => $response,
                ]);
        }

        return $parsedResult;
    }

    /**
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    private function handleException(Throwable $e): void
    {
        if ($e instanceof ProvisionFunctionError) {
            throw $e;
        }

        $errorData = [
            'exception' => get_class($e),
        ];

        if ($e instanceof ConnectException) {
            $errorData['connection_error'] = $e->getMessage();
            throw ProvisionFunctionError::create('Provider API Connection error', $e)
                ->withData($errorData);
        }

        if ($e instanceof TransferException) {
            if ($e instanceof BadResponseException && $e->hasResponse()) {
                $response = $e->getResponse();
                $reason = $response->getReasonPhrase();
                $responseBody = trim($response->getBody()->__toString());
                $responseData = json_decode($responseBody, true);

                $errorData['http_code'] = $response->getStatusCode();
                if (isset($responseData)) {
                    $errorData['response_data'] = $responseData;
                } else {
                    $errorData['response_body'] = Str::limit($responseBody, 1000);
                }

                $messages = [];
                $errors = $responseData['errors'] ?? $responseData['error'] ?? [];
                foreach ($errors as $key => $value) {
                    if (is_array($value)) {
                        $messages[] = $key . ': ' . implode(', ', $value);
                    } else {
                        $messages[] = $value;
                    }
                }

                if ($messages) {
                    $errorMessage = implode(', ', $messages);
                }

                if (!isset($errorMessage) && $reason === 'Unauthorized') {
                    $errorMessage = 'Unauthorized - check credentials and whitelisted IPs';
                }

                if (Str::contains($errorMessage ?? '', 'account has been locked')) {
                    $errorMessage = 'Configuration account error';
                }
            }

            $errorMessage = sprintf('Provider API error: %s', $errorMessage ?? $reason ?? 'Unknown');
            throw ProvisionFunctionError::create($errorMessage)
                ->withData($errorData);
        }

        throw ProvisionFunctionError::create('Unknown Provider API Error', $e)
            ->withData($errorData);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getServerInfo(string $serverId): ?array
    {
        $response = $this->makeRequest("/virtual_machines/{$serverId}.json");
        $vm = $response['virtual_machine'];

        $primaryDisk = $this->getPrimaryDisk($serverId);

        $location = $this->getHypervisorLocation($vm['hypervisor_id']);

        $ipAddress = null;

        if (count($vm['ip_addresses']) == 1) {
            $ipAddress = $vm['ip_addresses'][0]['ip_address']['address'];
        } else {
            foreach ($vm['ip_addresses'] as $ip) {
                $ipAddress = filter_var(
                    $ip['ip_address']['address'],
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                );

                if ($ipAddress) {
                    break;
                }
            }
        }

        return [
            'instance_id' => (string) ($vm['identifier'] ?? 'Unknown'),
            'state' => $this->getState($vm),
            'label' => $vm['label'] ?? 'Unknown',
            'hostname' => $vm['hostname'] ?? 'Unknown',
            'ip_address' => $ipAddress,
            'image' => $vm['template_label'] ?? 'Unknown',
            'memory_mb' => (int) ($vm['memory'] ?? 0),
            'cpu_cores' => (int) ($vm['cpus'] ?? 0),
            'disk_mb' => (isset($primaryDisk['disk_size']) ? ((int) $primaryDisk['disk_size']) : 0) * 1024,
            'location' => $location ?? 'Unknown',
            'virtualization_type' => $vm['hypervisor_type'],
            'created_at' => isset($vm['created_at'])
                ? Carbon::parse((string)$vm['created_at'])->format('Y-m-d H:i:s')
                : null,
            'updated_at' => isset($vm['updated_at'])
                ? Carbon::parse((string)$vm['updated_at'])->format('Y-m-d H:i:s')
                : null,
        ];
    }

    public function getState(array $vm): string
    {
        if ($vm['booted']) {
            $state = 'On';
        } else {
            $state = 'Off';
        }

        if ($vm['locked']) {
            $state = 'Locked';
        }

        return $state;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getPassword(string $serverId): ?string
    {
        $response = $this->makeRequest("/virtual_machines/{$serverId}.json");
        $vm = $response['virtual_machine'];

        return (string)$vm['initial_root_password'];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function create(CreateParams $params): string
    {
        $locationId = $this->findLocation($params->location)['id'];
        $templateId = $this->findTemplate($params->image)['id'];

        $body = [
            'virtual_machine' => [
                'cpu_shares' => 1,
                'hostname' => $params->label,
                'label' => $params->label,
                'template_id' => $templateId,
                'memory' => $params->memory_mb,
                'cpus' => $params->cpu_cores,
                'primary_disk_size' => (int)round(($params->disk_mb) / 1024),
                'required_virtual_machine_build' => 1,
                'required_virtual_machine_startup' => 1,
                'location_id' => $locationId,
                'initial_root_password' => $params->root_password,
            ]
        ];

        $response = $this->makeRequest("/virtual_machines.json", null, $body, 'POST');

        if (!$id = $response['virtual_machine']['identifier']) {
            throw ProvisionFunctionError::create('Server creation failed')
            ->withData([
                'result_data' => $response,
            ]);
        }

        return $id;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function changePassword(string $serverId, string $password): void
    {
        $body = [
            'virtual_machine' => [
                'initial_root_password' => $password,
            ]
        ];

        $this->makeRequest("/virtual_machines/{$serverId}/reset_password.json", null, $body, 'POST');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function resize(string $serverId, ResizeParams $params): void
    {
        $primaryDisk = $this->getPrimaryDisk($serverId);

        $body = [
            'virtual_machine' => [
                'memory' => $params->memory_mb,
                'cpus' => $params->cpu_cores,
            ]
        ];

        $this->makeRequest("/virtual_machines/{$serverId}.json", null, $body, 'PUT');

        $body = [
            'disk' => [
                'disk_size' => ($params->disk_mb) / 1024,
            ]
        ];

        $this->makeRequest("/virtual_machines/{$serverId}/disks/{$primaryDisk['id']}.json", null, $body, 'PUT');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getPrimaryDisk(string $serverId): array
    {
        $response = $this->makeRequest("/virtual_machines/{$serverId}/disks.json");
        return $response[0]['disk'];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function reboot(string $serverId): void
    {
        $this->makeRequest("/virtual_machines/{$serverId}/reboot.json", null, null, 'POST');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function shutdown(string $serverId): void
    {
        $this->makeRequest("/virtual_machines/{$serverId}/shutdown.json", null, null, 'POST');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function start(string $serverId): void
    {
        $this->makeRequest("/virtual_machines/{$serverId}/startup.json", null, null, 'POST');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function destroy(string $serverId): void
    {
        $params = [
            'destroy_all_backups' => 1,
        ];

        $this->makeRequest("/virtual_machines/{$serverId}.json", $params, null, 'DELETE');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function rebuildServer(string $serverId, string $image): void
    {
        $templateId = $this->findTemplate($image)['id'];

        $body = [
            'virtual_machine' => [
                'template_id' => $templateId,
                'required_startup' => 1,
            ]
        ];

        $this->makeRequest("/virtual_machines/{$serverId}/build.json", null, $body, 'POST');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getTemplate(int $templateId): array
    {
        $response = $this->makeRequest("/templates/{$templateId}.json");
        return $response['image_template'];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function listTemplates(): array
    {
        return $this->makeRequest("/templates/system.json");
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function findTemplate($templateName): array
    {
        if (is_numeric($templateName)) {
            return $this->getTemplate((int)$templateName);
        }

        // $response = $this->makeRequest('/templates/available.json?search_filter[query]=' . $templateName);
        // if (!$template = $response[0]['remote_template']) {
        //     throw ProvisionFunctionError::create('Image template not found')
        //         ->withData([
        //             'template' => $templateName,
        //         ]);
        // }

        // return $template;

        foreach ($this->listTemplates() as $template) {
            $template = $template['image_template'];
            if ($templateName === $template['label']) {
                return $template;
            }
        }

        throw ProvisionFunctionError::create('Template not found')
        ->withData([
            'template' => $templateName,
        ]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getLocation(int $locationId): array
    {
        $response = $this->makeRequest("/settings/location_groups/{$locationId}.json");
        return $response['location_group'];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function listLocations(): array
    {
        return $this->makeRequest("/settings/location_groups.json");
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function findLocation($locationName): array
    {
        if (is_numeric($locationName)) {
            return $this->getLocation((int)$locationName);
        }

        foreach ($this->listLocations() as $location) {
            $location = $location['location_group'];
            if ($locationName === $this->getLocationName($location['city'], $location['country'])) {
                return $location;
            }
        }

        throw ProvisionFunctionError::create('Location not found')
        ->withData([
            'location' => $locationName,
        ]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getHypervisorLocation(int $hypervisor_id): ?string
    {
        $response = $this->makeRequest("/settings/hypervisors/{$hypervisor_id}.json");
        $hypervisorGroupId = $response['hypervisor']['hypervisor_group_id'];

        $response = $this->makeRequest("/settings/hypervisor_zones/{$hypervisorGroupId}.json");
        $locationId = $response['hypervisor_group']['location_group_id'];

        if (empty($locationId)) {
            return null;
        }

        $location = $this->getLocation((int)$locationId);

        return "{$location['country']} ({$location['city']})";
    }

    public function getLocationName(string $city, string $country): string
    {
        return "{$country} ({$city})";
    }
}
