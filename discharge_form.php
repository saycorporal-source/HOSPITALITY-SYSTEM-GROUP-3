<?php
// discharge_form.php — discharge a patient for an appointment, update its bill, then show the receipt
require_once 'db.php';
checkAuth();

if (!table_exists($pdo, 'discharges')) {
    flash_set('Import sql/discharge_schema.sql into hospital_db first, then try again.', 'error');
    redirect('index.php');
}

$apptId = (int)($_POST['appointment_id'] ?? $_GET['appointment_id'] ?? 0);

/* ------------------------------------------------------------------
   Step 1: no appointment chosen yet -> searchable list of appointments
   that can still be discharged
------------------------------------------------------------------- */
if ($apptId === 0) {
    $q = trim($_GET['q'] ?? '');
    $where  = "WHERE x.discharge_id IS NULL AND a.status <> 'Cancelled'";
    $params = [];
    if ($q !== '') {
        $where .= " AND (CONCAT(p.first_name, ' ', p.last_name) LIKE :q1 OR a.reason_for_visit LIKE :q2
                         OR a.room_number LIKE :q3 OR a.appointment_id = :qid OR a.patient_id = :qpid)";
        $params = ['q1' => "%$q%", 'q2' => "%$q%", 'q3' => "%$q%", 'qid' => (int)$q, 'qpid' => (int)$q];
    }
    $stmt = $pdo->prepare(
        "SELECT a.appointment_id, a.patient_id, a.appointment_date, a.reason_for_visit, a.room_number, a.status, a.is_emergency,
                CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                CONCAT(d.first_name, ' ', d.last_name) AS doctor_name
         FROM appointments a
         LEFT JOIN patients p ON p.patient_id = a.patient_id
         LEFT JOIN doctors  d ON d.doctor_id  = a.doctor_id
         LEFT JOIN discharges x ON x.appointment_id = a.appointment_id
         $where
         ORDER BY (a.status = 'In-Progress') DESC, a.appointment_id DESC
         LIMIT 20"
    );
    $stmt->execute($params);
    $candidates = $stmt->fetchAll();

    $pageTitle = 'New discharge';
    $active    = 'discharges';
    require 'includes/header.php';
    ?>
    <div class="page-head">
        <h1>New discharge</h1>
        <a href="discharges.php" class="btn">Back to receipts</a>
    </div>
    <p class="muted" style="margin-top:-12px; margin-bottom:18px;">Choose the appointment to discharge. Patients who are in progress are listed first.</p>

    <form method="GET" class="filters" role="search">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search patient name, patient or appointment ID, reason or room" aria-label="Search appointments">
        <button type="submit" class="btn">Search</button>
        <?php if ($q !== ''): ?><a href="discharge_form.php" class="btn">Clear</a><?php endif; ?>
    </form>

    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Patient</th><th>Doctor</th><th>Date &amp; time</th><th>Reason</th><th>Room</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$candidates): ?>
                <tr><td colspan="8" class="empty">No dischargeable appointments match your search.</td></tr>
            <?php endif; ?>
            <?php foreach ($candidates as $c): ?>
                <tr class="<?= $c['is_emergency'] ? 'is-emergency' : '' ?>">
                    <td><?= (int)$c['appointment_id'] ?></td>
                    <td><?= e($c['patient_name']) ?> <span class="muted">(#<?= (int)$c['patient_id'] ?>)</span></td>
                    <td><?= e($c['doctor_name']) ?></td>
                    <td><?= e(date('Y-m-d H:i', strtotime($c['appointment_date']))) ?></td>
                    <td><?= e($c['reason_for_visit']) ?></td>
                    <td><?= e($c['room_number']) ?></td>
                    <td><?= status_badge((string)$c['status']) ?></td>
                    <td><a class="btn btn-sm btn-primary" href="discharge_form.php?appointment_id=<?= (int)$c['appointment_id'] ?>">Discharge</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted" style="margin-top:12px;">Showing up to 20 results. Search to narrow the list.</p>
    <?php
    require 'includes/footer.php';
    exit();
}

