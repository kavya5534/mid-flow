
<?php
require_once __DIR__ . '/db.php';

// Load department capacities and arrivals recorded in the last hour.
$sql = "
    SELECT
        dc.department,
        dc.hourly_capacity,
        COALESCE(SUM(pa.patient_count), 0) AS arrivals
    FROM department_capacity dc
    LEFT JOIN patient_arrivals pa
        ON pa.department = dc.department
        AND pa.arrival_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        AND pa.arrival_time <= NOW()
    GROUP BY dc.id, dc.department, dc.hourly_capacity
    ORDER BY dc.department
";

$departments = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$highCount = 0;
$cautionCount = 0;
$normalCount = 0;

foreach ($departments as &$dept) {
    $capacity = (int) $dept['hourly_capacity'];
    $arrivals = (int) $dept['arrivals'];

    $dept['percent'] = $capacity > 0
        ? round(($arrivals / $capacity) * 100)
        : 0;

    if ($capacity <= 0) {
        $dept['level'] = 'Unconfigured';
    } elseif ($dept['percent'] >= 90) {
        $dept['level'] = 'High Warning';
        $highCount++;
    } elseif ($dept['percent'] >= 70) {
        $dept['level'] = 'Caution';
        $cautionCount++;
    } else {
        $dept['level'] = 'Within Threshold';
        $normalCount++;
    }
}
unset($dept);

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MediFlow AI | Bottleneck Alerts</title>
<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 28px;
    font-family: "Segoe UI", Arial, sans-serif;
    color: #192a40;
    background: #f3f7fb;
}

.container { max-width: 1100px; margin: auto; }

header, .card {
    background: white;
    border: 1px solid #e3eaf2;
    border-radius: 14px;
    padding: 24px;
    margin-bottom: 20px;
}

a { color: #087f8c; text-decoration: none; }
h1 { margin: 10px 0; }
h2 { font-size: 18px; }
.muted { color: #738196; font-size: 13px; }

.summary {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}

.metric {
    padding: 20px;
    background: white;
    border: 1px solid #e3eaf2;
    border-radius: 12px;
}

.metric strong {
    display: block;
    font-size: 30px;
    margin-top: 12px;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 16px;
}

th, td {
    text-align: left;
    padding: 15px 12px;
    border-bottom: 1px solid #edf1f5;
}

th {
    font-size: 11px;
    color: #738196;
    text-transform: uppercase;
}

.badge {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.high { color: #a61b1b; background: #fde8e8; }
.caution { color: #875800; background: #fff0cf; }
.normal { color: #146c43; background: #dff7e9; }
.unconfigured { color: #4b5563; background: #e9edf2; }

.bar {
    height: 8px;
    background: #edf1f6;
    border-radius: 10px;
    overflow: hidden;
    min-width: 80px;
}

.fill { height: 100%; background: #23a78e; }
.fill.caution { background: #e4a329; }
.fill.high { background: #d94a4a; }

.note {
    padding: 14px;
    background: #eef6ff;
    border-radius: 9px;
    color: #315574;
    font-size: 13px;
    line-height: 1.6;
}

.table-wrap { overflow-x: auto; }

@media (max-width: 650px) {
    body { padding: 14px; }
    .summary { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="container">

<header>
    <a href="index.php">← Back to Dashboard</a>
    <h1>Hospital Bottleneck Alerts</h1>
    <p class="muted">
        Monitor recent recorded arrivals against configured department capacity.
    </p>
</header>

<section class="summary">
    <div class="metric">
        <span class="muted">High Warnings</span>
        <strong><?= $highCount ?></strong>
    </div>
    <div class="metric">
        <span class="muted">Capacity Cautions</span>
        <strong><?= $cautionCount ?></strong>
    </div>
    <div class="metric">
        <span class="muted">Within Threshold</span>
        <strong><?= $normalCount ?></strong>
    </div>
</section>

<section class="card">
    <h2>Department Capacity Monitor</h2>
    <p class="muted">
        Thresholds: caution at 70%; high warning at 90%.
        Based on recorded arrivals in the previous hour.
    </p>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Department</th>
            <th>Recent Arrivals</th>
            <th>Hourly Capacity</th>
            <th>Capacity Used</th>
            <th>Status</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($departments as $dept): ?>
            <?php
            $levelClass = match ($dept['level']) {
                'High Warning' => 'high',
                'Caution' => 'caution',
                'Within Threshold' => 'normal',
                default => 'unconfigured'
            };

            $barClass = in_array($levelClass, ['high', 'caution'], true)
                ? $levelClass : '';
            ?>
            <tr>
                <td><?= h($dept['department']) ?></td>
                <td><?= (int) $dept['arrivals'] ?></td>
                <td><?= (int) $dept['hourly_capacity'] ?></td>
                <td>
                    <div class="bar">
                        <div class="fill <?= $barClass ?>"
                             style="width: <?= min(100, $dept['percent']) ?>%">
                        </div>
                    </div>
                    <?= $dept['percent'] ?>%
                </td>
                <td>
                    <span class="badge <?= $levelClass ?>">
                        <?= h($dept['level']) ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card">
    <h2>Recommended Operational Checks</h2>
    <p>
        <strong>High warning:</strong>
        Review live demand, staffing, and available capacity with the hospital
        operations team.
    </p>
    <p>
        <strong>Caution:</strong>
        Monitor incoming arrivals and prepare resources if demand continues rising.
    </p>
    <p>
        <strong>Within threshold:</strong>
        Continue monitoring; this does not guarantee that the department has no bottleneck.
    </p>
    <div class="note">
        Demonstration only: sample data are synthetic. These alerts use recent
        recorded arrivals, not actual bed occupancy or a trained prediction model.
        Validate live data and capacity definitions before real-world use.
    </div>
</section>

</div>
</body>
</html>