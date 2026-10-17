<?php
// reports.php — summary reports with a date filter, print view and CSV export
require_once 'db.php';
checkAuth();

/* ---------- Date range ---------- */
function valid_date(?string $s): ?string
{
    $s = trim((string)$s);
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}
$from = valid_date($_GET['from'] ?? '');
$to   = valid_date($_GET['to'] ?? '');
$swapped = false;
if ($from && $to && $from > $to) {
    [$from, $to] = [$to, $from];
    $swapped = true;
}

function range_where(?string $from, ?string $to, array $extra = []): array
{
    $w = $extra;
    $p = [];
    if ($from) { $w[] = 'a.appointment_date >= :d1'; $p['d1'] = $from . ' 00:00:00'; }
    if ($to)   { $w[] = 'a.appointment_date <= :d2'; $p['d2'] = $to . ' 23:59:59'; }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}

$base = "FROM appointments a
         LEFT JOIN billing b ON b.appointment_id = a.appointment_id
         LEFT JOIN doctors d ON d.doctor_id = a.doctor_id";

$fetch = function (string $select, string $tail = '', array $extra = []) use ($pdo, $from, $to, $base): array {
    [$where, $params] = range_where($from, $to, $extra);
    $st = $pdo->prepare("SELECT $select $base $where $tail");
    $st->execute($params);
    return $st->fetchAll();
};

$billed = "CASE WHEN b.payment_status <> 'Refunded' THEN b.total_amount ELSE 0 END";

/* ---------- CSV export (must run before any HTML) ---------- */
if (isset($_GET['export'])) {
    [$where, $params] = range_where($from, $to);
    $st = $pdo->prepare(
        "SELECT a.appointment_id, a.appointment_date, CONCAT(p.first_name, ' ', p.last_name) AS patient,
                CONCAT(d.first_name, ' ', d.last_name) AS doctor, d.specialty, a.reason_for_visit, a.room_number,
                a.status, a.is_emergency, b.consultation_charge, b.medication_charge, b.lab_charge,
                b.total_amount, b.payment_status, b.payment_method
         FROM appointments a
         LEFT JOIN patients p ON p.patient_id = a.patient_id
         LEFT JOIN doctors  d ON d.doctor_id  = a.doctor_id
         LEFT JOIN billing  b ON b.appointment_id = a.appointment_id
         $where ORDER BY a.appointment_date, a.appointment_id"
    );
    $st->execute($params);

    // Stop spreadsheet apps from running text that starts with = + - @ as a formula
    $safe = fn($v) => (is_string($v) && preg_match('/^[=+\-@\t\r]/', $v)) ? "'" . $v : $v;

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hospital-report-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Appointment #', 'Date & time', 'Patient', 'Doctor', 'Specialty', 'Reason', 'Room', 'Status', 'Emergency',
                   'Consultation', 'Medication', 'Laboratory', 'Total due', 'Payment status', 'Payment method']);
    while ($r = $st->fetch()) {
        fputcsv($out, [
            $r['appointment_id'], $r['appointment_date'], $safe($r['patient']), $safe($r['doctor']), $safe($r['specialty']),
            $safe($r['reason_for_visit']), $safe($r['room_number']), $r['status'], $r['is_emergency'] ? 'Yes' : 'No',
            $r['consultation_charge'], $r['medication_charge'], $r['lab_charge'], $r['total_amount'],
            $r['payment_status'], $r['payment_method'],
        ]);
    }
    fclose($out);
    exit();
}

/* ---------- Report data ---------- */
$sum = $fetch(
    "COUNT(*) AS total,
     COALESCE(SUM(a.status = 'Completed'), 0) AS completed,
     COALESCE(SUM(a.status = 'Cancelled'), 0) AS cancelled,
     COALESCE(SUM(a.is_emergency = 1), 0) AS emergencies,
     COALESCE(SUM($billed), 0) AS billed,
     COALESCE(SUM(CASE WHEN b.payment_status = 'Paid' THEN b.total_amount ELSE 0 END), 0) AS collected,
     COALESCE(SUM(CASE WHEN b.payment_status IN ('Pending','Partially Paid') THEN b.total_amount ELSE 0 END), 0) AS outstanding"
)[0];

$byStatus  = $fetch('a.status AS label, COUNT(*) AS c', 'GROUP BY a.status ORDER BY c DESC');
$byPayment = $fetch('b.payment_status AS label, COUNT(*) AS c, COALESCE(SUM(b.total_amount), 0) AS amt',
                    'GROUP BY b.payment_status ORDER BY amt DESC', ['b.bill_id IS NOT NULL']);
