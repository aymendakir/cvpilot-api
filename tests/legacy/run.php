<?php

/**
 * Runs the standalone regression scripts in this directory, one PHP process
 * each, and reports PASS / FAIL / KNOWN FAILURE.
 *
 *   php tests/legacy/run.php        (or: composer test:scripts)
 *
 * Exit code is 1 if any script outside KNOWN_FAILURES fails, or if a script
 * in KNOWN_FAILURES unexpectedly passes (so the list cannot go stale).
 */

// Scripts that fail on main today. Each entry needs a reason and an owner.
const KNOWN_FAILURES = [
    'ats-document.php' => 'scoring caps in AtsDocumentReview disagree with the test (AUDIT A1); fixed in Phase 3',
];

$scripts = glob(__DIR__.'/*.php');
sort($scripts);
$failed = [];
$unexpectedPass = [];

foreach ($scripts as $script) {
    $name = basename($script);
    if ($name === basename(__FILE__)) {
        continue;
    }

    $output = [];
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1', $output, $code);
    $known = array_key_exists($name, KNOWN_FAILURES);

    if ($code === 0 && ! $known) {
        echo "PASS           {$name}\n";
    } elseif ($code !== 0 && $known) {
        echo "KNOWN FAILURE  {$name} - ".KNOWN_FAILURES[$name]."\n";
    } elseif ($code === 0) {
        echo "UNEXPECTED PASS {$name} - remove it from KNOWN_FAILURES\n";
        $unexpectedPass[] = $name;
    } else {
        echo "FAIL           {$name}\n".implode("\n", array_map(fn ($l) => '    '.$l, array_slice($output, 0, 15)))."\n";
        $failed[] = $name;
    }
}

exit($failed || $unexpectedPass ? 1 : 0);
