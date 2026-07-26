<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');

$db = getDB();
$search = trim($_GET['search'] ?? '');

$where = ['u.role_id = 1'];
$params = [];
if ($search) { $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.student_id LIKE ? OR u.email LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%", "%$search%"); }

$stmt = $db->prepare('SELECT u.*, sp.course, sp.year_level, sp.enrollment_status, sp.address, sp.city FROM users u LEFT JOIN student_profiles sp ON u.id = sp.user_id WHERE ' . implode(' AND ', $where) . ' ORDER BY u.last_name LIMIT 50');
$stmt->execute($params);
$students = $stmt->fetchAll();

$pageTitle = 'Student Records';
$activeNav = 'students';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2>Verify Student Records</h2></div>
    <div class="card-body">
        <form method="GET" class="filter-bar">
            <input type="text" name="search" placeholder="Search by name, student ID, or email..." value="<?= e($search) ?>" autofocus>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
        </form>

        <?php if ($search && empty($students)): ?>
            <div class="empty-state"><i class="fas fa-user-slash"></i><p>No students found.</p></div>
        <?php elseif (!empty($students)): ?>
            <table class="data-table">
                <thead><tr><th>Student ID</th><th>Name</th><th>Email</th><th>Course</th><th>Year</th><th>Status</th><th>Location</th></tr></thead>
                <tbody>
                    <?php foreach ($students as $s): ?>
                    <tr>
                        <td><strong><?= e($s['student_id']) ?></strong></td>
                        <td><?= e($s['first_name'] . ' ' . $s['last_name']) ?></td>
                        <td><?= e($s['email']) ?></td>
                        <td><?= e($s['course'] ?? '—') ?></td>
                        <td><?= e($s['year_level'] ?? '—') ?></td>
                        <td><?= e(enrollmentStatusLabel($s['enrollment_status'] ?? null)) ?></td>
                        <td><?= e(($s['city'] ?? '') ?: '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="text-muted">Enter a search term to find student records.</p>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
