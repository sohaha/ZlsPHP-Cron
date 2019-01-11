<?php
/**
 * Zls\SimpleCron
 * @author       https://github.com/dragonmantank/cron-expression
 */

namespace SimpleCron;

abstract class AbstractField implements FieldInterface
{
    protected $fullRange = [];
    protected $literals = [];
    protected $rangeStart;
    protected $rangeEnd;

    public function __construct()
    {
        $this->fullRange = range($this->rangeStart, $this->rangeEnd);
    }

    public function isSatisfied($dateValue, $value)
    {
        if ($this->isIncrementsOfRanges($value)) {
            return $this->isInIncrementsOfRanges($dateValue, $value);
        } elseif ($this->isRange($value)) {
            return $this->isInRange($dateValue, $value);
        }

        return $value == '*' || $dateValue == $value;
    }

    public function isRange($value)
    {
        return strpos($value, '-') !== false;
    }

    public function isIncrementsOfRanges($value)
    {
        return strpos($value, '/') !== false;
    }

    public function isInRange($dateValue, $value)
    {
        $parts = array_map(function ($value) {
            $value = trim($value);
            $value = $this->convertLiterals($value);

            return $value;
        }, explode('-', $value, 2));

        return $dateValue >= $parts[0] && $dateValue <= $parts[1];
    }

    public function isInIncrementsOfRanges($dateValue, $value)
    {
        $chunks = array_map('trim', explode('/', $value, 2));
        $range  = $chunks[0];
        $step   = isset($chunks[1]) ? $chunks[1] : 0;
        if (is_null($step) || '0' === $step || 0 === $step) {
            return false;
        }
        if ('*' == $range) {
            $range = $this->rangeStart . '-' . $this->rangeEnd;
        }
        $rangeChunks = explode('-', $range, 2);
        $rangeStart  = $rangeChunks[0];
        $rangeEnd    = isset($rangeChunks[1]) ? $rangeChunks[1] : $rangeStart;
        if ($rangeStart < $this->rangeStart || $rangeStart > $this->rangeEnd || $rangeStart > $rangeEnd) {
            throw new \OutOfRangeException('Invalid range start requested');
        }
        if ($rangeEnd < $this->rangeStart || $rangeEnd > $this->rangeEnd || $rangeEnd < $rangeStart) {
            throw new \OutOfRangeException('Invalid range end requested');
        }
        if ($step >= $this->rangeEnd) {
            $thisRange = [$this->fullRange[$step % count($this->fullRange)]];
        } else {
            $thisRange = range($rangeStart, $rangeEnd, $step);
        }

        return in_array($dateValue, $thisRange);
    }

    public function getRangeForExpression($expression, $max)
    {
        $values     = [];
        $expression = $this->convertLiterals($expression);
        if (strpos($expression, ',') !== false) {
            $ranges = explode(',', $expression);
            $values = [];
            foreach ($ranges as $range) {
                $expanded = $this->getRangeForExpression($range, $this->rangeEnd);
                $values   = array_merge($values, $expanded);
            }

            return $values;
        }
        if ($this->isRange($expression) || $this->isIncrementsOfRanges($expression)) {
            if (!$this->isIncrementsOfRanges($expression)) {
                list($offset, $to) = explode('-', $expression);
                $offset   = $this->convertLiterals($offset);
                $to       = $this->convertLiterals($to);
                $stepSize = 1;
            } else {
                $range    = array_map('trim', explode('/', $expression, 2));
                $stepSize = isset($range[1]) ? $range[1] : 0;
                $range    = $range[0];
                $range    = explode('-', $range, 2);
                $offset   = $range[0];
                $to       = isset($range[1]) ? $range[1] : $max;
            }
            $offset = $offset == '*' ? $this->rangeStart : $offset;
            if ($stepSize >= $this->rangeEnd) {
                $values = [$this->fullRange[$stepSize % count($this->fullRange)]];
            } else {
                for ($i = $offset; $i <= $to; $i += $stepSize) {
                    $values[] = (int)$i;
                }
            }
            sort($values);
        } else {
            $values = [$expression];
        }

        return $values;
    }

