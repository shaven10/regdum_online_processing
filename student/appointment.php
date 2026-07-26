<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('student');

$user = currentUser();
$db = getDB();
$requestId = (int) ($_GET['request_id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM requests WHERE id = ? AND user_id = ? AND status = 'ready_for_pickup'");
$stmt->execute([$requestId, $user['id']]);
$request = $stmt->fetch();

if (!$request) {
    setFlash('error', 'Request not available for pickup scheduling.');
    redirect(APP_URL . '/student/requests.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $date = $_POST['appointment_date'] ?? '';
    $time = $_POST['appointment_time'] ?? '';

    if ($date && $time) {
        $db->prepare('INSERT INTO appointments (request_id, user_id, appointment_date, appointment_time) VALUES (?, ?, ?, ?)')
           ->execute([$requestId, $user['id'], $date, $time]);
        $db->prepare('UPDATE requests SET pickup_date = ?, pickup_time = ? WHERE id = ?')
           ->execute([$date, $time, $requestId]);
        sendNotification($user['id'], 'Pickup Scheduled', 'Your pickup for ' . $request['request_number'] . ' is scheduled.', 'success');
        setFlash('success', 'Pickup appointment scheduled!');
        redirect(APP_URL . '/student/request-view.php?id=' . $requestId);
    }
}

$pageTitle = 'Schedule Pickup';
$activeNav = 'requests';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2>Schedule Pickup — <?= e($request['request_number']) ?></h2></div>
    <div class="card-body">
        <form method="POST" class="form-grid">
            <?= csrfField() ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="appointment_date">Date *</label>
                    <input type="date" id="appointment_date" name="appointment_date" min="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label for="appointment_time">Time *</label>
                    <select id="appointment_time" name="appointment_time" required>
                        <option value="09:00:00">9:00 AM - 10:00 AM</option>
                        <option value="10:00:00">10:00 AM - 11:00 AM</option>
                        <option value="11:00:00">11:00 AM - 12:00 PM</option>
                        <option value="13:00:00">1:00 PM - 2:00 PM</option>
                        <option value="14:00:00">2:00 PM - 3:00 PM</option>
                        <option value="15:00:00">3:00 PM - 4:00 PM</option>
                    </select>
                </div>
            </div>
            <div class="alert alert-info"><i class="fas fa-map-marker-alt"></i> Pickup location: Registrar's Office, Main Building, Ground Floor</div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-calendar-check"></i> Confirm Appointment</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
