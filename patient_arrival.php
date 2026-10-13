<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';

// Create a CSRF token for this form.
if (empty($_SESSION['arrival_csrf_token'])) {
    $_SESSION['arrival_csrf_token'] = bin2hex(random_bytes(32));
}

// Load departments from the database so the list stays in sync.
$departmentStmt = $pdo->query(
    'SELECT department FROM department_capacity ORDER BY department'
);
$departments = $departmentStmt->fetchAll(PDO::FETCH_COLUMN);

$errors = [];
$oldDepartment = '';
$oldPatientCount = '1';
$oldArrivalTime = date('Y-m-d\TH:i');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldDepartment = trim((string)($_POST['department'] ?? ''));
    $oldPatientCount = trim((string)($_POST['patient_count'] ?? ''));
    $oldArrivalTime = trim((string)($_POST['arrival_time'] ?? ''));
    $submittedToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['arrival_csrf_token'], $submittedToken)) {
        $errors[] = 'The form expired or could not be verified. Please try again.';
    }

    if ($oldDepartment === '' || !in_array($oldDepartment, $departments, true)) {
        $errors[] = 'Please select a valid department.';
    }

    $patientCount = filter_var(
        $oldPatientCount,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 10000]]
    );

    if ($patientCount === false) {
        $errors[] = 'Patient count must be a whole number between 1 and 10,000.';
    }

    $arrivalDate = DateTime::createFromFormat('!Y-m-d\TH:i', $oldArrivalTime);
    $dateErrors = DateTime::getLastErrors();
    $validDate = $arrivalDate !== false
        && ($dateErrors === false
            || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
        && $arrivalDate->format('Y-m-d\TH:i') === $oldArrivalTime;

    if (!$validDate) {
        $errors[] = 'Please enter a valid arrival date and time.';
    } elseif ($arrivalDate > new DateTime('+5 minutes')) {
        $errors[] = 'Arrival time cannot be more than 5 minutes in the future.';
    }

    if (!$errors) {
        try {
            $insert = $pdo->prepare(
                'INSERT INTO patient_arrivals (department, patient_count, arrival_time)
                 VALUES (:department, :patient_count, :arrival_time)'
            );

            $insert->execute([
                ':department' => $oldDepartment,
                ':patient_count' => $patientCount,
                ':arrival_time' => $arrivalDate->format('Y-m-d H:i:s'),
            ]);

            $_SESSION['arrival_flash_success'] = 'Patient arrival record saved successfully.';
            $_SESSION['arrival_csrf_token'] = bin2hex(random_bytes(32));
            header('Location: patient_arrival.php');
            exit;
        } catch (PDOException $e) {
            // Log the technical error on the server; show a safe message in the UI.
            error_log('MediFlow arrival insert failed: ' . $e->getMessage());
            $errors[] = 'The record could not be saved. Check the database connection and table columns.';
        }
    }
}