    protected function convertLiterals($value)
    {
        if (count($this->literals)) {
            $key = array_search($value, $this->literals);
            if ($key !== false) {
                return $key;
            }
        }

        return $value;
    }

    public function validate($value)
    {
        $value = $this->convertLiterals($value);
        if ('*' === $value) {
            return true;
        }
        if (strpos($value, '/') !== false) {
            list($range, $step) = explode('/', $value);

            return $this->validate($range) && filter_var($step, FILTER_VALIDATE_INT);
        }
        if (strpos($value, ',') !== false) {
            foreach (explode(',', $value) as $listItem) {
                if (!$this->validate($listItem)) {
                    return false;
                }
            }

            return true;
        }
        if (strpos($value, '-') !== false) {
            if (substr_count($value, '-') > 1) {
                return false;
            }
            $chunks    = explode('-', $value);
            $chunks[0] = $this->convertLiterals($chunks[0]);
            $chunks[1] = $this->convertLiterals($chunks[1]);
            if ('*' == $chunks[0] || '*' == $chunks[1]) {
                return false;
            }

            return $this->validate($chunks[0]) && $this->validate($chunks[1]);
        }
        if (!is_numeric($value)) {
            return false;
        }
        if (is_float($value) || strpos($value, '.') !== false) {
            return false;
        }
        $value = (int)$value;

        return in_array($value, $this->fullRange, true);
    }
}

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use RuntimeException;

class CronExpression
{
    const MINUTE = 0;
    const HOUR = 1;
    const DAY = 2;
    const MONTH = 3;
    const WEEKDAY = 4;
    const YEAR = 5;
    private $cronParts;
    private $fieldFactory;
    private $maxIterationCount = 1000;
    private static $order = [self::YEAR, self::MONTH, self::DAY, self::WEEKDAY, self::HOUR, self::MINUTE];

    public static function factory($expression, FieldFactory $fieldFactory = null)
    {
        $mappings = ['@yearly' => '0 0 1 1 *', '@annually' => '0 0 1 1 *', '@monthly' => '0 0 1 * *', '@weekly' => '0 0 * * 0', '@daily' => '0 0 * * *', '@hourly' => '0 * * * *',];
        if (isset($mappings[$expression])) {
            $expression = $mappings[$expression];
        }

        return new static($expression, $fieldFactory ?: new FieldFactory());
    }

    public static function isValidExpression($expression)
    {
        try {
            self::factory($expression);
        } catch (InvalidArgumentException $e) {
            return false;
        }

        return true;
    }

    public function __construct($expression, FieldFactory $fieldFactory = null)
    {
        $this->fieldFactory = $fieldFactory;
        $this->setExpression($expression);
    }

