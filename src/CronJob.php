<?php

namespace Zls\Cron;

use Z;

/**
 * Zls\Cron\CronJob
 * @author        影浅
 * @email         seekwe@gmail.com
 * @updatetime    2019-04-26 16:51
 */
class CronJob
{
    public function setJob($command, $debug = false)
    {
        $jobs   = $this->getJob();
        $jobs[] = $command;
        $jobs   = array_unique($jobs);
        $tmp    = Z::tempPath() . uniqid('ZlsCron_' . md5($command));
        z::defer(function () use ($tmp) {
            @unlink($tmp);
        });
        $jobs = join("\n", $jobs) . "\n";
        if ($debug) {
            echo $jobs . PHP_EOL;
        }

        return (@file_put_contents($tmp, $jobs) && !Z::command("crontab {$tmp}", '', true, false));
    }

    public function getJob()
    {
        return Z::arrayFilter(explode("\n", z::command('crontab -l')), function ($v) {
            return !!$v;
        });
    }

}
