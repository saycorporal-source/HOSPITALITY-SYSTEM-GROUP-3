<?php
// index.php — dashboard
require_once 'db.php';
checkAuth();

$count = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();
$sum   = fn(string $sql) => (float)$pdo->query($sql)->fetchColumn();

$total_patients     = $count("SELECT COUNT(*) FROM patients");
$total_doctors      = $count("SELECT COUNT(*) FROM doctors");
$total_appointments = $count("SELECT COUNT(*) FROM appointments");
$total_scheduled    = $count("SELECT COUNT(*) FROM appointments WHERE status = 'Scheduled'");
$in_progress        = $count("SELECT COUNT(*) FROM appointments WHERE status = 'In-Progress'");
$total_emergencies  = $count("SELECT COUNT(*) FROM appointments WHERE is_emergency = 1 AND status IN ('Scheduled','In-Progress')");
$today_appointments = $count("SELECT COUNT(*) FROM appointments WHERE DATE(appointment_date) = CURDATE() AND status <> 'Cancelled'");

// Billing comes from the existing `billing` table
$total_billed = $sum("SELECT COALESCE(SUM(total_amount),0) FROM billing WHERE payment_status <> 'Refunded'");
$collected    = $sum("SELECT COALESCE(SUM(total_amount),0) FROM billing WHERE payment_status = 'Paid'");
$outstanding  = $sum("SELECT COALESCE(SUM(total_amount),0) FROM billing WHERE payment_status IN ('Pending','Partially Paid')");
$open_bills   = $count("SELECT COUNT(*) FROM billing WHERE payment_status IN ('Pending','Partially Paid')");

// Discharges (needs sql/discharge_schema.sql)
$billing_ready    = table_exists($pdo, 'discharges');
$today_discharges = $billing_ready ? $count("SELECT COUNT(*) FROM discharges WHERE DATE(discharge_date) = CURDATE()") : 0;

$latest = $pdo->query(
    "SELECT a.appointment_id, a.appointment_date, a.reason_for_visit, a.room_number, a.status, a.is_emergency,
            CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
            CONCAT(d.first_name, ' ', d.last_name) AS doctor_name
     FROM appointments a
     LEFT JOIN patients p ON p.patient_id = a.patient_id
     LEFT JOIN doctors  d ON d.doctor_id  = a.doctor_id
     ORDER BY a.appointment_id DESC LIMIT 8"
)->fetchAll();

$pageTitle = 'Dashboard';
$active    = 'dashboard';
require 'includes/header.php';
?>

<div class="page-head">
    <h1>Dashboard</h1>
</div>

<?php if (!$billing_ready): ?>
    <div class="alert alert-error">Discharge table not found. Import <strong>sql/discharge_schema.sql</strong> to enable discharges and receipts.</div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat"><div class="stat-value"><?= number_format($total_patients) ?></div><div class="stat-label">Registered patients</div></div>
    <div class="stat"><div class="stat-value"><?= number_format($total_doctors) ?></div><div class="stat-label">Doctors</div></div>
    <div class="stat"><div class="stat-value"><?= number_format($total_appointments) ?></div><div class="stat-label">Total appointments</div></div>
    <div class="stat"><div class="stat-value"><?= number_format($total_scheduled) ?></div><div class="stat-label">Scheduled</div></div>
    <div class="stat"><div class="stat-value"><?= number_format($in_progress) ?></div><div class="stat-label">In progress (awaiting discharge)</div></div>
    <div class="stat <?= $total_emergencies ? 'is-alert' : '' ?>"><div class="stat-value"><?= number_format($total_emergencies) ?></div><div class="stat-label">Open emergency cases</div></div>
</div>

<div class="stat-grid">
    <div class="stat"><div class="stat-value"><?= money($total_billed) ?></div><div class="stat-label">Total billed</div></div>
    <div class="stat"><div class="stat-value"><?= money($collected) ?></div><div class="stat-label">Payments received (paid bills)</div></div>
    <div class="stat <?= $outstanding > 0 ? 'is-alert' : '' ?>"><div class="stat-value"><?= money($outstanding) ?></div><div class="stat-label">Unpaid or partial (<?= number_format($open_bills) ?> bills)</div></div>
    <div class="stat"><div class="stat-value"><?= $today_appointments ?> / <?= $today_discharges ?></div><div class="stat-label">Appointments / discharges today</div></div>
</div>

<div class="actions" style="margin-bottom: 8px;">
    <a href="insert.php" class="btn btn-primary">+ New appointment</a>
    <a href="discharge_form.php" class="btn">+ New discharge</a>
    <a href="discharges.php" class="btn">View receipts</a>
</div>

<h2 class="section-title">Latest appointments</h2>
<div class="table-wrap">
    <table>
        <thead>
            <tr><th>#</th><th>Patient</th><th>Doctor</th><th>Date &amp; time</th><th>Reason</th><th>Room</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php if (!$latest): ?>
            <tr><td colspan="7" class="empty">No appointments yet. <a href="insert.php">Book the first one.</a></td></tr>
        <?php endif; ?>
        <?php foreach ($latest as $r): ?>
            <tr class="<?= $r['is_emergency'] ? 'is-emergency' : '' ?>">
                <td><?= (int)$r['appointment_id'] ?></td>
                <td><?= e($r['patient_name'] ?: 'Unknown patient') ?></td>
                <td><?= e($r['doctor_name'] ?: 'Unassigned') ?></td>
                <td><?= e(date('Y-m-d H:i', strtotime($r['appointment_date']))) ?></td>
                <td><?= e($r['reason_for_visit']) ?></td>
                <td><?= e($r['room_number']) ?></td>
                <td><?= status_badge((string)$r['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require 'includes/footer.php'; ?>
