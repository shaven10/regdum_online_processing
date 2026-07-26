<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('student');

$user = currentUser();
$db = getDB();
$requestId = (int) ($_GET['request_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $rating = (int) ($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    $reqId = (int) ($_POST['request_id'] ?? 0);

    if ($rating >= 1 && $rating <= 5) {
        $db->prepare('INSERT INTO feedback (user_id, request_id, rating, comment) VALUES (?, ?, ?, ?)')
           ->execute([$user['id'], $reqId, $rating, $comment ?: null]);
        setFlash('success', 'Thank you for your feedback!');
        redirect(APP_URL . '/student/requests.php');
    }
}

$pageTitle = 'Feedback';
$activeNav = 'requests';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2>Service Feedback</h2></div>
    <div class="card-body">
        <form method="POST" class="form-grid">
            <?= csrfField() ?>
            <input type="hidden" name="request_id" value="<?= $requestId ?>">
            <div class="form-group">
                <label>Rating *</label>
                <div class="star-rating">
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                        <input type="radio" id="star<?= $i ?>" name="rating" value="<?= $i ?>" required>
                        <label for="star<?= $i ?>"><i class="fas fa-star"></i></label>
                    <?php endfor; ?>
                </div>
            </div>
            <div class="form-group">
                <label for="comment">Comments</label>
                <textarea id="comment" name="comment" rows="4" placeholder="Tell us about your experience..."></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Submit Feedback</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
