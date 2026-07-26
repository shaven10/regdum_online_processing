<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');

$db = getDB();
$page = max(1, (int) ($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');

$where = ['u.role_id = 1'];
$params = [];
if ($search) { $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.student_id LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%", "%$search%"); }
$whereClause = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM users u WHERE $whereClause");
$countStmt->execute($params);
$pag = paginate((int)$countStmt->fetchColumn(), $page);

$stmt = $db->prepare("SELECT u.*, sp.course, sp.year_level, sp.enrollment_status, (SELECT COUNT(*) FROM requests r WHERE r.user_id = u.id) as request_count FROM users u LEFT JOIN student_profiles sp ON u.id = sp.user_id WHERE $whereClause ORDER BY u.created_at DESC LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$stmt->execute($params);
$students = $stmt->fetchAll();

$pageTitle = 'Student Records';
$activeNav = 'students';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2>Student Records</h2></div>
    <div class="card-body">
        <form method="GET" class="filter-bar">
            <input type="text" name="search" placeholder="Search by name, email, or student ID..." value="<?= e($search) ?>">
            <button type="submit" class="btn btn-outline btn-sm">Search</button>
        </form>

        <table class="data-table">
            <thead><tr><th>Student ID</th><th>Name</th><th>Email</th><th>Course</th><th>Status</th><th>Requests</th><th>Registered</th></tr></thead>
            <tbody>
                <?php foreach ($students as $s): ?>
                <tr>
                    <td><?= e($s['student_id']) ?></td>
                    <td><?= e($s['first_name'] . ' ' . $s['last_name']) ?></td>
                    <td><?= e($s['email']) ?></td>
                    <td><?= e($s['course'] ?? '—') ?></td>
                    <td><?= e(enrollmentStatusLabel($s['enrollment_status'] ?? null)) ?></td>
                    <td><?= $s['request_count'] ?></td>
                    <td><?= formatDate($s['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?= paginationLinks($pag, '?' . http_build_query(array_filter(['search' => $search]))) ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
