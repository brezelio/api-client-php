<?php

namespace Brezel\Client;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;

class Client
{
    private GuzzleClient $client;
    private ?string $apiKey;
    private ?string $bearerToken;
    private string $system;

    /**
     * Map of module names to class names or factory functions.
     *
     * @var array<string, string|callable>
     */
    private array $entityMap = [];
    private string $apiUrl;

    public ?string $shareUrl = null;

    public ?int $impersonateUserId = null;

    public function __construct(string $apiUrl, string $system, ?string $apiKey = null, ?string $bearerToken = null)
    {
        $this->client = new GuzzleClient(['base_uri' => $apiUrl]);
        $this->apiKey = $apiKey;
        $this->bearerToken = $bearerToken;
        $this->system = $system;
        $this->apiUrl = $apiUrl;
    }

    /**
     * Return a new client with the impersonated user.
     *
     * @param int $userId
     * @return $this
     */
    public function impersonated(int $userId): self
    {
        $client = clone $this;
        $client->impersonate($userId);
        return $client;
    }

    /**
     * Impersonate a user.
     *
     * @param int $userId
     * @return $this
     */
    public function impersonate(int $userId): self
    {
        $this->impersonateUserId = $userId;
        return $this;
    }

    public function registerModule(string $moduleName, string|callable $entityClass): void
    {
        $this->entityMap[$moduleName] = $entityClass;
    }

    private function getEntityClass(string $moduleName): string|callable
    {
        return $this->entityMap[$moduleName] ?? Entity::class;
    }

    /**
     * @template T
     * @param string $module
     * @param array $attributes
     * @return Entity
     */
    private function initEntity(string $module, array $attributes): Entity
    {
        /** @var Entity $class */
        $class = $this->getEntityClass($module);
        if (is_callable($class)) {
            return $class($attributes);
        }
        return new $class($attributes);
    }

    /**
     * @throws ApiException
     */
    public function getGeneralInfo(): array
    {
        return $this->getSystemRequest('general');
    }

    /**
     * @template T of Entity
     * @param string $module
     * @param int $page
     * @param array $filters
     * @param int|null $results
     * @param array $query
     * @return array<T>
     * @throws ApiException
     */
    public function getEntities(
        string $module,
        int $page = 1,
        array $filters = [],
        ?int $results = null,
        array $query = [],
        array $with = []
    ): array {
        $response = $this->getSystemRequest('modules/' . $module . '/resources', array_merge($query, [
            'page' => $page,
            'pre_filters' => json_encode($filters),
            'results' => $results,
            'with' => $with ? json_encode($with) : null,
        ]));
        $data = $response['data'] ?? [];
        return array_map(fn($attributes) => $this->initEntity($module, $attributes), $data);
    }

    /**
     * @template T of Entity
     * @param string $module
     * @param int $id
     * @return T of Entity|null
     * @throws ApiException
     */
    public function getEntity(string $module, int $id): ?Entity
    {
        try {
            $response = $this->getSystemRequest(['modules', $module, 'resources', $id]);
        } catch (ApiException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
        return $this->initEntity($module, $response);
    }

    /**
     * @throws ApiException
     */
    public function putEntity(string $module, int $id, array $params): array
    {
        return $this->putSystemRequest('modules/' . $module . '/resources/' . $id, $params);
    }

    /**
     * @param int $fileId
     * @return array
     * @throws ApiException
     */
    public function shareFile(int $fileId): array
    {
        return $this->postSystemRequest('files/' . $fileId . '/share');
    }

    /**
     * @throws ApiException
     */
    public function webhook(string $event, ?string $module, ?int $entityId): array|string
    {
        return $this->postSystemRequest(array_filter(['webhook', $event, $module, $entityId]));
    }

    /**
     * @throws ApiException
     */
    public function getSharedFileContents(string $shareToken): ?string
    {
        return $this->getSystemRequest(['shared', $shareToken]);
    }

    /**
     * @param string $shareToken
     * @return string|null
     */
    public function getSharedFileURL(string $shareToken): ?string
    {
        return $this->getSystemURL(['shared', $shareToken], $this->shareUrl);
    }

    /**
     * @throws ApiException
     */
    public function request(
        string $method,
        string|array $path,
        array $data = [],
        array $query = [],
        array $options = []
    ): array|string {
        $uri = is_array($path) ? implode('/', $path) : $path;
        if ($this->apiKey) {
            $options['headers']['X-API-Key'] = $this->apiKey;
        } elseif ($this->bearerToken) {
            $options['headers']['Authorization'] = 'Bearer ' . $this->bearerToken;
        }

        if ($this->impersonateUserId !== null) {
            $options['headers']['X-Impersonate'] = $this->impersonateUserId;
        }

        if ($data && $method !== 'GET') {
            $options['json'] = $data;
        }

        if ($query) {
            $options['query'] = $query;
        }

        try {
            $response = $this->client->request($method, $uri, $options);
            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            throw new ApiException('API Request failed: ' . $e->getMessage(), $e->getCode(), $e);
        } catch (JsonException $e) {
            throw new ApiException('API Request failed: ' . $e->getMessage());
        }
    }

    /**
     * @throws JsonException
     */
    private function parseResponse(ResponseInterface $response): array|string
    {
        $contentType = $response->hasHeader('Content-Type') ? $response->getHeader('Content-Type')[0] : null;
        return match ($contentType) {
            'application/json' => json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR),
            default => $response->getBody()->getContents(),
        };
    }

    /**
     * @throws ApiException
     */
    private function systemRequest(
        string $method,
        string|array $path,
        array $data = [],
        array $query = []
    ): array|string {
        $path = is_array($path) ? $path : [$path];
        return $this->request($method, [$this->system, ...$path], $data, $query);
    }

    private function getSystemURL(string|array $path, ?string $apiUrl = null): string
    {
        $path = is_array($path) ? $path : [$path];
        return ($apiUrl ?? $this->apiUrl) . '/' . $this->system . '/' . implode('/', $path);
    }

    /**
     * @throws ApiException
     */
    private function getSystemRequest(string|array $path, array $query = []): array|string
    {
        return $this->systemRequest('GET', $path, [], $query);
    }

    /**
     * @throws ApiException
     */
    private function postSystemRequest(string|array $path, array $data = []): array|string
    {
        return $this->systemRequest('POST', $path, $data);
    }

    /**
     * @throws ApiException
     */
    private function putSystemRequest(string|array $path, array $data = []): array|string
    {
        return $this->systemRequest('PUT', $path, $data);
    }

    /**
     * @throws ApiException
     */
    private function deleteSystemRequest(string|array $path): array
    {
        return $this->systemRequest('DELETE', $path);
    }

    public function getShareUrl(): ?string
    {
        return $this->shareUrl;
    }

    public function setShareUrl(?string $shareUrl): void
    {
        $this->shareUrl = $shareUrl;
    }
}
