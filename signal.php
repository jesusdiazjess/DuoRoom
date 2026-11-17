<?php
declare(strict_types=1);

/**
 * DuoRoom same-origin JSON API.
 * All requests are POST. PHP/MySQL carry ONLY room metadata and WebRTC signals;
 * the microphone/camera media itself never passes through this PHP endpoint.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

ini_set('session.use_strict_mode', '1');
session_name('duoroom_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function result(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function reject(string $message, int $code = 400): never
{
    result(['ok' => false, 'error' => $message], $code);
}

function field(array $input, string $name, int $maxLength = 200): string
{
    $value = $input[$name] ?? null;
    if (!is_string($value) || $value === '' || strlen($value) > $maxLength) {
        reject("Invalid or missing {$name}.");
    }
    return $value;
}

function issueToken(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function roomCode(): string
{
    // 12 characters drawn from a 32-character alphabet (~60 bits of entropy).
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 12; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return substr($out, 0, 4) . '-' . substr($out, 4, 4) . '-' . substr($out, 8);
}

function authorizeRoom(PDO $pdo, array $input): array
{
    $roomId = filter_var($input['room_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $role = field($input, 'role', 5);
    $token = field($input, 'token', 100);
    if (!$roomId || !in_array($role, ['host', 'guest'], true)) {
        reject('Invalid room access.', 403);
    }
    $statement = $pdo->prepare('SELECT * FROM dc_rooms WHERE id = ? LIMIT 1');
    $statement->execute([$roomId]);
    $room = $statement->fetch();
    if (!$room) {
        reject('Call not found.', 404);
    }
    $known = $role === 'host' ? $room['host_key_hash'] : $room['guest_key_hash'];
    if (!is_string($known) || !hash_equals($known, hash('sha256', $token))) {
        reject('Access denied. Your call key is invalid.', 403);
    }
    return [$room, $role];
}

function isFinished(array $room): bool
{
    return $room['status'] === 'ended' || strtotime($room['expires_at'] . ' UTC') <= time();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reject('POST requests only.', 405);
}

// JSON-only requests cannot be sent as ordinary cross-site HTML forms.
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
    reject('JSON content type required.', 415);
}

if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 120000) {
    reject('Request is too large.', 413);
}

$body = file_get_contents('php://input', false, null, 0, 120001);
$input = json_decode($body ?: '', true);
if (!is_array($input) || json_last_error() !== JSON_ERROR_NONE) {
    reject('Invalid JSON body.');
}
$action = field($input, 'action', 30);

try {
    require_once __DIR__ . '/db.php';

    if ($action === 'whoami') {
        $authenticated = isset($_SESSION['admin_id']);
        session_write_close();
        result(['ok' => true, 'authenticated' => $authenticated]);
    }

    if ($action === 'login') {
        $now = time();
        $window = (int) ($_SESSION['login_window'] ?? 0);
        if ($now - $window > 600) {
            $_SESSION['login_window'] = $now;
            $_SESSION['login_failures'] = 0;
        }
        if (($_SESSION['login_failures'] ?? 0) >= 6) {
            reject('Too many attempts. Try again in 10 minutes.', 429);
        }
        $username = field($input, 'username', 50);
        $password = field($input, 'password', 200);
        $stmt = db()->prepare('SELECT id, password_hash FROM dc_admins WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            $_SESSION['login_failures'] = (int) ($_SESSION['login_failures'] ?? 0) + 1;
            reject('Incorrect username or password.', 401);
        }
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['login_failures'] = 0;
        session_write_close();
        result(['ok' => true]);
    }

    if ($action === 'logout') {
        unset($_SESSION['admin_id']);
        session_write_close();
        result(['ok' => true]);
    }

    if ($action === 'change_password') {
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        session_write_close();
        if ($adminId === 0) {
            reject('Admin login required.', 401);
        }
        $old = field($input, 'current_password', 200);
        $new = field($input, 'new_password', 200);
        if (strlen($new) < 12) {
            reject('The new password needs at least 12 characters.');
        }
        $pdo = db();
        $stmt = $pdo->prepare('SELECT password_hash FROM dc_admins WHERE id = ? LIMIT 1');
        $stmt->execute([$adminId]);
        $oldHash = $stmt->fetchColumn();
        if (!$oldHash || !password_verify($old, (string) $oldHash)) {
            reject('Current password is incorrect.', 401);
        }
        $stmt = $pdo->prepare('UPDATE dc_admins SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $adminId]);
        result(['ok' => true]);
    }

    if ($action === 'create_room') {
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        session_write_close();
        if ($adminId === 0) {
            reject('Only the administrator can create a call.', 401);
        }
        $mode = field($input, 'mode', 5);
        if (!in_array($mode, ['audio', 'video'], true)) {
            reject('Choose audio or video.');
        }
        $pdo = db();
        // Keep the database small without needing cron (unavailable on free hosting).
        $pdo->exec("DELETE FROM dc_rooms WHERE (status = 'ended' AND ended_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)) OR expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)");
        // Only one current admin-created call at a time for this single-admin demo.
        $pdo->exec("UPDATE dc_rooms SET status='ended', ended_at=UTC_TIMESTAMP() WHERE status<>'ended' AND expires_at>UTC_TIMESTAMP()");
        $settings = require __DIR__ . '/config.php';
        $hours = max(1, min(12, (int) ($settings['room_lifetime_hours'] ?? 3)));
        $token = issueToken();
        $code = roomCode();
        $stmt = $pdo->prepare('INSERT INTO dc_rooms (code, call_type, host_key_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $hours . ' HOUR))');
        $stmt->execute([$code, $mode, hash('sha256', $token)]);
        result([
            'ok' => true,
            'room_id' => (int) $pdo->lastInsertId(),
            'role' => 'host',
            'token' => $token,
            'code' => $code,
            'mode' => $mode,
        ]);
    }

    if ($action === 'join_room') {
        session_write_close();
        $code = strtoupper(trim(field($input, 'code', 40)));
        $code = preg_replace('/[^A-Z2-9]/', '', $code);
        if (!is_string($code) || strlen($code) !== 12) {
            reject('Enter a valid 12-character invitation code.');
        }
        $code = substr($code, 0, 4) . '-' . substr($code, 4, 4) . '-' . substr($code, 8);
        $name = trim(field($input, 'name', 120));
        if ($name === '' || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            reject('Enter a valid display name.');
        }
        if (function_exists('mb_strlen') && mb_strlen($name) > 50) {
            reject('Display name is too long.');
        }
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM dc_rooms WHERE code = ? AND status = 'waiting' AND expires_at > UTC_TIMESTAMP() LIMIT 1");
        $stmt->execute([$code]);
        $room = $stmt->fetch();
        if (!$room) {
            reject('Invitation not found, expired, or already used.', 404);
        }
        $token = issueToken();
        // An atomic update guarantees only ONE guest can use the invitation.
        $stmt = $pdo->prepare("UPDATE dc_rooms SET guest_name=?, guest_key_hash=?, status='joined' WHERE id=? AND guest_key_hash IS NULL AND status='waiting' AND expires_at>UTC_TIMESTAMP()");
        $stmt->execute([$name, hash('sha256', $token), $room['id']]);
        if ($stmt->rowCount() !== 1) {
            reject('This invitation has already been used.', 409);
        }
        result([
            'ok' => true,
            'room_id' => (int) $room['id'],
            'role' => 'guest',
            'token' => $token,
            'code' => $code,
            'name' => $name,
            'mode' => $room['call_type'],
        ]);
    }

    if (in_array($action, ['poll', 'send_signal', 'end_room'], true)) {
        session_write_close();
        $pdo = db();
        [$room, $role] = authorizeRoom($pdo, $input);
        $roomId = (int) $room['id'];

        if ($action === 'poll') {
            $cursor = filter_var($input['cursor'] ?? 0, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);
            if ($cursor === false) {
                reject('Invalid message cursor.');
            }
            $stmt = $pdo->prepare('SELECT id, kind, payload FROM dc_signals WHERE room_id = ? AND sender_role <> ? AND id > ? ORDER BY id ASC LIMIT 80');
            $stmt->execute([$roomId, $role, $cursor]);
            $signals = [];
            $next = (int) $cursor;
            while ($record = $stmt->fetch()) {
                $next = (int) $record['id'];
                $signals[] = [
                    'id' => $next,
                    'kind' => $record['kind'],
                    'payload' => json_decode($record['payload'], true),
                ];
            }
            result([
                'ok' => true,
                'status' => isFinished($room) ? 'ended' : $room['status'],
                'guest_name' => $role === 'host' ? $room['guest_name'] : null,
                'signals' => $signals,
                'cursor' => $next,
            ]);
        }

        if ($action === 'end_room') {
            $stmt = $pdo->prepare("UPDATE dc_rooms SET status = 'ended', ended_at = UTC_TIMESTAMP() WHERE id = ? AND status <> 'ended'");
            $stmt->execute([$roomId]);
            result(['ok' => true]);
        }

        if (isFinished($room)) {
            reject('The call has ended or expired.', 410);
        }
        $kind = field($input, 'kind', 10);
        if (!in_array($kind, ['ready', 'offer', 'answer', 'ice'], true)) {
            reject('Unknown signal type.');
        }
        if (($kind === 'ready' || $kind === 'answer') && $role !== 'guest') {
            reject('Only a guest may send this signal.', 403);
        }
        if ($kind === 'offer' && $role !== 'host') {
            reject('Only the admin may send an offer.', 403);
        }
        if (!in_array($room['status'], ['joined', 'active'], true)) {
            reject('Waiting for another participant.', 409);
        }
        $payload = $input['payload'] ?? null;
        if (!is_array($payload)) {
            reject('Invalid signal payload.');
        }
        if ($kind === 'offer' || $kind === 'answer') {
            if (($payload['type'] ?? '') !== $kind || !is_string($payload['sdp'] ?? null)) {
                reject('Invalid session description.');
            }
        }
        if ($kind === 'ice' && !is_string($payload['candidate'] ?? null)) {
            reject('Invalid ICE candidate.');
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json) || strlen($json) > 64000) {
            reject('Signal is too large.');
        }
        if ($kind === 'ready') {
            $pdo->prepare("UPDATE dc_rooms SET status = 'active' WHERE id = ? AND status = 'joined'")->execute([$roomId]);
        }
        $stmt = $pdo->prepare('INSERT INTO dc_signals (room_id, sender_role, kind, payload) VALUES (?, ?, ?, ?)');
        $stmt->execute([$roomId, $role, $kind, $json]);
        result(['ok' => true, 'signal_id' => (int) $pdo->lastInsertId()]);
    }

    reject('Unsupported action.', 404);
} catch (PDOException $e) {
    error_log('DuoRoom database error: ' . $e->getMessage());
    reject('Database unavailable. Check config.php and your imported schema.', 503);
} catch (Throwable $e) {
    error_log('DuoRoom error: ' . $e->getMessage());
    reject('Server error. Review the PHP error log.', 500);
}
