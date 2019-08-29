<?php

namespace Zls\Cron\Command;

use SimpleCron\CronExpression;
use z;

/**
 * Zls\Cron\Command
 * @author        影浅
 * @email         seekwe@gmail.com
 * @copyright     Copyright (c) 2019, 影浅, Inc.
 * @updatetime    2019-01-11 12:39
 */
class Cron extends \Zls\Command\Command {
	private $debug = false;

	public function __construct() {
		parent::__construct();
		z::includeOnce(__DIR__ . '/../CronFunction.php');
	}

	/**
	 * 命令配置.
	 * @return array
	 */
	public function options() {
		return [];
	}

	public function commands() {
		return [
			' start' => 'Start the corn server',
			' auto' => 'Automatic generation of system timing tasks',
			' init' => ['Publish Corn configuration', ['--force, -F' => ' Overwrite old config file']],
		];
	}

	/**
	 * 命令介绍.
	 * @return string
	 */
	public function description() {
		return 'Program timed task console';
	}

	/**
	 * 命令默认执行.
	 * @param $args
	 */
	public function execute($args) {
		$active = z::arrayGet($args, 2);
		$this->debug = z::arrayGet($args, ['debug', '-debug', 'D'], false);
		$hasConfing = Z::config()->find('cron');
		$active = z::strSnake2Camel($active, false, '-');
		if (method_exists($this, $active)) {
			$this->$active($args);
		} elseif ($hasConfing) {
			$config = Z::config('cron');
			$lists = Z::arrayGet($config, 'lists');
			$phpPath = Z::arrayGet($config, 'phpPath');
			if ($config['enable'] && $lists) {
				foreach ($lists as $list) {
					$this->start($list, $phpPath);
				}
			}
		} else {
			$this->error('Did not find the cron configuration file, please initialization configfile.');
		}
	}

	public function auto($args) {
		if (strpos(strtolower(PHP_OS), 'win') !== false) {
			$this->error('Windows is not supported, please use Linux.', '', true);
		}
		$ps = explode("\n", Z::command('ps -aux|grep /usr/sbin/cron', '', true, false));
		if (!$hasCron = count($ps) >= 4) {
			$this->warning('The cron service does not seem to start');
		}
		$path = Z::realPath(ZLS_PATH . '../');
		$name = pathinfo($path, PATHINFO_BASENAME);
		$fileName = Z::strSnake2Camel($name, true, '-') . '__' . md5($path);
		$phpPath = Z::config('cron.phpPath') ?: Z::phpPath();
		$command = Z::arrayGet($args, ['C', '-c', '--command'], Z::arrayGet($args, 1, 'cron'));
		$command = "* * * * * {$phpPath} {$path}/zls {$command}";
		if ((new \Zls\Cron\CronJob)->setJob($command, $this->debug)) {
			$this->success('Set cron successfully');
		} else {
			$this->error('Set cron failure, Please check if you have permission.');
		}
	}

	public function init($args) {
		$force = Z::arrayGet($args, ['-force', 'F']);
		$file = ZLS_APP_PATH . 'config/default/cron.php';
		$originFile = Z::realPath(__DIR__ . '/../Config/cron.php', false, false);
		$this->copyFile(
			$originFile,
			$file,
			$force,
			function ($status) use ($file) {
				if ($status) {
					$this->success('config: ' . Z::safePath($file));
					$this->printStr('Please modify according to the situation');
				} else {
					$this->error('Profile already exists, or insufficient permissions');
				}
			},
			null
		);
	}

	public function log($msg, $time = true) {
		if ($this->debug) {
			$nowTime = '' . Z::microtime();
			echo ($time ? date('[Y-m-d H:i:s.' . substr($nowTime, strlen($nowTime) - 3) . '] ') : '') . $msg . "\n";
		}
	}

	/**
	 * 执行任务
	 * @param array $data
	 * @return bool|string
	 */
	public function start($data = [], $phpPath = null) {
		$data = array_merge([
			'task' => '',
			'enable' => true,
			'args' => '',
			'cron' => '*/1 * * * *',
			'logPath' => '',
			'logSize' => 1024 * 1024,
		], $data);
		if ($data['enable']) {
			$this->log("Run Task: {$data['task']}");
			try {
				$task = Z::arrayGet($data, 'task');
				$cron = CronExpression::factory(Z::arrayGet($data, 'cron'));
				$logPath = $data['logPath'] ? z::realPath($data['logPath'], false, false) : null;
				if ($logPath) {
					$logPath = z::realPath($data['logPath'], false, false);
					$this->resize($logPath, $data['logSize']);
					$this->log("logPath: {$logPath}");
				}
				if ($cron->isDue()) {
					// $cron->getNextRunDate()->format('Y-m-d H:i:s');
					// $cron->getPreviousRunDate()->format('Y-m-d H:i:s')
					$cmd = z::task($task, $data['args'], null, $phpPath, $logPath);
					$this->log($cmd);
				} else {
					$this->log("Not executed at the specified time: {$data['cron']}");
				}
			} catch (\Exception $e) {
				$this->log("Error", $e->getMessage());
			}
		}

		return true;
	}

	/**
	 * 重置日志
	 * @param $logPath
	 * @param $size
	 */
	private function resize($logPath, $size) {
		$reservedLine = 2;
		if (@file_exists($logPath) && @filesize($logPath) > $size) {
			$line = count(file($logPath));
		}
	}
}
