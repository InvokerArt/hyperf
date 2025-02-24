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

namespace Hyperf\Crontab\Listener;

use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Crontab\Crontab;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\OnPipeMessage;
use Psr\Container\ContainerInterface;

class OnPipeMessageListener implements ListenerInterface
{
    /**
     * @var \Psr\Container\ContainerInterface
     */
    protected $container;

    /**
     * @var \Hyperf\Contract\StdoutLoggerInterface
     */
    protected $logger;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        if ($container->has(StdoutLoggerInterface::class)) {
            $this->logger = $container->get(StdoutLoggerInterface::class);
        }
    }

    /**
     * @return string[] returns the events that you want to listen
     */
    public function listen(): array
    {
        return [
            OnPipeMessage::class,
        ];
    }

    /**
     * Handle the Event when the event is triggered, all listeners will
     * complete before the event is returned to the EventDispatcher.
     */
    public function process(object $event)
    {
        /**
         * @var OnPipeMessage
         */
        $data = @unserialize($event->data);
        if (! $this->isValidData($data)) {
            return;
        }
        try {
            switch ($data['type']) {
                case 'callable':
                    $this->handleCallable($data);
                    break;
            }
        } catch (\Throwable $throwable) {
            if ($this->logger) {
                $this->logger->error($throwable->getMessage());
            }
        }
    }

    private function handleCallable($data): void
    {
        $instance = $this->container->get($data['callable'][0]);
        $method = $data['callable'][1] ?? null;
        if (! $instance || ! $method || ! method_exists($instance, $method)) {
            return;
        }
        $crontab = $data['data'] ?? null;
        $crontab instanceof Crontab && $instance->{$method}($crontab);
    }

    private function isValidData($data): bool
    {
        return ! is_array($data)
        || ! isset($data['identifier'], $data['type'], $data['callable'], $data['data'])
        || $data['identifier'] !== 'crontab';
    }
}
