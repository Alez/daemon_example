<?php

namespace console\controllers;

use yii\console\Controller;
use Yii;
use yii\db\Query;

class GrabberController extends Controller
{
    const PROCESSES_QTY = 2;
    const TICKS_FREQ = 1; // once per minute
    const JOBS_PER_TICK = 100;
    const QUEUE_NAMESPACE = 'queue:pid';

    private $sigterm = false;

    private $children = [];

    public function __construct($id, $module, $config = [])
    {
        parent::__construct($id, $module, $config = []);
        pcntl_signal(SIGTERM, [$this, 'signalHandler']);
        pcntl_signal(SIGCHLD, [$this, 'signalHandler']);
    }

    public function actionRun()
    {
        $this->daemonize();
        $this->turnOffIO();

        ini_set('error_log', __DIR__ . '/error.log');
        $STDIN = fopen('/dev/null', 'r');
        $STDOUT = fopen(__DIR__ . '/application.log', 'ab');
        $STDERR = fopen(__DIR__ . '/daemon.log', 'ab');

        $this->dropLocks();

        Yii::$app->db->close();
        for ($i = 0; $i < self::PROCESSES_QTY; $i++) {
            $this->forkChildren();
        }

        $this->parentJob();
    }

    private function daemonize()
    {
        $childPid = pcntl_fork();
        if ($childPid) {
            exit();
        }
        posix_setsid();
    }

    private function turnOffIO()
    {
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
    }

    public function signalHandler($signo, $pid = null, $status = null)
    {
        switch ($signo) {
            case SIGTERM:
                $this->sigterm = true;
                break;
            case SIGCHLD:
                if (!$pid) {
                    $pid = pcntl_waitpid(-1, $status, WNOHANG);
                }
                while ($pid > 0) {
                    if ($pid && isset($this->children[$pid])) {
                        unset($this->children[$pid]);
                    }
                    $pid = pcntl_waitpid(-1, $status, WNOHANG);
                }
                break;
        }
    }

    /**
     * Сбрасываем блокировки строк если вдруг они остались от прошлого упавлего процесса
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     */
    private function dropLocks()
    {
        Yii::$app->db->open();
        Yii::$app->db->createCommand()->update('source', ['read_lock' => 0], 'read_lock = 1')->execute();
        Yii::$app->db->close();
    }

    private function forkChildren()
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            error_log('Could not launch new job, exiting');
            return false;
        } elseif ($pid) {
            $this->children[$pid] = true;
        } else {
            $this->childJob();
            exit();
        }
        return true;
    }

    /**
     * Задача выполняемая на дочерних процессах
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     */
    private function childJob()
    {
        Yii::$app->db->open();
        Yii::$app->redis->open();

        while (!$this->sigterm) {
            pcntl_signal_dispatch();

            if ($rowId = Yii::$app->redis->executeCommand('LPOP', [self::QUEUE_NAMESPACE . ':' . getmypid()])) {
                Yii::$app->redis->executeCommand('EXPIRE', [self::QUEUE_NAMESPACE . ':' . getmypid(), self::TICKS_FREQ * 60]);
                $data = (new Query())->select('*')->from('source')->where(['id' => $rowId])->one();
                if (Yii::$app->db->createCommand()->insert('processed', ['id' => $rowId, 'data' => json_encode($data)])->execute()) {
                    Yii::$app->db->createCommand()->delete('source', ['id' => $rowId])->execute();
                }
            }

            sleep(1);
        }
    }

    /**
     * Задача выполняемая на родительском процессе
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     */
    private function parentJob()
    {
        Yii::$app->db->open();
        Yii::$app->redis->open();
        $counter = 0;
        while (!$this->sigterm) {
            pcntl_signal_dispatch();

            ++$counter;
            if ($counter === 1) {
                $newData = (new Query())->select('id')->from('source')->where(['read_lock' => 0])->limit(self::JOBS_PER_TICK)->column();
                $sql = Yii::$app->db->createCommand()->update('source', ['read_lock' => 1], 'read_lock = 0');
                $sql = $sql->getRawSql().' LIMIT ' . self::JOBS_PER_TICK;
                Yii::$app->db->createCommand($sql)->execute();

                if (count($newData) > 0) {
                    $this->addToQueues($newData);
                }
            }

            if ($counter === (60 * self::TICKS_FREQ)) {
                $counter = 0;
            }

            sleep(1);
        }
    }

    /**
     * Распределяет задачи поровну на все дочерние процессы
     * @param array $ids
     */
    private function addToQueues($ids)
    {
        $pidWorkload = [];
        foreach (array_keys($this->children) as $pid) {
            try {
                $pidWorkload[$pid] = (int)Yii::$app->redis->executeCommand('LLEN', [self::QUEUE_NAMESPACE . ':' . $pid]);
            } catch (\Exception $e) {
                echo $e->getMessage();
            }
        }

        $total = count($ids);
        while ($total > 0) {
            $minPids = array_keys($pidWorkload, min($pidWorkload));
            $minPid = (int)reset($minPids);
            Yii::$app->redis->executeCommand('RPUSH', [self::QUEUE_NAMESPACE . ':' . $minPid, array_shift($ids)]);
            Yii::$app->redis->executeCommand('EXPIRE', [self::QUEUE_NAMESPACE . ':' . $minPid, self::TICKS_FREQ * 60]);
            $total--;
            $pidWorkload[$minPid]++;
        }
    }
}