    public function setExpression($value)
    {
        $this->cronParts = preg_split('/\s/', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (count($this->cronParts) < 5) {
            throw new InvalidArgumentException($value . ' is not a valid CRON expression');
        }
        foreach ($this->cronParts as $position => $part) {
            $this->setPart($position, $part);
        }

        return $this;
    }

    public function setPart($position, $value)
    {
        if (!$this->fieldFactory->getField($position)->validate($value)) {
            throw new InvalidArgumentException('Invalid CRON field value ' . $value . ' at position ' . $position);
        }
        $this->cronParts[$position] = $value;

        return $this;
    }

    public function setMaxIterationCount($maxIterationCount)
    {
        $this->maxIterationCount = $maxIterationCount;

        return $this;
    }

    public function getNextRunDate($currentTime = 'now', $nth = 0, $allowCurrentDate = false, $timeZone = null)
    {
        return $this->getRunDate($currentTime, $nth, false, $allowCurrentDate, $timeZone);
    }

    public function getPreviousRunDate($currentTime = 'now', $nth = 0, $allowCurrentDate = false, $timeZone = null)
    {
        return $this->getRunDate($currentTime, $nth, true, $allowCurrentDate, $timeZone);
    }

    public function getMultipleRunDates($total, $currentTime = 'now', $invert = false, $allowCurrentDate = false, $timeZone = null)
    {
        $matches = [];
        for ($i = 0; $i < max(0, $total); $i++) {
            try {
                $matches[] = $this->getRunDate($currentTime, $i, $invert, $allowCurrentDate, $timeZone);
            } catch (RuntimeException $e) {
                break;
            }
        }

        return $matches;
    }

    public function getExpression($part = null)
    {
        if (null === $part) {
            return implode(' ', $this->cronParts);
        } elseif (array_key_exists($part, $this->cronParts)) {
            return $this->cronParts[$part];
        }

        return null;
    }

    public function __toString()
    {
        return $this->getExpression();
    }

    public function isDue($currentTime = 'now', $timeZone = null)
    {
        $timeZone = $this->determineTimeZone($currentTime, $timeZone);
        if ('now' === $currentTime) {
            $currentTime = new DateTime();
        } elseif ($currentTime instanceof DateTime) {
        } elseif ($currentTime instanceof DateTimeImmutable) {
            $currentTime = DateTime::createFromFormat('U', $currentTime->format('U'));
        } else {
            $currentTime = new DateTime($currentTime);
        }
        $currentTime->setTimeZone(new DateTimeZone($timeZone));
        $currentTime = DateTime::createFromFormat('Y-m-d H:i', $currentTime->format('Y-m-d H:i'));
        try {
            return $this->getNextRunDate($currentTime, 0, true)->getTimestamp() === $currentTime->getTimestamp();
        } catch (Exception $e) {
            return false;
        }
    }

    protected function getRunDate($currentTime = null, $nth = 0, $invert = false, $allowCurrentDate = false, $timeZone = null)
    {
        $timeZone = $this->determineTimeZone($currentTime, $timeZone);
        if ($currentTime instanceof DateTime) {
            $currentDate = clone $currentTime;
        } elseif ($currentTime instanceof DateTimeImmutable) {
            $currentDate = DateTime::createFromFormat('U', $currentTime->format('U'));
        } else {
            $currentDate = new DateTime($currentTime ?: 'now');
        }
        $currentDate->setTimeZone(new DateTimeZone($timeZone));
        $currentDate->setTime($currentDate->format('H'), $currentDate->format('i'), 0);
        $nextRun = clone $currentDate;
        $nth     = (int)$nth;
        $parts   = [];
        $fields  = [];
        foreach (self::$order as $position) {
            $part = $this->getExpression($position);
            if (null === $part || '*' === $part) {
                continue;
            }
            $parts[$position]  = $part;
            $fields[$position] = $this->fieldFactory->getField($position);
        }
        for ($i = 0; $i < $this->maxIterationCount; $i++) {
            foreach ($parts as $position => $part) {
                $satisfied = false;
                $field     = $fields[$position];
                if (strpos($part, ',') === false) {
                    $satisfied = $field->isSatisfiedBy($nextRun, $part);
                } else {
                    foreach (array_map('trim', explode(',', $part)) as $listPart) {
                        if ($field->isSatisfiedBy($nextRun, $listPart)) {
                            $satisfied = true;
                            break;
                        }
                    }
                }
                if (!$satisfied) {
                    $field->increment($nextRun, $invert, $part);
                    continue 2;
                }
            }
            if ((!$allowCurrentDate && $nextRun == $currentDate) || --$nth > -1) {
                $this->fieldFactory->getField(0)->increment($nextRun, $invert, isset($parts[0]) ? $parts[0] : null);
                continue;
            }

            return $nextRun;
        }
        throw new RuntimeException('Impossible CRON expression');
    }

    protected function determineTimeZone($currentTime, $timeZone)
    {
        if (!is_null($timeZone)) {
            return $timeZone;
        }
        if ($currentTime instanceof Datetime) {
            return $currentTime->getTimeZone()->getName();
        }

        return date_default_timezone_get();
    }
}

class DayOfMonthField extends AbstractField
{
    protected $rangeStart = 1;
    protected $rangeEnd = 31;

