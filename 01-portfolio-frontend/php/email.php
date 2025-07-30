<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

// ─────────────────────────────────────────────────────────────
// 1) Solo aceptar POST
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// ─────────────────────────────────────────────────────────────
// 2) Sanitizar y validar datos
// ─────────────────────────────────────────────────────────────
function s(string $v): string {
    // sanitización segura para HTML
    return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
}

$nombre   = s($_POST['nombre']  ?? '');
$apellido = s($_POST['apellido']?? '');
$correo   = s($_POST['email']   ?? '');
$telefono = s($_POST['tel']     ?? '');
$ciudad   = s($_POST['ciudad']  ?? '');
$mensaje  = s($_POST['mensaje'] ?? ''); // si tienes textarea "mensaje"

// Validaciones mínimas (ajusta a tu gusto)
$errores = [];

if (mb_strlen($nombre) < 2 || !preg_match('/^[\p{L}\s\'-]{2,50}$/u', $nombre)) {
    $errores[] = 'Nombre inválido.';
}
if (mb_strlen($apellido) < 2 || !preg_match('/^[\p{L}\s\'-]{2,50}$/u', $apellido)) {
    $errores[] = 'Apellido inválido.';
}
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    $errores[] = 'Correo inválido.';
}
if (!preg_match('/^(?:09\d{8}|\+5939\d{8})$/', $telefono)) { // Ecuador
    $errores[] = 'Teléfono inválido (use 09######## o +5939########).';
}
if (mb_strlen($ciudad) < 2 || !preg_match('/^[\p{L}\s\'-]{2,50}$/u', $ciudad)) {
    $errores[] = 'Ciudad inválida.';
}

if ($errores) {
    // Puedes renderizar una vista con Bootstrap mostrando errores
    http_response_code(422);
    echo '<h3>Errores en el formulario:</h3><ul>';
    foreach ($errores as $e) {
        echo '<li>' . $e . '</li>';
    }
    echo '</ul><a href="index.php">Volver</a>';
    exit;
}

// ─────────────────────────────────────────────────────────────
// 3) Construir el cuerpo del mensaje (HTML + texto plano)
// ─────────────────────────────────────────────────────────────
$asunto = 'Gracias por Contactarme ' . $nombre;
$cuerpoHtml = "
  <h2>Hola!!</h2>
  <p>{$nombre} {$apellido}</p>
  <p> Te queremos dar las gracias por tu confianza</p>
  <p><strong>Teléfono:</strong> {$telefono}</p>
  <p><strong>Ciudad:</strong> {$ciudad}</p>
  " . ($mensaje ? "<p><strong>Mensaje:</strong><br>" . nl2br($mensaje) . "</p>" : '');

$cuerpoTxt = "Nuevo mensaje de contacto\n"
    . "Nombre: {$nombre} {$apellido}\n"
    . "Email: {$correo}\n"
    . "Teléfono: {$telefono}\n"
    . "Ciudad: {$ciudad}\n"
    . ($mensaje ? "Mensaje:\n{$mensaje}\n" : "");

// ─────────────────────────────────────────────────────────────
// 4) Configurar PHPMailer (Gmail SMTP con App Password)
// ─────────────────────────────────────────────────────────────
// REEMPLAZA estas constantes por variables de entorno (.env) en producción
$SMTP_HOST   = 'smtp.gmail.com';
$SMTP_USER   = 'aldairarenabreakout@gmail.com';// ← CAMBIAR
$SMTP_PASS   = 'wsed tmpb hpug kdbi';        // ← CAMBIAR (no tu clave normal)
$SMTP_PORT   = 587;
$TU_NOMBRE   = 'Aldair Manjarrez';
$TU_CORREO   = $correo;  // destinatario principal (tú)

$mail = new PHPMailer(true);

try {
    // Servidor
    $mail->isSMTP();
    $mail->Host       = $SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = $SMTP_USER;
    $mail->Password   = $SMTP_PASS;        // App Password de Gmail (requiere 2FA)
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $SMTP_PORT;

    // Codificación
    $mail->CharSet = 'UTF-8';

    // Remitente y destinatarios
    $mail->setFrom($TU_CORREO, $TU_NOMBRE);      // Remitente: tú
    $mail->addAddress($TU_CORREO, $TU_NOMBRE);   // Te llega a ti
    $mail->addReplyTo($correo, $nombre . ' ' . $apellido); // al responder, responde al usuario

    // (Opcional) enviar copia al usuario
    // $mail->addCC($correo);

    // (Opcional) adjunto si tu formulario tiene <input type="file" name="documento">
    if (!empty($_FILES['documento']['name']) && is_uploaded_file($_FILES['documento']['tmp_name'])) {
        $mail->addAttachment($_FILES['documento']['tmp_name'], $_FILES['documento']['name']);
    }

    // Contenido
    $mail->isHTML(true);
    $mail->Subject = $asunto;
    $mail->Body    = $cuerpoHtml;
    $mail->AltBody = $cuerpoTxt;

    $mail->send();

    // Respuesta amigable
    echo "<h2>¡Formulario enviado correctamente! ✅</h2>"
    . "<p>Gracias, {$nombre}. Te responderé a la brevedad.</p>"
    . "<p><a href='index.php'>Volver al formulario</a></p>";

} catch (Exception $e) {
    http_response_code(500);
    echo "El mensaje no pudo ser enviado. Error: " . $mail->ErrorInfo;
}
