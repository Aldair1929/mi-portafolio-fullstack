<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

// Cargar .env (está en 01-portfolio-frontend/.env)
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$env = static function (string $key, $default = null) {
    return $_ENV[$key] ?? getenv($key) ?? $default;
};

// Validar variables obligatorias
$requiredEnv = [
    'SMTP_HOST','SMTP_PORT','SMTP_USER','SMTP_PASS',
    'MAIL_FROM_NAME','MAIL_FROM_ADDR','MAIL_TO_ADDR'
];
$missing = array_filter($requiredEnv, fn($k) => empty($env($k)));
if ($missing) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => false,
        'errors' => ['Faltan variables de entorno: ' . implode(', ', $missing)]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Config SMTP/Correo
$SMTP_HOST          = (string)$env('SMTP_HOST');
$SMTP_PORT          = (int)$env('SMTP_PORT');
$SMTP_USER          = (string)$env('SMTP_USER');
$SMTP_PASS          = (string)$env('SMTP_PASS');
$MAIL_FROM_NAME     = (string)$env('MAIL_FROM_NAME');
$MAIL_FROM_ADDR     = (string)$env('MAIL_FROM_ADDR');
$MAIL_TO_ADDR       = (string)$env('MAIL_TO_ADDR');
$MAX_ATTACH_BYTES   = (int)$env('MAX_ATTACH_BYTES', 2097152);

// Forzar remitente == usuario autenticado (recomendado por Gmail/DMARC)
$MAIL_FROM_ADDR = $SMTP_USER;

// Aceptar solo POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'errors' => ['Método no permitido']], JSON_UNESCAPED_UNICODE);
    exit;
}

// Sanitizar helper
function s(string $v): string { return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8'); }

// Datos
$nombre   = s($_POST['nombre']   ?? '');
$apellido = s($_POST['apellido'] ?? '');
$correo   = s($_POST['email']    ?? '');
$telefono = s($_POST['tel']      ?? '');
$ciudad   = s($_POST['ciudad']   ?? '');
$mensaje  = s($_POST['mensaje']  ?? '');

$errores = [];
$reTexto = '/^[\p{L}\s\'-]{2,50}$/u';

// Validaciones
if (mb_strlen($nombre) < 2 || !preg_match($reTexto, $nombre))       $errores[] = 'Nombre inválido.';
if (mb_strlen($apellido) < 2 || !preg_match($reTexto, $apellido))   $errores[] = 'Apellido inválido.';
if (!filter_var($correo, FILTER_VALIDATE_EMAIL))                     $errores[] = 'Correo inválido.';
if (!preg_match('/^(?:09\d{8}|\+5939\d{8})$/', $telefono))          $errores[] = 'Teléfono inválido (use 09######## o +5939########).';
if (mb_strlen($ciudad) < 2 || !preg_match($reTexto, $ciudad))        $errores[] = 'Ciudad inválida.';

// Adjuntos (opcional)
$adjuntoOk = true;
if (!empty($_FILES['documento']['name'])) {
    if (!is_uploaded_file($_FILES['documento']['tmp_name'])) {
        $errores[] = 'Error al subir el archivo.';
        $adjuntoOk = false;
    } else {
        $size = (int)($_FILES['documento']['size'] ?? 0);
        if ($size > $MAX_ATTACH_BYTES) {
            $errores[] = 'Adjunto supera el tamaño permitido.';
            $adjuntoOk = false;
        }
        $allowedExt = ['pdf','jpg','jpeg','png','doc','docx'];
        $ext = strtolower(pathinfo($_FILES['documento']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $errores[] = 'Tipo de archivo no permitido.';
            $adjuntoOk = false;
        }
    }
}

if ($errores) {
    http_response_code(422);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'errors' => $errores], JSON_UNESCAPED_UNICODE);
    exit;
}

// Envío (UN SOLO correo al usuario + BCC para ti)
$mail = new PHPMailer(true);

try {
    // $mail->SMTPDebug = 2; $mail->Debugoutput = 'html'; // activar solo para depurar

    $mail->isSMTP();
    $mail->Host       = $SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = $SMTP_USER;
    $mail->Password   = $SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // o ENCRYPTION_SMTPS (465)
    $mail->Port       = $SMTP_PORT;
    $mail->CharSet    = 'UTF-8';

    // Remitente autenticado
    $mail->setFrom($MAIL_FROM_ADDR, $MAIL_FROM_NAME);

    // Destinatario principal: el usuario
    $mail->addAddress($correo, $nombre . ' ' . $apellido);

    // Copia oculta para ti (registro)
    $mail->addBCC($MAIL_TO_ADDR);

    // reply-to al usuario
    if ($correo) {
        $mail->addReplyTo($correo, $nombre . ' ' . $apellido);
    }

    // adjunto opcional
    if ($adjuntoOk && !empty($_FILES['documento']['name'])) {
        $mail->addAttachment($_FILES['documento']['tmp_name'], $_FILES['documento']['name']);
    }

    // contenido
    $mail->isHTML(true);
    $mail->Subject = 'Gracias por contactarme, ' . $nombre;
    $mail->Body    = "<h2>¡Hola {$nombre}!</h2>
                    <p>Gracias por escribirme. Pronto me pondré en contacto contigo.</p>
                    ".($mensaje ? "<p><strong>Tu mensaje:</strong><br>".nl2br($mensaje)."</p>" : '');
    $mail->AltBody = "Hola {$nombre}!\nGracias por escribirme. Pronto me pondré en contacto contigo."
                . ($mensaje ? "\n\nTu mensaje:\n{$mensaje}" : '');

    $mail->send();

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => true, 'message' => '¡Enviado con éxito! Gracias por contactarme.'], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => false,
        'errors' => ['El mensaje no pudo ser enviado. ' . $mail->ErrorInfo]
    ], JSON_UNESCAPED_UNICODE);
}