    private static function getNearestWeekday($currentYear, $currentMonth, $targetDay)
    {
        $tday           = str_pad($targetDay, 2, '0', STR_PAD_LEFT);
        $target         = DateTime::createFromFormat('Y-m-d', "$currentYear-$currentMonth-$tday");
        $currentWeekday = (int)$target->format('N');
        if ($currentWeekday < 6) {
            return $target;
        }
        $lastDayOfMonth = $target->format('t');
        foreach ([-1, 1, -2, 2] as $i) {
            $adjusted = $targetDay + $i;
            if ($adjusted > 0 && $adjusted <= $lastDayOfMonth) {
                $target->setDate($currentYear, $currentMonth, $adjusted);
                if ($target->format('N') < 6 && $target->format('m') == $currentMonth) {
                    return $target;
                }
            }
        }
    }

    public function isSatisfiedBy(DateTime $date, $value)
    {
        if ($value == '?') {
            return true;
        }
        $fieldValue = $date->format('d');
        if ($value == 'L') {
            return $fieldValue == $date->format('t');
        }
        if (strpos($value, 'W')) {
            $targetDay = substr($value, 0, strpos($value, 'W'));

            return $date->format('j') == self::getNearestWeekday($date->format('Y'), $date->format('m'), $targetDay)->format('j');
        }

        return $this->isSatisfied($date->format('d'), $value);
    }

    public function increment(DateTime $date, $invert = false)
    {
        if ($invert) {
            $date->modify('previous day');
            $date->setTime(23, 59);
        } else {
            $date->modify('next day');
            $date->setTime(0, 0);
        }

        return $this;
    }

    public function validate($value)
    {
        $basicChecks = parent::validate($value);
        if (strpos($value, ',') !== false && (strpos($value, 'W') !== false || strpos($value, 'L') !== false)) {
            return false;
        }
        if (!$basicChecks) {
            if ($value === 'L') {
                return true;
            }
            if (preg_match('/^(.*)W$/', $value, $matches)) {
                return $this->validate($matches[1]);
            }

            return false;
        }

        return $basicChecks;
    }
}

class DayOfWeekField extends AbstractField
{
    protected $rangeStart = 0;
    protected $rangeEnd = 7;
    protected $nthRange;
    protected $literals = [1 => 'MON', 2 => 'TUE', 3 => 'WED', 4 => 'THU', 5 => 'FRI', 6 => 'SAT', 7 => 'SUN'];

    public function __construct()
    {
        $this->nthRange = range(1, 5);
        parent::__construct();
    }

