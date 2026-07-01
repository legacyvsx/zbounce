<?php
/* ============================================================
   Slipstream tunnel pong — WebRTC signaling relay (LAMP)

   This is a tiny store-and-forward relay. It only brokers the
   connection handshake (SDP + ICE) between two browsers; once
   they're connected, gameplay flows peer-to-peer and never hits
   this script again.

   ----------------------------------------------------------------
   >>> EDIT YOUR MYSQL CONNECTION INFO HERE <<<
   ---------------------------------------------------------------- */
$DB_HOST = '127.0.0.1';      // usually 127.0.0.1 (or 'localhost')
$DB_NAME = 'tunnelpong';     // the database you created
$DB_USER = 'tunnelpong';     // the MySQL user
$DB_PASS = 'CHANGE_ME';      // that user's password
/* ============================================================ */

header('Content-Type: application/json');

function out($a){ echo json_encode($a); exit; }
function body_json(){ $d = json_decode(file_get_contents('php://input'), true); return is_array($d) ? $d : []; }

try {
  $pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER, $DB_PASS,
    [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
  );
} catch (Throwable $e) {
  http_response_code(500);
  out(['ok' => false, 'error' => 'db_connect']);
}

$now    = time();
$action = $_GET['action'] ?? '';
$code   = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['code'] ?? ''));

// Lazy housekeeping: drop anything older than an hour so the tables stay tiny.
$pdo->prepare('DELETE FROM rooms   WHERE created_at < ?')->execute([$now - 3600]);
$pdo->prepare('DELETE FROM signals WHERE created_at < ?')->execute([$now - 3600]);

switch ($action) {

  // Host creates a room and gets a fresh invite code.
  case 'create': {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no easily-confused chars
    for ($try = 0; $try < 12; $try++) {
      $c = '';
      for ($i = 0; $i < 6; $i++) $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
      $stmt = $pdo->prepare("INSERT IGNORE INTO rooms (code, status, created_at) VALUES (?, 'waiting', ?)");
      $stmt->execute([$c, $now]);
      if ($stmt->rowCount() > 0) out(['ok' => true, 'code' => $c]);
    }
    out(['ok' => false, 'error' => 'code_gen']);
  }

  // Guest joins an existing room by code.
  case 'join': {
    if ($code === '') out(['ok' => false, 'error' => 'bad_input']);
    $r = $pdo->prepare('SELECT status FROM rooms WHERE code = ?');
    $r->execute([$code]);
    $row = $r->fetch();
    if (!$row)                       out(['ok' => false, 'error' => 'no_room']);
    if ($row['status'] === 'full')   out(['ok' => false, 'error' => 'room_full']);
    $pdo->prepare("UPDATE rooms SET status = 'full' WHERE code = ?")->execute([$code]);
    out(['ok' => true]);
  }

  // Either peer posts a signaling message (offer / answer / ice).
  case 'signal': {
    $d       = body_json();
    $sender  = $d['sender']  ?? '';
    $type    = $d['type']    ?? '';
    $payload = $d['payload'] ?? null;
    if ($code === '' || !in_array($sender, ['host','guest'], true) || $type === '' || $payload === null)
      out(['ok' => false, 'error' => 'bad_input']);
    $pdo->prepare('INSERT INTO signals (code, sender, type, payload, created_at) VALUES (?,?,?,?,?)')
        ->execute([$code, $sender, $type, json_encode($payload), $now]);
    out(['ok' => true]);
  }

  // Either peer polls for the OTHER peer's messages since the last id it saw.
  case 'poll': {
    $me    = $_GET['me'] ?? '';
    $since = (int)($_GET['since'] ?? 0);
    if ($code === '' || !in_array($me, ['host','guest'], true))
      out(['ok' => false, 'error' => 'bad_input']);
    $other = $me === 'host' ? 'guest' : 'host';
    $stmt = $pdo->prepare('SELECT id, type, payload FROM signals WHERE code = ? AND sender = ? AND id > ? ORDER BY id ASC');
    $stmt->execute([$code, $other, $since]);
    $msgs = [];
    foreach ($stmt as $row) {
      $msgs[] = ['id' => (int)$row['id'], 'type' => $row['type'], 'payload' => json_decode($row['payload'], true)];
    }
    $rs = $pdo->prepare('SELECT status FROM rooms WHERE code = ?');
    $rs->execute([$code]);
    $room = $rs->fetch();
    out(['ok' => true, 'messages' => $msgs, 'status' => $room['status'] ?? 'gone']);
  }

  default:
    out(['ok' => false, 'error' => 'unknown_action']);
}
