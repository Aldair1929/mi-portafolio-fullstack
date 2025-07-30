<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

// Cargar .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();


$env = static function (string $key, $default = null) {
    // Prioriza $_ENV (phpdotenv) y cae a getenv() si hiciera falta
    return $_ENV[$key] ?? getenv($key) ?? $default;
};

// Requeridas para SMTP/correos
$requiredEnv = [
    'SMTP_HOST','SMTP_PORT','SMTP_USER','SMTP_PASS',
    'MAIL_FROM_NAME','MAIL_FROM_ADDR','MAIL_TO_ADDR'
];
$missing = array_filter($requiredEnv, fn($k) => empty($env($k)));
if ($missing) {
    http_response_code(500);
    echo "Faltan variables de entorno: " . implode(', ', $missing);
    exit;
}

// Config SMTP/Correo
$SMTP_HOST = (string)$env('SMTP_HOST');
$SMTP_PORT = (int)$env('SMTP_PORT');
$SMTP_USER = (string)$env('SMTP_USER');
$SMTP_PASS = (string)$env('SMTP_PASS');

$MAIL_FROM_NAME = (string)$env('MAIL_FROM_NAME');
$MAIL_FROM_ADDR = (string)$env('MAIL_FROM_ADDR');
$MAIL_TO_ADDR   = (string)$env('MAIL_TO_ADDR');

$MAIL_SEND_AUTOREPLY = filter_var($env('MAIL_SEND_AUTOREPLY', 'true'), FILTER_VALIDATE_BOOLEAN);
$MAX_ATTACH_BYTES    = (int)$env('MAX_ATTACH_BYTES', 2097152); // 2 MB por defecto

// Asegura que el remitente sea la misma cuenta autenticada (recomendado por Gmail/DMARC)
$MAIL_FROM_ADDR = $SMTP_USER;

/* ─────────────────────────────────────────────────────────────
1) Aceptar solo POST
   ───────────────────────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.php');
    exit;
}

/* ─────────────────────────────────────────────────────────────
2) Sanitizar y validar datos
   ───────────────────────────────────────────────────────────── */
function s(string $v): string {
    return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
}

$nombre   = s($_POST['nombre']   ?? '');
$apellido = s($_POST['apellido'] ?? '');
$correo   = s($_POST['email']    ?? '');
$telefono = s($_POST['tel']      ?? '');
$ciudad   = s($_POST['ciudad']   ?? '');
$mensaje  = s($_POST['mensaje']  ?? '');

$errores = [];

// Regex: letras (incluye tildes/ñ), espacios, apóstrofe y guion, 2–50 chars
$reTexto = '/^[\p{L}\s\'-]{2,50}$/u';

// Validaciones
if (mb_strlen($nombre) < 2 || !preg_match($reTexto, $nombre)) {
    $errores[] = 'Nombre inválido.';
}
if (mb_strlen($apellido) < 2 || !preg_match($reTexto, $apellido)) {
    $errores[] = 'Apellido inválido.';
}
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    $errores[] = 'Correo inválido.';
}
// Ecuador: 09 + 8 dígitos o +5939 + 8 dígitos
if (!preg_match('/^(?:09\d{8}|\+5939\d{8})$/', $telefono)) {
    $errores[] = 'Teléfono inválido (use 09######## o +5939########).';
}
if (mb_strlen($ciudad) < 2 || !preg_match($reTexto, $ciudad)) {
    $errores[] = 'Ciudad inválida.';
}

/* Adjuntos (opcional) */
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
        // Extensiones permitidas
        $allowedExt = ['pdf','jpg','jpeg','png','doc','docx'];
        $ext = strtolower(pathinfo($_FILES['documento']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $errores[] = 'Tipo de archivo no permitido.';
            $adjuntoOk = false;
        }
        // (Opcional) Validación por MIME real
        if (function_exists('mime_content_type') && $adjuntoOk) {
            $allowedMime = [
                'application/pdf', 'image/jpeg', 'image/png',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];
            $mime = mime_content_type($_FILES['documento']['tmp_name']);
            if ($mime !== false && !in_array($mime, $allowedMime, true)) {
                $errores[] = 'Tipo MIME del adjunto no permitido.';
                $adjuntoOk = false;
            }
        }
    }
}

// Si hay errores, responder
if ($errores) {
    http_response_code(422);
    echo '<h3>Errores en el formulario:</h3><ul>';
    foreach ($errores as $e) {
        echo '<li>' . $e . '</li>';
    }
    echo '</ul><a href="index.php">Volver</a>';
    exit;
}

/* ─────────────────────────────────────────────────────────────
3) Cuerpos de correo (HTML + texto)
   ───────────────────────────────────────────────────────────── */