/* ------------------------------------------------------------------
   Step 2: the discharge form for one appointment
------------------------------------------------------------------- */
$stmt = $pdo->prepare(
    "SELECT a.*, p.first_name AS p_first, p.last_name AS p_last, p.date_of_birth, p.gender, p.blood_type,
            d.first_name AS d_first, d.last_name AS d_last, d.specialty, d.consultation_fee
     FROM appointments a
     LEFT JOIN patients p ON p.patient_id = a.patient_id
     LEFT JOIN doctors  d ON d.doctor_id  = a.doctor_id
     WHERE a.appointment_id = ?"
);
$stmt->execute([$apptId]);
$appt = $stmt->fetch();
if (!$appt) {
    flash_set('That appointment could not be found.', 'error');
    redirect('discharge_form.php');
}
if ($appt['status'] === 'Cancelled') {
    flash_set('Appointment #' . $apptId . ' was cancelled, so it cannot be discharged.', 'error');
    redirect('discharge_form.php');
}
$existing = $pdo->prepare('SELECT discharge_id FROM discharges WHERE appointment_id = ?');
$existing->execute([$apptId]);
if ($did = $existing->fetchColumn()) {
    flash_set('Appointment #' . $apptId . ' has already been discharged.');
    redirect('receipt.php?id=' . (int)$did);
}

$billStmt = $pdo->prepare('SELECT * FROM billing WHERE appointment_id = ?');
$billStmt->execute([$apptId]);
$bill = $billStmt->fetch() ?: null;

$f = [
    'discharge_date'      => date('Y-m-d\TH:i'),
    'diagnosis'           => (string)$appt['reason_for_visit'],
    'discharge_condition' => 'Improved',
    'medications'         => '',
    'instructions'        => '',
    'follow_up_date'      => '',
    'consultation'        => $bill ? $bill['consultation_charge'] : $appt['consultation_fee'],
    'medication'          => $bill ? $bill['medication_charge']   : '0.00',
    'lab'                 => $bill ? $bill['lab_charge']          : '0.00',
    'discount'            => '0.00',
    'payment_method'      => $bill ? $bill['payment_method'] : 'Cash',
    'amount_paid'         => ($bill && $bill['payment_status'] === 'Paid') ? $bill['total_amount'] : '0.00',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    foreach (['diagnosis', 'discharge_condition', 'medications', 'instructions', 'payment_method'] as $k) {
        $f[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $f['discharge_date'] = (string)($_POST['discharge_date'] ?? '');
    $f['follow_up_date'] = (string)($_POST['follow_up_date'] ?? '');

    $amt = function (string $key, string $label) use (&$errors, &$f): float {
        $v = trim((string)($_POST[$key] ?? ''));
        $f[$key] = $v;
        if ($v === '') $v = '0';
        if (!is_numeric($v) || (float)$v < 0 || (float)$v > 99999999.99) {
            $errors[] = "$label must be a number from 0 to 99,999,999.99.";
            return 0.0;
        }
        return round((float)$v, 2);
    };
    $consultation = $amt('consultation', 'Consultation charge');
    $medication   = $amt('medication', 'Medication charge');
    $lab          = $amt('lab', 'Laboratory charge');
    $discount     = $amt('discount', 'Discount');
    $paid         = $amt('amount_paid', 'Amount paid');

    $dischargeAt = dt_input($f['discharge_date']);
    $followUp    = $f['follow_up_date'] !== '' ? date('Y-m-d', strtotime($f['follow_up_date'])) : null;

    if (!$dischargeAt) {
        $errors[] = 'Enter a valid discharge date and time.';
    } elseif (strtotime($dischargeAt) < strtotime($appt['appointment_date'])) {
        $errors[] = 'The discharge date cannot be before the admission (' . date('Y-m-d H:i', strtotime($appt['appointment_date'])) . ').';
    }
    if ($f['diagnosis'] === '' || strlen($f['diagnosis']) > 255) $errors[] = 'Enter a diagnosis (up to 255 characters).';
    if (!in_array($f['discharge_condition'], DISCHARGE_CONDITIONS, true)) $errors[] = 'Choose the condition at discharge.';
    if (!in_array($f['payment_method'], PAYMENT_METHODS, true))           $errors[] = 'Choose a payment method.';
    if ($followUp && $dischargeAt && $followUp < date('Y-m-d', strtotime($dischargeAt))) $errors[] = 'The follow-up date cannot be before the discharge date.';

    $subtotal = round($consultation + $medication + $lab, 2);
    if ($discount > $subtotal) $errors[] = 'The discount cannot be more than the subtotal (' . money($subtotal) . ').';
    $total = round(max(0, $subtotal - $discount), 2);

    if ($total <= 0 || $paid >= $total) {
        $payStatus = 'Paid';
    } elseif ($paid > 0) {
        $payStatus = 'Partially Paid';
    } else {
        $payStatus = 'Pending';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $createdBy = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: ($_SESSION['username'] ?? '');
            $pdo->prepare(
                'INSERT INTO discharges (appointment_id, discharge_date, diagnosis, discharge_condition, medications, instructions,
                                         follow_up_date, discount, amount_paid, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $apptId, $dischargeAt, $f['diagnosis'], $f['discharge_condition'],
                $f['medications'] ?: null, $f['instructions'] ?: null, $followUp, $discount, $paid, $createdBy,
            ]);
            $newId = (int)$pdo->lastInsertId();
            $receiptNo = 'DR-' . date('Y') . '-' . str_pad((string)$newId, 5, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE discharges SET receipt_no = ? WHERE discharge_id = ?')->execute([$receiptNo, $newId]);

            // billing.total_amount holds the amount due after discount
            if ($bill) {
                $pdo->prepare(
                    'UPDATE billing SET consultation_charge = ?, medication_charge = ?, lab_charge = ?, total_amount = ?,
                            payment_status = ?, payment_method = ? WHERE appointment_id = ?'
                )->execute([$consultation, $medication, $lab, $total, $payStatus, $f['payment_method'], $apptId]);
            } else {
                $pdo->prepare(
                    'INSERT INTO billing (appointment_id, patient_id, consultation_charge, medication_charge, lab_charge,
                                          total_amount, payment_status, payment_method, billing_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$apptId, $appt['patient_id'], $consultation, $medication, $lab, $total, $payStatus, $f['payment_method'], $dischargeAt]);
            }

            $pdo->prepare("UPDATE appointments SET status = 'Completed' WHERE appointment_id = ?")->execute([$apptId]);
            $pdo->commit();

            flash_set('Discharge recorded. Receipt ' . $receiptNo . ' is ready to print.');
            redirect('receipt.php?id=' . $newId);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Discharge save failed: ' . $e->getMessage());
            $errors[] = 'The discharge could not be saved. If this appointment was just discharged by someone else, open it from the receipts list.';
        }
    }
}

