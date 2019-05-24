<?php

namespace Zls\Cron;

use Z;

/**
 * Class ParseCron
 * @package       Zls\Cron
 * @author        影浅 seekwe@gmail.com
 * @updatetime    2019-05-20 16:51
 */
class ParseCron
{
    static public $error;

    static public function parse($crontab_string, $startTime = null)
    {
        $date = [];
        if (!preg_match('/^((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)$/i',
            trim($crontab_string))
        ) {
            if (!preg_match('/^((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)\s+((\*(\/[0-9]+)?)|[0-9\-\,\/]+)$/i',
                trim($crontab_string))
            ) {
                self::$error = "Invalid cron string: " . $crontab_string;

                return false;
            }
        }
        if ($startTime && !is_numeric($startTime)) {
            self::$error = "\$startTime must be a valid unix timestamp ($startTime given)";

            return false;
        }
        $cron  = preg_split("/[\s]+/i", trim($crontab_string));
        $start = empty($startTime) ? time() : $startTime;
        if (count($cron) == 6) {
            $date = [
                'second'  => (empty($cron[0])) ? [1 => 1] : self::parseCronNumber($cron[0], 1, 59),
                'minutes' => self::parseCronNumber($cron[1], 0, 59),
                'hours'   => self::parseCronNumber($cron[2], 0, 23),
                'day'     => self::parseCronNumber($cron[3], 1, 31),
                'month'   => self::parseCronNumber($cron[4], 1, 12),
                'week'    => self::parseCronNumber($cron[5], 0, 6),
            ];
        } elseif (count($cron) == 5) {
            $date = [
                'second'  => [1 => 1],
                'minutes' => self::parseCronNumber($cron[0], 0, 59),
                'hours'   => self::parseCronNumber($cron[1], 0, 23),
                'day'     => self::parseCronNumber($cron[2], 1, 31),
                'month'   => self::parseCronNumber($cron[3], 1, 12),
                'week'    => self::parseCronNumber($cron[4], 0, 6),
            ];
        }
        $curMinutes = intval(date('i', $start));
        $curHours   = intval(date('G', $start));
        $curDay     = intval(date('j', $start));
        $curMonth   = intval(date('n', $start));
        if (
            in_array($curMinutes, $date['minutes'], true) &&
            in_array($curHours, $date['hours'], true) &&
            in_array($curDay, $date['day'], true) &&
            in_array($curMonth, $date['month'], true) &&
            in_array(intval(date('w', $start)), $date['week'], true)
        ) {
            return $date['second'];
        }

        return null;
    }

    static private function parseCronNumber($s, $min, $max)
    {
        $result = [];
        $v1     = explode(",", $s);
        foreach ($v1 as $v2) {
            $v3   = explode("/", $v2);
            $step = empty($v3[1]) ? 1 : $v3[1];
            $v4   = explode("-", $v3[0]);
            $_min = count($v4) == 2 ? $v4[0] : ($v3[0] == "*" ? $min : $v3[0]);
            $_max = count($v4) == 2 ? $v4[1] : ($v3[0] == "*" ? $max : $v3[0]);
            for ($i = $_min; $i <= $_max; $i += $step) {
                if (intval($i) < $min) {
                    $result[$min] = $min;
                } elseif (intval($i) > $max) {
                    $result[$max] = $max;
                } else {
                    $result[$i] = intval($i);
                }
            }
        }
        ksort($result);

        return $result;
    }
}
