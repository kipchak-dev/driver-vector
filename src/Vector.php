<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector;

use Kipchak\Core\Interfaces;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Vector\Library\ClientFactory;
use Kipchak\Driver\Vector\Library\VectorClient;
use Psr\Container\ContainerInterface;

/**
 * Vector store connections from config/kipchak.vector.php, each a VectorClient in the container under
 * drivers.vector.<name>, backed by Qdrant, pgvector or OpenSearch.
 */
class Vector implements Interfaces\Driver
{
    protected static ?ContainerInterface $container = null;

    public static function initialise(ContainerInterface $container, ?string $configFileName = null): void
    {
        self::$container = $container;
        self::set($configFileName);
    }

    public static function set(?string $configFileName = null): void
    {
        $config = Config::get($configFileName ?? 'kipchak.vector');

        if (empty($config['connections'])) {
            return;
        }

        foreach ($config['connections'] as $connectionName => $connectionDetails) {
            self::$container->set(
                'drivers.vector.' . $connectionName,
                function (ContainerInterface $c) use ($connectionName, $connectionDetails): VectorClient {
                    return (new ClientFactory($c))->create((string) $connectionName, (array) $connectionDetails);
                }
            );
        }
    }

    public static function get(?string $configKey = null): VectorClient
    {
        return self::$container->get('drivers.vector.' . ($configKey ?? 'default'));
    }
}