$patientName = trim($appt['p_first'] . ' ' . $appt['p_last']);
$pageTitle = 'Discharge patient';
$active    = 'discharges';
require 'includes/header.php';
?>

<div class="page-head">
    <h1>Discharge patient</h1>
    <a href="discharge_form.php" class="btn">Choose a different appointment</a>
</div>

<div class="summary">
    <div><span class="muted">Patient</span><strong><?= e($patientName) ?> <span class="muted">#<?= (int)$appt['patient_id'] ?></span></strong>
        <span class="muted"><?= e($appt['gender']) ?>, <?= age_on($appt['date_of_birth']) ?> yrs, blood type <?= e($appt['blood_type']) ?></span></div>
    <div><span class="muted">Doctor</span><strong>Dr. <?= e($appt['d_first'] . ' ' . $appt['d_last']) ?></strong>
        <span class="muted"><?= e($appt['specialty']) ?></span></div>
    <div><span class="muted">Admitted</span><strong><?= e(date('M j, Y g:i A', strtotime($appt['appointment_date']))) ?></strong>
        <span class="muted">Appointment #<?= (int)$appt['appointment_id'] ?>, room <?= e($appt['room_number']) ?></span></div>
</div>

<div class="form-card" style="max-width: 1000px;">
    <?php if ($errors): ?>
        <div class="alert alert-error" role="alert">
            Fix the following before saving:
            <ul><?php foreach (array_unique($errors) as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form method="POST" id="discharge-form">
        <?= csrf_field() ?>
        <input type="hidden" name="appointment_id" value="<?= $apptId ?>">

        <h2>Discharge details</h2>
        <div class="form-grid" style="margin-top:16px;">
            <div>
                <label for="discharge_date">Discharged</label>
                <input type="datetime-local" id="discharge_date" name="discharge_date" value="<?= e($f['discharge_date']) ?>" required>
            </div>
            <div>
                <label for="discharge_condition">Condition at discharge</label>
                <select id="discharge_condition" name="discharge_condition">
                    <?php foreach (DISCHARGE_CONDITIONS as $c): ?>
                        <option value="<?= e($c) ?>" <?= $f['discharge_condition'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="full">
                <label for="diagnosis">Diagnosis</label>
                <input type="text" id="diagnosis" name="diagnosis" value="<?= e($f['diagnosis']) ?>" maxlength="255" required>
                <div class="hint">Pre-filled from the reason for visit. Replace it with the final diagnosis.</div>
            </div>
            <div>
                <label for="medications">Discharge medications</label>
                <textarea id="medications" name="medications" rows="3" placeholder="One per line, with dose and schedule"><?= e($f['medications']) ?></textarea>
            </div>
            <div>
                <label for="instructions">Home care instructions</label>
                <textarea id="instructions" name="instructions" rows="3" placeholder="Diet, activity, warning signs"><?= e($f['instructions']) ?></textarea>
            </div>
            <div>
                <label for="follow_up_date">Follow-up date</label>
                <input type="date" id="follow_up_date" name="follow_up_date" value="<?= e($f['follow_up_date']) ?>">
            </div>
        </div>

        <h2 class="fieldset-title">Charges</h2>
        <?php if ($bill): ?>
            <div class="hint" style="margin:-4px 0 12px;">This appointment already has a bill (<?= e($bill['payment_status']) ?>). Saving updates it.
                <?php if ($bill['payment_status'] === 'Partially Paid'): ?>Enter the total amount paid so far.<?php endif; ?></div>
        <?php else: ?>
            <div class="hint" style="margin:-4px 0 12px;">No bill exists yet. One will be created. Consultation is pre-filled with the doctor's standard fee.</div>
        <?php endif; ?>

        <div class="form-grid" style="grid-template-columns: repeat(3, 1fr);">
            <div><label for="consultation">Consultation (<?= e(CURRENCY) ?>)</label>
                <input type="number" id="consultation" name="consultation" min="0" step="0.01" value="<?= e($f['consultation']) ?>"></div>
            <div><label for="medication">Medication (<?= e(CURRENCY) ?>)</label>
                <input type="number" id="medication" name="medication" min="0" step="0.01" value="<?= e($f['medication']) ?>"></div>
            <div><label for="lab">Laboratory (<?= e(CURRENCY) ?>)</label>
                <input type="number" id="lab" name="lab" min="0" step="0.01" value="<?= e($f['lab']) ?>"></div>
        </div>

        <div class="totals-box">
            <div class="row"><span>Subtotal</span><span id="t-subtotal"></span></div>
            <div class="row">
                <label for="discount" style="margin:0; align-self:center;">Discount</label>
                <input type="number" id="discount" name="discount" min="0" step="0.01" value="<?= e($f['discount']) ?>">
            </div>
            <div class="row grand"><span>Total due</span><span id="t-total"></span></div>
            <div class="row">
                <label for="payment_method" style="margin:0; align-self:center;">Payment method</label>
                <select id="payment_method" name="payment_method" style="max-width:170px; padding:6px 8px;">
                    <?php foreach (PAYMENT_METHODS as $m): ?>
                        <option value="<?= e($m) ?>" <?= $f['payment_method'] === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row">
                <label for="amount_paid" style="margin:0; align-self:center;">Amount paid</label>
                <input type="number" id="amount_paid" name="amount_paid" min="0" step="0.01" value="<?= e($f['amount_paid']) ?>">
            </div>
            <div class="row"><span id="t-balance-label">Balance due</span><span id="t-balance"></span></div>
            <div class="row"><span>Bill status</span><span id="t-status"></span></div>
        </div>

        <div class="form-actions">
            <a href="discharge_form.php" class="btn">Cancel</a>
            <button type="submit" class="btn btn-primary">Save and view receipt</button>
        </div>
    </form>
</div>

<script>
(function () {
    const cur = <?= json_encode(CURRENCY) ?>;
    const $ = id => document.getElementById(id);
    const val = id => Math.max(0, parseFloat($(id).value) || 0);
    const fmt = n => cur + n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    function recalc() {
        const sub = Math.round((val('consultation') + val('medication') + val('lab')) * 100) / 100;
        const disc = Math.min(val('discount'), sub);
        const total = Math.round((sub - disc) * 100) / 100;
        const paid = val('amount_paid');
        const bal = Math.round((total - paid) * 100) / 100;
        $('t-subtotal').textContent = fmt(sub);
        $('t-total').textContent = fmt(total);
        $('t-balance-label').textContent = bal < 0 ? 'Change due to patient' : 'Balance due';
        $('t-balance').textContent = fmt(Math.abs(bal));
        $('t-status').textContent = (total <= 0 || paid >= total) ? 'Paid' : (paid > 0 ? 'Partially Paid' : 'Pending');
    }
    $('discharge-form').addEventListener('input', recalc);
    recalc();
})();
</script>

<?php require 'includes/footer.php'; ?>