$successMessage = $_SESSION['arrival_flash_success'] ?? '';
unset($_SESSION['arrival_flash_success']);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Patient Arrival | MediFlow AI</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Arial, sans-serif;
            background: #f4f7fb;
            color: #182338;
        }
        .page { max-width: 960px; margin: 0 auto; padding: 28px 18px 45px; }
        .topbar {
            display: flex; justify-content: space-between; align-items: center;
            gap: 14px; flex-wrap: wrap; margin-bottom: 24px;
        }
        .brand { font-size: 24px; font-weight: 800; color: #12263f; }
        .brand span { color: #20ad9b; }
        .sub { color: #758399; font-size: 13px; margin-top: 5px; }
        .nav { display: flex; gap: 10px; flex-wrap: wrap; }
        .nav a {
            display: inline-block; text-decoration: none; color: #23415f;
            background: #e8eef5; border-radius: 9px; padding: 10px 13px;
            font-size: 13px; font-weight: 600;
        }
        .nav a.active { background: #12263f; color: #fff; }
        .card {
            background: #fff; border: 1px solid #e7edf4; border-radius: 16px;
            padding: 26px; box-shadow: 0 4px 18px rgba(25, 50, 80, .04);
        }
        h1 { margin: 0 0 8px; font-size: 26px; }
        .intro { color: #758399; font-size: 14px; line-height: 1.6; margin-bottom: 24px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .field { display: flex; flex-direction: column; gap: 8px; }
        .field.full { grid-column: 1 / -1; }
        label { font-size: 13px; font-weight: 700; color: #34445c; }
        input, select {
            width: 100%; min-height: 46px; padding: 11px 12px;
            border: 1px solid #d8e0ea; border-radius: 9px; background: #fff;
            color: #182338; font: inherit; outline: none;
        }
        input:focus, select:focus { border-color: #20ad9b; box-shadow: 0 0 0 3px rgba(32,173,155,.12); }
        .hint { font-size: 12px; color: #8490a1; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 24px; }
        button, .secondary {
            border: 0; border-radius: 9px; padding: 12px 17px; font: inherit;
            font-size: 14px; font-weight: 700; cursor: pointer; text-decoration: none;
        }
        button { color: #fff; background: #159b8b; }
        button:hover { background: #117e72; }
        .secondary { background: #edf1f6; color: #34445c; }
        .notice { padding: 13px 15px; border-radius: 9px; margin-bottom: 18px; font-size: 14px; }
        .success { color: #12694f; background: #e1f8f1; border: 1px solid #b8eadb; }
        .error { color: #923a43; background: #fff0f1; border: 1px solid #f3c6ca; }
        .error ul { margin: 8px 0 0; padding-left: 20px; }
        .foot { text-align: center; font-size: 11px; color: #8995a6; margin-top: 22px; }
        @media (max-width: 620px) {
            .page { padding: 18px 12px 30px; }
            .card { padding: 20px 16px; }
            .form-grid { grid-template-columns: 1fr; }
            .field.full { grid-column: auto; }
            h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
<div class="page">
    <header class="topbar">
        <div>
            <div class="brand">MediFlow<span> AI</span></div>
            <div class="sub">Intelligent Hospital Operations</div>
        </div>
        <nav class="nav" aria-label="Main navigation">
            <a href="index.php">Dashboard</a>
            <a href="forecast.php">Arrival Forecast</a>
            <a href="alerts.php">Bottleneck Alerts</a>
            <a class="active" href="patient_arrival.php">Add Arrival</a>
        </nav>
    </header>

    <main class="card">
        <h1>Record Patient Arrival</h1>
        <p class="intro">
            Enter the number of patients who arrived in a department at a specific date and time.
            The record will be stored in the MediFlow AI database and can be used by the dashboard
            and forecasting features.
        </p>

        <?php if ($successMessage !== ''): ?>
            <div class="notice success" role="status"><?= e($successMessage) ?></div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="notice error" role="alert">
                <strong>Please correct the following:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!$departments): ?>
            <div class="notice error">
                No departments are configured. Add departments to the department_capacity table first.
            </div>
        <?php else: ?>
            <form method="post" action="patient_arrival.php">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['arrival_csrf_token']) ?>">
                <div class="form-grid">
                    <div class="field">
                        <label for="department">Department *</label>
                        <select id="department" name="department" required>
                            <option value="">Choose a department</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= e((string)$department) ?>"
                                    <?= $oldDepartment === $department ? 'selected' : '' ?>>
                                    <?= e((string)$department) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="patient_count">Number of patients *</label>
                        <input
                            id="patient_count"
                            name="patient_count"
                            type="number"
                            min="1"
                            max="10000"
                            step="1"
                            value="<?= e($oldPatientCount) ?>"
                            required
                        >
                        <span class="hint">Enter a whole number from 1 to 10,000.</span>
                    </div>

                    <div class="field full">
                        <label for="arrival_time">Arrival date and time *</label>
                        <input
                            id="arrival_time"
                            name="arrival_time"
                            type="datetime-local"
                            value="<?= e($oldArrivalTime) ?>"
                            required
                        >
                        <span class="hint">Use the time the patients arrived. Future times are not accepted.</span>
                    </div>
                </div>

                <div class="actions">
                    <button type="submit">Save Patient Arrival</button>
                    <a class="secondary" href="index.php">Back to Dashboard</a>
                </div>
            </form>
        <?php endif; ?>
    </main>

    <div class="foot">
        MediFlow AI · Demonstration data only. Do not enter identifiable patient information.
    </div>
</div>
</body>
</html>