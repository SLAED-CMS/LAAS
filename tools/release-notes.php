<?php
declare(strict_types=1);

use Laas\Support\ReleaseNotesExtractor;

$rootPath = dirname(__DIR__);
require $rootPath . '/vendor/autoload.php';

$file = $argv[1] ?? '';
$tag = $argv[2] ?? '';
if ($file === '' || $tag === '') {
    fwrite(STDERR, "Usage: php tools/release-notes.php <versions-file> <tag>\n");
    exit(1);
}

if (!is_file($file)) {
    fwrite(STDERR, "Versions file not found.\n");
    exit(1);
}

$content = (string) file_get_contents($file);
$extractor = new ReleaseNotesExtractor();
$notes = $extractor->extract($content, $tag);
if ($notes === null || trim($notes) === '') {
    fwrite(STDERR, "Release notes not found for tag {$tag}.\n");
    exit(1);
}

echo $notes;
