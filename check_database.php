<?php
/**
 * One-time database health check and repair.
 *
 * Use this when an existing database has problems (for example students that all have
 * id 0, so "student not found", or missing tables) and you do NOT want to wipe it by
 * re-importing database/institute_system.sql.
 *
 * It is switched off until you add a secret to config/db.local.php:
 *
 *     define('CHECK_DB_TOKEN', 'type-a-long-random-secret-here');
 *
 * then open  https://your-site/check_database.php?token=type-a-long-random-secret-here
 *
 * DELETE THIS FILE (and the token line) when you are done.
 */
require __DIR__ . '/config/db.php';

header('X-Robots-Tag: noindex');
$configured = defined('CHECK_DB_TOKEN') && is_string(CHECK_DB_TOKEN) && strlen(CHECK_DB_TOKEN) >= 12;
$given = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if (!$configured || !hash_equals(CHECK_DB_TOKEN, $given)) {
    http_response_code(403);
    echo '<!DOCTYPE html><meta charset="utf-8"><body style="font-family:sans-serif;max-width:640px;margin:60px auto;line-height:1.6">'
        . '<h2>Database check is switched off</h2>'
        . '<p>Add this line to <code>config/db.local.php</code> (use your own long random secret, at least 12 characters):</p>'
        . '<pre style="background:#f3f4f6;padding:12px;border-radius:8px">define(\'CHECK_DB_TOKEN\', \'type-a-long-random-secret-here\');</pre>'
        . '<p>Then open <code>check_database.php?token=your-secret</code>. Delete this file when you are finished.</p></body>';
    exit;
}

/** Tables the app expects (checked and repaired by name only — never user input). */
const EXPECTED_TABLES = [
    'accountants', 'activity_log', 'admins', 'attendance', 'class_sections', 'daily_reports',
    'departments', 'department_prices', 'institutes', 'login_attempts', 'marks', 'resources',
    'students', 'student_discounts', 'subjects', 'super_admins', 'teachers', 'teacher_assignments',
    'student_payments',
];
const EXPECTED_FOREIGN_KEYS = 29;
/** Tables that point at students.id — used only to report rows left orphaned by an old id 0. */
const STUDENT_CHILD_TABLES = ['attendance', 'marks', 'student_discounts'];

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function table_exists(mysqli $c, string $t): bool
{
    $r = $c->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . $c->real_escape_string($t) . "'");
    return $r->num_rows > 0;
}

/** Facts about a table's id column, or null when the table has no id column. */
function id_info(mysqli $c, string $t): ?array
{
    $r = $c->query(
        "SELECT column_type, extra, column_key FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = '" . $c->real_escape_string($t) . "' AND column_name = 'id'"
    );
    $col = $r->fetch_assoc();
    if (!$col) {
        return null;
    }
    $pk = $c->query(
        "SELECT 1 FROM information_schema.table_constraints
         WHERE table_schema = DATABASE() AND table_name = '" . $c->real_escape_string($t) . "' AND constraint_type = 'PRIMARY KEY'"
    )->num_rows > 0;
    $zero = (int)$c->query("SELECT COUNT(*) FROM `$t` WHERE id = 0")->fetch_row()[0];
    $dups = (int)$c->query("SELECT COUNT(*) FROM (SELECT id FROM `$t` WHERE id <> 0 GROUP BY id HAVING COUNT(*) > 1) x")->fetch_row()[0];
    return [
        'type' => $col['column_type'], 'auto' => stripos($col['extra'], 'auto_increment') !== false,
        'pk' => $pk, 'zero' => $zero, 'dups' => $dups,
        'rows' => (int)$c->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0],
    ];
}

