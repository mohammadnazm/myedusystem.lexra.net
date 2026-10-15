<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('student');

$studentId = current_user_id();
$rows = attendance_summary_by_subject_for_student($conn, $studentId);

$totalPresent = 0; $totalAbsent = 0; $totalLate = 0; $totalExcused = 0; $totalDays = 0;
foreach ($rows as $r) {
    $totalPresent += (int)$r['present_count'];
    $totalAbsent  += (int)$r['absent_count'];
    $totalLate    += (int)$r['late_count'];
    $totalExcused += (int)$r['excused_count'];
    $totalDays    += (int)$r['total_days'];
}
$overallRate = $totalDays > 0 ? round((($totalPresent + $totalLate) / $totalDays) * 100) : null;

$pageTitle = 'Attendance';
$role = 'student';
$activePage = 'attendance';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<div class="panel">
  <div class="panel-title mb-0">My attendance, <?= h(current_user_name()) ?></div>
  <p class="text-muted mb-0">Your attendance record for every subject this semester, updated as your teachers take attendance.</p>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon-wrap"><i class="bi bi-graph-up"></i></div>
      <div>
        <div class="stat-value"><?= $overallRate !== null ? $overallRate . '%' : '—' ?></div>
        <div class="stat-label">Overall attendance rate</div>
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
      <div class="icon-wrap"><i class="bi bi-calendar2-week"></i></div>
      <div>
        <div class="stat-value"><?= $totalDays ?></div>
        <div class="stat-label">Days recorded</div>
      </div>
    </div>
  </div>
</div>

<div class="panel mb-0">
  <div class="panel-title">Attendance by subject</div>
  <?php if (!$rows): ?>
    <div class="empty-state"><i class="bi bi-calendar2-week"></i>No attendance has been recorded for you yet.</div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>Subject</th>
            <th class="text-center">Present</th>
            <th class="text-center">Absent</th>
            <th class="text-center">Late</th>
            <th class="text-center">Excused</th>
            <th class="text-center">Days recorded</th>
            <th style="min-width:160px;">Attendance rate</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $totalDaysRow = (int)$r['total_days'];
            $rate = $totalDaysRow > 0 ? round((((int)$r['present_count'] + (int)$r['late_count']) / $totalDaysRow) * 100) : 0;
            $rateColor = $rate >= 90 ? 'bg-success' : ($rate >= 75 ? 'bg-warning' : 'bg-danger'); ?>
            <tr>
              <td class="fw-semibold"><?= h($r['subject_name']) ?></td>
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
