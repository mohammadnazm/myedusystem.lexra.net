<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('admin');

$instituteId = current_institute_id();

$departments = all_departments($conn, $instituteId);
$sections = all_class_sections($conn, $instituteId);
$subjects = all_subjects_for_institute($conn, $instituteId);

$departmentId = isset($_GET['department']) && $_GET['department'] !== '' ? (int)$_GET['department'] : null;
$classSectionId = isset($_GET['section']) && $_GET['section'] !== '' ? (int)$_GET['section'] : null;
$subjectId = isset($_GET['subject']) && $_GET['subject'] !== '' ? (int)$_GET['subject'] : null;
$status = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null;
if ($departmentId && !department_belongs_to_institute($conn, $departmentId, $instituteId)) {
    $departmentId = null;
}
if ($classSectionId && !class_section_belongs_to_institute($conn, $classSectionId, $instituteId)) {
    $classSectionId = null;
}
if ($subjectId && !subject_belongs_to_institute($conn, $subjectId, $instituteId)) {
    $subjectId = null;
}
if ($status && !in_array($status, ATTENDANCE_STATUSES, true)) {
    $status = null;
}

$from = $_GET['from'] ?? $_POST['from'] ?? date('Y-m-d', strtotime('-14 days'));
$to = $_GET['to'] ?? $_POST['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-d', strtotime('-14 days')); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to = date('Y-m-d'); }
if ($from > $to) { [$from, $to] = [$to, $from]; }

