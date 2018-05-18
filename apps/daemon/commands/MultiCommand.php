<?php

namespace apps\daemon\commands;

use mix\console\Command;
use mix\console\ExitCode;
use mix\facades\Input;
use mix\facades\Output;
use mix\swoole\Process;
use mix\swoole\TaskProcess;
use mix\swoole\TaskServer;

/**
 * 这是一个多进程守护进程的范例
 * 进程模型为：生产者消费者模型
 * 你可以自由选择是左进程当生产者还是右进程当生产者，本范例是左进程当生产者
 * @author 刘健 <coder.liu@qq.com>
 */
class MultiCommand extends Command
{

    // 是否后台运行
    public $daemon = false;

    // PID 文件
    const PID_FILE = '/var/run/multi.pid';

    // 进程名称
    protected $processName = '';

    // 选项配置
    public function options()
    {
        return ['daemon'];
    }

    // 选项别名配置
    public function optionAliases()
    {
        return ['d' => 'daemon'];
    }

    // 初始化事件
    public function onInitialize()
    {
        parent::onInitialize(); // TODO: Change the autogenerated stub
        // 获取进程名称
        $this->processName = Input::getCommandName();
    }

    /**
     * 获取服务
     * @return TaskServer
     */
    public function getServer()
    {
        return \Mix::createObject(
            [
                // 类路径
                'class'        => 'mix\swoole\TaskServer',
                // 左进程数
                'leftProcess'  => 1,
                // 右进程数
                'rightProcess' => 3,
                // 服务名称
                'name'         => "mix-daemon: {$this->processName}",
                // 进程队列的key
                'queueKey'     => __FILE__ . uniqid(),
            ]
        );
    }

    // 启动
    public function actionStart()
    {
        // 重复启动处理
        if ($pid = Process::getMasterPid(self::PID_FILE)) {
            Output::writeln("mix-daemon '{$this->processName}' is running, PID : {$pid}.");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        // 启动提示
        Output::writeln("mix-daemon '{$this->processName}' start successed.");
        // 蜕变为守护进程
        if ($this->daemon) {
            Process::daemon();
        }
        // 写入 PID 文件
        Process::writePid(self::PID_FILE);
        // 启动服务
        $server = $this->getServer();
        $server->on('LeftStart', [$this, 'onLeftStart']);
        $server->on('RightStart', [$this, 'onRightStart']);
        $server->start();
        // 返回退出码
        return ExitCode::OK;
    }

    // 停止
    public function actionStop()
    {
        if ($pid = Process::getMasterPid(self::PID_FILE)) {
            Process::kill($pid);
            while (Process::isRunning($pid)) {
                // 等待进程退出
                usleep(100000);
            }
            Output::writeln("mix-daemon '{$this->processName}' stop completed.");
        } else {
            Output::writeln("mix-daemon '{$this->processName}' is not running.");
        }
        // 返回退出码
        return ExitCode::OK;
    }

    // 重启
    public function actionRestart()
    {
        $this->actionStop();
        $this->actionStart();
        // 返回退出码
        return ExitCode::OK;
    }

    // 查看状态
    public function actionStatus()
    {
        if ($pid = Process::getMasterPid(self::PID_FILE)) {
            Output::writeln("mix-daemon '{$this->processName}' is running, PID : {$pid}.");
        } else {
            Output::writeln("mix-daemon '{$this->processName}' is not running.");
        }
        // 返回退出码
        return ExitCode::OK;
    }

    // 左进程启动事件回调函数
    public function onLeftStart(TaskProcess $worker, $index)
    {
        // 模型内使用长连接版本的数据库组件，这样组件会自动帮你维护连接不断线
        $queueModel = new \apps\common\models\QueueModel();
        // 循环执行任务
        for ($j = 0; $j < 16000; $j++) {
            $worker->checkMaster(TaskProcess::PRODUCER);
            // 从消息队列中间件取出一条消息
            $msg = $queueModel->pop();
            // 将消息推送给消费者进程去处理
            $worker->push(serialize($msg));
        }
    }

    // 右进程启动事件回调函数
    public function onRightStart(TaskProcess $worker, $index)
    {
        // 循环执行任务
        for ($j = 0; $j < 16000; $j++) {
            $worker->checkMaster();
            // 从进程队列中抢占一条消息
            $msg = $worker->pop();
            $msg = unserialize($msg);
            if (!empty($msg)) {
                // 处理消息，比如：发送短信、发送邮件、微信推送
                // ...
            }
        }
    }

}