    public function isSatisfiedBy(DateTime $date, $value)
    {
        if ($value == '?') {
            return true;
        }
        $value          = $this->convertLiterals($value);
        $currentYear    = $date->format('Y');
        $currentMonth   = $date->format('m');
        $lastDayOfMonth = $date->format('t');
        if (strpos($value, 'L')) {
            $weekday = $this->convertLiterals(substr($value, 0, strpos($value, 'L')));
            $weekday = str_replace('7', '0', $weekday);
            $tdate   = clone $date;
            $tdate->setDate($currentYear, $currentMonth, $lastDayOfMonth);
            while ($tdate->format('w') != $weekday) {
                $tdateClone = new DateTime();
                $tdate      = $tdateClone->setTimezone($tdate->getTimezone())->setDate($currentYear, $currentMonth, --$lastDayOfMonth);
            }

            return $date->format('j') == $lastDayOfMonth;
        }
        if (strpos($value, '#')) {
            list($weekday, $nth) = explode('#', $value);
            if (!is_numeric($nth)) {
                throw new InvalidArgumentException("Hashed weekdays must be numeric, {$nth} given");
            } else {
                $nth = (int)$nth;
            }
            if ($weekday === '0') {
                $weekday = 7;
            }
            $weekday = $this->convertLiterals($weekday);
            if ($weekday < 0 || $weekday > 7) {
                throw new InvalidArgumentException("Weekday must be a value between 0 and 7. {$weekday} given");
            }
            if (!in_array($nth, $this->nthRange)) {
                throw new InvalidArgumentException("There are never more than 5 or less than 1 of a given weekday in a month, {$nth} given");
            }
            if ($date->format('N') != $weekday) {
                return false;
            }
            $tdate = clone $date;
            $tdate->setDate($currentYear, $currentMonth, 1);
            $dayCount   = 0;
            $currentDay = 1;
            while ($currentDay < $lastDayOfMonth + 1) {
                if ($tdate->format('N') == $weekday) {
                    if (++$dayCount >= $nth) {
                        break;
                    }
                }
                $tdate->setDate($currentYear, $currentMonth, ++$currentDay);
            }

            return $date->format('j') == $currentDay;
        }
        if (strpos($value, '-')) {
            $parts = explode('-', $value);
            if ($parts[0] == '7') {
                $parts[0] = '0';
            } elseif ($parts[1] == '0') {
                $parts[1] = '7';
            }
            $value = implode('-', $parts);
        }
        $format     = in_array(7, str_split($value)) ? 'N' : 'w';
        $fieldValue = $date->format($format);

        return $this->isSatisfied($fieldValue, $value);
    }

    public function increment(DateTime $date, $invert = false)
    {
        if ($invert) {
            $date->modify('-1 day');
            $date->setTime(23, 59, 0);
        } else {
            $date->modify('+1 day');
            $date->setTime(0, 0, 0);
        }

        return $this;
    }

    public function validate($value)
    {
        $basicChecks = parent::validate($value);
        if (!$basicChecks) {
            if (strpos($value, '#') !== false) {
                $chunks    = explode('#', $value);
                $chunks[0] = $this->convertLiterals($chunks[0]);
                if (parent::validate($chunks[0]) && is_numeric($chunks[1]) && in_array($chunks[1], $this->nthRange)) {
                    return true;
                }
            }
            if (preg_match('/^(.*)L$/', $value, $matches)) {
                return $this->validate($matches[1]);
            }

            return false;
        }

        return $basicChecks;
    }
}

class FieldFactory
{
    private $fields = [];

    public function getField($position)
    {
        if (!isset($this->fields[$position])) {
            switch ($position) {
                case 0:
                    $this->fields[$position] = new MinutesField();
                    break;
                case 1:
                    $this->fields[$position] = new HoursField();
                    break;
                case 2:
                    $this->fields[$position] = new DayOfMonthField();
                    break;
                case 3:
                    $this->fields[$position] = new MonthField();
                    break;
                case 4:
                    $this->fields[$position] = new DayOfWeekField();
                    break;
                default:
                    throw new InvalidArgumentException($position . ' is not a valid position');
            }
        }

        return $this->fields[$position];
    }
}

interface FieldInterface
{
    public function isSatisfiedBy(DateTime $date, $value);

    public function increment(DateTime $date, $invert = false);

    public function validate($value);
}

class HoursField extends AbstractField
{
    protected $rangeStart = 0;
    protected $rangeEnd = 23;

    public function isSatisfiedBy(DateTime $date, $value)
    {
        return $this->isSatisfied($date->format('H'), $value);
    }

