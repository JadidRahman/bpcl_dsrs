<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

$me = require_role('CONSULTANT','ADMIN');
$pdo = db();

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
  echo json_encode(['ok'=>false,'error'=>'Invalid payload.']);
  exit;
}

$aid = (int)($data['id'] ?? 0);
if ($aid <= 0) {
  echo json_encode(['ok'=>false,'error'=>'Invalid assessment id.']);
  exit;
}

if (!isset($data['csrf']) || !csrf_verify((string)$data['csrf'])) {
  echo json_encode(['ok'=>false,'error'=>'CSRF failed. Refresh and try again.']);
  exit;
}

$impression      = trim((string)($data['impression'] ?? ''));
$recommendations = trim((string)($data['recommendations'] ?? ''));

/* fetch current notes */
$st = $pdo->prepare("SELECT notes FROM assessments WHERE id=? LIMIT 1");
$st->execute([$aid]);
$row = $st->fetch();
if (!$row) {
  echo json_encode(['ok'=>false,'error'=>'Assessment not found.']);
  exit;
}

$notesJson = [];
$rawNotes  = (string)($row['notes'] ?? '');
if ($rawNotes !== '') {
  $decoded = json_decode($rawNotes, true);
  if (is_array($decoded)) $notesJson = $decoded;
}

$notesJson['impression']      = $impression;
$notesJson['recommendations'] = $recommendations;

$pdo->prepare("UPDATE assessments SET notes=? WHERE id=?")->execute([
  json_encode($notesJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  $aid
]);

echo json_encode(['ok'=>true]);