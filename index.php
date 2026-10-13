<?php
require_once 'db.php';

// Total recorded patient arrivals
$totalPatients = (int) $pdo
    ->query("SELECT COALESCE(SUM(patient_count), 0) FROM patient_arrivals")
    ->fetchColumn();

// Total departments
$totalDepartments = (int) $pdo
    ->query("SELECT COUNT(*) FROM department_capacity")
    ->fetchColumn();

// Latest arrival records
$stmt = $pdo->query("
    SELECT department, patient_count, arrival_time
    FROM patient_arrivals
    ORDER BY arrival_time DESC
    LIMIT 10
");
$recentArrivals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Department capacity summary
$capacityStmt = $pdo->query("
    SELECT
        dc.department,
        dc.hourly_capacity,
        COALESCE(SUM(
            CASE
                WHEN pa.arrival_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                THEN pa.patient_count
                ELSE 0
            END
        ), 0) AS recent_arrivals
    FROM department_capacity dc
    LEFT JOIN patient_arrivals pa
        ON pa.department = dc.department
    GROUP BY dc.id, dc.department, dc.hourly_capacity
    ORDER BY dc.department
");
$departments = $capacityStmt->fetchAll(PDO::FETCH_ASSOC);

// Hourly arrival chart for the last 7 hours
$chartStmt = $pdo->query("
    SELECT
        DATE_FORMAT(arrival_time, '%H:00') AS hour_label,
        SUM(patient_count) AS total
    FROM patient_arrivals
    WHERE arrival_time >= DATE_SUB(NOW(), INTERVAL 7 HOUR)
    GROUP BY HOUR(arrival_time)
    ORDER BY HOUR(arrival_time)
");
$chartRows = $chartStmt->fetchAll(PDO::FETCH_ASSOC);

$chartLabels = [];
$chartValues = [];

foreach ($chartRows as $row) {
    $chartLabels[] = $row['hour_label'];
    $chartValues[] = (int) $row['total'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediFlow AI | Hospital Operations</title>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", Arial, sans-serif;
            background: #f4f7fb;
            color: #182338;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        aside {
            width: 235px;
            flex-shrink: 0;
            background: #12263f;
            color: white;
            padding: 28px 18px;
        }

        .brand {
            font-size: 25px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .brand span { color: #54d6c7; }

        .tagline {
            color: #b9c9dc;
            font-size: 12px;
            margin-bottom: 38px;
        }

        .nav-title {
            color: #91a7bf;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin: 25px 10px 12px;
        }

        .nav-item {
            display: block;
            color: #d9e5f3;
            text-decoration: none;
            padding: 13px 12px;
            margin-bottom: 7px;
            border-radius: 9px;
            font-size: 14px;
        }

        .nav-item.active {
            background: #23415f;
            color: #69e1d0;
        }

        main {
            flex: 1;
            min-width: 0;
            padding: 30px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 28px;
        }

        h1 {
            margin: 0 0 7px;
            font-size: 27px;
        }

        .muted { color: #758399; font-size: 13px; }

        .live {
            background: #e1f8f1;
            color: #147a62;
            padding: 10px 14px;
            border-radius: 25px;
            font-size: 12px;
            font-weight: 700;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 22px;
        }

        .card {
            background: white;
            border: 1px solid #e7edf4;
            border-radius: 15px;
            padding: 22px;
            box-shadow: 0 4px 18px rgba(25, 50, 80, .035);
        }

        .stat-label { color: #718096; font-size: 13px; }

        .stat-value {
            font-size: 32px;
            font-weight: 750;
            margin: 12px 0 8px;
        }

        .stat-note { color: #7b899d; font-size: 12px; }

        .content-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(280px, 1fr);
            gap: 20px;
            margin-bottom: 22px;
        }

        h2 { font-size: 17px; margin: 0 0 6px; }

        .section-subtitle {
            color: #8591a3;
            font-size: 12px;
            margin-bottom: 20px;
        }

        .chart-wrap { height: 270px; }

        .dept {
            margin-top: 21px;
        }

        .dept-head {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            font-size: 13px;
            margin-bottom: 9px;
        }

        .track {
            height: 8px;
            border-radius: 10px;
            background: #edf1f6;
            overflow: hidden;
        }

        .fill {
            height: 100%;
            border-radius: 10px;
            background: #28b8a6;
        }

        .fill.warning { background: #f0ad4e; }
        .fill.danger { background: #e46d78; }

        .table-wrap { overflow-x: auto; }

        table {
            border-collapse: collapse;
            width: 100%;
            font-size: 13px;
            text-align: left;
        }

        th {
            color: #7c899b;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .5px;
            background: #f8fafc;
        }

        th, td {
            padding: 15px 12px;
            border-bottom: 1px solid #edf1f5;
            white-space: nowrap;
        }

        td { color: #42516a; }

        .pill {
            background: #e5f8f3;
            color: #14806b;
            padding: 5px 9px;
            border-radius: 15px;
            font-size: 11px;
        }

        footer {
            color: #8995a6;
            font-size: 11px;
            text-align: center;
            padding: 24px 0 5px;
        }

        @media (max-width: 950px) {
            .stats { grid-template-columns: 1fr; }
            .content-grid { grid-template-columns: 1fr; }
            aside { width: 190px; }
            main { padding: 20px; }
        }

        @media (max-width: 600px) {
            .layout { display: block; }
            aside { width: 100%; padding: 20px; }
            .tagline { margin-bottom: 15px; }
            .nav-title { margin-top: 15px; }
            main { padding: 16px; }
            .topbar { align-items: flex-start; }
            h1 { font-size: 22px; }
        }
    </style>
</head>

<body>
<div class="layout">

    <aside>
        <div class="brand">MediFlow<span> AI</span></div>
        <div class="tagline">Intelligent Hospital Operations</div>

        <div class="nav-title">Workspace</div>
        <a class="nav-item active" href="index.php">▦ &nbsp; Dashboard</a>
        <a class="nav-item" href="#forecast">◷ &nbsp; Arrival Forecast</a>
        <a class="nav-item" href="#departments">▤ &nbsp; Department Capacity</a>
        <a class="nav-item" href="#arrivals">☷ &nbsp; Patient Arrivals</a>
        <a class="nav-item" href="patient_arrival.php">＋ &nbsp; Add Patient Arrival</a>
        <a class="nav-item" href="alerts.php">⚠ &nbsp; Bottleneck Alerts</a>

        <div class="nav-title">System</div>
        <a class="nav-item" href="index.php">↻ &nbsp; Refresh Dashboard</a>
    </aside>

    <main>
        <div class="topbar">
            <div>
                <h1>Hospital Operations Dashboard</h1>
                <div class="muted">
                    Monitor patient arrivals and available department capacity.
                </div>
            </div>
            <div class="live">● DEMO DATA</div>
        </div>

        <section class="stats">
            <div class="card">
                <div class="stat-label">Recorded Patient Arrivals</div>
                <div class="stat-value">
                    <?= number_format($totalPatients) ?>
                </div>
                <div class="stat-note">Across all stored sample records</div>
            </div>

            <div class="card">
                <div class="stat-label">Departments Monitored</div>
                <div class="stat-value">
                    <?= number_format($totalDepartments) ?>
                </div>
                <div class="stat-note">Configured in the capacity table</div>
            </div>

            <div class="card">
                <div class="stat-label">Arrival Records</div>
                <div class="stat-value">
                    <?= count($recentArrivals) ?>
                </div>
                <div class="stat-note">Latest records displayed below</div>
            </div>
        </section>

        <section class="content-grid">
            <div class="card" id="forecast">
                <h2>Patient Arrival Trends</h2>
                <div class="section-subtitle">
                    Recorded arrivals grouped by hour
                </div>
                <div class="chart-wrap">
                    <canvas id="arrivalChart"></canvas>
                </div>
            </div>

            <div class="card" id="departments">
                <h2>Department Capacity</h2>
                <div class="section-subtitle">
                    Recent arrivals compared with configured hourly capacity
                </div>

                <?php foreach ($departments as $dept): ?>
                    <?php
                    $capacity = max(1, (int) $dept['hourly_capacity']);
                    $arrivals = (int) $dept['recent_arrivals'];
                    $percent = min(100, ($arrivals / $capacity) * 100);

                    $fillClass = '';
                    if ($percent >= 90) {
                        $fillClass = 'danger';
                    } elseif ($percent >= 70) {
                        $fillClass = 'warning';
                    }
                    ?>
                    <div class="dept">
                        <div class="dept-head">
                            <strong>
                                <?= htmlspecialchars($dept['department']) ?>
                            </strong>
                            <span>
                                <?= $arrivals ?> / <?= $capacity ?>
                            </span>
                        </div>
                        <div class="track">
                            <div
                                class="fill <?= $fillClass ?>"
                                style="width: <?= $percent ?>%">
                            </div>
                        </div>
                        <div class="muted" style="margin-top:7px">
                            <?= round($percent) ?>% of configured capacity
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card" id="arrivals">
            <h2>Recent Patient Arrivals</h2>
            <div class="section-subtitle">
                Latest 10 records from the database
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Department</th>
                        <th>Patient Count</th>
                        <th>Arrival Date &amp; Time</th>
                        <th>Record Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($recentArrivals)): ?>
                        <tr>
                            <td colspan="4">No patient arrival records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentArrivals as $arrival): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($arrival['department']) ?>
                                </td>
                                <td>
                                    <?= (int) $arrival['patient_count'] ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($arrival['arrival_time']) ?>
                                </td>
                                <td><span class="pill">Recorded</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <footer>
            MediFlow AI · Hospital operations decision-support prototype.
            Demonstration data only; not for clinical decision-making.
        </footer>
    </main>
</div>

<script>
const labels = <?= json_encode($chartLabels) ?>;
const values = <?= json_encode($chartValues) ?>;

new Chart(document.getElementById('arrivalChart'), {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: 'Recorded patient arrivals',
            data: values,
            borderColor: '#20ad9b',
            backgroundColor: 'rgba(32, 173, 155, 0.12)',
            fill: true,
            tension: 0.35,
            borderWidth: 3,
            pointRadius: 4,
            pointBackgroundColor: '#20ad9b'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { precision: 0 }
            }
        }
    }
});
</script>
</body>
</html>