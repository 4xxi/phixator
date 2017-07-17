<?php

/**
 * Class TimeLogHelper
 */
class TimeLogHelper
{
    /**
     * @param string $timeLog
     *
     * @return int
     */
    public static function timeLogToMinutes(string $timeLog): int
    {
        $totalMinutes = 0;
        if (!$parts = explode(' ', $timeLog)) {
            return 0;
        }

        foreach ($parts as $part) {
            $modifier = substr($part, -1, 1);
            $value = (int)substr($part, 0, strlen($part) - 1);

            switch ($modifier) {
                case 'w':
                    $totalMinutes += $value*10080;
                    break;

                case 'd':
                    $totalMinutes += $value*1440;
                    break;

                case 'h':
                    $totalMinutes += $value*60;
                    break;

                case 'm':
                    $totalMinutes += $value;
                    break;
            }
        }

        return $totalMinutes;
    }

    /**
     * @param int $minutes
     *
     * @return string
     */
    public static function minutesToTimeLog(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.'m';
        }

        $out = [
            'w' => 0,
            'd' => 0,
            'h' => 0,
            'm' => 0,
        ];

        while ($minutes > 60) {
            if ($minutes - 10080 >= 0) {
                $minutes -= 10080;
                $out['w']++;
                continue;
            }

            if ($minutes - 1440 >= 0) {
                $minutes -= 1440;
                $out['d']++;
                continue;
            }

            if ($minutes - 60 >= 0) {
                $minutes -= 60;
                $out['h']++;
                continue;
            }

            if ($out['h'] === 24) {
                $out['h'] = 0;
                $out['d']++;
            }
        };

        if ($minutes === 60) {
            $out['h']++;
            $minutes = 0;
        }

        $out['m'] += $minutes;

        $formatted = '';
        foreach ($out as $timeTitle => $timeValue) {
            if ($timeValue == 0) {
                continue;
            }

            $formatted .= $timeValue.$timeTitle.' ';
        }

        return $formatted;
    }
}