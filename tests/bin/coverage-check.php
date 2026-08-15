<?php

/**
 * Fails the build when line coverage is below the required threshold.
 *
 * Codeception's `coverage.low_limit` / `high_limit` only colour the report
 * (they are consumed by the HTML and text writers), so the actual gate lives
 * here. Reads the Clover report produced by `codecept run --coverage-xml`.
 *
 * Usage: php tests/bin/coverage-check.php [clover.xml]
 */

declare(strict_types=1);

/** The project is a hard-100% shop; this is the only place the number lives. */
const REQUIRED_COVERAGE = 100.0;

$cloverPath = $argv[1] ?? __DIR__ . '/../_output/coverage.xml';

if (!is_file($cloverPath)) {
    fwrite(STDERR, "Coverage report not found: {$cloverPath}\n");
    fwrite(STDERR, "Run `make coverage`. If the suite died on a fatal error the report is\n");
    fwrite(STDERR, "never written, because coverage is flushed only after a normal run.\n");
    exit(1);
}

$previous = libxml_use_internal_errors(true);
$xml = simplexml_load_file($cloverPath);
libxml_use_internal_errors($previous);

if ($xml === false) {
    fwrite(STDERR, "Coverage report is not valid XML: {$cloverPath}\n");
    exit(1);
}

// Files that declare a namespaced class are nested inside <package>, so this
// has to be a descendant lookup rather than /coverage/project/file.
$files = $xml->xpath('//file') ?: [];

if ($files === []) {
    fwrite(STDERR, "Coverage report contains no files.\n");
    fwrite(STDERR, "Check that the coverage driver is loaded (php -m | grep pcov) and that\n");
    fwrite(STDERR, "`coverage.include` in codeception.yml points at existing directories.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$offenders = [];

foreach ($files as $file) {
    // Line coverage: `statements`, not `elements` — the latter folds in method
    // counts, where a method only counts as covered when it is fully covered.
    $statements = (int) $file->metrics['statements'];

    // Interfaces and other files with no executable lines are 0/0: neutral for
    // the total, and a division by zero here.
    if ($statements === 0) {
        continue;
    }

    $covered = (int) $file->metrics['coveredstatements'];

    if ($covered >= $statements) {
        continue;
    }

    $missed = [];

    foreach ($file->line as $line) {
        if ((string) $line['type'] === 'stmt' && (int) $line['count'] === 0) {
            $missed[] = (int) $line['num'];
        }
    }

    $offenders[] = [
        'path' => str_replace($projectRoot, '', (string) $file['name']),
        'covered' => $covered,
        'statements' => $statements,
        'percent' => $covered / $statements * 100,
        'lines' => $missed,
    ];
}

// Taken from the report's own totals so the number always matches the HTML.
$totalStatements = (int) $xml->project->metrics['statements'];
$totalCovered = (int) $xml->project->metrics['coveredstatements'];
$totalPercent = $totalStatements > 0 ? $totalCovered / $totalStatements * 100 : 100.0;

if ($offenders !== []) {
    usort($offenders, static fn (array $a, array $b): int => $a['percent'] <=> $b['percent']);

    fwrite(STDERR, sprintf("\nFiles below 100%% line coverage (%d):\n\n", count($offenders)));

    foreach ($offenders as $offender) {
        fwrite(STDERR, sprintf(
            "  %-58s %6.2f%%  (%d/%d)\n      uncovered lines: %s\n",
            $offender['path'],
            $offender['percent'],
            $offender['covered'],
            $offender['statements'],
            $offender['lines'] === [] ? '-' : implode(', ', $offender['lines'])
        ));
    }
}

fwrite(STDOUT, sprintf(
    "\nTotal line coverage: %.2f%% (%d/%d statements), required %.2f%%\n",
    $totalPercent,
    $totalCovered,
    $totalStatements,
    REQUIRED_COVERAGE
));

// Epsilon guards against a float-rounding false negative when every file is
// fully covered but the division lands just short of the threshold.
if ($totalPercent + 1e-9 < REQUIRED_COVERAGE) {
    fwrite(STDERR, "\nCoverage gate FAILED.\n");
    fwrite(STDERR, "Add tests, or mark genuinely unreachable code with @codeCoverageIgnore\n");
    fwrite(STDERR, "plus a comment explaining why the branch cannot be reached.\n");
    exit(1);
}

fwrite(STDOUT, "Coverage gate passed.\n");
exit(0);
