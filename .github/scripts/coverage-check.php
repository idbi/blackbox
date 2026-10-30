<?php

// Fails when line coverage in a Clover report is below the minimum.
// Usage: php coverage-check.php build/coverage.xml 95

[, $file, $minimum] = $argv + [null, null, '95'];

$metrics = simplexml_load_file($file)->project->metrics;
$total = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $total === 0 ? 100.0 : $covered / $total * 100;

printf("Line coverage: %.2f%% (%d/%d), minimum %s%%\n", $percent, $covered, $total, $minimum);

exit($percent >= (float) $minimum ? 0 : 1);
