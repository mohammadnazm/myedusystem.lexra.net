<?php
$activePage = $activePage ?? '';

$navByRole = [
    'admin' => [
        ['key' => 'dashboard',  'label' => 'Dashboard',    'icon' => 'bi-speedometer2', 'href' => base_url('admin/dashboard.php')],
        ['key' => 'departments','label' => 'Departments',  'icon' => 'bi-diagram-3',    'href' => base_url('admin/departments.php')],
        ['key' => 'sections',   'label' => 'Class Sections','icon' => 'bi-grid-3x3-gap','href' => base_url('admin/sections.php')],
        ['key' => 'subjects',   'label' => 'Subjects',     'icon' => 'bi-journal-bookmark', 'href' => base_url('admin/subjects.php')],
        ['key' => 'teachers',   'label' => 'Teachers',     'icon' => 'bi-person-workspace', 'href' => base_url('admin/teachers.php')],
        ['key' => 'students',   'label' => 'Students',     'icon' => 'bi-people',       'href' => base_url('admin/students.php')],
        ['key' => 'promote',    'label' => 'Promote Students', 'icon' => 'bi-arrow-up-right-circle', 'href' => base_url('admin/promote.php')],
        ['key' => 'marks',      'label' => 'Marks Entry',  'icon' => 'bi-pencil-square','href' => base_url('admin/marks.php')],
        ['key' => 'attendance', 'label' => 'Attendance',   'icon' => 'bi-calendar2-check', 'href' => base_url('admin/attendance.php')],
        ['key' => 'daily_reports', 'label' => 'Daily Reports', 'icon' => 'bi-journal-text', 'href' => base_url('admin/daily_reports.php')],
        ['key' => 'resources',  'label' => 'PDF Resources', 'icon' => 'bi-file-earmark-pdf', 'href' => base_url('admin/resources.php')],
        ['key' => 'payments',   'label' => 'Payments',     'icon' => 'bi-receipt',      'href' => base_url('admin/payments.php')],
        ['key' => 'accounting', 'label' => 'Fees & Discounts', 'icon' => 'bi-cash-coin', 'href' => base_url('admin/accounting.php')],
        ['key' => 'accountants','label' => 'Accountants',  'icon' => 'bi-person-badge', 'href' => base_url('admin/accountants.php')],
        ['key' => 'activity',   'label' => 'Activity Log', 'icon' => 'bi-clock-history', 'href' => base_url('admin/activity.php')],
        ['key' => 'profile',    'label' => 'My Profile',   'icon' => 'bi-person-circle','href' => base_url('admin/profile.php')],
    ],
    'accountant' => [
        ['key' => 'payments',  'label' => 'Payments',       'icon' => 'bi-receipt', 'href' => base_url('accountant/payments.php')],
        ['key' => 'dashboard', 'label' => 'Student Prices', 'icon' => 'bi-cash-coin', 'href' => base_url('accountant/dashboard.php')],
        ['key' => 'profile',   'label' => 'My Profile',     'icon' => 'bi-person-circle', 'href' => base_url('accountant/profile.php')],
    ],
    'teacher' => [
        ['key' => 'dashboard', 'label' => 'Dashboard',   'icon' => 'bi-speedometer2',  'href' => base_url('teacher/dashboard.php')],
        ['key' => 'marks',     'label' => 'Marks Entry', 'icon' => 'bi-pencil-square', 'href' => base_url('teacher/marks.php')],
        ['key' => 'attendance', 'label' => 'Attendance', 'icon' => 'bi-calendar2-check', 'href' => base_url('teacher/attendance.php')],
        ['key' => 'daily_reports', 'label' => 'Daily Reports', 'icon' => 'bi-journal-text', 'href' => base_url('teacher/daily_reports.php')],
        ['key' => 'resources', 'label' => 'PDF Resources', 'icon' => 'bi-file-earmark-pdf', 'href' => base_url('teacher/resources.php')],
        ['key' => 'profile',   'label' => 'My Profile',  'icon' => 'bi-person-circle', 'href' => base_url('teacher/profile.php')],
    ],
    'student' => [
        ['key' => 'profile', 'label' => 'My Profile', 'icon' => 'bi-person-circle', 'href' => base_url('student/profile.php')],
        ['key' => 'dashboard', 'label' => 'My Marks', 'icon' => 'bi-mortarboard', 'href' => base_url('student/dashboard.php')],
        ['key' => 'attendance', 'label' => 'Attendance', 'icon' => 'bi-calendar2-check', 'href' => base_url('student/attendance.php')],
        ['key' => 'resources', 'label' => 'Class Materials', 'icon' => 'bi-file-earmark-pdf', 'href' => base_url('student/resources.php')],
    ],
    'owner' => [
        ['key' => 'dashboard', 'label' => 'Institutes', 'icon' => 'bi-buildings', 'href' => base_url('owner/dashboard.php')],
        ['key' => 'profile',   'label' => 'My Profile', 'icon' => 'bi-person-circle', 'href' => base_url('owner/profile.php')],
    ],
];

$navItems = $navByRole[$role] ?? [];
$roleLabel = ['admin' => 'Administrator', 'teacher' => 'Teacher', 'student' => 'Student', 'owner' => 'Platform Owner', 'accountant' => 'Accountant'][$role] ?? '';
$flash = flash_get();
$logoUrl = in_array($role, ['admin', 'teacher', 'student', 'accountant'], true) ? institute_logo_url($conn, current_institute_id()) : null;
?>
<div class="app-shell">
  <aside class="app-sidebar">
    <div class="sidebar-brand">
      <?php if ($logoUrl): ?>
        <img src="<?= h($logoUrl) ?>" alt="<?= h(current_institute_name() ?? 'Institute logo') ?>" class="sidebar-logo">
        <span><?= h(current_institute_name() ?? 'Institute System') ?></span>
      <?php else: ?>
        <i class="bi bi-mortarboard-fill"></i>
        <span>Institute System</span>
      <?php endif; ?>
    </div>
    <?php if (current_institute_name() && !$logoUrl): ?>
      <div class="sidebar-institute">
        <i class="bi bi-building"></i>
        <span><?= h(current_institute_name()) ?></span>
      </div>
    <?php endif; ?>
    <nav class="sidebar-nav">
      <?php foreach ($navItems as $item): ?>
        <a href="<?= $item['href'] ?>" class="sidebar-link <?= $activePage === $item['key'] ? 'active' : '' ?>">
          <i class="bi <?= $item['icon'] ?>"></i>
          <span><?= h($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      <a href="<?= base_url('logout.php') ?>" class="sidebar-link text-danger">
        <i class="bi bi-box-arrow-right"></i>
        <span>Log out</span>
      </a>
    </div>
  </aside>

  <div class="app-main">
    <header class="app-topbar">
      <button class="sidebar-toggle d-lg-none" type="button" aria-label="Toggle menu">
        <i class="bi bi-list"></i>
      </button>
      <h1 class="topbar-title"><?= h($pageTitle ?? '') ?></h1>
      <?= i18n_switcher() ?>
      <div class="topbar-user">
        <span class="badge-role"><?= h($roleLabel) ?></span>
        <span class="user-name"><?= h(current_user_name() ?? '') ?></span>
      </div>
    </header>

    <main class="app-content">
      <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
          <?= h($flash['message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
