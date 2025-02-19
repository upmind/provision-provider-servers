<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\Virtualizor;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Helper;
use Upmind\ProvisionProviders\Servers\Virtualizor\Data\Configuration;

class ApiClient
{
    protected Configuration $configuration;
    protected Client $client;

    public function __construct(Configuration $configuration, ?HandlerStack $handler = null)
    {
        $this->configuration = $configuration;
        $this->client = new Client([
            'handler' => $handler,
            'base_uri' => sprintf('https://%s:%s/', $configuration->hostname, $configuration->port ?: 4085),
            'headers' => [
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
            'verify' => !($configuration->ignore_ssl_errors ?? true),
            'connect_timeout' => 10,
            'timeout' => 60, // this API is SLOW!
            'allow_redirects' => false,
        ]);
    }

    /**
     * @param string $virtualizationType
     * @param int|string $planId
     * @param int|string $osId
     * @param int|string|null $serverGroupId
     * @param int|string|null $serverId
     * @param string $hostname
     * @param string $email
     * @param string|null $password
     */
    public function createVirtualServer(
        string $virtualizationType,
        $planId,
        $osId,
        $serverGroupId,
        $serverId,
        string $hostname,
        string $email,
        ?string $password
    ): array {
        $password ??= Helper::generateStrictPassword(16, true, true, true);

        $data = $this->apiCall('addvs', [], [
            'virt' => $virtualizationType,
            'node_select' => 1,
            'server_group' => $serverGroupId,
            'slave_server' => $serverId,
            'plid' => $planId,
            'osid' => $osId,
            'hostname' => $hostname,
            'user_email' => $email,
            'user_pass' => $password,
            'rootpass' => $password,
            'control_panel' => 0,
            'addvps' => 1,
        ]);

        if (empty($data['done'])) {
            throw $this->throwError('Virtual server creation unsuccessful', [
                'virt' => $virtualizationType,
                'node_select' => 0,
                'slave_server' => $serverId,
                'plid' => $planId,
                'osid' => $osId,
                'hostname' => $hostname,
                'user_email' => $email,
                'response_data' => $this->condenseResponseData($data),
            ]);
        }

        $data['vpsid'] ??= $data['done'];

        return $data;
    }

    /**
     * @param int|string $vpsId
     * @param string $action start/stop/restart/poweroff
     */
    public function runVirtualServerAction($vpsId, string $action): array
    {
        $data = $this->apiCall('vs', [
            'vpsid' => $vpsId,
            'action' => $action,
        ]);

        if (empty($data['done'])) {
            throw $this->throwError('Virtual server shutdown unsuccessful', [
                'vpsid' => $vpsId,
                'action' => $action,
                'response_data' => $this->condenseResponseData($data),
            ]);
        }

        return $data;
    }

    /**
     * @param int|string $vpsId
     */
    public function getVirtualServer($vpsId): array
    {
        $data = $this->apiCall('vs', [], ['vpsid' => $vpsId]);

        if (empty($data['vs'][$vpsId])) {
            throw $this->throwError('Virtual server not found', [
                'vpsid' => $vpsId,
                'response_data' => $this->condenseResponseData($data),
            ]);
        }

        return $data['vs'][$vpsId];
    }

    /**
     * @param int|string $vpsId
     */
    public function getVirtualServerStatus($vpsId): array
    {
        $data = $this->apiCall('vs_status', ['vs_status' => [$vpsId]]);

        if (empty($data['vs'][$vpsId])) {
            throw $this->throwError('Virtual server not found', [
                'vpsid' => $vpsId,
                'response_data' => $this->condenseResponseData($data),
            ]);
        }

        return $data['vs'][$vpsId];
    }

    /**
     * @param int|string $vpsId
     */
    public function changeVirtualServerRootPass($vpsId, string $rootPassword): array
    {
        // $data = $this->apiCall('editvs', ['vpsid' => $vpsId], [
        //     'rootpass' => $rootPassword,
        //     'editvps' => 1,
        // ]);
        $data = $this->apiCall('managevps', ['vpsid' => $vpsId], [
            'rootpass' => $rootPassword,
            'enable_guest_agent' => 1,
            'editvps' => 1,
        ]);

        if (empty($data['done']['change_pass_msg']) && empty($data['done']['done'])) { // this is insane <:'(
            throw $this->throwError('Virtual server password change unsuccessful', [
                'vpsid' => $vpsId,
                'response_data' => $this->condenseResponseData($data),
            ]);
        }

        return $data;
    }

    /**
     * @param int|string $vpsId
     * @param int|string $planId
     */
    public function changeVirtualServerPlan($vpsId, $planId): array
    {
        $data = $this->apiCall('editvs', ['vpsid' => $vpsId], [
            'plid' => $planId,
            'editvps' => 1,
        ]);

        if (empty($data['done'])) {
            throw $this->throwError('Virtual server plan change unsuccessful', [
                'vpsid' => $vpsId,
                'response_data' => $this->condenseResponseData($data),
            ]);
        }

        return $data;
    }

    /**
     * @param int|string $vpsId
     */
    public function getAllVirtualServerInfo($vpsId): array
    {
        $data = $this->apiCall('editvs', ['vpsid' => $vpsId]);

        if (empty($data['vps'])) {
            throw $this->throwError('Virtual server not found', [
                'vpsid' => $vpsId,
                'response_data' => $this->condenseResponseData($data),
            ]);
        }

        return $data;
    }

    /**
     * @param int|string $vpsId
     */
    public function getVncInfo($vpsId): array
    {
        $data = $this->apiCall('vnc', ['novnc' => $vpsId]);

        return $data['info'];
    }

    /**
     * @param int|string|null $planId
     * @param string|null $planName
     * @param string|null $virtualizationType
     */
    public function getPlan(
        $planId,
        ?string $planName = null,
        ?string $virtualizationType = null,
        bool $orFail = true
    ): ?array {
        if (empty($planId) && empty($planName)) {
            throw $this->throwError('Size parameter is required');
        }

        $query = ['page' => 1, 'reslen' => 100];
        $post = [];

        if ($planName) {
            $post['planname'] = $planName;
        }

        if ($virtualizationType) {
            $post['ptype'] = $virtualizationType;
        }

        if (is_numeric($planId) || !empty($planName)) {
            do {
                $data = $this->apiCall('plans', $query, $post);

                foreach ($data['plans'] ?? [] as $plan) {
                    if (is_numeric($planId)) {
                        if ((string)$planId === (string)$plan['plid']) {
                            return $plan;
                        }
                    } elseif ($planName === $plan['plan_name']) {
                        return $plan;
                    }
                }

                $query['page']++;
            } while (!empty($data['plans']));
        }

        if ($orFail) {
            $error = 'Plan not found';
            if ($virtualizationType) {
                $error = ucfirst($virtualizationType) . ' ' . lcfirst($error);
            }
            throw $this->throwError($error, [
                'plid' => $planId,
                'planname' => $planName,
                'virt' => $virtualizationType,
            ]);
        }

        return null;
    }

    /**
     * @param int|string|null $groupId
     * @param string|null $groupName
     */
    public function getServerGroup(
        $groupId,
        ?string $groupName = null,
        bool $orFail = true
    ): ?array {
        $query = ['page' => 1, 'reslen' => 100];
        $post = [];

        if ($groupName) {
            $post['sg_name'] = $groupName;
        }

        if (is_numeric($groupId) || !empty($groupName)) {
            do {
                $data = $this->apiCall('servergroups', $query, $post);

                foreach ($data['servergroups'] ?? [] as $group) {
                    if (is_numeric($groupId)) {
                        if ((string)$groupId === (string)$group['sgid']) {
                            return $group;
                        }
                    } elseif ($groupName === $group['sg_name']) {
                        return $group;
                    }
                }

                $query['page']++;
            } while (!empty($data['servergroups']));
        }

        if ($orFail) {
            throw $this->throwError('Server group not found', [
                'sgid' => $groupId,
                'sg_name' => $groupName,
            ]);
        }

        return null;
    }

    /**
     * @param int|string|null $serverId
     * @param string|null $serverName
     * @param string|null $location
     */
    public function getServer(
        $serverId,
        ?string $serverName = null,
        ?string $location = null,
        bool $orFail = true
    ): ?array {
        $query = ['page' => 1, 'reslen' => 100];
        $post = [];

        if ($serverName) {
            $post['servername'] = $serverName;
        }

        if (is_numeric($serverId) || !empty($serverName) || !empty($location)) {
            do {
                $data = $this->apiCall('servers', $query, $post);

                foreach ($data['servs'] as $server) {
                    if (is_numeric($serverId)) {
                        if ((string)$serverId === (string)$server['serid']) {
                            return $server;
                        }
                    } elseif ($serverName && $serverName === $server['server_name']) {
                        return $server;
                    } elseif ($location && $location === self::locationJsonToString($server['location'])) {
                        return $server;
                    }
                }

                $query['page']++;
            } while (!empty($data['servs']));
        }

        if ($orFail) {
            throw $this->throwError('Host server not found', [
                'serid' => $serverId,
                'servername' => $serverName,
                'location' => $location,
            ]);
        }

        return null;
    }

    /**
     * @param int|string|null $osId
     * @param string|null $osName
     */
    public function getOsTemplate($osId, ?string $osName = null): array
    {
        $data = $this->apiCall('ostemplates');

        $osTemplates = collect($data['ostemplates'])
            ->map(function ($os, $osId) {
                $os['osid'] = $osId;
                return $os;
            });

        if (is_numeric($osId) && isset($osTemplates[$osId])) {
            return $osTemplates[$osId];
        }

        foreach ($osTemplates as $os) {
            if ($osName === $os['name']) {
                return $os;
            }
        }

        throw $this->throwError('OS template not found', [
            'osid' => $osId,
            'name' => $osName,
        ]);
    }

    /**
     * @param int|string $vpsId
     * @param int|string $osId
     * @param int|string|null $serverId
     * @param null|string $password
     */
    public function rebuildVirtualServer($vpsId, $osId, $serverId = null, ?string $password = null): array
    {
        $password ??= Helper::generateStrictPassword(16, true, true, true);

        $query = [];

        if ($serverId) {
            $query['changeserid'] = $serverId;
        }

        $data = $this->apiCall('rebuild', $query, [
            'vpsid' => $vpsId,
            'osid' => $osId,
            'newos' => $osId,
            'newpass' => $password,
            'conf' => $password,
            'control_panel' => 0,
            'reos' => 1,
            'format_primary' => 0,
            'eu_send_rebuild_email' => 0,
        ]);

        if (empty($data['done'])) {
            throw $this->throwError('Virtual server rebuild unsuccessful', [
                'vpsid' => $vpsId,
                'osid' => $osId,
                'vps' => $data['vpses'][$vpsId],
                'response_data' => $this->condenseResponseData($data),
            ]);
        }

        return $data;
    }

    /**
     * @param int|string $vpsId
     */
    public function suspendVirtualServer($vpsId): array
    {
        $data = $this->apiCall('vs', ['suspend' => $vpsId]);

        if (empty($data['done'])) {
            throw $this->throwError('Virtual server suspend unsuccessful', [
                'vpsid' => $vpsId,
                'response_data' => $this->condenseResponseData($data),
            ]);
        }

        return $data;
    }

    /**
     * @param int|string $vpsId
     */
    public function unsuspendVirtualServer($vpsId): array
    {
        $data = $this->apiCall('vs', ['unsuspend' => $vpsId]);

        if (empty($data['done'])) {
            throw $this->throwError('Virtual server unsuspend unsuccessful', [
                'vpsid' => $vpsId,
                'response_data' => $this->condenseResponseData($data),
            ]);
        }

        return $data;
    }

    /**
     * @param int|string $vpsId
     */
    public function deleteVirtualServer($vpsId): array
    {
        $data = $this->apiCall('vs', ['delete' => $vpsId]);

        if (empty($data['done'])) {
            throw $this->throwError('Virtual server delete unsuccessful', [
                'vpsid' => $vpsId,
                'response_data' => $this->condenseResponseData($data),
            ]);
        }

        return $data;
    }

    /**
     * @param int|string $vpsId
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getSsoUrl($vpsId): string
    {
        $data = $this->apiCall('sso', ['svs' => $vpsId]);

        if (empty($data['sid'] || empty($data['token_key']))) {
            $this->throwError('Unable to obtain SSO url', [
                'vpsid' => $vpsId,
                'response_data' => $this->condenseResponseData($data),
            ]);
        }

        return sprintf('https://%s:4083/%s/?as=%s&svs=%s', $this->configuration->hostname, $data['token_key'], $data['sid'], $vpsId);
    }

    /**
     * @param string $act API Action
     * @param mixed[] $query Query params
     * @param mixed[] $post POST body params
     *
     * @return mixed[]
     */
    public function apiCall(string $act, array $query = [], array $post = []): array
    {
        $query = array_merge($query, [
            'api' => 'json',
            'act' => $act,
            'adminapikey' => $this->configuration->api_key,
            'adminapipass' => $this->configuration->api_password,
            'apikey' => $this->getApiKeyHash(),
        ]);

        try {
            $response = $this->client->post('index.php', [
                RequestOptions::QUERY => $query,
                RequestOptions::FORM_PARAMS => $post,
            ]);
        } catch (ConnectException $e) {
            $errorMessage = 'Provider API connection failed';
            if (Str::contains($e->getMessage(), ['timeout', 'timed out'])) {
                $errorMessage = 'Provider API request timeout';
            }

            throw $this->throwError($errorMessage, [], [], $e);
        }

        $responseData = json_decode($response->getBody()->__toString(), true) ?? [];

        $this->checkResponse($response, $responseData);

        return $responseData;
    }

    /**
     * @throws ProvisionFunctionError
     */
    protected function checkResponse(Response $response, ?array $responseData = null): void
    {
        $responseBody = $response->getBody()->__toString();
        $responseData ??= json_decode($responseBody, true) ?? [];

        if (
            !empty($responseData['fatal_error_text'])
            || !empty($responseData['error_heading'])
            || !empty($responseData['error'])
        ) {
            $errorMessage = 'API Error';

            if (!empty($responseData['title'])) {
                $errorMessage .= sprintf(' [%s]', $responseData['title']);
            }

            if (!empty($responseData['fatal_error_heading'])) {
                $errorMessage .= ': ' . $responseData['fatal_error_heading'];
            }

            if (!empty($responseData['fatal_error_text'])) {
                $errorMessage .= ': ' . $responseData['fatal_error_text'];
            }

            if (!empty($responseData['error'])) {
                $errors = implode(', ', Arr::wrap($responseData['error']));

                if ($errorMessage) {
                    $errorMessage .= ': ' . $errors;
                } else {
                    $errorMessage = ' - ' . $errors;
                }
            }
        }

        $errorData = [
            'http_code' => $response->getStatusCode(),
            'response_data' => $this->condenseResponseData($responseData),
        ];

        if (empty($responseData)) {
            unset($errorData['response_data']);
            $errorData['response_body'] = Str::limit($responseBody, 1000);
        }

        if (!empty($errorMessage)) {
            throw $this->throwError($errorMessage, $errorData);
        }

        if ($response->getStatusCode() !== 200) {
            throw $this->throwError(sprintf('API %s Error', $response->getStatusCode()), $errorData);
        }
    }

    /**
     * Condenses the sometimes gigantic response data to make it more readable in logs
     * and consume less bandwidth when sending responses over the network.
     */
    protected function condenseResponseData(?array $responseData): ?array
    {
        if (empty($responseData)) {
            return $responseData;
        }

        $redact = [
            'vs',
            'vpses',
            'ostemplates',
            'scripts',
            'plans',
            'servers',
            'users',
            'ips',
        ];

        foreach ($redact as $attribute) {
            if (isset($responseData[$attribute]) && count($responseData[$attribute]) > 1) {
                $responseData[$attribute] = sprintf('[redacted %s %s]', count($responseData[$attribute]), $attribute);
            }
        }

        return $responseData;
    }

    /**
     * Get API authentication key hash.
     */
    protected function getApiKeyHash(): string
    {
        $randomString = Helper::generateStrictPassword(8, false, true, false);

        return $randomString . md5($this->configuration->api_password . $randomString);
    }

    /**
     * @return no-return
     *
     * @throws ProvisionFunctionError
     */
    protected function throwError(string $message, array $data = [], array $debug = [], ?Throwable $e = null): void
    {
        throw ProvisionFunctionError::create($message, $e)
            ->withData($data)
            ->withDebug($debug);
    }

    /**
     * Transform a server location JSON string to a formatted location string.
     */
    public static function locationJsonToString(?string $locationJson): string
    {
        if (empty($locationJson)) {
            return 'Unknown';
        }

        $locationData = json_decode($locationJson);
        if (is_null($locationData)) {
            // maybe it was already a formatted string?
            return $locationJson;
        }

        $location = $locationData->country_code ?? '';

        if (!empty($locationData->state)) {
            $location = $locationData->state . ', ' . $location;
        }

        if (!empty($locationData->city)) {
            $location = $locationData->city . ', ' . $location;
        }

        return rtrim($location, ', ') ?: 'Unknown';
    }

    /**
     * Transform an integer status number to a formatted status string.
     *
     * @param int|string|null $statusId
     */
    public static function statusNumberToString($statusId): string
    {
        if (!is_numeric($statusId)) {
            return 'Unknown';
        }

        $statusMap = [
            0 => 'Off',
            1 => 'On',
            2 => 'Suspended',
        ];

        return $statusMap[$statusId] ?? 'Unknown';
    }
}
