<?php

declare(strict_types=1);

namespace Imi\Etcd\Client;

use Imi\Etcd\Exception\EtcdApiException;
use Yurun\Util\HttpRequest;

class Client
{
    // KV
    public const URI_PUT = 'kv/put';
    public const URI_RANGE = 'kv/range';
    public const URI_DELETE_RANGE = 'kv/deleterange';
    public const URI_TXN = 'kv/txn';
    public const URI_COMPACTION = 'kv/compaction';

    // Lease
    public const URI_GRANT = 'lease/grant';
    public const URI_REVOKE = 'kv/lease/revoke';
    public const URI_KEEPALIVE = 'lease/keepalive';
    public const URI_TIMETOLIVE = 'kv/lease/timetolive';

    // Role
    public const URI_AUTH_ROLE_ADD = 'auth/role/add';
    public const URI_AUTH_ROLE_GET = 'auth/role/get';
    public const URI_AUTH_ROLE_DELETE = 'auth/role/delete';
    public const URI_AUTH_ROLE_LIST = 'auth/role/list';

    // Authenticate
    public const URI_AUTH_ENABLE = 'auth/enable';
    public const URI_AUTH_DISABLE = 'auth/disable';
    public const URI_AUTH_AUTHENTICATE = 'auth/authenticate';

    // User
    public const URI_AUTH_USER_ADD = 'auth/user/add';
    public const URI_AUTH_USER_GET = 'auth/user/get';
    public const URI_AUTH_USER_DELETE = 'auth/user/delete';
    public const URI_AUTH_USER_CHANGE_PASSWORD = 'auth/user/changepw';
    public const URI_AUTH_USER_LIST = 'auth/user/list';

    public const URI_AUTH_ROLE_GRANT = 'auth/role/grant';
    public const URI_AUTH_ROLE_REVOKE = 'auth/role/revoke';

    public const URI_AUTH_USER_GRANT = 'auth/user/grant';
    public const URI_AUTH_USER_REVOKE = 'auth/user/revoke';

    public const PERMISSION_READ = 0;

    public const PERMISSION_WRITE = 1;

    public const PERMISSION_READWRITE = 2;

    public Config $config;

    protected string $version;

    public bool $pretty = true;

    protected Client $client;

