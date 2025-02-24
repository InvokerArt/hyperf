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

namespace Hyperf\Crontab\Strategy;

use Carbon\Carbon;
use Hyperf\Crontab\Crontab;
use Hyperf\Server\ServerFactory;
use Psr\Container\ContainerInterface;
use Swoole\Server;

class ProcessStrategy extends AbstractStrategy
{
    /**
     * @var ServerFactory
     */
    protected $serverFactory;

    /**
     * @var int
     */
    protected $currentWorkerId = -1;

    public function __construct(ContainerInterface $container)
    {
        $this->serverFactory = $container->get(ServerFactory::class);
    }

    public function dispatch(Crontab $crontab)
    {
        $server = $this->serverFactory->getServer()->getServer();
        if ($server instanceof Server && $crontab->getExecuteTime() instanceof Carbon) {
            $workerId = $this->getNextWorkerId($server);
            $server->sendMessage(serialize([
                'identifier' => 'crontab',
                'type' => 'callable',
                'callable' => [Executor::class, 'execute'],
                'data' => $crontab,
            ]), $workerId);
        }
    }

    protected function getNextWorkerId(Server $server): int
    {
        ++$this->currentWorkerId;
        $maxWorkerId = $server->setting['worker_num'] + $server->setting['task_worker_num'] - 1;
        if ($this->currentWorkerId > $maxWorkerId) {
            $this->currentWorkerId = 0;
        }
        return $this->currentWorkerId;
    }
}
