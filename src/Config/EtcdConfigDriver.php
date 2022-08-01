<?php

declare( strict_types=1 );

namespace Imi\Etcd\Config;

use Imi\App;
use Imi\Etcd\Client\Client;
use Imi\Etcd\Client\Config;
use Imi\Etcd\Config\Contract\IEtcdConfigDriver;


class EtcdConfigDriver implements IEtcdConfigDriver
{
    protected Client $client;
    
    protected array $config = [];
    
    protected string $name = '';
    
    protected bool $listening = false;
    
    public function __construct ( string $name, array $config )
    {
        $this->config = $config;
        $this->name   = $name;
        $this->client = new Client( new Config( $config['client'] ) );
        
    }
    
    public function getName (): string
    {
        return $this->name;
    }
    
    public function push ( string $key, string $value, array $options = [] ): void
    {
        $this->client->put( $key, $value, $options );
    }
    
    public function pull ( bool $enableCache = true ): void
    {
        // TODO: Implement pull() method.
    }
    
    public function getRaw ( string $key, bool $enableCache = true, array $options = [] ): ?string
    {
        return $this->client->getRaw( $key, $options ) ?: '';
    }
    
    public function get ( string $key, bool $enableCache = true, array $options = [] )
    {
            return $this->client->get( $key, $options ) ?: [];
    }
    
    public function delete ( $keys, array $options = [] ): void
    {
        $this->client->del( $keys, $options );
    }
    
    public function listen ( string $imiConfigKey, string $key, array $options = [] ): void
    {
        // TODO: Implement listen() method.
    }
    
    public function polling (): void
    {
        // TODO: Implement polling() method.
    }
    
    public function startListner (): void
    {
        $this->listening = true;
    }
    
    public function stopListner (): void
    {
        $this->listening = false;
    }
    
    public function isListening (): bool
    {
        return $this->listening;
    }
    
    public function isSupportServerPush (): bool
    {
        return true;
    }
    
    public function getOriginClient (): Client
    {
        return $this->client;
    }
}
