<?php

namespace Zls\Cron;

use SimpleCron\CronExpression;
use Z;

/**
 * Class Main
 * @package Zls\Cron
 * @author        影浅 seekwe@gmail.com
 * @updatetime    2019-04-26 16:51
 */
class Main
{
    private $instanceObj;
    public function __construct($cron)
    {
        Z::includeOnce(__DIR__ . '/CronFunction.php');
        try {
            $this->instanceObj = CronExpression::factory($cron);
        } catch (InvalidArgumentException $e) {
            return false;
        }
        $this->instance($cron);
    }

    public function verification()
    {
        return $this->instanceObj != false;
    }

    public function instance()
    {
        return $this->instanceObj;
    }

    public function isDue()
    {
        if ($this->verification()) {
            return $this->instanceObj->isDue();
        } else {
            return false;
        }
    }
}
