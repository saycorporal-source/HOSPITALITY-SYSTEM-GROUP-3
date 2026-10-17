<?php
// insert.php — create or edit an appointment
require_once 'db.php';
checkAuth();

$patients = $pdo->query('SELECT patient_id, first_name, last_name, date_of_birth FROM patients ORDER BY last_name, first_name')->fetchAll();
$doctors  = $pdo->query('SELECT doctor_id, first_name, last_name, specialty FROM doctors ORDER BY last_name, first_name')->fetchAll();

// Searchable picker labels: "#873 Lopez, Jessica (b. 1960-10-02)" — the leading number is what gets saved.
$patientLabels = [];
foreach ($patients as $p) {
    $patientLabels[(int)$p['patient_id']] = '#' . $p['patient_id'] . ' ' . $p['last_name'] . ', ' . $p['first_name'] . ' (b. ' . $p['date_of_birth'] . ')';
}
$doctorLabels = [];
foreach ($doctors as $d) {
    $doctorLabels[(int)$d['doctor_id']] = '#' . $d['doctor_id'] . ' Dr. ' . $d['first_name'] . ' ' . $d['last_name'] . ' (' . $d['specialty'] . ')';
}
$patient_label = '';
$doctor_label  = '';

$id               = (int)($_GET['edit'] ?? 0);
$patient_id       = 0;
$doctor_id        = 0;
$appointment_date = '';
$reason_for_visit = '';
$status           = 'Scheduled';
$room_number      = '';
$notes            = '';
$is_emergency     = 0;
$errors           = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id               = (int)($_POST['id'] ?? 0);
    $patient_label    = trim((string)($_POST['patient_pick'] ?? ''));
    $doctor_label     = trim((string)($_POST['doctor_pick'] ?? ''));
    $patient_id       = picker_id($patient_label);
    $doctor_id        = picker_id($doctor_label);
    $appointment_date = (string)($_POST['appointment_date'] ?? '');
    $reason_for_visit = trim($_POST['reason_for_visit'] ?? '');
    $status           = $_POST['status'] ?? 'Scheduled';
    $room_number      = trim($_POST['room_number'] ?? '');
    $notes            = trim($_POST['notes'] ?? '');
    $is_emergency     = isset($_POST['is_emergency']) ? 1 : 0;

    $dbDate = dt_input($appointment_date);
    if (!isset($patientLabels[$patient_id])) $errors[] = 'Pick a patient from the list (type a name or ID to search).';
    if (!isset($doctorLabels[$doctor_id]))   $errors[] = 'Pick a doctor from the list (type a name, specialty or ID to search).';
    if ($dbDate === null)               $errors[] = 'Enter a valid appointment date and time.';
    if ($reason_for_visit === '')       $errors[] = 'Enter the reason for the visit.';
    if ($room_number === '')            $errors[] = 'Enter a room number.';
    if (strlen($room_number) > 10)      $errors[] = 'Room number can be at most 10 characters.';
    if (!in_array($status, APPOINTMENT_STATUSES, true)) $errors[] = 'Choose a valid status.';

    if (!$errors) {
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE appointments SET patient_id = ?, doctor_id = ?, appointment_date = ?, reason_for_visit = ?,
                            status = ?, room_number = ?, notes = ?, is_emergency = ? WHERE appointment_id = ?'
                );
                $stmt->execute([$patient_id, $doctor_id, $dbDate, $reason_for_visit, $status, $room_number, $notes, $is_emergency, $id]);
                flash_set('Appointment #' . $id . ' updated.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO appointments (patient_id, doctor_id, appointment_date, reason_for_visit, status, room_number, notes, is_emergency)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$patient_id, $doctor_id, $dbDate, $reason_for_visit, $status, $room_number, $notes, $is_emergency]);
                flash_set('New appointment booked.');
            }
            redirect('appointments.php');
        } catch (PDOException $e) {
            error_log('Appointment save failed: ' . $e->getMessage());
            $errors[] = 'The appointment could not be saved. Check that the patient and doctor still exist.';
        }
    }
} elseif ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM appointments WHERE appointment_id = ?');
    $stmt->execute([$id]);
    $data = $stmt->fetch();
    if (!$data) {
        flash_set('That appointment no longer exists.', 'error');
        redirect('appointments.php');
    }
    $patient_id       = (int)$data['patient_id'];
    $doctor_id        = (int)$data['doctor_id'];
    $appointment_date = date('Y-m-d\TH:i', strtotime($data['appointment_date']));
    $reason_for_visit = $data['reason_for_visit'];
    $status           = $data['status'];
    $room_number      = $data['room_number'];
    $notes            = $data['notes'] ?? '';
    $is_emergency     = (int)$data['is_emergency'];
}

if ($patient_label === '' && $patient_id) $patient_label = $patientLabels[$patient_id] ?? '';
if ($doctor_label === ''  && $doctor_id)  $doctor_label  = $doctorLabels[$doctor_id] ?? '';

$is_edit   = $id > 0;
$pageTitle = $is_edit ? 'Edit appointment' : 'New appointment';
$active    = 'appointments';
require 'includes/header.php';
?>

<div class="page-head">
    <h1><?= $is_edit ? 'Edit appointment #' . $id : 'New appointment' ?></h1>
</div>

<div class="form-card">
    <?php if ($errors): ?>
        <div class="alert alert-error" role="alert">
            Fix the following before saving:
            <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form method="POST">
        <?= csrf_field() ?>
        <?php if ($is_edit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

        <div class="form-grid">
            <div>
                <label for="patient_pick">Patient</label>
                <input type="text" id="patient_pick" name="patient_pick" list="patient-list" value="<?= e($patient_label) ?>"
                       placeholder="Type a name or ID" autocomplete="off" required>
                <datalist id="patient-list">
                    <?php foreach ($patientLabels as $label): ?><option value="<?= e($label) ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label for="doctor_pick">Doctor</label>
                <input type="text" id="doctor_pick" name="doctor_pick" list="doctor-list" value="<?= e($doctor_label) ?>"
                       placeholder="Type a name, specialty or ID" autocomplete="off" required>
                <datalist id="doctor-list">
                    <?php foreach ($doctorLabels as $label): ?><option value="<?= e($label) ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label for="appointment_date">Date &amp; time</label>
                <input type="datetime-local" id="appointment_date" name="appointment_date" value="<?= e($appointment_date) ?>" required>
            </div>
            <div>
                <label for="room_number">Room number</label>
                <input type="text" id="room_number" name="room_number" value="<?= e($room_number) ?>" placeholder="e.g. R-101" maxlength="10" required>
            </div>
            <div>
                <label for="reason_for_visit">Reason for visit</label>
                <input type="text" id="reason_for_visit" name="reason_for_visit" value="<?= e($reason_for_visit) ?>" maxlength="255" required>
            </div>
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php foreach (APPOINTMENT_STATUSES as $s): ?>
                        <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="full">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="3"><?= e($notes) ?></textarea>
            </div>
            <div class="full check">
                <input type="checkbox" id="is_emergency" name="is_emergency" value="1" <?= $is_emergency ? 'checked' : '' ?>>
                <label for="is_emergency">Mark as emergency</label>
            </div>
        </div>

        <div class="form-actions">
            <a href="appointments.php" class="btn">Cancel</a>
            <button type="submit" class="btn btn-primary"><?= $is_edit ? 'Save changes' : 'Book appointment' ?></button>
        </div>
    </form>
</div>

<?php require 'includes/footer.php'; ?>
