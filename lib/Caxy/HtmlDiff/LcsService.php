<?php

namespace Caxy\HtmlDiff;

use Caxy\HtmlDiff\Strategy\EqualMatchStrategy;
use Caxy\HtmlDiff\Strategy\MatchStrategyInterface;

/**
 * LcsService - rewritten with iterative backtrace.
 */
class LcsService
{
    protected $matchStrategy;

    public function __construct(?MatchStrategyInterface $matchStrategy = null)
    {
        $this->matchStrategy = $matchStrategy ?? new EqualMatchStrategy();
    }

    public function longestCommonSubsequence(array $a, array $b)
    {
        $m = count($a);
        $n = count($b);
        $c = [];

        for ($i = 0; $i <= $m; $i++) $c[$i][0] = 0;
        for ($j = 0; $j <= $n; $j++) $c[0][$j] = 0;

        for ($i = 1; $i <= $m; $i++) {
            $ai = $a[$i - 1];
            for ($j = 1; $j <= $n; $j++) {
                if ($this->matchStrategy->isMatch($ai, $b[$j - 1])) {
                    $c[$i][$j] = ($c[$i - 1][$j - 1] ?? 0) + 1;
                } else {
                    $c[$i][$j] = max($c[$i][$j - 1] ?? 0, $c[$i - 1][$j] ?? 0);
                }
            }
        }

        // Iterative backtrace (no stack overflow risk)
        $lcs = array_pad([], $m + 1, 0);
        $i = $m; $j = $n;
        $stack = [];
        while ($i > 0 && $j > 0) {
            if ($this->matchStrategy->isMatch($a[$i - 1], $b[$j - 1])) {
                $stack[] = [$i, $j];
                $i--; $j--;
            } elseif (($c[$i][$j - 1] ?? 0) >= ($c[$i - 1][$j] ?? 0)) {
                $j--;
            } else {
                $i--;
            }
        }
        for ($k = count($stack) - 1; $k >= 0; $k--) {
            $lcs[$stack[$k][0]] = $stack[$k][1];
        }

        return $lcs;
    }

    // Keep for compatibility
    protected function compileMatches($c, $a, $b, $i, $j, &$matches)
    {
        if ($i > 0 && $j > 0 && $this->matchStrategy->isMatch($a[$i - 1], $b[$j - 1])) {
            $this->compileMatches($c, $a, $b, $i - 1, $j - 1, $matches);
            $matches[$i] = $j;
        } elseif ($j > 0 && ($i === 0 || $c[$i][$j - 1] >= $c[$i - 1][$j])) {
            $this->compileMatches($c, $a, $b, $i, $j - 1, $matches);
        } elseif ($i > 0 && ($j === 0 || $c[$i][$j - 1] < $c[$i - 1][$j])) {
            $this->compileMatches($c, $a, $b, $i - 1, $j, $matches);
        }
    }
}
