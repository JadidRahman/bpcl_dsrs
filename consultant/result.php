<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/lib_scoring.php';

$me  = require_role('CONSULTANT','ADMIN');
$pdo = db();

$aid = (int)($_GET['id'] ?? 0);
if ($aid <= 0) { redirect('index.php'); }

// ensure answers exist
$st = $pdo->prepare("SELECT COUNT(*) FROM assessment_answers WHERE assessment_id=?");
$st->execute([$aid]);
$cnt = (int)$st->fetchColumn();

if ($cnt < 27) {
  flash('warning', 'Please complete all 27 OMR answers first.');
  redirect("consultant/omr.php?id=".$aid);
}

try {
  generate_assessment_scores($pdo, $aid);
  flash('success', 'Result generated successfully.');
} catch (Throwable $e) {
  flash('danger', 'Result generation failed: ' . $e->getMessage());
  redirect("consultant/omr.php?id=".$aid);
}

redirect("consultant/report.php?id=".$aid);