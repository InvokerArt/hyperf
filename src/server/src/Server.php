<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Server;

use Hyperf\Contract\MiddlewareInitializerInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Framework\Event\BeforeMainServerStart;
use Hyperf\Framework\Event\BeforeServerStart;
use Hyperf\Server\Exception\RuntimeException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Swoole\Http\Server as SwooleHttpServer;
use Swoole\Server as SwooleServer;
use Swoole\WebSocket\Server as SwooleWebSocketServer;

class Server implements ServerInterface
{
    /**
     * @var bool
     */
    protected $enableHttpServer = false;

    /**
     * @var bool
     */
    protected $enableWebsocketServer = false;

    /**
     * @var SwooleServer
     */
    protected $server;

    /**
     * @var array
     */
    protected $onRequestCallbacks = [];

    /**
     * @var StdoutLoggerInterface
     */
    protected $logger;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    public function __construct(ContainerInterface $container, LoggerInterface $logger, EventDispatcherInterface $dispatcher)
    {
        $this->container = $container;
        $this->logger = $logger;
        $this->eventDispatcher = $dispatcher;
    }

    public function init(ServerConfig $config): ServerInterface
    {
        $this->initServers($config);

        return $this;
    }

    public function start()
    {
        $this->server->start();
    }

    protected function initServers(ServerConfig $config)
    {
        $servers = $this->sortServers($config->getServers());

        foreach ($servers as $server) {
            $name = $server->getName();
            $type = $server->getType();
            $host = $server->getHost();
            $port = $server->getPort();
            $sockType = $server->getSockType();
            $callbacks = $server->getCallbacks();

            if (! $this->server instanceof SwooleServer) {
                $this->server = $this->makeServer($type, $host, $port, $config->getMode(), $sockType);
                $callbacks = array_replace($this->defaultCallbacks(), $config->getCallbacks(), $callbacks);
                $this->registerSwooleEvents($this->server, $callbacks, $name);
                $this->server->set(array_replace($config->getSettings(), $server->getSettings()));
                ServerManager::add($name, [$type, current($this->server->ports)]);

                if (class_exists(BeforeMainServerStart::class)) {
                    // Trigger BeforeMainEventStart event, this event only trigger once before main server start.
                    $this->eventDispatcher->dispatch(new BeforeMainServerStart($this->server, $config->toArray()));
                }
            } else {
                /** @var \Swoole\Server\Port $slaveServer */
                $slaveServer = $this->server->addlistener($host, $port, $sockType);
                $server->getSettings() && $slaveServer->set($server->getSettings());
                $this->registerSwooleEvents($slaveServer, $callbacks, $name);
                ServerManager::add($name, [$type, $slaveServer]);
            }

            // Trigger beforeStart event.
            if (isset($callbacks[SwooleEvent::ON_BEFORE_START])) {
                [$class, $method] = $callbacks[SwooleEvent::ON_BEFORE_START];
                if ($this->container->has($class)) {
                    $this->container->get($class)->{$method}();
                }
            }

            if (class_exists(BeforeServerStart::class)) {
                // Trigger BeforeEventStart event.
                $this->eventDispatcher->dispatch(new BeforeServerStart($name));
            }
        }
    }

    /**
     * @param Port[] $servers
     * @return Port[]
     */
    protected function sortServers(array $servers)
    {
        $sortServers = [];
        foreach ($servers as $server) {
            switch ($server->getType() ?? 0) {
                case ServerInterface::SERVER_HTTP:
                    $this->enableHttpServer = true;
                    if (! $this->enableWebsocketServer) {
                        array_unshift($sortServers, $server);
                    } else {
                        $sortServers[] = $server;
                    }
                    break;
                case ServerInterface::SERVER_WEBSOCKET:
                    $this->enableWebsocketServer = true;
                    array_unshift($sortServers, $server);
                    break;
                default:
                    $sortServers[] = $server;
                    break;
            }
        }

        return $sortServers;
    }

    protected function makeServer(int $type, string $host, int $port, int $mode, int $sockType)
    {
        switch ($type) {
            case ServerInterface::SERVER_HTTP:
                return new SwooleHttpServer($host, $port, $mode, $sockType);
            case ServerInterface::SERVER_WEBSOCKET:
                return new SwooleWebSocketServer($host, $port, $mode, $sockType);
            case ServerInterface::SERVER_TCP:
                return new SwooleServer($host, $port, $mode, $sockType);
        }

        throw new RuntimeException('Server type is invalid.');
    }

    /**
     * @param Port|SwooleServer $server
     */
    protected function registerSwooleEvents($server, array $events, string $serverName): void
    {
        foreach ($events as $event => $callback) {
            if (! SwooleEvent::isSwooleEvent($event)) {
                continue;
            }
            if (is_array($callback)) {
                [$className, $method] = $callback;
                if (array_key_exists($className . $method, $this->onRequestCallbacks)) {
                    $this->logger->warning(sprintf('%s will be replaced by %s, each server should has own onRequest callback, please check your configs.', $this->onRequestCallbacks[$className . $method], $serverName));
                }

                $this->onRequestCallbacks[$className . $method] = $serverName;
                $class = $this->container->get($className);
                if (method_exists($class, 'setServerName')) {
                    // Override the server name.
                    $class->setServerName($serverName);
                }
                if ($class instanceof MiddlewareInitializerInterface) {
                    $class->initCoreMiddleware($serverName);
                }
                $callback = [$class, $method];
            }
            $server->on($event, $callback);
        }
    }

    protected function defaultCallbacks()
    {
        return [
            SwooleEvent::ON_WORKER_START => function (SwooleServer $server, int $workerId) {
                printf('Worker %d started.' . PHP_EOL, $workerId);
            },
        ];
    }

    public function getServer(): \Swoole\Server
    {
        return $this->server;
    }
}