    public function increment(DateTime $date, $invert = false, $parts = null)
    {
        if (is_null($parts) || $parts == '*') {
            $timezone = $date->getTimezone();
            $date->setTimezone(new DateTimeZone('UTC'));
            if ($invert) {
                $date->modify('-1 hour');
            } else {
                $date->modify('+1 hour');
            }
            $date->setTimezone($timezone);
            $date->setTime($date->format('H'), $invert ? 59 : 0);

            return $this;
        }
        $parts = strpos($parts, ',') !== false ? explode(',', $parts) : [$parts];
        $hours = [];
        foreach ($parts as $part) {
            $hours = array_merge($hours, $this->getRangeForExpression($part, 23));
        }
        $current_hour = $date->format('H');
        $position     = $invert ? count($hours) - 1 : 0;
        if (count($hours) > 1) {
            for ($i = 0; $i < count($hours) - 1; $i++) {
                if ((!$invert && $current_hour >= $hours[$i] && $current_hour < $hours[$i + 1]) || ($invert && $current_hour > $hours[$i] && $current_hour <= $hours[$i + 1])) {
                    $position = $invert ? $i : $i + 1;
                    break;
                }
            }
        }
        $hour = $hours[$position];
        if ((!$invert && $date->format('H') >= $hour) || ($invert && $date->format('H') <= $hour)) {
            $date->modify(($invert ? '-' : '+') . '1 day');
            $date->setTime($invert ? 23 : 0, $invert ? 59 : 0);
        } else {
            $date->setTime($hour, $invert ? 59 : 0);
        }

        return $this;
    }
}

class MinutesField extends AbstractField
{
    protected $rangeStart = 0;
    protected $rangeEnd = 59;

    public function isSatisfiedBy(DateTime $date, $value)
    {
        return $this->isSatisfied($date->format('i'), $value);
    }

    public function increment(DateTime $date, $invert = false, $parts = null)
    {
        if (is_null($parts)) {
            if ($invert) {
                $date->modify('-1 minute');
            } else {
                $date->modify('+1 minute');
            }

            return $this;
        }
        $parts   = strpos($parts, ',') !== false ? explode(',', $parts) : [$parts];
        $minutes = [];
        foreach ($parts as $part) {
            $minutes = array_merge($minutes, $this->getRangeForExpression($part, 59));
        }
        $current_minute = $date->format('i');
        $position       = $invert ? count($minutes) - 1 : 0;
        if (count($minutes) > 1) {
            for ($i = 0; $i < count($minutes) - 1; $i++) {
                if ((!$invert && $current_minute >= $minutes[$i] && $current_minute < $minutes[$i + 1]) || ($invert && $current_minute > $minutes[$i] && $current_minute <= $minutes[$i + 1])) {
                    $position = $invert ? $i : $i + 1;
                    break;
                }
            }
        }
        if ((!$invert && $current_minute >= $minutes[$position]) || ($invert && $current_minute <= $minutes[$position])) {
            $date->modify(($invert ? '-' : '+') . '1 hour');
            $date->setTime($date->format('H'), $invert ? 59 : 0);
        } else {
            $date->setTime($date->format('H'), $minutes[$position]);
        }

        return $this;
    }
}

class MonthField extends AbstractField
{
    protected $rangeStart = 1;
    protected $rangeEnd = 12;
    protected $literals = [1 => 'JAN', 2 => 'FEB', 3 => 'MAR', 4 => 'APR', 5 => 'MAY', 6 => 'JUN', 7 => 'JUL', 8 => 'AUG', 9 => 'SEP', 10 => 'OCT', 11 => 'NOV', 12 => 'DEC',];

    public function isSatisfiedBy(DateTime $date, $value)
    {
        $value = $this->convertLiterals($value);

        return $this->isSatisfied($date->format('m'), $value);
    }

    public function increment(DateTime $date, $invert = false)
    {
        if ($invert) {
            $date->modify('last day of previous month');
            $date->setTime(23, 59);
        } else {
            $date->modify('first day of next month');
            $date->setTime(0, 0);
        }

        return $this;
    }
}
