<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\HetznerRobot;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Helper;
use Upmind\ProvisionProviders\Servers\Data\CreateParams;
use Upmind\ProvisionProviders\Servers\HetznerRobot\Data\Configuration;

class RobotApiClient
{
    protected Configuration $configuration;
    protected Client $client;

    public function __construct(Configuration $configuration, ?HandlerStack $handler = null)
    {
        $this->configuration = $configuration;
        $this->client = new Client([
            'base_uri' => 'https://robot-ws.your-server.de',
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Upmind/ProvisionProviders/DomainNames/HetznerRobotApi',
            ],
            'auth' => [$this->configuration->api_login, $this->configuration->api_password],
            'connect_timeout' => 10,
            'timeout' => 30,
            'handler' => $handler,
        ]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function create(CreateParams $params): string
    {
        $product = $this->findProduct($params->size);

        if (!in_array($params->location, $product['location'])) {
            throw ProvisionFunctionError::create('Location not found')
                ->withData([
                    'result_data' => $product,
                ]);
        }

        if (!in_array($params->image, $product['dist'])) {
            throw ProvisionFunctionError::create('Image not found')
                ->withData([
                    'result_data' => $product,
                ]);
        }

        $body = [
            'product_id' => $product['id'],
            'password' => $params->root_password ?: Helper::generatePassword(15),
            'location' => $params->location,
            'dist' => $params->image,
            'addon' => ['primary_ipv4'],
        ];

        if ($this->configuration->test) {
            $body['test'] = 'true';
        }

        $response = $this->makeRequest("/order/server/transaction", null, $body, 'POST');

        if (!$id = $response['transaction']['id']) {
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
    public function updateServerName(string $serverId, string $label): void
    {
        $this->makeRequest("/server/{$serverId}", [
            'server_name' => $label
        ], null, 'POST');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function checkTransaction(string $transactionId): ?string
    {
        $response = $this->makeRequest("/order/server/transaction/{$transactionId}");
        return $response["transaction"]['server_number'];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function findProduct($productName): array
    {
        foreach ($this->listProducts() as $product) {
            if ($productName === $product['product']['id']) {
                return $product['product'];
            }
        }

        throw ProvisionFunctionError::create('Product not found')
            ->withData([
                'product' => $productName,
            ]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function listProducts(): array
    {
        return $this->makeRequest("/order/server/product");
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getServerInfo($serverId): array
    {
        $response = $this->makeRequest("server/{$serverId}")['server'];
        $image = 'Unknown';

        try {
            $boot = $this->makeRequest("/boot/{$serverId}/linux/last")['linux'];
            if (is_string($boot['dist'])) {
                $image = $boot['dist'];
            }
        } catch (Throwable $t) {
            // Ignore errors for fetching image info
        }

        $reset = $this->getReset($serverId);

        return [
            'instance_id' => (string)$response['server_number'],
            'state' => $reset['operating_status'] === 'running' ? 'on' : 'off',
            'label' => $response['server_name'] ?: 'N/A',
            'image' => $image,
            'ip_address' => $response['server_ip'],
            'size' => $response['product'],
            'location' => $response['dc'],
        ];
    }

    /**
     * @param $serverId
     * @return array
     * @throws Throwable
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function getReset($serverId): array
    {
        return $this->makeRequest("/reset/{$serverId}")['reset'];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function destroy(string $serverId): void
    {
        $this->makeRequest("/server/{$serverId}/cancellation", ['cancellation_date' => 'now'], null, 'POST');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function reboot(string $serverId): void
    {
        $this->makeRequest("/reset/{$serverId}", ['type' => 'hw'], null, 'POST');
    }

    /**
     * Method to change the power state of the server.
     * It will turn it `off` if currently `on` and `on` if currently `off`
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function toggle(string $serverId): void
    {
        $this->makeRequest("/reset/{$serverId}", ['type' => 'power'], null, 'POST');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */

    public function rebuildServer(string $serverId, string $image): void
    {

        $linux = $this->makeRequest("/boot/{$serverId}/linux")['linux'];

        if (!in_array($image, $linux['dist'])) {
            throw ProvisionFunctionError::create('Image not found')
                ->withData([
                    'result_data' => $linux,
                ]);
        }

        $this->makeRequest("/boot/{$serverId}/linux", ['dist' => $image, 'lang' => 'en'], null, 'POST');

        $this->makeRequest("/reset/{$serverId}", ['type' => 'hw'], null, 'POST');
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
    ): ?array
    {
        try {
            $requestParams = [];

            if ($params) {
                $requestParams['query'] = $params;
            }

            if ($body) {
                $requestParams['form_params'] = $body;
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

        if (($e instanceof ClientException) && $e->hasResponse()) {
            $response = $e->getResponse();
            $reason = $response->getReasonPhrase();
            $responseBody = $response->getBody()->__toString();
            $responseData = json_decode($responseBody, true);

            $messages = [];
            $errors = $responseData['error'];
            foreach ($errors as $key => $value) {
                if (is_array($value)) {
                    $messages[] = $key . ': ' . implode(', ', $value);
                } else {
                    $messages[] = $key . ': ' . $value;
                }
            }

            if ($messages) {
                $errorMessage = implode(', ', $messages);
            }

            $errorMessage = sprintf('Provider API error: %s', $errorMessage ?? $reason);
            throw ProvisionFunctionError::create($errorMessage)
                ->withData($errorData);

        }

        throw $e;
    }
}
