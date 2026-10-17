<?php
// appointments.php
require_once 'db.php';
checkAuth();

// Delete handler (POST + CSRF token, instead of a GET link)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();
    $stmt = $pdo->prepare('DELETE FROM appointments WHERE appointment_id = ?');
    $stmt->execute([(int)($_POST['id'] ?? 0)]);
    flash_set('Appointment deleted.');
    redirect('appointments.php');
}

// Filters
$q      = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
if (!in_array($status, APPOINTMENT_STATUSES, true)) {
    $status = '';
}

$hasDischarges = table_exists($pdo, 'discharges');
$from   = "FROM appointments a
           LEFT JOIN patients p ON p.patient_id = a.patient_id
           LEFT JOIN doctors  d ON d.doctor_id  = a.doctor_id"
        . ($hasDischarges ? " LEFT JOIN discharges x ON x.appointment_id = a.appointment_id" : "");
$dischargeCol = $hasDischarges ? ', x.discharge_id' : ', NULL AS discharge_id';
$where  = [];
$params = [];

if ($q !== '') {
    $where[] = "(CONCAT(p.first_name, ' ', p.last_name) LIKE :q1
              OR CONCAT(d.first_name, ' ', d.last_name) LIKE :q2
              OR a.reason_for_visit LIKE :q3
              OR a.room_number LIKE :q4)";
    foreach (['q1', 'q2', 'q3', 'q4'] as $k) {
        $params[$k] = '%' . $q . '%';
    }
}
if ($status !== '') {
    $where[] = 'a.status = :status';
    $params['status'] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Pagination
$limit = 15;
$cnt = $pdo->prepare("SELECT COUNT(*) $from $whereSql");
$cnt->execute($params);
$total_records = (int)$cnt->fetchColumn();
$total_pages   = max(1, (int)ceil($total_records / $limit));
$page          = min($total_pages, max(1, (int)($_GET['page'] ?? 1)));
$offset        = ($page - 1) * $limit;

$stmt = $pdo->prepare(
    "SELECT a.*, CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                 CONCAT(d.first_name, ' ', d.last_name) AS doctor_name $dischargeCol
     $from $whereSql
     ORDER BY a.appointment_id DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) {
    $stmt->bindValue(':' . $k, $v);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$appointments = $stmt->fetchAll();

$pageTitle = 'Appointments';
$active    = 'appointments';
require 'includes/header.php';
?>

<div class="page-head">
    <h1>Appointments</h1>
    <a href="insert.php" class="btn btn-primary">+ New appointment</a>
</div>

<form method="GET" class="filters" role="search">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search patient, doctor, reason or room" aria-label="Search appointments">
    <select name="status" aria-label="Filter by status" style="width:auto;">
        <option value="">All statuses</option>
        <?php foreach (APPOINTMENT_STATUSES as $s): ?>
            <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn">Filter</button>
    <?php if ($q !== '' || $status !== ''): ?><a href="appointments.php" class="btn">Clear</a><?php endif; ?>
</form>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th><th>Patient</th><th>Doctor</th><th>Date &amp; time</th>
                <th>Reason</th><th>Room</th><th>Status</th><th>Emergency</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$appointments): ?>
            <tr><td colspan="9" class="empty">No appointments match. <a href="insert.php">Book a new one.</a></td></tr>
        <?php endif; ?>
        <?php foreach ($appointments as $row): ?>
            <tr class="<?= $row['is_emergency'] ? 'is-emergency' : '' ?>">
                <td><?= (int)$row['appointment_id'] ?></td>
                <td><?= e($row['patient_name'] ?: 'Patient #' . $row['patient_id']) ?></td>
                <td><?= e($row['doctor_name'] ?: 'Doctor #' . $row['doctor_id']) ?></td>
                <td><?= e(date('Y-m-d H:i', strtotime($row['appointment_date']))) ?></td>
                <td><?= e($row['reason_for_visit']) ?></td>
                <td><?= e($row['room_number']) ?></td>
                <td><?= status_badge((string)$row['status']) ?></td>
                <td><?= $row['is_emergency'] ? '<span class="badge badge-emergency">Yes</span>' : '<span class="muted">No</span>' ?></td>
                <td>
                    <div class="actions">
                        <a href="insert.php?edit=<?= (int)$row['appointment_id'] ?>" class="btn btn-sm">Edit</a>
                        <?php if ($row['discharge_id']): ?>
                            <a href="receipt.php?id=<?= (int)$row['discharge_id'] ?>" class="btn btn-sm">Receipt</a>
                        <?php elseif ($row['status'] !== 'Cancelled'): ?>
                            <a href="discharge_form.php?appointment_id=<?= (int)$row['appointment_id'] ?>" class="btn btn-sm">Discharge</a>
                        <?php endif; ?>
                        <form method="POST" class="inline-form"
                              onsubmit="return confirm('Delete appointment #<?= (int)$row['appointment_id'] ?>? Its bill and discharge receipt will be deleted too. This cannot be undone.')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$row['appointment_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<p class="muted" style="margin-top:12px;"><?= number_format($total_records) ?> appointment<?= $total_records === 1 ? '' : 's' ?> found</p>
<?= pagination_html($page, $total_pages, ['q' => $q, 'status' => $status]) ?>

<?php require 'includes/footer.php'; ?>
