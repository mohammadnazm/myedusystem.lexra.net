<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('admin');

$instituteId = current_institute_id();

const PER_PAGE = 40;

$roleFilter = $_GET['role'] ?? '';
$search = trim($_GET['q'] ?? '');
$dateFrom = trim($_GET['from'] ?? '');
$dateTo = trim($_GET['to'] ?? '');
// Only accept clean YYYY-MM-DD input — anything else is ignored rather than
// risking a malformed date silently filtering out everything.
$validDate = fn(string $d) => $d !== '' && DateTime::createFromFormat('Y-m-d', $d) !== false;
if (!$validDate($dateFrom)) $dateFrom = '';
if (!$validDate($dateTo)) $dateTo = '';
$page = max(1, (int)($_GET['page'] ?? 1));

$where = ['institute_id = ?'];
$types = 'i';
$params = [$instituteId];

if (in_array($roleFilter, ['admin', 'teacher', 'accountant'], true)) {
    $where[] = 'actor_role = ?';
    $types .= 's';
    $params[] = $roleFilter;
}
if ($search !== '') {
    $where[] = '(action LIKE ? OR actor_name LIKE ?)';
    $types .= 'ss';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($dateFrom !== '') {
    $where[] = 'created_at >= ?';
    $types .= 's';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'created_at < ?';
    $types .= 's';
    $params[] = (new DateTime($dateTo))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
}
$whereSql = implode(' AND ', $where);

$totalStmt = $conn->prepare("SELECT COUNT(*) c FROM activity_log WHERE $whereSql");
$totalStmt->bind_param($types, ...$params);
$totalStmt->execute();
$total = (int)$totalStmt->get_result()->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($total / PER_PAGE));
$page = min($page, $totalPages);
$offset = ($page - 1) * PER_PAGE;

$listStmt = $conn->prepare("SELECT * FROM activity_log WHERE $whereSql ORDER BY created_at DESC LIMIT ? OFFSET ?");
$listTypes = $types . 'ii';
$listParams = array_merge($params, [PER_PAGE, $offset]);
$listStmt->bind_param($listTypes, ...$listParams);
$listStmt->execute();
$rows = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'Activity Log';
$role = 'admin';
$activePage = 'activity';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';

/** Preserves the current filters while overriding one query param, for pagination/sort links. */
function activity_url(array $overrides): string
{
    $params = array_merge([
        'role' => $_GET['role'] ?? '',
        'q' => $_GET['q'] ?? '',
        'from' => $_GET['from'] ?? '',
        'to' => $_GET['to'] ?? '',
        'page' => $_GET['page'] ?? 1,
    ], $overrides);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return base_url('admin/activity.php') . (empty($params) ? '' : '?' . http_build_query($params));
}

$today = date('Y-m-d');
$hasFilters = $roleFilter !== '' || $search !== '' || $dateFrom !== '' || $dateTo !== '';
// Quick date-range presets, so "today", "this week" etc. are one click —
// each just points at a from/to pair, reusing the same GET-driven filtering.
$presets = [
    'Today' => [$today, $today],
    'Yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    'Last 7 days' => [date('Y-m-d', strtotime('-6 days')), $today],
    'This month' => [date('Y-m-01'), $today],
];
?>
<div class="panel content-fill">
  <div class="panel-title">Activity log</div>
  <p class="text-muted small mb-3">
    A full history of admin and teacher actions in this institute — who did it, and exactly what changed.
  </p>

  <form method="get" class="activity-filter-bar mb-3">
    <div class="row g-2 align-items-end">
      <div class="col-sm-6 col-md-3">
        <label class="form-label small mb-1">Role</label>
        <select name="role" class="form-select form-select-sm">
          <option value="">All roles</option>
          <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
          <option value="teacher" <?= $roleFilter === 'teacher' ? 'selected' : '' ?>>Teacher</option>
          <option value="accountant" <?= $roleFilter === 'accountant' ? 'selected' : '' ?>>Accountant</option>
        </select>
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label small mb-1">From date</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= h($dateFrom) ?>" max="<?= h($today) ?>">
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label small mb-1">To date</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= h($dateTo) ?>" max="<?= h($today) ?>">
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label small mb-1">Person or action</label>
        <input type="text" name="q" class="form-control form-control-sm" value="<?= h($search) ?>" placeholder="e.g. a name, &quot;deleted&quot;, &quot;gender&quot;...">
      </div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
      <span class="text-muted small me-1">Quick range:</span>
      <?php foreach ($presets as $label => [$pFrom, $pTo]): ?>
        <a href="<?= activity_url(['from' => $pFrom, 'to' => $pTo, 'page' => 1]) ?>"
           class="btn btn-sm <?= $dateFrom === $pFrom && $dateTo === $pTo ? 'btn-brand' : 'btn-outline-secondary' ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
      <div class="ms-auto d-flex gap-2">
        <button type="submit" class="btn btn-sm btn-brand"><i class="bi bi-search me-1"></i>Filter</button>
        <?php if ($hasFilters): ?>
          <a href="<?= base_url('admin/activity.php') ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <?php if ($hasFilters): ?>
    <div class="text-muted small mb-3">
      Showing
      <?php if ($dateFrom !== '' && $dateTo !== ''): ?>
        <?= $dateFrom === $dateTo ? h($dateFrom) : (h($dateFrom) . ' to ' . h($dateTo)) ?>
      <?php elseif ($dateFrom !== ''): ?>
        from <?= h($dateFrom) ?> onward
      <?php elseif ($dateTo !== ''): ?>
        up to <?= h($dateTo) ?>
      <?php else: ?>
        all dates
      <?php endif; ?>
      <?= $roleFilter !== '' ? ' · role: ' . h(ucfirst($roleFilter)) : '' ?>
      <?= $search !== '' ? ' · matching "' . h($search) . '"' : '' ?>
      — <?= $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?>
    </div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="empty-state"><i class="bi bi-clock-history"></i>No activity found<?= $hasFilters ? ' for that filter.' : ' yet.' ?></div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="table">
        <thead><tr><th style="width:160px;">When</th><th style="width:110px;">Role</th><th style="width:160px;">Who</th><th>What changed</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="text-nowrap text-muted small"><?= h($r['created_at']) ?></td>
              <td><span class="badge-role"><?= h(ucfirst($r['actor_role'])) ?></span></td>
              <td class="fw-semibold"><?= h($r['actor_name']) ?></td>
              <td><?= h($r['action']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="d-flex justify-content-between align-items-center mt-3">
        <span class="text-muted small">Page <?= $page ?> of <?= $totalPages ?> (<?= $total ?> total)</span>
        <div class="btn-group">
          <a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= activity_url(['page' => max(1, $page - 1)]) ?>"><i class="bi bi-chevron-left"></i> Newer</a>
          <a class="btn btn-sm btn-outline-secondary <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= activity_url(['page' => min($totalPages, $page + 1)]) ?>">Older <i class="bi bi-chevron-right"></i></a>
        </div>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
