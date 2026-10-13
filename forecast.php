
<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

date_default_timezone_set('Asia/Kolkata');

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$departments = [];
$history = [];
$forecast = [];
$forecasts = [];
$errorMessage = '';

$selectedDepartment = trim((string)($_GET['department'] ?? 'Emergency'));

try {
    $departments = $pdo->query("
        SELECT department, hourly_capacity
        FROM department_capacity
        ORDER BY department
    ")->fetchAll(PDO::FETCH_ASSOC);

    $validDepartments = array_column($departments, 'department');

    if (!in_array($selectedDepartment, $validDepartments, true)) {
        $selectedDepartment = $validDepartments[0] ?? '';
    }

    if ($selectedDepartment !== '') {
        $capacity = 0;

        foreach ($departments as $dept) {
            if ($dept['department'] === $selectedDepartment) {
                $capacity = max(0, (int)$dept['hourly_capacity']);
                break;
            }
        }

        // Use the previous 28 days of historical arrivals.
        $now = new DateTimeImmutable('now');
        $currentHour = $now->setTime(
            (int)$now->format('H'),
            0,
            0
        );
        $historyStart = $currentHour->modify('-28 days');

        $stmt = $pdo->prepare("
            SELECT
                DATE_FORMAT(arrival_time, '%Y-%m-%d %H:00:00') AS hour_slot,
                SUM(patient_count) AS total
            FROM patient_arrivals
            WHERE department = :department
              AND arrival_time >= :start_time
              AND arrival_time < :end_time
            GROUP BY DATE_FORMAT(arrival_time, '%Y-%m-%d %H:00:00')
            ORDER BY hour_slot ASC
        ");

        $stmt->execute([
            ':department' => $selectedDepartment,
            ':start_time' => $historyStart->format('Y-m-d H:i:s'),
            ':end_time' => $currentHour->format('Y-m-d H:i:s'),
        ]);

        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /*
         * Convert recorded totals into a complete hourly series.
         * Missing hours are treated as zero recorded arrivals.
         */
        $recordedByHour = [];

        foreach ($history as $row) {
            $recordedByHour[$row['hour_slot']] = max(
                0,
                (int)$row['total']
            );
        }

        $hourlySeries = [];
        $slot = $historyStart;

        while ($slot < $currentHour) {
            $key = $slot->format('Y-m-d H:00:00');

            $hourlySeries[] = [
                'time' => $slot,
                'count' => $recordedByHour[$key] ?? 0,
            ];

            $slot = $slot->modify('+1 hour');
        }

        $seriesCount = count($hourlySeries);
        $allCounts = array_column($hourlySeries, 'count');

        $average = $seriesCount > 0
            ? array_sum($allCounts) / $seriesCount
            : 0;

        /*
         * Build averages for each hour of the day and each
         * weekday/hour combination.
         */
        $hourSums = array_fill(0, 24, 0);
        $hourSamples = array_fill(0, 24, 0);
        $weekdaySums = [];
        $weekdaySamples = [];

        foreach ($hourlySeries as $item) {
            $hourNumber = (int)$item['time']->format('G');
            $weekday = (int)$item['time']->format('N');
            $weekdayKey = $weekday . '-' . $hourNumber;

            $hourSums[$hourNumber] += $item['count'];
            $hourSamples[$hourNumber]++;

            $weekdaySums[$weekdayKey] =
                ($weekdaySums[$weekdayKey] ?? 0) + $item['count'];

            $weekdaySamples[$weekdayKey] =
                ($weekdaySamples[$weekdayKey] ?? 0) + 1;
        }

        $forecast = [];

        // Forecast each of the next six hours.
        for ($i = 1; $i <= 6; $i++) {
            $targetTime = $currentHour->modify("+{$i} hour");
            $targetHour = (int)$targetTime->format('G');
            $targetWeekday = (int)$targetTime->format('N');
            $weekdayKey = $targetWeekday . '-' . $targetHour;

            $hourAverage = $hourSamples[$targetHour] > 0
                ? $hourSums[$targetHour] / $hourSamples[$targetHour]
                : null;

            $matchingSamples = $weekdaySamples[$weekdayKey] ?? 0;

            $weekdayAverage = $matchingSamples > 0
                ? $weekdaySums[$weekdayKey] / $matchingSamples
                : null;

            /*
             * Recent trend: average the three completed hours
             * immediately before the current hour.
             */
            $recentCounts = [];

            for ($j = 1; $j <= 3; $j++) {
                $recentTime = $currentHour->modify("-{$j} hour");
                $recentKey = $recentTime->format('Y-m-d H:00:00');
                $recentCounts[] = $recordedByHour[$recentKey] ?? 0;
            }

            $recentAverage = array_sum($recentCounts) / count($recentCounts);

            /*
             * Weighted estimate:
             * 65% same weekday/hour, 25% same hour, 10% recent trend.
             * When historical matching data is limited, use fallback
             * averages instead.
             */
            if (
                $matchingSamples >= 2 &&
                $hourAverage !== null
            ) {
                $prediction =
                    (0.65 * $weekdayAverage) +
                    (0.25 * $hourAverage) +
                    (0.10 * $recentAverage);

                $method = 'Weekday pattern + hourly average + recent trend';
            } elseif ($hourAverage !== null) {
                $prediction =
                    (0.70 * $hourAverage) +
                    (0.30 * $recentAverage);

                $method = 'Hourly average + recent trend';
            } elseif ($seriesCount > 0) {
                $prediction =
                    (0.70 * $average) +
                    (0.30 * $recentAverage);

                $method = 'Overall historical average + recent trend';
            } else {
                $prediction = 0;
                $method = 'No historical data';
            }

            $predictedCount = max(0, (int)round($prediction));

            if ($capacity <= 0) {
                $status = 'unknown';
                $statusLabel = 'Capacity not set';
                $utilization = null;
            } else {
                $utilization = $predictedCount / $capacity;

                if ($utilization >= 1.0) {
                    $status = 'critical';
                    $statusLabel = 'Potential overload';
                } elseif ($utilization >= 0.80) {
                    $status = 'warning';
                    $statusLabel = 'Capacity warning';
                } else {
                    $status = 'safe';
                    $statusLabel = 'Within capacity';
                }
            }

            $forecast[] = [
                'time' => $targetTime->format('D, H:i'),
                'count' => $predictedCount,
                'capacity' => $capacity,
                'status' => $status,
                'status_label' => $statusLabel,
                'utilization' => $utilization,
                'method' => $method,
            ];
        }

        // Prepare historical rows for display, newest first.
        $history = array_reverse($history);
    } else {
        $capacity = 0;
        $average = 0;
    }
} catch (Throwable $e) {
    error_log('MediFlow forecast error: ' . $e->getMessage());
    $errorMessage = 'Unable to generate the forecast. Check your database connection and table structure.';
    $capacity = 0;
    $average = 0;
}

$nextHour = $forecast[0]['count'] ?? 0;
$nextStatus = $forecast[0]['status'] ?? 'unknown';

$warningCount = 0;
$criticalCount = 0;

foreach ($forecast as $item) {
    if ($item['status'] === 'warning') {
        $warningCount++;
    } elseif ($item['status'] === 'critical') {
        $criticalCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MediFlow AI | Arrival Forecast</title>

<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 28px;
    font-family: Arial, sans-serif;
    background: #f3f7fb;
    color: #192a40;
}

.container { max-width: 1100px; margin: auto; }

a { color: #087f8c; text-decoration: none; }

header, .card, .metric {
    background: white;
    border: 1px solid #e3eaf2;
    border-radius: 14px;
    padding: 22px;
    margin-bottom: 20px;
}

h1 { margin: 0 0 8px; }

h2 { font-size: 19px; }

.muted {
    color: #738196;
    font-size: 13px;
    line-height: 1.5;
}

form { margin-top: 18px; }

select, button {
    padding: 11px;
    border: 1px solid #d5dfeb;
    border-radius: 8px;
    font-size: 14px;
    margin-top: 7px;
}

button {
    background: #087f8c;
    color: white;
    border: 0;
    cursor: pointer;
}

.metrics {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}

.metric { margin: 0; }

.metric strong {
    display: block;
    font-size: 28px;
    margin: 10px 0;
}

.notice {
    padding: 14px;
    border-radius: 9px;
    margin: 14px 0;
    line-height: 1.5;
    font-size: 13px;
}

.warning { background: #fff1d9; color: #805400; }
.critical { background: #ffe7e9; color: #a61e2d; }
.safe { background: #e3f8f0; color: #12684d; }
.unknown { background: #edf1f5; color: #526275; }
.error { background: #fff0f1; color: #8d2430; }

.table-wrap { overflow-x: auto; }

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 14px;
    min-width: 650px;
}

th, td {
    padding: 13px;
    text-align: left;
    border-bottom: 1px solid #edf1f5;
    font-size: 13px;
}

th {
    color: #758398;
    font-size: 11px;
    text-transform: uppercase;
}

.badge {
    display: inline-block;
    padding: 6px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
}

.badge.warning { background: #fff1d9; }
.badge.critical { background: #ffe7e9; }
.badge.safe { background: #e3f8f0; }
.badge.unknown { background: #edf1f5; }

@media(max-width:650px) {
    body { padding: 14px; }
    .metrics { grid-template-columns: 1fr; }
    header, .card { padding: 16px; }
}
</style>
</head>

<body>
<div class="container">

<header>
    <p><a href="index.php">← Back to Dashboard</a></p>
    <h1>Patient Arrival Forecast</h1>
    <p class="muted">
        MediFlow AI · Historical patterns and recent arrival trends
    </p>

    <form method="get">
        <label for="department">Choose department:</label>
        <select name="department" id="department">
            <?php foreach ($departments as $dept): ?>
                <option
                    value="<?= h($dept['department']) ?>"
                    <?= $dept['department'] === $selectedDepartment ? 'selected' : '' ?>>
                    <?= h($dept['department']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">View Forecast</button>
    </form>
</header>

<?php if ($errorMessage !== ''): ?>

    <section class="card notice error">
        <?= h($errorMessage) ?>
    </section>

<?php elseif ($selectedDepartment === ''): ?>

    <section class="card">
        No departments found. Add departments and hourly capacities to
        the department_capacity table first.
    </section>

<?php else: ?>

<div class="metrics">
    <div class="metric">
        <span class="muted">Historical hourly average</span>
        <strong><?= number_format($average, 2) ?></strong>
        <span class="muted">Previous 28 days, including empty hours</span>
    </div>

    <div class="metric">
        <span class="muted">Estimated next-hour arrivals</span>
        <strong><?= $nextHour ?></strong>
        <span class="muted">Weighted statistical estimate</span>
    </div>

    <div class="metric">
        <span class="muted">Configured hourly capacity</span>
        <strong><?= $capacity ?></strong>
        <span class="muted">Department capacity setting</span>
    </div>
</div>

<section class="card">
    <h2>Forecast Summary</h2>

    <p class="muted">
        Forecast for <?= h($selectedDepartment) ?>.
        Warning threshold: 80% of configured hourly capacity.
    </p>

    <?php if ($nextStatus === 'critical'): ?>
        <div class="notice critical">
            <strong>Potential overload:</strong>
            predicted arrivals meet or exceed hourly capacity.
            Check actual queue conditions, staffing, and available rooms.
        </div>
    <?php elseif ($nextStatus === 'warning'): ?>
        <div class="notice warning">
            <strong>Capacity warning:</strong>
            predicted arrivals reach at least 80% of hourly capacity.
            Review staffing and equipment readiness.
        </div>
    <?php elseif ($nextStatus === 'unknown'): ?>
        <div class="notice unknown">
            Capacity is not configured. Enter a positive hourly capacity
            to enable capacity-based warnings.
        </div>
    <?php else: ?>
        <div class="notice safe">
            The forecast is below the capacity warning threshold.
            Continue monitoring actual arrivals.
        </div>
    <?php endif; ?>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Forecast hour</th>
            <th>Estimated arrivals</th>
            <th>Hourly capacity</th>
            <th>Predicted utilization</th>
            <th>Status</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($forecast as $item): ?>
            <tr>
                <td><?= h($item['time']) ?></td>
                <td><?= $item['count'] ?></td>
                <td><?= $item['capacity'] > 0 ? $item['capacity'] : 'Not set' ?></td>
                <td>
                    <?= $item['utilization'] === null
                        ? 'Unavailable'
                        : number_format($item['utilization'] * 100, 1) . '%' ?>
                </td>
                <td>
                    <span class="badge <?= h($item['status']) ?>">
                        <?= h($item['status_label']) ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <p class="muted">
        Method: <?= h($forecast[0]['method'] ?? 'No historical data') ?>.
        Estimates are rounded to whole patients.
    </p>
</section>

<section class="card">
    <h2>Historical Data Used</h2>
    <p class="muted">
        <?= count($history) ?> recorded hourly groups found for
        <?= h($selectedDepartment) ?> during the history window.
        Missing hours are treated as zero recorded arrivals in the averages.
    </p>

    <?php if (!$history): ?>
        <p>No historical records are available for this department yet.</p>
    <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Hour</th>
                <th>Recorded arrivals</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($history as $row): ?>
                <tr>
                    <td><?= h($row['hour_slot']) ?></td>
                    <td><?= (int)$row['total'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

<?php endif; ?>

<p class="muted">
    Demo data only. This is a statistical baseline improvement, not a
    trained or validated AI/ML model. Forecasts support operational
    planning and must not replace clinical judgment.
</p>

</div>
</body>
</html>