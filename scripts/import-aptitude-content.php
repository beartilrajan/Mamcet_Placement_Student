<?php
// MAMCET Placement & Learning Portal - Developer CLI Aptitude Importer

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This importer is available from the command line only.\n");
}

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../services/AptitudeService.php');
require_once(__DIR__ . '/../services/AptitudeContentImporter.php');

function printUsage(): void {
    echo "Usage: php scripts/import-aptitude-content.php --file=<path> [--actor=<name>]\n";
    echo "\nImports developer-maintained JSON, CSV, or TSV aptitude questions.\n";
    echo "Existing rows are updated by question_id or exact category/question text.\n";
}

$filePath = null;
$actor = 'developer-cli';
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        printUsage();
        exit(0);
    }
    if (strpos($argument, '--file=') === 0) {
        $filePath = substr($argument, 7);
    } elseif (strpos($argument, '--actor=') === 0) {
        $actor = substr($argument, 8);
    } elseif ($filePath === null && $argument !== '') {
        $filePath = $argument;
    }
}

if ($filePath === null || trim($filePath) === '') {
    printUsage();
    exit(2);
}

$resolvedPath = realpath($filePath);
if ($resolvedPath === false) {
    fwrite(STDERR, "Aptitude content file not found: {$filePath}\n");
    exit(2);
}

try {
    $db = Database::getInstance()->getConnection();
    AptitudeService::ensureTables($db);
    $result = AptitudeContentImporter::importFile($db, $resolvedPath, $actor);

    echo "Aptitude content import completed.\n";
    echo "  Source: {$result['source_file']}\n";
    echo "  Inserted: {$result['inserted']}\n";
    echo "  Updated: {$result['updated']}\n";
    echo "  Total processed: {$result['total']}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Aptitude content import failed: {$e->getMessage()}\n");
    exit(1);
}
