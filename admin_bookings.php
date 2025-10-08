<?php
// admin_bookings.php – einfache Tabelle aller Buchungen (mit Login-Schutz)
declare(strict_types=1);
require __DIR__.'/db.php';
require __DIR__ . '/admin_guard.php';
admin_require_password();

// HTML-Header mit UTF-8 (muss NACH dem include kommen, um den JSON-Header zu überschreiben)
header('Content-Type: text/html; charset=utf-8');

$pdo = pdo();
$stmt = $pdo->query("
  SELECT b.id, b.box_id, b.customer_name, b.customer_email,
         b.start_date, b.end_date, b.status, b.total_amount, b.created_at,
         bx.name AS box_name
  FROM bookings b
  LEFT JOIN boxes bx ON bx.id = b.box_id
  ORDER BY b.created_at DESC
  LIMIT 1000
");
$rows = $stmt->fetchAll();
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>MMB – Buchungen</title>
<style>
  body{font-family:system-ui,Arial,sans-serif;max-width:1100px;margin:24px auto;padding:0 12px}
  h1{margin:8px 0}
  table{width:100%;border-collapse:collapse;margin-top:10px}
  th,td{border:1px solid #eee;padding:8px;text-align:left;font-size:14px}
  th{background:#fafafa}
  .muted{color:#666}
</style>
</head>
<body>
<h1>Buchungen</h1>
<p class="muted">Max. 1000 Einträge. (Eingeloggt als Admin-Session)</p>
<table>
  <thead>
    <tr>
      <th>ID</th><th>Box</th><th>Kunde</th><th>Email</th>
      <th>Start</th><th>Ende</th><th>Status</th><th>Gesamt (€)</th><th>Erstellt</th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="9" class="muted">Keine Einträge.</td></tr>
  <?php else: foreach ($rows as $r): ?>
    <tr>
      <td><?=htmlspecialchars((string)$r['id'])?></td>
      <td><?=htmlspecialchars(($r['box_name']?:('Box #'.$r['box_id'])))?></td>
      <td><?=htmlspecialchars((string)$r['customer_name'])?></td>
      <td><?=htmlspecialchars((string)$r['customer_email'])?></td>
      <td><?=htmlspecialchars((string)$r['start_date'])?></td>
      <td><?=htmlspecialchars((string)$r['end_date'])?></td>
      <td><?=htmlspecialchars((string)$r['status'])?></td>
      <td><?=htmlspecialchars((string)$r['total_amount'])?></td>
      <td><?=htmlspecialchars((string)$r['created_at'])?></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</body>
</html>