$byMonth   = $fetch("DATE_FORMAT(a.appointment_date, '%Y-%m') AS ym, COUNT(*) AS c, COALESCE(SUM($billed), 0) AS billed,
                     COALESCE(SUM(CASE WHEN b.payment_status = 'Paid' THEN b.total_amount ELSE 0 END), 0) AS paid",
                    'GROUP BY ym ORDER BY ym DESC LIMIT 24');
$byDoctor  = $fetch("d.doctor_id, CONCAT(d.first_name, ' ', d.last_name) AS name, d.specialty, COUNT(*) AS c, COALESCE(SUM($billed), 0) AS billed",
                    'GROUP BY d.doctor_id, d.first_name, d.last_name, d.specialty ORDER BY c DESC, billed DESC LIMIT 10');
$bySpec    = $fetch("d.specialty AS label, COUNT(*) AS c, COALESCE(SUM($billed), 0) AS billed",
                    'GROUP BY d.specialty ORDER BY c DESC');
$byReason  = $fetch('a.reason_for_visit AS label, COUNT(*) AS c', 'GROUP BY a.reason_for_visit ORDER BY c DESC LIMIT 8');

$discharges = null;
if (table_exists($pdo, 'discharges')) {
    $w = []; $p = [];
    if ($from) { $w[] = 'discharge_date >= :d1'; $p['d1'] = $from . ' 00:00:00'; }
    if ($to)   { $w[] = 'discharge_date <= :d2'; $p['d2'] = $to . ' 23:59:59'; }
    $st = $pdo->prepare('SELECT COUNT(*) FROM discharges' . ($w ? ' WHERE ' . implode(' AND ', $w) : ''));
    $st->execute($p);
    $discharges = (int)$st->fetchColumn();
}

$total = (int)$sum['total'];
$pct   = fn($n, $d) => $d > 0 ? round($n / $d * 100, 1) : 0;
$bar   = fn($value, $max) => '<div class="bar" aria-hidden="true"><span style="width:' . ($max > 0 ? round($value / $max * 100) : 0) . '%"></span></div>';

$quick = [
    'All time'        => [],
    'This year'       => ['from' => date('Y-01-01'), 'to' => date('Y-m-d')],
    'Last 12 months'  => ['from' => date('Y-m-d', strtotime('-12 months')), 'to' => date('Y-m-d')],
];
$exportQs = http_build_query(array_filter(['from' => $from, 'to' => $to]) + ['export' => 1]);

$pageTitle = 'Reports';
$active    = 'reports';
require 'includes/header.php';
?>

<div class="page-head">
    <h1>Reports</h1>
    <div class="actions no-print">
        <button type="button" class="btn" onclick="window.print()">Print / Save as PDF</button>
        <a href="reports.php?<?= e($exportQs) ?>" class="btn btn-primary">Export CSV</a>
    </div>
</div>

<form method="GET" class="filters no-print" style="align-items:flex-end;">
    <div><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <button type="submit" class="btn">Apply</button>
    <?php foreach ($quick as $label => $qs): ?>
        <a href="reports.php<?= $qs ? '?' . e(http_build_query($qs)) : '' ?>" class="btn"><?= e($label) ?></a>
    <?php endforeach; ?>
</form>

<p class="muted" style="margin: 0 0 20px;">
    Showing appointments
    <?php if ($from || $to): ?>
        <?= $from ? 'from <strong>' . e($from) . '</strong>' : '' ?> <?= $to ? 'to <strong>' . e($to) . '</strong>' : '' ?>
    <?php else: ?>across <strong>all dates</strong><?php endif; ?>.
    Generated <?= e(date('M j, Y g:i A')) ?>.
    <?php if ($swapped): ?>The dates were in reverse order, so they were swapped.<?php endif; ?>
</p>

<div class="stat-grid">
    <div class="stat"><div class="stat-value"><?= number_format($total) ?></div><div class="stat-label">Appointments</div></div>
    <div class="stat"><div class="stat-value"><?= number_format((int)$sum['completed']) ?></div><div class="stat-label">Completed</div></div>
    <div class="stat"><div class="stat-value"><?= number_format((int)$sum['cancelled']) ?></div><div class="stat-label">Cancelled</div></div>
    <div class="stat <?= (int)$sum['emergencies'] ? 'is-alert' : '' ?>"><div class="stat-value"><?= number_format((int)$sum['emergencies']) ?></div><div class="stat-label">Emergency cases</div></div>
    <?php if ($discharges !== null): ?>
        <div class="stat"><div class="stat-value"><?= number_format($discharges) ?></div><div class="stat-label">Discharges recorded</div></div>
    <?php endif; ?>
