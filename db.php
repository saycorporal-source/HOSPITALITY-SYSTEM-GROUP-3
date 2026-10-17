<?php
// db.php — connection, session and shared helpers

const DB_HOST = 'localhost';
const DB_NAME = 'hospital_db';
const DB_USER = 'root'; // Update with your MySQL username
const DB_PASS = '';     // Update with your MySQL password

// Shown on the discharge receipt
const HOSPITAL_NAME    = 'Hospital DB System';
const HOSPITAL_ADDRESS = 'Your hospital address here';
const HOSPITAL_PHONE   = 'Tel. (000) 000-0000';
const CURRENCY         = '$'; // your data uses US-style records; change to '₱' if you prefer

const APPOINTMENT_STATUSES  = ['Scheduled', 'In-Progress', 'Completed', 'Cancelled'];
const DISCHARGE_CONDITIONS  = ['Recovered', 'Improved', 'Stable', 'Transferred', 'Left against advice'];
const PAYMENT_METHODS       = ['Cash', 'Credit Card', 'Insurance', 'Bank Transfer']; // matches billing.payment_method

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    die('Database connection failed. Check the settings in db.php.');
}

/* ---------- Helpers ---------- */

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($amount): string
{
    return CURRENCY . number_format((float)$amount, 2);
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit();
}

function checkAuth(): void
{
    if (!isset($_SESSION['user_id'])) {
        redirect('login.php');
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        die('Your session expired. Go back, refresh the page and try again.');
    }
}

function flash_set(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function flash_get(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/** Convert a datetime-local / date string to 'Y-m-d H:i:s', or null if invalid. */
function dt_input(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $t = strtotime($value);
    return $t ? date('Y-m-d H:i:s', $t) : null;
}

function status_badge(string $status): string
{
    $class = match ($status) {
        'Scheduled'   => 'badge-scheduled',
        'In-Progress' => 'badge-progress',
        'Completed'   => 'badge-completed',
        'Cancelled'   => 'badge-cancelled',
        default       => 'badge-scheduled',
    };
    return '<span class="badge ' . $class . '">' . e($status) . '</span>';
}

function pagination_html(int $page, int $pages, array $params = []): string
{
    if ($pages <= 1) {
        return '';
    }
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);

    $link = function (int $p, string $label, bool $active = false, bool $disabled = false) use ($params) {
        if ($disabled) {
            return '<span class="pg disabled">' . $label . '</span>';
        }
        $qs = http_build_query(array_merge($params, ['page' => $p]));
        return '<a class="pg' . ($active ? ' active' : '') . '" href="?' . e($qs) . '"'
            . ($active ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    };

    $out = '<nav class="pagination" aria-label="Pagination">';
    $out .= $link(max(1, $page - 1), 'Previous', false, $page === 1);
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) {
        $out .= $link($i, (string)$i, $i === $page);
    }
    $out .= $link(min($pages, $page + 1), 'Next', false, $page === $pages);
    return $out . '</nav>';
}

/** True if the table exists (cached) — used so pages still load before the discharge SQL is imported. */
function table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        $cache[$table] = (int)$stmt->fetchColumn() > 0;
    }
    return $cache[$table];
}

function payment_badge(string $status): string
{
    $class = match ($status) {
        'Paid'           => 'badge-completed',
        'Partially Paid' => 'badge-progress',
        'Refunded'       => 'badge-cancelled',
        default          => 'badge-scheduled', // Pending
    };
    return '<span class="badge ' . $class . '">' . e($status) . '</span>';
}

function age_on(string $dob, ?string $on = null): int
{
    return (int)(new DateTime($dob))->diff(new DateTime($on ?: 'now'))->y;
}

/** "873" from "#873 Lopez, Jessica" (searchable picker values); 0 if none. */
function picker_id($value): int
{
    return preg_match('/^\s*#?(\d+)/', (string)$value, $m) ? (int)$m[1] : 0;
}
