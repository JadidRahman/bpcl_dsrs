<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

$me = require_role('ADMIN');
$pdo = db();

$st = $pdo->query("SELECT rt.id, rt.gender, ab.label AS age_label, iv.version_name
                   FROM rating_tables rt
                   JOIN age_brackets ab ON ab.id=rt.age_bracket_id
                   JOIN instrument_versions iv ON iv.id=rt.instrument_version_id
                   ORDER BY rt.gender, ab.sort_order, rt.id");
$tables = $st->fetchAll();

include __DIR__ . '/../partials/header.php';
?>
<div class="card card-soft">
  <div class="card-body">
    <h4 class="mb-1">Scoring Tables (Seed Check)</h4>
    <div class="small-muted mb-3">This page confirms that rating tables were imported correctly from your Excel sheets.</div>

    <div class="table-responsive">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>ID</th>
            <th>Version</th>
            <th>Gender</th>
            <th>Age Bracket</th>
            <th class="text-end">Rows</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tables as $t): 
            $st2 = $pdo->prepare("SELECT COUNT(*) c FROM rating_table_rows WHERE rating_table_id=?");
            $st2->execute([(int)$t['id']]);
            $c = (int)$st2->fetch()['c'];
          ?>
            <tr>
              <td><?= (int)$t['id'] ?></td>
              <td><?= e($t['version_name']) ?></td>
              <td><?= e($t['gender']) ?></td>
              <td><?= e($t['age_label']) ?></td>
              <td class="text-end"><?= $c ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="small-muted">
      Next improvement (optional): add CSV import UI to update rating tables without SQL.
    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
