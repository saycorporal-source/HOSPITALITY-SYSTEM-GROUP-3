<?php
// discharges.php — list of discharge receipts
require_once 'db.php';
checkAuth();

$ready = table_exists($pdo, 'discharges');

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();
    $pdo->prepare('DELETE FROM discharges WHERE discharge_id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    flash_set('Discharge record deleted. The appointment and its bill were not changed.');
    redirect('discharges.php');
}

$q = trim($_GET['q'] ?? '');
$rows = [];
$total_records = 0;
$page = 1;
$total_pages = 1;

if ($ready) {
    $from = "FROM discharges x
             JOIN appointments a ON a.appointment_id = x.appointment_id
             LEFT JOIN patients p ON p.patient_id = a.patient_id
             LEFT JOIN doctors  d ON d.doctor_id  = a.doctor_id
             LEFT JOIN billing  b ON b.appointment_id = x.appointment_id";
    $where = '';
    $params = [];
    if ($q !== '') {
        $where = "WHERE (x.receipt_no LIKE :q1 OR CONCAT(p.first_name, ' ', p.last_name) LIKE :q2 OR x.diagnosis LIKE :q3 OR a.patient_id = :qpid)";
        $params = ['q1' => "%$q%", 'q2' => "%$q%", 'q3' => "%$q%", 'qpid' => (int)$q];
    }

    $limit = 15;
    $cnt = $pdo->prepare("SELECT COUNT(*) $from $where");
    $cnt->execute($params);
    $total_records = (int)$cnt->fetchColumn();
    $total_pages   = max(1, (int)ceil($total_records / $limit));
    $page          = min($total_pages, max(1, (int)($_GET['page'] ?? 1)));

    $stmt = $pdo->prepare(
        "SELECT x.discharge_id, x.receipt_no, x.discharge_date, x.amount_paid, a.patient_id,
                CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                CONCAT(d.first_name, ' ', d.last_name) AS doctor_name,
                b.total_amount, b.payment_status
         $from $where ORDER BY x.discharge_id DESC LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $k => $v) $stmt->bindValue(':' . $k, $v);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

$pageTitle = 'Discharges';
$active    = 'discharges';
require 'includes/header.php';
?>

<div class="page-head">
    <h1>Discharge receipts</h1>
    <a href="discharge_form.php" class="btn btn-primary">+ New discharge</a>
</div>

<?php if (!$ready): ?>
    <div class="alert alert-error">Discharge table not found. Import <strong>sql/discharge_schema.sql</strong> into hospital_db first.</div>
<?php endif; ?>

<form method="GET" class="filters" role="search">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search receipt number, patient name or ID, or diagnosis" aria-label="Search receipts">
    <button type="submit" class="btn">Search</button>
    <?php if ($q !== ''): ?><a href="discharges.php" class="btn">Clear</a><?php endif; ?>
</form>

<div class="table-wrap">
    <table>
        <thead>
            <tr><th>Receipt</th><th>Patient</th><th>Doctor</th><th>Discharged</th><th class="num">Total</th><th class="num">Paid</th><th>Bill status</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="8" class="empty">No discharge receipts yet. <a href="discharge_form.php">Discharge a patient.</a></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
            $no = $r['receipt_no'] ?: 'DR-' . str_pad((string)$r['discharge_id'], 5, '0', STR_PAD_LEFT);
        ?>
            <tr>
                <td><a href="receipt.php?id=<?= (int)$r['discharge_id'] ?>"><?= e($no) ?></a></td>
                <td><?= e($r['patient_name']) ?> <span class="muted">(#<?= (int)$r['patient_id'] ?>)</span></td>
                <td><?= e($r['doctor_name']) ?></td>
                <td><?= e(date('Y-m-d H:i', strtotime($r['discharge_date']))) ?></td>
                <td class="num"><?= $r['total_amount'] !== null ? money($r['total_amount']) : '—' ?></td>
                <td class="num"><?= money($r['amount_paid']) ?></td>
                <td><?= $r['payment_status'] ? payment_badge((string)$r['payment_status']) : '<span class="muted">No bill</span>' ?></td>
                <td>
                    <div class="actions">
                        <a href="receipt.php?id=<?= (int)$r['discharge_id'] ?>" class="btn btn-sm">View / print</a>
                        <form method="POST" class="inline-form"
                              onsubmit="return confirm('Delete the discharge record for <?= e($no) ?>? The bill is kept. This cannot be undone.')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$r['discharge_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<p class="muted" style="margin-top:12px;"><?= number_format($total_records) ?> receipt<?= $total_records === 1 ? '' : 's' ?></p>
<?= pagination_html($page, $total_pages, ['q' => $q]) ?>

<?php require 'includes/footer.php'; ?>
