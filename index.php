<?php

include __DIR__ . "/vendor/autoload.php";

define('ID', 0);
define('FIRSTNAME', 1);
define('LASTNAME', 2);
define('DATE', 4);
define('DEPARTMENT', 5);
define('TITLE', 7);
define('SCHEDULE', 8);
define('ACTION', 9);
define('CHECKPOINT', 10);

define('IN', 'Մուտք');
define('IN_AGAIN', 'Կրկին մուտք');
define('OUT', 'Ելք');
define('OUT_AGAIN', 'Կրկին ելք');

/**
 * 0.16 hours - 10 minutes
 * 0.33 hours - 20 minutes
 */
define('ACCEPTABLE_LATE', 0.33);

if ($xlsx = SimpleXLSX::parse(__DIR__ . '/data/ogostos.xlsx')) {
    $startTime = microtime(true);
    $data = firstTransformation($xlsx);

    [$minDate, $maxDate] = getMinMaxDates($data);
    $emptyDayList = buildHours($minDate, $maxDate);

    $data = lastTransformation($data, $emptyDayList);
    $html = render($data);

    file_put_contents(__DIR__ . '/report.html', $html);

    echo sprintf('done by %s second', microtime(true) - $startTime) . PHP_EOL;
} else {
    echo SimpleXLSX::parseError();
}

function lastTransformation(array $data, array $emptyDayList): array {
    foreach ($data as $i => $employees) {
        foreach ($employees as $j => $employee) {
            $data[$i][$j]['hours'] = mapHours($employee['raw_hours'], $emptyDayList);
        }
    }

    return $data;
}

function firstTransformation($xlsxObject): array {
    $data = [];

    foreach ($xlsxObject->rows() as $row) {
        if (!is_numeric($row[ID])) continue;

        if (!isset($data[$row[DEPARTMENT]][$row[FIRSTNAME] . ' ' . $row[LASTNAME]])) {
            $data[$row[DEPARTMENT]][$row[FIRSTNAME] . ' ' . $row[LASTNAME]] = [
                'title' => $row[TITLE],
                'schedule' => $row[SCHEDULE],
                'raw_hours' => [],
            ];
        }

        $data[$row[DEPARTMENT]][$row[FIRSTNAME] . ' ' . $row[LASTNAME]]['raw_hours'][] = [
            'time' => new \DateTimeImmutable($row[DATE]),
            'checkpoint' => $row[CHECKPOINT],
            'action' => $row[ACTION],
        ];
    }

    return $data;
}

function mapHours(array $hours, array $dayList): array {
    foreach ($hours as $hour) {
        $date = $hour['time']->format('Y-m-d');
        $dayList[$date][] = [
            'hour' => $hour['time']->format('H:i'),
            'checkpoint' => $hour['checkpoint'],
            'action' => $hour['action'],
        ];
    }

    foreach ($dayList as &$hours) {
        if (is_null($hours)) {
            continue;
        }

        uasort($hours, function ($a, $b) {
            $a = strtotime($a['hour'] . ':00');
            $b = strtotime($b['hour'] . ':00');

            if ($a == $b) {
                return 0;
            }
            return ($a < $b) ? -1 : 1;
        });
    }

    return $dayList;
}

function buildHours(\DateTimeImmutable $minDate, \DateTimeImmutable $maxDate): array {
    $startDate = new \DateTime($minDate->format('Y-m-d H:i:s'));
    $dates = [];

    do {
        $dates[$startDate->format('Y-m-d')] = null;
        $startDate->add(new \DateInterval('P1D'));
    } while ($startDate->format('Ymd') <= $maxDate->format('Ymd'));

    return $dates;
}

function getMinMaxDates(array $data): array {
    $min = false;
    $max = false;

    foreach ($data as $employees) {
        foreach ($employees as $employee) {
            foreach ($employee['raw_hours'] as $hour) {
                $time = $hour['time'];

                if ($min === false && $max === false) {
                    $min = $time;
                    $max = $time;
                }

                if ($time < $min) {
                    $min = $time;
                }

                if ($time > $max) {
                    $max = $time;
                }
            }
        }
    }

    return [$min, $max];
}

function render(array $data): string {
    $content = '';

    foreach ($data as $departmentName => $department) {
        $content .= '<thead><tr><th class="ecell"></th>';

        foreach ($department as $employeeName => $employee) {
            foreach ($employee['hours'] as $date => $hours) {
                $tooltip = sprintf('data-toggle="tooltip" data-placement="top" title="%s"', date('l', strtotime($date)));
                $content .= sprintf('<th %s>%s</th>', $tooltip, date('Y\<\b\r\>m-d', strtotime($date)));
            }
            break;
        }

        $content .= '</tr></thead>';
        break;
    }

    $content .= '<tbody>';

    foreach ($data as $departmentName => $department) {
        $content .= <<<EOT
      <tr>
        <th colspan="100"><h3 class="mt-2 mb-2">{$departmentName}</h3></th>
      </tr>

EOT;

        foreach ($department as $employeeName => $employee) {
            $hoursHtml = '';

            foreach ($employee['hours'] as $date => $hours) {
                $hoursOut = 'x';
                $tooltip = '';
                $class = '';

                if (!is_null($hours)) {
                    $start = $hours[count($hours) - 1]['hour'];
                    $end = $hours[0]['hour'];
                    $diff = hourDiff($start, $end) - 1;

                    if ($diff < 8) {
                        $class = 'table-warning';
                    }

                    if ($diff < 4) {
                        $class = 'table-danger';
                    }

                    if ($diff > 9) {
                        $class = 'table-success';
                    }

                    if ($diff < 4) {
                        $diff++;
                    }

                    $lateHours = hourDiff('9:30', $start);
                    $late = '';

                    if ($lateHours > ACCEPTABLE_LATE) {
                        $late = sprintf('<strong class="text-danger">L%s</strong>', $lateHours);
                    }

                    $details = sprintf('H%s %s', $diff, $late);
                    $hoursOut = sprintf('%s<br>%s', $start, $details);
                    $tooltip = sprintf('data-toggle="tooltip" data-placement="top" title="%s hours, %s - %s"', $diff, $start, $end);
                }

                if (isWeekend($date)) {
                    $class = 'table-secondary';
                }

                $hoursDetails = '<div class="progress">';
                $stack = [];
                // modifyHours($hours);
                // foreach ($hours as $hour) {
                //
                //   $hoursDetails = sprintf('<div class="progress-bar" role="progressbar" style="width: %d%" aria-valuenow="%d" aria-valuemin="%d" aria-valuemax="%d"></div>', $now, $now, $min, 100);
                // }exit;
                $hoursDetails .= '</div>';

                $hoursHtml .= sprintf('<td class="align-middle %s" %s>%s</td>', $class, $tooltip, $hoursOut);
            }

            $content .= <<<EOT
        <tr>
          <th class="align-middle ecell">{$employeeName}</th>
          {$hoursHtml}
        </tr>

EOT;
        }
    }

    $content .= '</tbody>';

    $template = file_get_contents(__DIR__ . '/template.html');
    return str_replace('{{content}}', $content, $template);
}

function modifyHours(array $hours): array {
    print_r($hours);

    exit;
}

function hourDiff(string $start, string $end): string {
    $start = strtotime($start);
    $end = strtotime($end);
    $diff = $end - $start;

    return round($diff / 3600, 1);
}

function isWeekend($date): bool {
    return (date('N', strtotime($date)) >= 6);
}