    /**
     * @var string|null auth token
     */
    protected ?string $token = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->version = trim($config->getVersion());
        $this->pretty = $config->isPretty();
    }

    public function setToken(?string $token): void
    {
        $this->token = $token;
    }

    public function clearToken(): void
    {
        $this->token = null;
    }

    // region kv

    /**
     * Put puts the given key into the key-value store.
     * A put request increments the revision of the key-value
     * store and generates one event in the event history.
     *
     * @param array $options 可选参数
     *                       int64  lease
     *                       bool   prev_kv
     *                       bool   ignore_value
     *                       bool   ignore_lease
     */
    public function put(string $key, string $value, array $options = []): array
    {
        $params = [
            'key'   => $key,
            'value' => $value,
        ];

        $params = $this->encode($params);
        $options = $this->encode($options);
        $body = $this->request(self::URI_PUT, $params, $options);
        $body = $this->decodeBodyForFields($body, 'prev_kv', ['key', 'value']);

        if (isset($body['prev_kv']) && $this->pretty)
        {
            return $this->convertFields($body['prev_kv']);
        }

        return $body;
    }

    /**
     * Gets the key or a range of keys.
     *
     * @param array $options
     *                       string range_end
     *                       int    limit
     *                       int    revision
     *                       int    sort_order
     *                       int    sort_target
     *                       bool   serializable
     *                       bool   keys_only
     *                       bool   count_only
     *                       int64  min_mod_revision
     *                       int64  max_mod_revision
     *                       int64  min_create_revision
     *                       int64  max_create_revision
     */
    public function get(string $key, array $options = []): array
    {
        $params = [
            'key' => $key,
        ];
        $params = $this->encode($params);
        $options = $this->encode($options);
        $body = $this->request(self::URI_RANGE, $params, $options);
        $body = $this->decodeBodyForFields(
            $body,
            'kvs',
            ['key', 'value']
        );

        if (isset($body['kvs']) && $this->pretty)
        {
            return $this->convertFields($body['kvs']);
        }

        return $body;
    }

    /**
     * 原始格式以json形式返回.
     */
    public function getRaw(string $key, array $options = []): ?string
    {
        return json_encode($this->get($key, $options), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * get all keys.
     */
    public function getAllKeys(): array
    {
        return $this->get("\0", ['range_end' => "\0"]);
    }

    /**
     * get all keys with prefix.
     */
    public function getKeysWithPrefix(string $prefix): array
    {
        $prefix = trim($prefix);
        if (!$prefix)
        {
            return [];
        }
        $lastIndex = \strlen($prefix) - 1;
        $lastChar = $prefix[$lastIndex];
        $nextAsciiCode = \ord($lastChar) + 1;
        $rangeEnd = $prefix;
        $rangeEnd[$lastIndex] = \chr($nextAsciiCode);

        return $this->get($prefix, ['range_end' => $rangeEnd]);
    }

    /**
     * Removes the specified key or range of keys.
     *
     * @param array $options
     *                       string range_end
     *                       bool   prev_kv
     */
    public function del(string $key, array $options = []): array
    {
        $params = [
            'key' => $key,
        ];
        $params = $this->encode($params);
        $options = $this->encode($options);
        $body = $this->request(self::URI_DELETE_RANGE, $params, $options);
        $body = $this->decodeBodyForFields(
            $body,
            'prev_kvs',
            ['key', 'value']
        );

        if (isset($body['prev_kvs']) && $this->pretty)
        {
            return $this->convertFields($body['prev_kvs']);
        }

        return $body;
    }

    /**
     * Compact compacts the event history in the etcd key-value store.
     * The key-value store should be periodically compacted
     * or the event history will continue to grow indefinitely.
     *
     * @param bool|false $physical
     */
    public function compaction(int $revision, $physical = false): array
    {
        $params = [
            'revision' => $revision,
            'physical' => $physical,
        ];

        return $this->request(self::URI_COMPACTION, $params);
    }

    // endregion kv

    // region lease

    /**
     * LeaseGrant creates a lease which expires if the server does not receive a
     * keepAlive within a given time to live period. All keys attached to the lease
     * will be expired and deleted if the lease expires.
     * Each expired key generates a delete event in the event history.",.
     *
     * @param int $ttl TTL is the advisory time-to-live in seconds
     * @param int $id  ID is the requested ID for the lease.
     *                 If ID is set to 0, the lessor chooses an ID.
     */
    public function grant(int $ttl, $id = 0): array
    {
        $params = [
            'TTL' => $ttl,
            'ID'  => $id,
        ];

        return $this->request(self::URI_GRANT, $params);
    }

    /**
     * revokes a lease. All keys attached to the lease will expire and be deleted.
     *
     * @param int $id ID is the lease ID to revoke. When the ID is revoked,
     *                all associated keys will be deleted.
     *
     * @return array
     */
    public function revoke(int $id): ?array
    {
        $params = [
            'ID' => $id,
        ];

        return $this->request(self::URI_REVOKE, $params);
    }

    /**
     * keeps the lease alive by streaming keep alive requests
     * from the client\nto the server and streaming keep alive responses
     * from the server to the client.
     *
     * @param int $id ID is the lease ID for the lease to keep alive
     *
     * @return array
     */
    public function keepAlive(int $id): ?array
    {
        $params = [
            'ID' => $id,
        ];

        $body = $this->request(self::URI_KEEPALIVE, $params);

        if (!isset($body['result']))
        {
            return $body;
        }
        // response "result" field, etcd bug?
        return [
            'ID'  => $body['result']['ID'],
            'TTL' => $body['result']['TTL'],
        ];
    }

    /**
     * retrieves lease information.
     *
     * @param int        $id   ID is the lease ID for the lease
     * @param bool|false $keys
     *
     * @return array
     */
    public function timeToLive(int $id, $keys = false): ?array
    {
        $params = [
            'ID'   => $id,
            'keys' => $keys,
        ];

        $body = $this->request(self::URI_TIMETOLIVE, $params);

        if (isset($body['keys']))
        {
            $body['keys'] = array_map(fn ($value) => base64_decode($value), $body['keys']);
        }

        return $body;
    }

    // endregion lease

    // region auth

    /**
     * enable authentication.
     *
     * @return array
     */
    public function authEnable(): ?array
    {
        $body = $this->request(self::URI_AUTH_ENABLE);
        $this->clearToken();

        return $body;
    }

    /**
     * disable authentication.
     *
     * @return array
     */
    public function authDisable(): ?array
    {
        $body = $this->request(self::URI_AUTH_DISABLE);
        $this->clearToken();

        return $body;
    }

    /**
     * @return array
     */
    public function authenticate(string $user, string $password): ?array
    {
        $params = [
            'name'     => $user,
            'password' => $password,
        ];

        $body = $this->request(self::URI_AUTH_AUTHENTICATE, $params);
        if ($this->pretty && isset($body['token']))
        {
            return $body['token'];
        }

        return $body;
    }

    /**
     * add a new role.
     *
     * @return array
     */
    public function addRole(string $name): ?array
    {
        $params = [
            'name' => $name,
        ];

        return $this->request(self::URI_AUTH_ROLE_ADD, $params);
    }

    /**
     * get detailed role information.
     *
     * @return array
     */
    public function getRole(string $role): ?array
    {
        $params = [
            'role' => $role,
        ];

        $body = $this->request(self::URI_AUTH_ROLE_GET, $params);

        $body = $this->decodeBodyForFields(
            $body,
            'perm',
            ['key', 'range_end']
        );
        if ($this->pretty && isset($body['perm']))
        {
            return $body['perm'];
        }

        return $body;
    }

    /**
     * delete a specified role.
     *
     * @return array
     */
    public function deleteRole(string $role): ?array
    {
        $params = [
            'role' => $role,
        ];

        return $this->request(self::URI_AUTH_ROLE_DELETE, $params);
    }

    /**
     * get lists of all roles.
     *
     * @return array
     */
    public function roleList(): ?array
    {
        $body = $this->request(self::URI_AUTH_ROLE_LIST);

        if ($this->pretty && isset($body['roles']))
        {
            return $body['roles'];
        }

        return $body;
    }

    /**
     * add a new user.
     *
     * @return array
     */
    public function addUser(string $user, string $password): ?array
    {
        $params = [
            'name'     => $user,
            'password' => $password,
        ];

        return $this->request(self::URI_AUTH_USER_ADD, $params);
    }

    /**
     * get detailed user information.
     *
     * @return array
     */
    public function getUser(string $user): ?array
    {
        $params = [
            'name' => $user,
        ];

        $body = $this->request(self::URI_AUTH_USER_GET, $params);
        if ($this->pretty && isset($body['roles']))
        {
            return $body['roles'];
        }

        return $body;
    }

    /**
     * delete a specified user.
     *
     * @return array
     */
    public function deleteUser(string $user): ?array
    {
        $params = [
            'name' => $user,
        ];

        return $this->request(self::URI_AUTH_USER_DELETE, $params);
    }

    /**
     * get a list of all users.
     *
     * @return array
     */
    public function userList(): ?array
    {
        $body = $this->request(self::URI_AUTH_USER_LIST);
        if ($this->pretty && isset($body['users']))
        {
            return $body['users'];
        }

        return $body;
    }

    /**
     * change the password of a specified user.
     *
     * @return array
     */
    public function changeUserPassword(string $user, string $password): ?array
    {
        $params = [
            'name'     => $user,
            'password' => $password,
        ];

        return $this->request(self::URI_AUTH_USER_CHANGE_PASSWORD, $params);
    }

    /**
     * grant a permission of a specified key or range to a specified role.
     *
     * @return array
     */
    public function grantRolePermission(string $role, int $permType, string $key, ?string $rangeEnd = null): ?array
    {
        $params = [
            'name' => $role,
            'perm' => [
                'permType' => $permType,
                'key'      => base64_encode($key),
            ],
        ];
        if (null !== $rangeEnd)
        {
            $params['perm']['range_end'] = base64_encode($rangeEnd);
        }

        return $this->request(self::URI_AUTH_ROLE_GRANT, $params);
    }

    /**
     * revoke a key or range permission of a specified role.
     *
     * @return array
     */
    public function revokeRolePermission(string $role, string $key, ?string $rangeEnd = null): ?array
    {
        $params = [
            'role' => $role,
            'key'  => $key,
        ];
        if (null !== $rangeEnd)
        {
            $params['range_end'] = $rangeEnd;
        }

        return $this->request(self::URI_AUTH_ROLE_REVOKE, $params);
    }

    /**
     * grant a role to a specified user.
     *
     * @return array
     */
    public function grantUserRole(string $user, string $role): ?array
    {
        $params = [
            'user' => $user,
            'role' => $role,
        ];

        return $this->request(self::URI_AUTH_USER_GRANT, $params);
    }

    /**
     * revoke a role of specified user.
     *
     * @return array
     */
    public function revokeUserRole(string $user, string $role): ?array
    {
        $params = [
            'name' => $user,
            'role' => $role,
        ];

        return $this->request(self::URI_AUTH_USER_REVOKE, $params);
    }

    // endregion auth

    /**
     * @throws EtcdApiException
     */
    public function request(string $uri, array $params = [], array $options = []): ?array
    {
        if ($options)
        {
            $params = array_merge($params, $options);
        }
        // 没有参数, 设置一个默认参数
        if (!$params)
        {
            $params['php-etcd-client'] = 1;
        }

        $header = [];
        if ($this->token)
        {
            $header['Grpc-Metadata-Token'] = $this->token;
        }

        $url = sprintf('%s://%s:%s/%s/%s', $this->config->getScheme(), $this->config->getHost(),
            $this->config->getPort(), $this->version, $uri);

        $httpClient = new HttpRequest();

        if ($this->config->isSsl())
        {
            $httpClient->isVerifyCA = true;
            $httpClient->sslCert($this->config->getSslCert());
            $httpClient->sslKey($this->config->getSslKey());
        }

        $httpClient->timeout($this->config->getTimeout());
        $httpClient = $httpClient->headers($header);
        $response = $httpClient->post($url, json_encode($params));
        // request failed
        if (!$response->success)
        {
            throw new EtcdApiException(sprintf('Request failed [%d] %s. Request method[%s], url[%s], header:[%s], params:[%s]', $response->errno(), $response->error(), 'POST', $url, json_encode($header, \JSON_PRETTY_PRINT), json_encode($params, \JSON_PRETTY_PRINT)));
        }
        $body = json_decode($response->body(), true);
        if ($this->pretty && isset($body['header']))
        {
            unset($body['header']);
        }

        return $body;
    }

    /**
     * string类型key用base64编码
     */
    protected function encode(array $data): array
    {
        foreach ($data as $key => $value)
        {
            if (\is_string($value))
            {
                $data[$key] = base64_encode($value);
            }
        }

        return $data;
    }

    /**
     * 指定字段base64解码
     *
     * @param array $fields 需要解码的字段
     */
    protected function decodeBodyForFields(array $body, string $bodyKey, array $fields): array
    {
        if (!isset($body[$bodyKey]))
        {
            return $body;
        }
        $data = $body[$bodyKey];
        if (!isset($data[0]))
        {
            $data = [$data];
        }
        foreach ($data as $key => $value)
        {
            foreach ($fields as $field)
            {
                if (isset($value[$field]))
                {
                    $data[$key][$field] = base64_decode($value[$field]);
                }
            }
        }

        if (isset($body[$bodyKey][0]))
        {
            $body[$bodyKey] = $data;
        }
        else
        {
            $body[$bodyKey] = $data[0];
        }

        return $body;
    }

    protected function convertFields(array $data): array
    {
        $map = [];

        if (!isset($data[0]))
        {
            $map[$data['key']] = $data['value'];

            return $map;
        }

        foreach ($data as $value)
        {
            $key = $value['key'];
            $map[$key] = $value['value'];
        }

        return $map;
    }
}
