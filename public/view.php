<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$token = $_GET['token'] ?? '';
$readable_id = $_GET['id'] ?? null;

if ($token){
$stmt = db()->prepare('
    SELECT d.*, s.recipient_email
    FROM shares s
    JOIN documents d ON d.id = s.document_id
    WHERE s.token = ?
');
$stmt->execute([$token]);
$doc = $stmt->fetch();
} elseif ($readable_id) {
    $stmt = db()->prepare('
        SELECT *
        FROM documents
        WHERE readable_id = ?
    ');
    $stmt->execute([$readable_id]);
    $doc = $stmt->fetch();
} else {
    $doc = null;
}

if (!$doc) {
    http_response_code(404);
    render_header('Not found');
    ?>
    <div class="centered-message">
        <h1>Share link not found</h1>
        <p>The link you used is invalid or has been removed.</p>
    </div>
    <?php
    render_footer();
    exit;
}

render_header($doc['title']);
?>

<h1 class="page-title"><?= h($doc['title']) ?></h1>
<?php if (!empty($doc['recipient_email'])): ?>
    <p class="meta">Shared with <?= h($doc['recipient_email']) ?></p>
<?php endif; ?>
<pre class="doc-body"><?= h($doc['body']) ?></pre>

<?php render_footer(); ?>
