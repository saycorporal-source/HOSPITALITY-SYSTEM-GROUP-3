<?php
// receipt.php — printable discharge receipt
require_once 'db.php';
checkAuth();

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT x.*, a.patient_id, a.doctor_id, a.appointment_date, a.room_number, a.reason_for_visit,
            p.first_name AS p_first, p.last_name AS p_last, p.date_of_birth, p.gender, p.blood_type, p.phone_number AS p_phone,
            d.first_name AS d_first, d.last_name AS d_last, d.specialty, d.license_number,
            b.consultation_charge, b.medication_charge, b.lab_charge, b.total_amount, b.payment_status, b.payment_method
     FROM discharges x
     JOIN appointments a ON a.appointment_id = x.appointment_id
     LEFT JOIN patients p ON p.patient_id = a.patient_id
     LEFT JOIN doctors  d ON d.doctor_id  = a.doctor_id
     LEFT JOIN billing  b ON b.appointment_id = x.appointment_id
     WHERE x.discharge_id = ?"
);
$stmt->execute([$id]);
$r = $stmt->fetch();
if (!$r) {
    flash_set('That receipt could not be found.', 'error');
    redirect('discharges.php');
}

$lines = [
    ['Consultation', (float)$r['consultation_charge']],
    ['Medication',   (float)$r['medication_charge']],
    ['Laboratory',   (float)$r['lab_charge']],
];
$subtotal = round(array_sum(array_column($lines, 1)), 2);
$discount = (float)$r['discount'];
$total    = $r['total_amount'] !== null ? (float)$r['total_amount'] : round(max(0, $subtotal - $discount), 2);
$paid     = (float)$r['amount_paid'];
$balance  = round($total - $paid, 2);

$days = (int)(new DateTime($r['appointment_date']))->diff(new DateTime($r['discharge_date']))->days;
$stay = $days === 0 ? 'Same day' : $days . ' day' . ($days === 1 ? '' : 's');

$receiptNo = $r['receipt_no'] ?: 'DR-' . str_pad((string)$r['discharge_id'], 5, '0', STR_PAD_LEFT);
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Discharge receipt <?= e($receiptNo) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="receipt-toolbar no-print">
    <a href="discharges.php" class="btn">Back to receipts</a>
    <button type="button" class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
    <button type="button" class="btn" id="save-png">Save as PNG</button>
    <?php if ($flash): ?><span class="alert alert-<?= e($flash['type']) ?>" style="margin:0; padding:6px 12px;"><?= e($flash['message']) ?></span><?php endif; ?>
</div>

<article class="receipt">
    <div class="receipt-head">
        <div>
            <h1><?= e(HOSPITAL_NAME) ?></h1>
            <div class="hosp-meta"><?= e(HOSPITAL_ADDRESS) ?><br><?= e(HOSPITAL_PHONE) ?></div>
        </div>
        <div class="receipt-title">
            <div class="doc">Discharge Receipt</div>
            <div class="no">No. <?= e($receiptNo) ?></div>
            <div class="hosp-meta">Issued <?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></div>
        </div>
    </div>

    <dl class="info-grid">
        <div><dt>Patient</dt><dd><?= e(trim($r['p_first'] . ' ' . $r['p_last'])) ?> <span class="muted">(ID <?= (int)$r['patient_id'] ?>)</span></dd></div>
        <div><dt>Age / sex / blood type</dt><dd><?= age_on($r['date_of_birth'], $r['discharge_date']) ?> yrs, <?= e($r['gender']) ?>, <?= e($r['blood_type']) ?></dd></div>
        <div><dt>Attending doctor</dt><dd>Dr. <?= e($r['d_first'] . ' ' . $r['d_last']) ?> <span class="muted">(<?= e($r['specialty']) ?>)</span></dd></div>
        <div><dt>Doctor license no.</dt><dd><?= e($r['license_number']) ?></dd></div>
        <div><dt>Admitted</dt><dd><?= e(date('M j, Y g:i A', strtotime($r['appointment_date']))) ?></dd></div>
        <div><dt>Discharged</dt><dd><?= e(date('M j, Y g:i A', strtotime($r['discharge_date']))) ?></dd></div>
        <div><dt>Length of stay</dt><dd><?= e($stay) ?></dd></div>
        <div><dt>Room / appointment no.</dt><dd><?= e($r['room_number']) ?> / #<?= (int)$r['appointment_id'] ?></dd></div>
        <div><dt>Diagnosis</dt><dd><?= e($r['diagnosis']) ?></dd></div>
        <div><dt>Condition at discharge</dt><dd><?= e($r['discharge_condition']) ?></dd></div>
    </dl>

    <table>
        <thead><tr><th>Description</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($lines as [$label, $amount]): ?>
            <tr><td><?= e($label) ?></td><td class="num"><?= money($amount) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals-box">
        <div class="row"><span>Subtotal</span><span><?= money($subtotal) ?></span></div>
        <?php if ($discount > 0): ?>
            <div class="row"><span>Discount</span><span>&minus;<?= money($discount) ?></span></div>
        <?php endif; ?>
        <div class="row grand"><span>Total due</span><span><?= money($total) ?></span></div>
        <div class="row"><span>Paid<?= $r['payment_method'] ? ' (' . e($r['payment_method']) . ')' : '' ?></span><span><?= money($paid) ?></span></div>
        <?php if ($balance > 0): ?>
            <div class="row"><strong>Balance due</strong><strong><?= money($balance) ?></strong></div>
        <?php elseif ($balance < 0): ?>
            <div class="row"><span>Change returned</span><span><?= money(abs($balance)) ?></span></div>
        <?php endif; ?>
        <div style="text-align:right; margin-top:10px;">
            <span class="paid-stamp <?= $balance <= 0 ? '' : 'due-stamp' ?>"><?= $balance <= 0 ? 'PAID IN FULL' : 'BALANCE DUE' ?></span>
        </div>
    </div>

    <?php if (!empty($r['medications'])): ?>
        <section class="note-block"><h3>Discharge medications</h3><p><?= e($r['medications']) ?></p></section>
    <?php endif; ?>
    <?php if (!empty($r['instructions'])): ?>
        <section class="note-block"><h3>Home care instructions</h3><p><?= e($r['instructions']) ?></p></section>
    <?php endif; ?>
    <?php if (!empty($r['follow_up_date'])): ?>
        <section class="note-block"><h3>Follow-up</h3><p><?= e(date('l, F j, Y', strtotime($r['follow_up_date']))) ?></p></section>
    <?php endif; ?>

    <div class="signatures">
        <div><div class="sig-line">Patient / guardian signature</div></div>
        <div><div class="sig-line">Prepared by: <?= e($r['created_by'] ?: 'Billing') ?></div></div>
    </div>

    <p class="receipt-foot">Please keep this receipt for your records and bring it to your follow-up visit.</p>
</article>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
document.getElementById('save-png').addEventListener('click', async function () {
    if (typeof html2canvas === 'undefined') {
        alert('Saving as PNG needs an internet connection to load a small library. Use Print / Save as PDF instead, or connect and try again.');
        return;
    }
    const btn = this;
    btn.disabled = true;
    try {
        const canvas = await html2canvas(document.querySelector('.receipt'), {scale: 2, backgroundColor: '#ffffff'});
        const a = document.createElement('a');
        a.download = 'receipt-<?= e($receiptNo) ?>.png';
        a.href = canvas.toDataURL('image/png');
        a.click();
    } catch (err) {
        alert('Could not create the PNG. Try Print / Save as PDF instead.');
    }
    btn.disabled = false;
});
</script>
</body>
</html>
