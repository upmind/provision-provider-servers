<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\Virtfusion;

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
use Upmind\ProvisionProviders\Servers\Data\ServerInfoResult;
use Upmind\ProvisionProviders\Servers\Virtfusion\Data\Configuration;

class ApiClient
{
    protected Configuration $configuration;
    protected Client $client;

    public function __construct(Configuration $configuration, ?HandlerStack $handler = null)
    {
        $this->configuration = $configuration;

        $this->client = new Client([
            'base_uri' => sprintf('https://%s', $configuration->hostname),
            'headers' => [
                'Accept' => 'application/json',
                'Content-type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->configuration->api_token,
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

            $response = $this->client->request($method, '/api/v1/' . ltrim($command, '/'), $requestParams);
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
                $errorData['http_code'] = $response->getStatusCode();
                $responseBody = $response->getBody()->__toString();
                $responseData = json_decode($responseBody, true);
                $errorData['response_data'] = $responseData;

                if (isset($responseData['errors'])) {
                    $errors = collect((array)$responseData['errors'])->first();
                    if (is_array($errors)) {
                        $errorMessage = $errors[0] ?? null;
                    }
                    if (is_string($errors)) {
                        $errorMessage = $errors;
                    }
                }

                if (empty($errorMessage)) {
                    $errorMessage = $responseData['msg'] ?? null;
                }

                if (empty($errorMessage)) {
                    $errorMessage = $responseData['message'] ?? null;
                }
            }

            $errorMessage = sprintf('Provider API error: %s', ucfirst($errorMessage ?? $reason ?? 'Unknown'));
            throw ProvisionFunctionError::create($errorMessage, $e)
                ->withData($errorData);
        }

        throw ProvisionFunctionError::create('Unknown Provider API Error', $e)
            ->withData($errorData);
    }

    public function retrieveServer(int $serverId, bool $withRemoteState = false): array
    {
        $response = $this->makeRequest("/servers/{$serverId}", ['remoteState' => $withRemoteState ? 'true' : 'false']);
        return $response['data'];
    }

    /**
     * @param int|string $serverId
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getServerInfo($serverId): ServerInfoResult
    {
        $data = $this->retrieveServer((int)$serverId, true);

        $state = ucfirst((string)($data['remoteState']['state'] ?? $data['state'] ?? 'unknown'));

        $ipv4Interfaces = collect($data['network']['interfaces'])
        ->sortByDesc('enabled')
            ->first()['ipv4'] ?? [];
        $ipAddress = collect($ipv4Interfaces)
            ->sortByDesc('enabled')
            ->first()['address'] ?? null;

        $image = $this->getServerImage($serverId, $data['settings']['osTemplateInstallId'] ?? null, false);
        if ($image) {
            $imageName = sprintf('%s %s %s', $image['name'], $image['version'], $image['variant']);
        }

        return new ServerInfoResult([
            'customer_identifier' => (int)$data['ownerId'],
            'instance_id' => (string)($data['id'] ?? 'Unknown'),
            'state' => $state,
            'suspended' => (bool)$data['suspended'],
            'label' => $data['name'] ?: 'Unknown',
            'hostname' => $data['hostname'] ?: 'Unknown',
            'ip_address' => $ipAddress,
            'image' => $imageName ?? 'Unknown',
            'memory_mb' => (int)($data['settings']['resources']['memory'] ?? 0),
            'cpu_cores' => (int)($data['settings']['resources']['cpuCores'] ?? 0),
            'disk_mb' => (isset($data['settings']['resources']['storage']) ? ((int)$data['settings']['resources']['storage']) : 0) * 1024,
            'location' => $data['hypervisor']['name'] ?? 'Unknown',
            'virtualization_type' => $data['settings']['hyperv']['vendorIdValue'] ?? 'Unknown',
            'created_at' => isset($data['created'])
                ? Carbon::parse((string)$data['created'])->format('Y-m-d H:i:s')
                : null,
            'updated_at' => isset($data['updated'])
                ? Carbon::parse((string)$data['updated'])->format('Y-m-d H:i:s')
                : null,
        ]);
    }

    /**
     * @param string|int $image Image ID or name
     */
    public function getImage(int $packageId, $image): array
    {
        $response = $this->makeRequest("/media/templates/fromServerPackageSpec/{$packageId}");
        foreach ($response['data'] as $group) {
            foreach ($group['templates'] as $template) {
                if ($template['id'] === (int)$image) {
                    return $template;
                }

                if (sprintf('%s %s', $template['name'], $template['version']) === (string)$image) {
                    return $template;
                }

                if (sprintf('%s %s %s', $template['name'], $template['version'], $template['variant']) === (string)$image) {
                    return $template;
                }
            }
        }

        throw ProvisionFunctionError::create("Package image {$image} not found")
            ->withData([
                'package_id' => $packageId,
            ]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getServerImage(string $serverId, $image, bool $orFail = true): ?array
    {
        $response = $this->makeRequest("/servers/{$serverId}/templates");
        $data = $response['data'];
        foreach ($data as $group) {
            foreach ($group['templates'] as $template) {
                if ($template['id'] === (int)$image) {
                    return $template;
                }

                if (sprintf('%s %s', $template['name'], $template['version']) === (string)$image) {
                    return $template;
                }

                if (sprintf('%s %s %s', $template['name'], $template['version'], $template['variant']) === (string)$image) {
                    return $template;
                }
            }
        }

        if ($orFail) {
            throw ProvisionFunctionError::create("Server image {$image} not found")
                ->withData([
                    'server_id' => $serverId,
                ]);
        }

        return null;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getVNC(string $serverId): array
    {
        $body = [
            'action' => 'enable'
        ];

        $response = $this->makeRequest("/servers/{$serverId}/vnc", null, $body, 'POST');
        return $response['data']['vnc'];
    }

    /**
     * @link https://docs.virtfusion.com/api/#api-Users-Generate_a_set_of_login_tokens_for_a_user_based_on_a_server_id_and_ext_relation_id
     */
    public function getAuthenticationTokens(int $extUserId, int $serverId): array
    {
        $response = $this->makeRequest("/users/{$extUserId}/serverAuthenticationTokens/{$serverId}", null, null, 'POST');
        return $response['data']['authentication'];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function create(CreateParams $params): string
    {
        $userId = (int)$params->customer_identifier;
        if (!$userId) {
            $userId = $this->createUser($params);
        }

        if (!is_numeric($params->size)) {
            $packageId = $this->getPackageId($params->size);
        } else {
            $packageId = (int)$params->size;
        }

        $image = $this->getImage($packageId, $params->image);

        $body = [
            'userId' => $userId,
            'packageId' => $packageId,
            'ipv4' => 1,
            'hypervisorId' => $params->location, // This actually means hypervisor group id
            // 'cpuCores' => $params->cpu_cores,
            // 'storage' => (int)round(($params->disk_mb) / 1024),
            // 'memory' => $params->memory_mb,
        ];

        $response = $this->makeRequest("/servers", null, $body, 'POST');

        if (!$id = $response['data']['id']) {
            throw ProvisionFunctionError::create('Server creation failed')
                ->withData([
                    'result_data' => $response,
                ]);
        }

        $sshKeyIds = $this->getUserSshKeyIds($userId);

        try {
            $this->buildServer($id, $params->label, $image['id'], $sshKeyIds);
        } catch (\Exception $e) {
            throw ProvisionFunctionError::create('Server building failed', $e)
                ->withData([
                    'result_data' => $response,
                ]);
        }

        return (string)$id;
    }

    /**
     * @link https://docs.virtfusion.com/api/#api-Users-Create
     *
     * @return int User ID
     */
    public function createUser(CreateParams $params): int
    {
        $response = $this->makeRequest('/users', null, [
            'name' => $params->customer_name ?: $params->email,
            'email' => $params->email,
            'extRelationId' => $params->upmind_client_int_id, // we need this for SSO later on
            'sendMail' => true,
        ], 'POST');

        return $response['data']['id'];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function resetPassword(string $serverId): void
    {
        $body = [
            'user' => 'root',
            'sendMail' => true,
        ];

        $this->makeRequest("/servers/{$serverId}/resetPassword", null, $body, 'POST');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function suspend(string $serverId): void
    {
        $this->makeRequest("/servers/$serverId}/suspend", null, null, 'POST');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function unsuspend(string $serverId): void
    {
        $this->makeRequest("/servers/$serverId}/unsuspend", null, null, 'POST');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function power(string $serverId, string $action): void
    {
        $this->makeRequest("/servers/{$serverId}/power/{$action}", null, null, 'POST');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function destroy(string $serverId): void
    {
        $params = [
            'delay' => 0,
        ];

        $this->makeRequest("/servers/{$serverId}", $params, null, 'DELETE');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function changePackage(string $serverId, string $size): array
    {
        if (!is_numeric($size)) {
            $size = $this->getPackageId($size);
        }

        return $this->makeRequest("/servers/{$serverId}/package/{$size}", null, null, 'PUT');
    }

    /**
     * @param string $packageName
     * @throws Throwable
     */
    private function getPackageId($packageName): int
    {
        $response = $this->makeRequest("/packages");
        $data = $response['data'];
        foreach ($data as $package) {
            if ($package['name'] == $packageName) {
                return $package['id'];
            }
        }

        throw ProvisionFunctionError::create("Package {$packageName} not found")
            ->withData([
                'response' => $response,
            ]);
    }

    /**
     * @return int[]
     */
    public function getUserSshKeyIds(int $userId): array
    {
        $response = $this->makeRequest("ssh_keys/user/{$userId}");
        return collect($response['data'])->where('enabled', true)->pluck('id')->toArray();
    }

    /**
     * @param int[] $sshKeyIds
     */
    public function buildServer(int $serverId, ?string $label, int $image, array $sshKeyIds = []): void
    {
        $hostname = Str::slug($label, '.');
        if (!Str::contains($hostname, '.')) {
            $hostname .= '.host';
        }

        $body = [
            'name' => $label,
            'hostname' => $hostname,
            'operatingSystemId' => $image,
            'sshKeys' => $sshKeyIds,
        ];

        $this->makeRequest("/servers/{$serverId}/build", null, $body, 'POST');
    }
}
