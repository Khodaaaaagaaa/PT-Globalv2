<?php
// ============================================
// PT Global — Form Handler
// Archivo: submit.php
// Colocar en: C:\laragon\www\ptglobal\submit.php
// ============================================

// ── Configuración de BD ──────────────────────
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'ptglobal');
define('DB_USER', 'root');
define('DB_PASS', '');          // Laragon default: sin contraseña

// ── Solo acepta POST ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// ── Conexión ─────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('DB connection failed: ' . $e->getMessage());
}

// ── Sanitizar inputs ─────────────────────────
function clean(string $val): string {
    return trim(strip_tags($val));
}

$first_name   = clean($_POST['q_fname']    ?? '');
$last_name    = clean($_POST['q_lname']    ?? '');
$email        = filter_var(trim($_POST['q_email'] ?? ''), FILTER_SANITIZE_EMAIL);
$phone        = clean($_POST['q_phone']    ?? '');
$revenue      = clean($_POST['q_revenue']  ?? '');
$team_size    = clean($_POST['q_size']     ?? '');
$services     = $_POST['q_services']       ?? [];   // array (checkboxes)
$hiring_stage = clean($_POST['q_stage']    ?? '');
$offshore_exp = clean($_POST['q_offshore'] ?? '');
$challenge    = clean($_POST['q_challenge']?? '');
$ip_address   = $_SERVER['REMOTE_ADDR']    ?? null;

// ── Validación básica ────────────────────────
$errors = [];
if (empty($first_name))                         $errors[] = 'First name required';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required';
if (!in_array($revenue, ['low','mid','high','top'])) $errors[] = 'Revenue range required';
if (empty($team_size))                          $errors[] = 'Team size required';
if (empty($hiring_stage))                       $errors[] = 'Hiring stage required';
if (empty($offshore_exp))                       $errors[] = 'Offshore experience required';

if (!empty($errors)) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

// ── Calificación (lógica del formulario) ─────
// low = $0-250k → no calificado
// mid / high / top → calificado
$qualified = ($revenue !== 'low') ? 1 : 0;

// ── Validar servicios permitidos ─────────────
$allowed_services = ['bookkeeping', 'cfo', 'tax_prep', 'tax_advisory'];
$services = array_filter($services, fn($s) => in_array($s, $allowed_services));

// ── Insertar lead ────────────────────────────
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO leads
            (first_name, last_name, email, phone,
             revenue_range, team_size, hiring_stage,
             offshore_exp, challenge, qualified, ip_address)
        VALUES
            (:first_name, :last_name, :email, :phone,
             :revenue_range, :team_size, :hiring_stage,
             :offshore_exp, :challenge, :qualified, :ip_address)
    ");

    $stmt->execute([
        ':first_name'   => $first_name,
        ':last_name'    => $last_name,
        ':email'        => $email,
        ':phone'        => $phone ?: null,
        ':revenue_range'=> $revenue,
        ':team_size'    => $team_size,
        ':hiring_stage' => $hiring_stage,
        ':offshore_exp' => $offshore_exp,
        ':challenge'    => $challenge ?: null,
        ':qualified'    => $qualified,
        ':ip_address'   => $ip_address,
    ]);

    $lead_id = $pdo->lastInsertId();

    // Insertar servicios seleccionados
    if (!empty($services)) {
        $svc_stmt = $pdo->prepare("
            INSERT INTO lead_services (lead_id, service) VALUES (:lead_id, :service)
        ");
        foreach ($services as $svc) {
            $svc_stmt->execute([':lead_id' => $lead_id, ':service' => $svc]);
        }
    }

    $pdo->commit();

} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    exit;
}

// ── Respuesta ────────────────────────────────
header('Content-Type: application/json');
echo json_encode([
    'success'   => true,
    'lead_id'   => (int) $lead_id,
    'qualified' => (bool) $qualified,
]);
