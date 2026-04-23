<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\TwentyI;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Throwable;
use TwentyI\API\Exception;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\Servers\TwentyI\Helper\Services;
use Upmind\ProvisionProviders\Servers\Data\CreateParams;
use Upmind\ProvisionProviders\Servers\TwentyI\Data\Configuration;


class ApiClient
{
    protected Configuration $configuration;
    protected Client $client;

    /**
     * @var Services $services
     */
    protected Services $services;

    private array $serverSizeMapping = [
        "1" => "vps-a", "2" => "vps-b", "4" => "vps-c",
        "6" => "vps-d", "8" => "vps-e", "10" => "vps-f",
        "12" => "vps-g", "16" => "vps-h", "20" => "vps-i"
    ];

    public function __construct(Configuration $configuration, ?LoggerInterface $logger = null)
    {
        $this->configuration = $configuration;

        $this->services = new Services($configuration->general_api_key);
        $this->services->setLogger($logger);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getServerInfo(string $serverId): ?array
    {
        $response = $this->services->getWithFields("/vps/$serverId");
        $configuration = $response->configuration;

        return [
            'customer_identifier' => "",
            'instance_id' => (string)($response->id ?? 'Unknown'),
            'state' => $response->Status->PendingAction && $response->Status->CurrentAction === "nothing"
                ? "pending"
                : ($response->Status->Domstate ?? 'Unknown'),
            'suspended' => false,
            'label' => $response->name ?? 'Unknown',
            'hostname' => $response->name ?? 'Unknown',
            'ip_address' => $response->Network[0]->Addresses[0]->IpAddress ?? 'Unknown',
            'image' => $response->OS->DisplayName ?? 'Unknown',
            'memory_mb' => (int)($configuration->RamMb ?? 0),
            'cpu_cores' => (int)($configuration->CpuCores ?? 0),
            'disk_mb' => (int)($configuration->OsDiskSizeGb ?? 0) * 1024,
            'location' => $configuration->location ?? 'Unknown',
            'virtualization_type' => 'kvm',
            'created_at' => $response->CreatedAt
                ? Carbon::parse($response->CreatedAt)->format('Y-m-d H:i:s')
                : null,
            'updated_at' => $response->UpdatedAt
                ? Carbon::parse($response->UpdatedAt)->format('Y-m-d H:i:s')
                : null,
        ];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getVNC(string $serverId): array
    {
        $response = $this->services->getWithFields("/vps/$serverId");

        return [
            'ip_address' => $response->Network[0]->Addresses[0]->IpAddress ?? 'Unknown',
            'password' => $response->SuperPassword,
        ];
    }


    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function create(CreateParams $params): string
    {
        $response = $this->services->postWithFields("/reseller/*/addVPS", [
            "configuration" => [
                "Name" => $params->label
            ],
            "forUser" => null,
            "options" => [
                "os" => $params->image
            ],
            "periodMonths" => 1,
            "type" => $this->getTypeName($params->size)
        ]);

        if (is_bool($response)) {
            return $params->label;
        }

        return $response->result;
    }

    private function getTypeName(string $size): string
    {
        if (preg_match('/^vps-[a-i]$/', $size)) {
            return $size;
        }

        $type = $this->serverSizeMapping[$size] ?? null;
        if (!$type) {
            throw ProvisionFunctionError::create("Server size {$size} not found");
        }

        return $type;
    }


    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function changePassword(string $serverId, string $password): void
    {
        $this->services->postWithFields("/vps/$serverId/changePassword", [
            "password" => $password
        ]);
    }


    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function suspend(string $serverId): void
    {
        $this->services->postWithFields("/vps/$serverId/userStatus", [
            'includeRepeated' => true,
            'subservices' => [
                'default' => false
            ]
        ]);
    }


    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function unsuspend(string $serverId): void
    {
        $this->services->postWithFields("/vps/$serverId/userStatus", [
            'includeRepeated' => true,
            'subservices' => [
                "default" => true
            ]
        ]);
    }


    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function reboot(string $serverId): void
    {
        $this->services->postWithFields("/vps/$serverId/reboot", []);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function shutdown(string $serverId): void
    {
        $this->services->postWithFields("/vps/$serverId/stop", []);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function start(string $serverId): void
    {
        $this->services->postWithFields("/vps/$serverId/start", []);
    }


    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function rebuildServer(string $serverId, string $image): void
    {
        $this->services->postWithFields("/vps/$serverId/rebuild", [
            'cpanel' => false,
            'cpanelCode' => false,
            'VpsOsId' => $image,
        ]);
    }

}