function create_payments_table(mysqli $c): void
{
    $c->query(
        "CREATE TABLE IF NOT EXISTS `student_payments` (
          `id` int unsigned NOT NULL AUTO_INCREMENT, `institute_id` int NOT NULL, `receipt_no` int unsigned NOT NULL,
          `student_id` int NOT NULL, `student_name` varchar(160) NOT NULL, `grade` varchar(20) NOT NULL,
          `amount` decimal(12,2) NOT NULL, `paid_at` date NOT NULL, `method` varchar(20) NOT NULL DEFAULT 'cash',
          `reference` varchar(100) DEFAULT NULL, `note` varchar(255) DEFAULT NULL,
          `recorded_by_role` varchar(20) NOT NULL, `recorded_by_id` int NOT NULL, `recorded_by_name` varchar(100) NOT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP, `is_void` tinyint(1) NOT NULL DEFAULT '0',
          `void_reason` varchar(255) DEFAULT NULL, `voided_at` timestamp NULL DEFAULT NULL, `voided_by_name` varchar(100) DEFAULT NULL,
          PRIMARY KEY (`id`), UNIQUE KEY `uq_receipt` (`institute_id`, `receipt_no`),
          KEY `idx_student_grade` (`student_id`, `grade`), KEY `idx_institute_date` (`institute_id`, `paid_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** Gives rows with id 0 fresh unique ids, then makes id a proper AUTO_INCREMENT primary key. */
function repair_id_column(mysqli $c, string $t, array $info): string
{
    $notes = [];
    if ($c->query("SHOW COLUMNS FROM `$t` LIKE '__rn'")->num_rows > 0) {
        $c->query("ALTER TABLE `$t` DROP COLUMN `__rn`"); // left over from an interrupted earlier run
    }
    if ($info['zero'] > 0) {
        // Number the id-0 rows after the current highest id (rows are numbered in stored order).
        $c->query("ALTER TABLE `$t` ADD COLUMN `__rn` INT NOT NULL AUTO_INCREMENT, ADD UNIQUE KEY `__rn_key` (`__rn`)");
        try {
            $max = (int)$c->query("SELECT COALESCE(MAX(id), 0) FROM `$t`")->fetch_row()[0];
            $c->query("UPDATE `$t` SET id = $max + `__rn` WHERE id = 0");
        } finally {
            $c->query("ALTER TABLE `$t` DROP COLUMN `__rn`"); // the helper column never stays behind
        }
        $notes[] = $info['zero'] . ' row(s) with id 0 were given new ids';
    }
    if (!$info['pk']) {
        $c->query("ALTER TABLE `$t` ADD PRIMARY KEY (`id`)");
        $notes[] = 'primary key added';
    }
    if (!$info['auto']) {
        $c->query("ALTER TABLE `$t` MODIFY `id` " . $info['type'] . " NOT NULL AUTO_INCREMENT");
        $notes[] = 'AUTO_INCREMENT turned on';
    }
    return implode('; ', $notes);
}

$log = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'repair') {
    try {
        // Other tables may already point at students.id; let the table be rebuilt without tripping over them.
        $conn->query('SET FOREIGN_KEY_CHECKS = 0');
        if (!table_exists($conn, 'student_payments')) {
            create_payments_table($conn);
            $log[] = ['ok', 'student_payments', 'table created'];
        }
        foreach (EXPECTED_TABLES as $t) {
            if (!table_exists($conn, $t)) {
                continue;
            }
            $info = id_info($conn, $t);
            if (!$info || ($info['pk'] && $info['auto'] && !$info['zero'])) {
                continue;
            }
            if ($info['dups'] > 0) {
                $log[] = ['warn', $t, 'has duplicate ids — not changed automatically; re-import database/institute_system.sql on a fresh database'];
                continue;
            }
            $log[] = ['ok', $t, repair_id_column($conn, $t, $info)];
        }
    } catch (Throwable $e) {
        $log[] = ['bad', 'error', $e->getMessage()];
    }
    $conn->query('SET FOREIGN_KEY_CHECKS = 1');
}

/* ------------------------------ run the checks ------------------------------ */
$rows = [];
$problems = 0;
$missing = [];
foreach (EXPECTED_TABLES as $t) {
    if (!table_exists($conn, $t)) {
        $rows[] = [$t, 'bad', 'Table is missing'];
        $missing[] = $t;
        $problems++;
        continue;
    }
    $info = id_info($conn, $t);
    if (!$info) {
        $rows[] = [$t, 'ok', 'Present'];
        continue;
    }
    $bad = [];
    if (!$info['pk']) { $bad[] = 'no primary key'; }
    if (!$info['auto']) { $bad[] = 'id is not AUTO_INCREMENT'; }
    if ($info['zero'] > 0) { $bad[] = $info['zero'] . ' row(s) have id 0'; }
    if ($info['dups'] > 0) { $bad[] = 'duplicate ids'; }
    if ($bad) {
        $problems++;
        $rows[] = [$t, 'bad', implode(', ', $bad) . ' (' . $info['rows'] . ' rows)'];
    } else {
        $rows[] = [$t, 'ok', 'OK (' . $info['rows'] . ' rows)'];
    }
}

$fks = (int)$conn->query("SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE()")->fetch_row()[0];
$orphans = [];
if (table_exists($conn, 'students')) {
    foreach (STUDENT_CHILD_TABLES as $t) {
        if (table_exists($conn, $t) && $conn->query("SHOW COLUMNS FROM `$t` LIKE 'student_id'")->num_rows > 0) {
            $n = (int)$conn->query("SELECT COUNT(*) FROM `$t` WHERE student_id = 0")->fetch_row()[0];
            if ($n > 0) {
                $orphans[$t] = $n;
            }
        }
    }
}
$collBad = (int)$conn->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_collation IS NOT NULL
     AND table_collation NOT LIKE 'utf8mb4%'"
)->fetch_row()[0];
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Database check</title>
<style>
 body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f3f4f8;margin:0;padding:24px;color:#111827}
 .box{max-width:760px;margin:0 auto;background:#fff;border-radius:16px;padding:28px;box-shadow:0 8px 24px rgba(17,24,39,.08)}
 h1{margin:0 0 4px;font-size:1.4rem} .sub{color:#6b7280;margin-bottom:20px}
 table{width:100%;border-collapse:collapse} td{padding:8px 10px;border-top:1px solid #eef0f4}
 .ok{color:#15803d;font-weight:600}.bad{color:#b91c1c;font-weight:600}.warn{color:#b45309;font-weight:600}
 .banner{padding:12px 16px;border-radius:12px;margin:16px 0}.banner.good{background:#ecfdf3}.banner.fix{background:#fef2f2}
 button{background:#4f46e5;color:#fff;border:0;border-radius:10px;padding:10px 18px;font-size:1rem;cursor:pointer}
 code{background:#f3f4f6;padding:1px 6px;border-radius:6px}
</style></head><body><div class="box">
<h1>Database check</h1><div class="sub">Database: <code><?= h2(DB_NAME) ?></code> · <?= $problems === 0 ? 'no problems found' : $problems . ' problem(s) found' ?></div>

<?php foreach ($log as [$kind, $name, $msg]): ?>
  <div class="banner <?= $kind === 'ok' ? 'good' : 'fix' ?>"><span class="<?= h2($kind) ?>"><?= h2($name) ?></span>: <?= h2($msg) ?></div>
<?php endforeach; ?>

<table>
<?php foreach ($rows as [$name, $state, $msg]): ?>
  <tr><td><code><?= h2($name) ?></code></td><td class="<?= h2($state) ?>"><?= $state === 'ok' ? '✓ ' : '✗ ' ?><?= h2($msg) ?></td></tr>
<?php endforeach; ?>
  <tr><td>Foreign keys</td><td class="<?= $fks >= EXPECTED_FOREIGN_KEYS ? 'ok' : 'warn' ?>"><?= $fks ?> of <?= EXPECTED_FOREIGN_KEYS ?><?= $fks < EXPECTED_FOREIGN_KEYS ? ' — some links between tables are missing' : '' ?></td></tr>
<?php if ($collBad): ?><tr><td>Character set</td><td class="warn"><?= $collBad ?> table(s) are not utf8mb4 (Kurdish/Arabic text may not save correctly)</td></tr><?php endif; ?>
</table>

<?php foreach ($orphans as $t => $n): ?>
  <div class="banner fix"><span class="warn"><?= h2($t) ?></span>: <?= $n ?> row(s) still point to student id 0. They belonged to students whose ids were all 0, so they cannot be matched back automatically.</div>
<?php endforeach; ?>

<?php if ($problems > 0 || $fks < EXPECTED_FOREIGN_KEYS): ?>
  <div class="banner fix">
    <?php if ($missing && $missing !== ['student_payments']): ?>
      Some tables are missing, which means the original import did not finish. Easiest fix: create a new empty database and import <code>database/institute_system.sql</code> (this file only repairs ids and the payments table).
    <?php else: ?>
      Pressing Repair fixes the ids and creates the payments table. Make a backup export in phpMyAdmin first.
    <?php endif; ?>
  </div>
  <form method="post"><input type="hidden" name="token" value="<?= h2($given) ?>"><input type="hidden" name="action" value="repair">
    <button type="submit">Repair what can be repaired</button></form>
<?php else: ?>
  <div class="banner good">Everything looks healthy. Delete <code>check_database.php</code> and the <code>CHECK_DB_TOKEN</code> line from <code>config/db.local.php</code>.</div>
<?php endif; ?>
</div></body></html>
