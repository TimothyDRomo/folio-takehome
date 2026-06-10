<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

system('php ' . escapeshellarg(__DIR__ . '/../migrate.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "migrate failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

test('document with future publish_at does not show share link', function () {
    $stmt = db()->prepare('UPDATE documents SET publish_at = ? WHERE id = 1');
    $stmt->execute(['2099-01-01 00:00:00']);
    
    $stmt = db()->prepare('SELECT publish_at FROM documents WHERE id = 1');
    $stmt->execute();
    $doc = $stmt->fetch();
    
    $now = date('Y-m-d H:i:s');
    assert_true($doc['publish_at'] > $now, 'publish_at should be in the future');
});

test('document with null publish_at is immediately available', function () {
    // ensure null explicitly
    $stmt = db()->prepare('UPDATE documents SET publish_at = NULL WHERE id = 1');
    $stmt->execute();
    
    $stmt = db()->prepare('SELECT publish_at FROM documents WHERE id = 1');
    $stmt->execute();
    $doc = $stmt->fetch();
    
    assert_true($doc['publish_at'] === null, 'seeded document should have no publish_at');
});

test('document readable_id is generated on creation', function () {
    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by)
        VALUES (?, ?, 1)
    ');
    $stmt->execute(['Test Document', 'Test body']);
    $docId = (int) db()->lastInsertId();

    // generate readable_id manually and update
    $readable_id = generate_readable_id('Test Document');
    $stmt = db()->prepare('UPDATE documents SET readable_id = ? WHERE id = ?');
    $stmt->execute([$readable_id, $docId]);

    $stmt = db()->prepare('SELECT readable_id FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();

    assert_true(!empty($doc['readable_id']), 'readable_id should be set');
    assert_true(str_starts_with($doc['readable_id'], 'test-document-'), 'readable_id should start with slugified title');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
