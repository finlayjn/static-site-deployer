<?php
/**
 * Shared minimal test helpers for the standalone PHP test scripts.
 */

$GLOBALS['ssd_failures'] = 0;

/**
 * @param string $label
 * @param mixed  $expected
 * @param mixed  $actual
 */
function ssd_assert($label, $expected, $actual)
{
    if ($expected === $actual) {
        echo "ok   - {$label}\n";
        return;
    }
    $GLOBALS['ssd_failures']++;
    echo "FAIL - {$label}\n";
    echo '       expected: ' . var_export($expected, true) . "\n";
    echo '       actual:   ' . var_export($actual, true) . "\n";
}

function ssd_done()
{
    echo "\n";
    if ($GLOBALS['ssd_failures'] > 0) {
        echo $GLOBALS['ssd_failures'] . " test(s) failed.\n";
        exit(1);
    }
    echo "All tests passed.\n";
}
