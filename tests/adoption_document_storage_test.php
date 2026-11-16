<?php
require_once __DIR__ . '/../includes/adoption-workflow.php';

function expectAdoptionDocumentCondition($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dcvs-adoption-document-' . bin2hex(random_bytes(6));
$documentRoot = $testRoot . DIRECTORY_SEPARATOR . 'htdocs';
$adoptionDirectory = $testRoot . DIRECTORY_SEPARATOR . 'dcvs-private' . DIRECTORY_SEPARATOR . 'adoption';
$filename = 'residency_' . str_repeat('a', 48) . '.webp';
$documentPath = $adoptionDirectory . DIRECTORY_SEPARATOR . $filename;

if (!mkdir($documentRoot, 0700, true)) {
    throw new RuntimeException('Could not create the adoption document test directory.');
}

$_SERVER['DOCUMENT_ROOT'] = $documentRoot;

try {
    adoptionPrivateDirectory();

    expectAdoptionDocumentCondition(
        !adoptionPrivateDocumentExists('not-a-private-document.webp'),
        'Invalid document filenames must not be treated as available.'
    );
    expectAdoptionDocumentCondition(
        !adoptionPrivateDocumentExists($filename),
        'A valid filename without a stored file must be reported missing.'
    );
    if (file_put_contents($documentPath, 'test document') === false) {
        throw new RuntimeException('Could not create the adoption document fixture.');
    }
    expectAdoptionDocumentCondition(
        adoptionPrivateDocumentExists($filename),
        'A readable private document must be reported available.'
    );
} finally {
    if ($documentPath !== '' && is_file($documentPath)) {
        unlink($documentPath);
    }
    if (is_dir($adoptionDirectory)) {
        rmdir($adoptionDirectory);
    }
    $privateDirectory = dirname($adoptionDirectory);
    if (is_dir($privateDirectory)) {
        rmdir($privateDirectory);
    }
    if (is_dir($documentRoot)) {
        rmdir($documentRoot);
    }
    if (is_dir($testRoot)) {
        rmdir($testRoot);
    }
}
