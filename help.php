<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = currentUser();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $message = trim($_POST['message'] ?? '');
    if ($message) {
        $db->prepare('INSERT INTO chat_messages (user_id, message) VALUES (?, ?)')->execute([$user['id'], $message]);
    }
    redirect(APP_URL . '/help.php');
}

$messages = $db->prepare('SELECT c.*, u.first_name, u.last_name FROM chat_messages c JOIN users u ON c.user_id = u.id WHERE c.user_id = ? OR c.staff_id IS NOT NULL ORDER BY c.created_at ASC');
$messages->execute([$user['id']]);
$chatMessages = $messages->fetchAll();

$pageTitle = 'Help Desk';
$activeNav = 'help';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card chat-card">
    <div class="card-header"><h2><i class="fas fa-headset"></i> Help Desk</h2></div>
    <div class="card-body">
        <div class="chat-messages" id="chatMessages">
            <?php if (empty($chatMessages)): ?>
                <p class="text-muted text-center">No messages yet. Send us a question!</p>
            <?php else: ?>
                <?php foreach ($chatMessages as $msg): ?>
                <div class="chat-bubble <?= !empty($msg['staff_id']) ? 'staff' : 'user' ?>">
                    <p><?= e($msg['message']) ?></p>
                    <small><?= e($msg['first_name'] . ' ' . $msg['last_name']) ?> · <?= formatDateTime($msg['created_at']) ?></small>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <form method="POST" class="chat-input">
            <?= csrfField() ?>
            <input type="text" name="message" placeholder="Type your question..." required>
            <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