$queryString = http_build_query(array_filter([
    'department' => $departmentId,
    'section' => $classSectionId,
    'subject' => $subjectId,
    'status' => $status,
    'from' => $from,
    'to' => $to,
], fn($v) => $v !== null && $v !== ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_all') {
    csrf_verify();
    $deleted = delete_attendance_records_matching($conn, $instituteId, $from, $to, $departmentId, $classSectionId, $subjectId, $status);
    log_activity($conn, $instituteId, 'admin', current_user_id(), current_user_name(), "Bulk-deleted $deleted attendance record(s) ($from to $to)");
    flash_set('success', $deleted . ' attendance record(s) deleted.');
    header('Location: ' . base_url('admin/attendance.php') . '?' . $queryString);
    exit;
}

// Detailed, record-by-record listing: exactly which subject + lesson a
// student was present/absent/late/excused for, on which day. Grouped so a
// student appears once per day even if they have entries in several
// subjects — each subject/lesson/status is listed together in that row.
$records = attendance_records_range($conn, $instituteId, $from, $to, $departmentId, $classSectionId, $subjectId, $status);
$groupedRecords = group_attendance_records_by_student_day($records);

// Per-student aggregate (unaffected by the status filter, since it always
// shows the full present/absent/late/excused breakdown per student).
$summaryRows = attendance_summary_range($conn, $instituteId, $from, $to, $departmentId, $classSectionId, $subjectId);

// Header stat cards, computed straight from the filtered record set.
$totalRecords = count($records);
$totalPresent = 0; $totalAbsent = 0; $totalLate = 0; $totalExcused = 0;
foreach ($records as $rec) {
    switch ($rec['status']) {
        case 'present': $totalPresent++; break;
        case 'absent':  $totalAbsent++; break;
        case 'late':    $totalLate++; break;
        case 'excused': $totalExcused++; break;
    }
}
$overallRate = $totalRecords > 0 ? round((($totalPresent + $totalLate) / $totalRecords) * 100, 1) : null;

$pageTitle = 'Attendance';
$role = 'admin';
$activePage = 'attendance';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="panel">
  <div class="panel-title">Search attendance records</div>
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-2">
      <label class="form-label">Department</label>
      <select name="department" class="form-select">
        <option value="">All departments</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= $departmentId === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Class section</label>
      <select name="section" class="form-select">
        <option value="">All class sections</option>
        <?php foreach ($sections as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $classSectionId === (int)$s['id'] ? 'selected' : '' ?>><?= h(class_section_label($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Subject</label>
      <select name="subject" class="form-select">
        <option value="">All subjects</option>
        <?php foreach ($subjects as $sub): ?>
          <option value="<?= (int)$sub['id'] ?>" <?= $subjectId === (int)$sub['id'] ? 'selected' : '' ?>><?= h($sub['department_name']) ?> — <?= h($sub['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Status</label>
      <select name="status" class="form-select">
        <option value="">All statuses</option>
        <?php foreach (ATTENDANCE_STATUSES as $statusOption): $meta = attendance_status_meta($statusOption); ?>
          <option value="<?= $statusOption ?>" <?= $status === $statusOption ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">From date</label>
      <input type="date" name="from" class="form-control" value="<?= h($from) ?>" max="<?= h(date('Y-m-d')) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">To date</label>
      <input type="date" name="to" class="form-control" value="<?= h($to) ?>" max="<?= h(date('Y-m-d')) ?>">
    </div>
    <div class="col-12">
      <button type="submit" class="btn btn-brand"><i class="bi bi-search me-1"></i>Search</button>
    </div>
  </form>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon-wrap"><i class="bi bi-list-check"></i></div>
      <div>
        <div class="stat-value"><?= $totalRecords ?></div>
        <div class="stat-label">Attendance records</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon-wrap"><i class="bi bi-check-circle"></i></div>
      <div>
        <div class="stat-value"><?= $totalPresent ?></div>
        <div class="stat-label">Present</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon-wrap"><i class="bi bi-x-circle"></i></div>
      <div>
        <div class="stat-value"><?= $totalAbsent ?></div>
        <div class="stat-label">Absent</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon-wrap"><i class="bi bi-graph-up"></i></div>
      <div>
        <div class="stat-value"><?= $overallRate !== null ? $overallRate . '%' : '—' ?></div>
        <div class="stat-label">Overall attendance rate</div>
      </div>
    </div>
  </div>
</div>
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon-wrap"><i class="bi bi-clock-history"></i></div>
      <div>
        <div class="stat-value"><?= $totalLate ?></div>
        <div class="stat-label">Late</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon-wrap"><i class="bi bi-file-earmark-check"></i></div>
      <div>
        <div class="stat-value"><?= $totalExcused ?></div>
        <div class="stat-label">Excused</div>
      </div>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel-title d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span>Attendance records (<?= count($groupedRecords) ?>) — <?= h(date('M j, Y', strtotime($from))) ?> to <?= h(date('M j, Y', strtotime($to))) ?></span>
    <div class="d-flex gap-2 flex-wrap">
      <a href="<?= base_url('admin/attendance_export.php') ?>?<?= h($queryString) ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-file-earmark-excel me-1"></i>Export to Excel
      </a>
      <?php if ($records): ?>
        <form method="post" action="?<?= h($queryString) ?>" onsubmit="return confirm('Permanently delete all <?= count($records) ?> attendance record(s) matching the current filters? This cannot be undone.');" class="d-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_all">
          <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete all matching (<?= count($records) ?>)</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php if (!$groupedRecords): ?>
    <div class="empty-state"><i class="bi bi-calendar2-week"></i>No attendance records found for this range.</div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>Date</th>
            <th>Student</th>
            <th>Grade</th>
            <th>Class</th>
            <th>Department</th>
            <th style="min-width:280px;">Subject &amp; Lesson</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($groupedRecords as $g):
            $fullName = trim(implode(' ', array_filter([$g['first_name'], $g['second_name'], $g['last_name']]))); ?>
            <tr>
              <td><?= h(date('M j, Y', strtotime($g['attendance_date']))) ?></td>
              <td class="fw-semibold"><?= h($fullName) ?></td>
              <td><?= h($g['grade']) ?></td>
              <td>Group <?= h($g['class_group']) ?></td>
              <td><?= h($g['department_name']) ?></td>
              <td>
                <div class="d-flex flex-column gap-1">
                  <?php foreach ($g['entries'] as $entry): $meta = attendance_status_meta($entry['status']);
                    $editUrl = base_url('admin/attendance_edit.php') . '?id=' . (int)$entry['id'] . '&return=' . urlencode(base_url('admin/attendance.php') . '?' . $queryString); ?>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                      <span class="badge <?= $meta['badge'] ?>"><i class="bi <?= $meta['icon'] ?> me-1"></i><?= h($meta['label']) ?></span>
                      <span class="fw-semibold small"><?= h($entry['subject_name']) ?></span>
                      <span class="text-muted small">— <?= h($entry['lesson']) ?></span>
                      <?php if ($entry['remarks']): ?>
                        <span class="text-muted small fst-italic">(<?= h($entry['remarks']) ?>)</span>
                      <?php endif; ?>
                      <a href="<?= h($editUrl) ?>" class="text-muted small" title="Edit this entry"><i class="bi bi-pencil-square"></i></a>
                    </div>
                  <?php endforeach; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>


<div class="panel mb-0">
  <div class="panel-title">
    Attendance summary by student
    <?php if ($subjectId):
      $subjectLabel = '';
      foreach ($subjects as $sub) { if ((int)$sub['id'] === $subjectId) { $subjectLabel = $sub['name']; break; } } ?>
      <span class="text-muted fw-normal">— <?= h($subjectLabel) ?> only</span>
    <?php else: ?>
      <span class="text-muted fw-normal">— all subjects combined</span>
    <?php endif; ?>
  </div>
  <?php if (!$summaryRows): ?>
    <div class="empty-state"><i class="bi bi-people"></i>No attendance records found for this range.</div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>Student</th>
            <th>Grade</th>
            <th>Class</th>
            <th class="text-center">Present</th>
            <th class="text-center">Absent</th>
            <th class="text-center">Late</th>
            <th class="text-center">Excused</th>
            <th class="text-center">Days recorded</th>
            <th style="min-width:140px;">Attendance rate</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($summaryRows as $r):
            $totalDaysRow = (int)$r['total_days'];
            $rate = $totalDaysRow > 0 ? round((((int)$r['present_count'] + (int)$r['late_count']) / $totalDaysRow) * 100) : 0;
            $rateColor = $rate >= 90 ? 'bg-success' : ($rate >= 75 ? 'bg-warning' : 'bg-danger');
            $fullName = trim(implode(' ', array_filter([$r['first_name'], $r['second_name'], $r['last_name']]))); ?>
            <tr>
              <td class="fw-semibold"><?= h($fullName) ?></td>
              <td><?= h($r['grade']) ?></td>
              <td><?= h($r['department_name']) ?> — Group <?= h($r['class_group']) ?></td>
              <td class="text-center"><span class="badge text-bg-success-subtle text-success-emphasis"><?= (int)$r['present_count'] ?></span></td>
              <td class="text-center"><span class="badge text-bg-danger-subtle text-danger-emphasis"><?= (int)$r['absent_count'] ?></span></td>
              <td class="text-center"><span class="badge text-bg-warning-subtle text-warning-emphasis"><?= (int)$r['late_count'] ?></span></td>
              <td class="text-center"><span class="badge text-bg-info-subtle text-info-emphasis"><?= (int)$r['excused_count'] ?></span></td>
              <td class="text-center"><?= $totalDaysRow ?></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div class="progress attendance-progress flex-grow-1">
                    <div class="progress-bar <?= $rateColor ?>" style="width: <?= $rate ?>%"></div>
                  </div>
                  <span class="small fw-semibold" style="min-width:38px;"><?= $rate ?>%</span>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