// Correo para TI (admin)
$asuntoAdmin = 'Nuevo contacto desde el portafolio';
$bodyAdminHTML = "
<h2>Nuevo mensaje de contacto</h2>
<p><strong>Nombre:</strong> {$nombre} {$apellido}</p>
<p><strong>Email:</strong> {$correo}</p>
<p><strong>Teléfono:</strong> {$telefono}</p>
<p><strong>Ciudad:</strong> {$ciudad}</p>" .
($mensaje ? "<p><strong>Mensaje:</strong><br>" . nl2br($mensaje) . "</p>" : '');

$bodyAdminTXT = "Nuevo mensaje de contacto\n"
. "Nombre: {$nombre} {$apellido}\n"
. "Email: {$correo}\n"
. "Teléfono: {$telefono}\n"
. "Ciudad: {$ciudad}\n"
. ($mensaje ? "Mensaje:\n{$mensaje}\n" : "");

// Auto‑reply para el usuario
$asuntoUser = 'Gracias por contactarme, ' . $nombre;
$bodyUserHTML = "
<h2>¡Hola {$nombre}!</h2>
<p>Gracias por tu mensaje. Te responderé a la brevedad.</p>
<p><strong>Resumen que enviaste:</strong></p>
<ul>
    <li>Nombre: {$nombre} {$apellido}</li>
    <li>Email: {$correo}</li>
    <li>Teléfono: {$telefono}</li>
    <li>Ciudad: {$ciudad}</li>
</ul>" .
($mensaje ? "<p><strong>Mensaje:</strong><br>" . nl2br($mensaje) . "</p>" : '') .
"<p>Saludos,<br>{$MAIL_FROM_NAME}</p>";

$bodyUserTXT = "Hola {$nombre}!\n\nGracias por tu mensaje. Te responderé pronto.\n\n"
. "Resumen:\nNombre: {$nombre} {$apellido}\nEmail: {$correo}\nTeléfono: {$telefono}\nCiudad: {$ciudad}\n"
. ($mensaje ? "Mensaje:\n{$mensaje}\n" : "")
. "\nSaludos,\n{$MAIL_FROM_NAME}";

/* ─────────────────────────────────────────────────────────────
4) Envío con PHPMailer (1) a ti + (2) auto‑reply al usuario
   ───────────────────────────────────────────────────────────── */
$mail = new PHPMailer(true);

try {
    // DEBUG (solo en desarrollo):
    // $mail->SMTPDebug = 2;            // 0=off, 2=info, 3/4=verbose
    // $mail->Debugoutput = 'html';

    // Config SMTP
    $mail->isSMTP();
    $mail->Host       = $SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = $SMTP_USER;
    $mail->Password   = $SMTP_PASS;        // App Password de Gmail si usas Gmail
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // o PHPMailer::ENCRYPTION_SMTPS (465)
    $mail->Port       = $SMTP_PORT;
    $mail->CharSet    = 'UTF-8';

    /* 1) A TI (admin) */
    $mail->setFrom($MAIL_FROM_ADDR, $MAIL_FROM_NAME); // Remitente autenticado
    $mail->addAddress($correo, $nombre . ' ' . $apellido); // usuario
    $mail->addBCC($MAIL_TO_ADDR); // copia oculta a tu correo


    if ($correo) {
        $mail->addReplyTo($correo, $nombre . ' ' . $apellido); // Responder al usuario
    }

    if ($adjuntoOk && !empty($_FILES['documento']['name'])) {
        $mail->addAttachment($_FILES['documento']['tmp_name'], $_FILES['documento']['name']);
    }

    $mail->isHTML(true);
    $mail->Subject = $asuntoAdmin;
    $mail->Body    = $bodyAdminHTML;
    $mail->AltBody = $bodyAdminTXT;
    $mail->send();

    /* 2) Auto‑reply al usuario (opcional) */
    if ($MAIL_SEND_AUTOREPLY && $correo) {
        $mail->clearAllRecipients();
        $mail->clearAttachments();

        $mail->addAddress($correo, $nombre . ' ' . $apellido);
        // Remitente se mantiene como tu cuenta autenticada
        $mail->Subject = $asuntoUser;
        $mail->Body    = $bodyUserHTML;
        $mail->AltBody = $bodyUserTXT;
        $mail->send();
    }

    echo "<h2>¡Formulario enviado correctamente! ✅</h2>"
    . "<p>Gracias, {$nombre}. Te responderé a la brevedad.</p>"
    . "<p><a href='index.php'>Volver al formulario</a></p>";

} catch (Exception $e) {
    http_response_code(500);
    echo "El mensaje no pudo ser enviado. Error: " . $mail->ErrorInfo;
}