</div>
<div class="stat-grid">
    <div class="stat"><div class="stat-value"><?= money($sum['billed']) ?></div><div class="stat-label">Total billed (excl. refunded)</div></div>
    <div class="stat"><div class="stat-value"><?= money($sum['collected']) ?></div><div class="stat-label">Payments received (paid bills)</div></div>
    <div class="stat <?= (float)$sum['outstanding'] > 0 ? 'is-alert' : '' ?>"><div class="stat-value"><?= money($sum['outstanding']) ?></div><div class="stat-label">Unpaid or partial</div></div>
</div>

<?php if ($total === 0): ?>
    <div class="alert alert-error">No appointments fall in this date range. Try a wider range or choose All time.</div>
<?php else: ?>

<div class="report-grid">
    <section>
        <h2 class="section-title">Appointments by status</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>Status</th><th class="num">Count</th><th class="num">Share</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($byStatus as $r): ?>
                <tr><td><?= status_badge((string)$r['label']) ?></td><td class="num"><?= number_format((int)$r['c']) ?></td>
                    <td class="num"><?= $pct($r['c'], $total) ?>%</td><td><?= $bar($r['c'], $total) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <section>
        <h2 class="section-title">Billing by payment status</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>Status</th><th class="num">Bills</th><th class="num">Amount</th></tr></thead>
            <tbody>
            <?php if (!$byPayment): ?><tr><td colspan="3" class="empty">No bills in this range.</td></tr><?php endif; ?>
            <?php foreach ($byPayment as $r): ?>
                <tr><td><?= payment_badge((string)$r['label']) ?></td><td class="num"><?= number_format((int)$r['c']) ?></td>
                    <td class="num"><?= money($r['amt']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
</div>

<h2 class="section-title">By month <span class="muted" style="font-size:.85rem; font-family:var(--sans); font-weight:400;">(by appointment date, latest 24 months)</span></h2>
<?php $maxBilled = max(array_map(fn($r) => (float)$r['billed'], $byMonth) ?: [0]); ?>
<div class="table-wrap"><table>
    <thead><tr><th>Month</th><th class="num">Appointments</th><th class="num">Billed</th><th class="num">Paid</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($byMonth as $r): ?>
        <tr><td><?= e($r['ym']) ?></td><td class="num"><?= number_format((int)$r['c']) ?></td>
            <td class="num"><?= money($r['billed']) ?></td><td class="num"><?= money($r['paid']) ?></td>
            <td><?= $bar((float)$r['billed'], $maxBilled) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>

<div class="report-grid">
    <section>
        <h2 class="section-title">Busiest doctors</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>Doctor</th><th>Specialty</th><th class="num">Appts</th><th class="num">Billed</th></tr></thead>
            <tbody>
            <?php foreach ($byDoctor as $r): ?>
                <tr><td>Dr. <?= e($r['name']) ?> <span class="muted">#<?= (int)$r['doctor_id'] ?></span></td><td><?= e($r['specialty']) ?></td>
                    <td class="num"><?= number_format((int)$r['c']) ?></td><td class="num"><?= money($r['billed']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <section>
        <h2 class="section-title">By specialty</h2>
        <?php $maxSpec = max(array_map(fn($r) => (int)$r['c'], $bySpec) ?: [0]); ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Specialty</th><th class="num">Appts</th><th class="num">Billed</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($bySpec as $r): ?>
                <tr><td><?= e($r['label'] ?: 'Unassigned') ?></td><td class="num"><?= number_format((int)$r['c']) ?></td>
                    <td class="num"><?= money($r['billed']) ?></td><td><?= $bar((int)$r['c'], $maxSpec) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
</div>

<h2 class="section-title">Most common reasons for visit</h2>
<?php $maxReason = max(array_map(fn($r) => (int)$r['c'], $byReason) ?: [0]); ?>
<div class="table-wrap" style="max-width: 640px;"><table>
    <thead><tr><th>Reason</th><th class="num">Appointments</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($byReason as $r): ?>
        <tr><td><?= e($r['label']) ?></td><td class="num"><?= number_format((int)$r['c']) ?></td><td><?= $bar((int)$r['c'], $maxReason) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>

<?php endif; ?>

<?php require 'includes/footer.php'; ?>
