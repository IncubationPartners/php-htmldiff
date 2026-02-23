<?php

namespace Caxy\HtmlDiff;

use Caxy\HtmlDiff\Table\TableDiff;

/**
 * Class HtmlDiff - Complete rewrite with optimal algorithms.
 *
 * Major algorithmic changes:
 * 1. All tag detection uses character-level checks + caching (no regex in hot path)
 * 2. Word indexing uses hash maps with sorted position arrays
 * 3. Operation processing uses array_slice (O(k) not O(n))
 * 4. Whitespace checking uses a full precomputed bitmap
 * 5. extractConsecutiveWords uses direct array_splice
 * 6. Isolated diff tag placeholder checking uses hash set
 * 7. stripTagAttributes results are cached
 */
class HtmlDiff extends AbstractDiff
{
    /** @var array word => [positions] */
    protected $wordIndices;

    protected $newIsolatedDiffTags;
    protected $oldIsolatedDiffTags;

    // --- Caches (populated lazily, massive perf win) ---
    private $tagCache = [];
    private $openCache = [];
    private $closeCache = [];
    private $stripCache = [];
    private $placeholderSet = [];

    /** @var bool[] precomputed whitespace bitmap for oldWords */
    private $oldWordIsWhitespace = [];

    public static function create($oldText, $newText, ?HtmlDiffConfig $config = null)
    {
        $diff = new self($oldText, $newText);
        if (null !== $config) $diff->setConfig($config);
        return $diff;
    }

    public function setUseTableDiffing($bool) { $this->config->setUseTableDiffing($bool); return $this; }
    public function setInsertSpaceInReplace($boolean) { $this->config->setInsertSpaceInReplace($boolean); return $this; }
    public function getInsertSpaceInReplace() { return $this->config->isInsertSpaceInReplace(); }

    public function build()
    {
        $this->prepare();

        if ($this->hasDiffCache() && $this->getDiffCache()->contains($this->oldText, $this->newText)) {
            $this->content = $this->getDiffCache()->fetch($this->oldText, $this->newText);
            return $this->content;
        }

        if ($this->oldText == $this->newText) return $this->newText;

        // Build placeholder set for O(1) lookups
        $this->placeholderSet = array_flip($this->config->getIsolatedDiffTags());

        $this->splitInputsToWords();
        $this->replaceIsolatedDiffTags();

        // Precompute whitespace bitmap for oldWords
        $this->precomputeWhitespaceBitmap();

        $this->indexNewWords();

        $operations = $this->operations();
        foreach ($operations as $item) {
            $this->performOperation($item);
        }

        if ($this->hasDiffCache()) {
            $this->getDiffCache()->save($this->oldText, $this->newText, $this->content);
        }

        return $this->content;
    }

    /**
     * Precompute which oldWords are whitespace so oldTextIsOnlyWhitespace
     * becomes an O(k) scan of booleans instead of calling trim() repeatedly.
     */
    private function precomputeWhitespaceBitmap() : void
    {
        $this->oldWordIsWhitespace = [];
        foreach ($this->oldWords as $i => $w) {
            $this->oldWordIsWhitespace[$i] = ($w === '' || trim($w) === '');
        }
    }

    protected function indexNewWords() : void
    {
        $this->wordIndices = [];
        foreach ($this->newWords as $i => $word) {
            $key = $this->isTagFast($word) ? $this->stripCached($word) : $word;
            $this->wordIndices[$key][] = $i;
        }
    }

    // ========== Isolated Diff Tag Handling ==========

    protected function replaceIsolatedDiffTags()
    {
        $this->oldIsolatedDiffTags = $this->createIsolatedDiffTagPlaceholders($this->oldWords);
        $this->newIsolatedDiffTags = $this->createIsolatedDiffTagPlaceholders($this->newWords);
    }

    protected function createIsolatedDiffTagPlaceholders(&$words)
    {
        $openIsolatedDiffTags = 0;
        $isolatedDiffTagIndices = [];
        $isolatedDiffTagStart = 0;
        $currentIsolatedDiffTag = null;

        foreach ($words as $index => $word) {
            $openIsolatedDiffTag = $this->isOpeningIsolatedDiffTag($word, $currentIsolatedDiffTag);
            if ($openIsolatedDiffTag) {
                if ($this->isSelfClosingTag($word) || stripos($word, '<img') !== false) {
                    if ($openIsolatedDiffTags === 0) {
                        $isolatedDiffTagIndices[] = ['start' => $index, 'length' => 1, 'tagType' => $openIsolatedDiffTag];
                        $currentIsolatedDiffTag = null;
                    }
                } else {
                    if ($openIsolatedDiffTags === 0) $isolatedDiffTagStart = $index;
                    ++$openIsolatedDiffTags;
                    $currentIsolatedDiffTag = $openIsolatedDiffTag;
                }
            } elseif ($openIsolatedDiffTags > 0 && $this->isClosingIsolatedDiffTag($word, $currentIsolatedDiffTag)) {
                --$openIsolatedDiffTags;
                if ($openIsolatedDiffTags == 0) {
                    $isolatedDiffTagIndices[] = ['start' => $isolatedDiffTagStart, 'length' => $index - $isolatedDiffTagStart + 1, 'tagType' => $currentIsolatedDiffTag];
                    $currentIsolatedDiffTag = null;
                }
            }
        }

        $script = [];
        $offset = 0;
        foreach ($isolatedDiffTagIndices as $idx) {
            $start = $idx['start'] - $offset;
            $placeholder = $this->config->getIsolatedDiffTagPlaceholder($idx['tagType']);
            $script[$start] = array_splice($words, $start, $idx['length'], $placeholder);
            $offset += $idx['length'] - 1;
        }
        return $script;
    }

    protected function isOpeningIsolatedDiffTag($item, $currentIsolatedDiffTag = null)
    {
        $tagsToMatch = $currentIsolatedDiffTag !== null
            ? [$currentIsolatedDiffTag => $this->config->getIsolatedDiffTagPlaceholder($currentIsolatedDiffTag)]
            : $this->config->getIsolatedDiffTags();
        foreach ($tagsToMatch as $key => $value) {
            if (preg_match('#<' . $key . '(\s+[^>]*)?>#iUu', $item)) return $key;
        }
        return false;
    }

    protected function isSelfClosingTag($text) { return (bool) preg_match('/<[^>]+\/\s*>/u', $text); }

    protected function isClosingIsolatedDiffTag($item, $currentIsolatedDiffTag = null)
    {
        $tagsToMatch = $currentIsolatedDiffTag !== null
            ? [$currentIsolatedDiffTag => $this->config->getIsolatedDiffTagPlaceholder($currentIsolatedDiffTag)]
            : $this->config->getIsolatedDiffTags();
        foreach ($tagsToMatch as $key => $value) {
            if (preg_match('#</' . $key . '(\s+[^>]*)?>#iUu', $item)) return $key;
        }
        return false;
    }

    // ========== Core Diff Operations ==========

    protected function performOperation($operation)
    {
        switch ($operation->action) {
            case 'equal':   $this->processEqualOperation($operation); break;
            case 'delete':  $this->processDeleteOperation($operation, 'diffdel'); break;
            case 'insert':  $this->processInsertOperation($operation, 'diffins'); break;
            case 'replace': $this->processDeleteOperation($operation, 'diffmod'); $this->processInsertOperation($operation, 'diffmod'); break;
        }
    }

    /**
     * All three operation processors use array_slice for O(k) instead of O(n).
     */
    protected function processInsertOperation($operation, $cssClass)
    {
        $text = [];
        $len = $operation->endInNew - $operation->startInNew;
        $slice = array_slice($this->newWords, $operation->startInNew, $len);
        foreach ($slice as $offset => $s) {
            $pos = $operation->startInNew + $offset;
            if (isset($this->placeholderSet[$s]) && isset($this->newIsolatedDiffTags[$pos])) {
                array_push($text, ...$this->newIsolatedDiffTags[$pos]);
            } else {
                $text[] = $s;
            }
        }
        $this->insertTag('ins', $cssClass, $text);
    }

    protected function processDeleteOperation($operation, $cssClass)
    {
        $text = [];
        $len = $operation->endInOld - $operation->startInOld;
        $slice = array_slice($this->oldWords, $operation->startInOld, $len);
        foreach ($slice as $offset => $s) {
            $pos = $operation->startInOld + $offset;
            if (isset($this->placeholderSet[$s]) && isset($this->oldIsolatedDiffTags[$pos])) {
                array_push($text, ...$this->oldIsolatedDiffTags[$pos]);
            } else {
                $text[] = $s;
            }
        }
        $this->insertTag('del', $cssClass, $text);
    }

    protected function processEqualOperation($operation)
    {
        $result = [];
        $len = $operation->endInNew - $operation->startInNew;
        $slice = array_slice($this->newWords, $operation->startInNew, $len);
        foreach ($slice as $offset => $s) {
            $pos = $operation->startInNew + $offset;
            if (isset($this->placeholderSet[$s]) && isset($this->newIsolatedDiffTags[$pos])) {
                $result[] = $this->diffIsolatedPlaceholder($operation, $pos, $s);
            } else {
                $result[] = $s;
            }
        }
        $this->content .= implode('', $result);
    }

    // ========== Isolated Placeholder Diffing ==========

    protected function diffIsolatedPlaceholder($operation, $pos, $placeholder, $stripWrappingTags = true)
    {
        $oldText = implode('', $this->oldIsolatedDiffTags[$operation->startInOld + ($pos - $operation->startInNew)]);
        $newText = implode('', $this->newIsolatedDiffTags[$pos]);

        if ($this->isPlaceholderType($placeholder, ['ol', 'dl', 'ul'])) return $this->diffList($oldText, $newText);
        if ($this->config->isUseTableDiffing() && $this->isPlaceholderType($placeholder, 'table')) return $this->diffTables($oldText, $newText);
        if ($this->isPlaceholderType($placeholder, 'a')) return $this->diffElementsByAttribute($oldText, $newText, 'href', 'a');
        if ($this->isPlaceholderType($placeholder, 'img')) return $this->diffElementsByAttribute($oldText, $newText, 'src', 'img');
        if ($this->isPlaceholderType($placeholder, 'picture')) return $this->diffPicture($oldText, $newText);
        return $this->diffElements($oldText, $newText, $stripWrappingTags);
    }

    protected function diffElements($oldText, $newText, $stripWrappingTags = true)
    {
        $wrapStart = $wrapEnd = '';
        if ($stripWrappingTags) {
            $pattern = '/(^<[^>]+>)|(<\/[^>]+>$)/iu';
            if (preg_match_all($pattern, $newText, $matches)) {
                $wrapStart = $matches[0][0] ?? '';
                $wrapEnd = $matches[0][1] ?? '';
            }
            $oldText = preg_replace($pattern, '', $oldText);
            $newText = preg_replace($pattern, '', $newText);
        }
        return $wrapStart . self::create($oldText, $newText, $this->config)->build() . $wrapEnd;
    }

    protected function diffList($oldText, $newText)
    {
        return ListDiffLines::create($oldText, $newText, $this->config)->build();
    }

    protected function diffTables($oldText, $newText)
    {
        return TableDiff::create($oldText, $newText, $this->config)->build();
    }

    protected function diffPicture($oldText, $newText) {
        if ($oldText !== $newText) {
            return $this->wrapText($oldText, 'del', 'diffmod') . $this->wrapText($newText, 'ins', 'diffmod');
        }
        return $this->diffElements($oldText, $newText);
    }

    protected function diffElementsByAttribute($oldText, $newText, $attribute, $element)
    {
        $oldAttr = $this->getAttributeFromTag($oldText, $attribute);
        $newAttr = $this->getAttributeFromTag($newText, $attribute);
        if ($oldAttr !== $newAttr) {
            $cls = sprintf('diffmod diff%s diff%s', $element, $attribute);
            return $this->wrapText($oldText, 'del', $cls) . $this->wrapText($newText, 'ins', $cls);
        }
        return $this->diffElements($oldText, $newText);
    }

    protected function getAttributeFromTag($text, $attribute)
    {
        if (preg_match(sprintf('/<[^>]*\b%s\s*=\s*([\'"])(.*)\1[^>]*>/iu', $attribute), $text, $m)) return htmlspecialchars_decode($m[2]);
        return;
    }

    // ========== Tag insertion ==========

    protected function insertTag($tag, $cssClass, &$words)
    {
        while (count($words) > 0) {
            $nonTags = $this->extractConsecutiveWords($words, 'noTag');
            if ($nonTags) $this->content .= $this->wrapText(implode('', $nonTags), $tag, $cssClass);
            if (!$words) break;

            $workTag = $this->extractConsecutiveWords($words, 'tag');
            if (isset($workTag[0]) && $this->isOpeningTagFast($workTag[0]) && !$this->isClosingTagFast($workTag[0])) {
                if (strpos($workTag[0], 'class=') !== false) {
                    $workTag[0] = str_replace('class="', 'class="diffmod ', $workTag[0]);
                } else {
                    $workTag[0] = strpos($workTag[0], '/>') !== false
                        ? str_replace('/>', ' class="diffmod" />', $workTag[0])
                        : str_replace('>', ' class="diffmod">', $workTag[0]);
                }
            }
            $appendContent = implode('', $workTag);
            if (isset($workTag[0]) && stripos($workTag[0], '<img') !== false) {
                $appendContent = $this->wrapText($appendContent, $tag, $cssClass);
            }
            $this->content .= $appendContent;
        }
    }

    protected function wrapText(string $text, string $tagName, string $cssClass) : string
    {
        if (!$this->config->isSpaceMatching() && trim($text) === '') return '';
        return '<' . $tagName . ' class="' . $cssClass . '">' . $text . '</' . $tagName . '>';
    }

    /**
     * Optimized: direct array_splice, no redundant copies.
     */
    protected function extractConsecutiveWords(&$words, $condition)
    {
        $words = array_values($words);
        $isTag = ($condition === 'tag');
        $count = count($words);
        $splitAt = $count; // default: take all

        for ($i = 0; $i < $count; $i++) {
            $wIsTag = $this->isTagFast($words[$i]);
            if ($isTag !== $wIsTag) { $splitAt = $i; break; }
        }

        if ($splitAt === 0) return [];
        if ($splitAt === $count) { $items = $words; $words = []; return $items; }
        return array_splice($words, 0, $splitAt);
    }

    // ========== Placeholder type checking ==========

    public function isLinkPlaceholder($text)    { return $this->isPlaceholderType($text, 'a'); }
    public function isImagePlaceholder($text)   { return $this->isPlaceholderType($text, 'img'); }
    public function isPicturePlaceholder($text)  { return $this->isPlaceholderType($text, 'picture'); }
    protected function isListPlaceholder($text) { return $this->isPlaceholderType($text, ['ol', 'dl', 'ul']); }
    protected function isTablePlaceholder($text){ return $this->isPlaceholderType($text, 'table'); }

    protected function isPlaceholderType($text, $types)
    {
        if (!is_array($types)) $types = [$types];
        foreach ($types as $type) {
            $ph = $this->config->isIsolatedDiffTag($type) ? $this->config->getIsolatedDiffTagPlaceholder($type) : $type;
            if ($text === $ph) return true;
        }
        return false;
    }

    // ========== Matching Algorithm ==========

    protected function operations()
    {
        $positionInOld = $positionInNew = 0;
        $operations = [];
        $matches = $this->matchingBlocks();
        $matches[] = new MatchingBlock(count($this->oldWords), count($this->newWords), 0);

        foreach ($matches as $match) {
            $mOld = ($positionInOld === $match->startInOld);
            $mNew = ($positionInNew === $match->startInNew);
            if (!$mOld && !$mNew) $action = 'replace';
            elseif ($mOld && !$mNew) $action = 'insert';
            elseif (!$mOld && $mNew) $action = 'delete';
            else $action = 'none';

            if ($action !== 'none') $operations[] = new Operation($action, $positionInOld, $match->startInOld, $positionInNew, $match->startInNew);
            if (count($match) !== 0) $operations[] = new Operation('equal', $match->startInOld, $match->endInOld(), $match->startInNew, $match->endInNew());
            $positionInOld = $match->endInOld();
            $positionInNew = $match->endInNew();
        }
        return $operations;
    }

    protected function matchingBlocks()
    {
        $blocks = [];
        $this->findMatchingBlocks(0, count($this->oldWords), 0, count($this->newWords), $blocks);
        return $blocks;
    }

    protected function findMatchingBlocks(int $startInOld, int $endInOld, int $startInNew, int $endInNew, array &$blocks) : void
    {
        $match = $this->findMatch($startInOld, $endInOld, $startInNew, $endInNew);
        if ($match === null) return;
        if ($startInOld < $match->startInOld && $startInNew < $match->startInNew)
            $this->findMatchingBlocks($startInOld, $match->startInOld, $startInNew, $match->startInNew, $blocks);
        $blocks[] = $match;
        if ($match->endInOld() < $endInOld && $match->endInNew() < $endInNew)
            $this->findMatchingBlocks($match->endInOld(), $endInOld, $match->endInNew(), $endInNew, $blocks);
    }

    /**
     * Core matching: uses precomputed whitespace bitmap for O(1) whitespace checks.
     */
    protected function findMatch(int $startInOld, int $endInOld, int $startInNew, int $endInNew) : ?MatchingBlock
    {
        $groupDiffs = $this->config->isGroupDiffs();
        $bestMatchInOld = $startInOld;
        $bestMatchInNew = $startInNew;
        $bestMatchSize = 0;
        $matchLengthAt = [];

        for ($indexInOld = $startInOld; $indexInOld < $endInOld; ++$indexInOld) {
            $newMatchLengthAt = [];
            $word = $this->oldWords[$indexInOld];
            $index = $this->isTagFast($word) ? $this->stripCached($word) : $word;

            if (!isset($this->wordIndices[$index])) {
                $matchLengthAt = $newMatchLengthAt;
                continue;
            }

            foreach ($this->wordIndices[$index] as $indexInNew) {
                if ($indexInNew < $startInNew) continue;
                if ($indexInNew >= $endInNew) break;

                $newMatchLength = (isset($matchLengthAt[$indexInNew - 1]) ? $matchLengthAt[$indexInNew - 1] + 1 : 1);
                $newMatchLengthAt[$indexInNew] = $newMatchLength;

                if ($newMatchLength > $bestMatchSize ||
                    ($groupDiffs && $bestMatchSize > 0 && $this->isOnlyWhitespace($bestMatchInOld, $bestMatchSize))
                ) {
                    $bestMatchInOld = $indexInOld - $newMatchLength + 1;
                    $bestMatchInNew = $indexInNew - $newMatchLength + 1;
                    $bestMatchSize = $newMatchLength;
                }
            }
            $matchLengthAt = $newMatchLengthAt;
        }

        if ($bestMatchSize !== 0 && (!$groupDiffs || !$this->isOnlyWhitespace($bestMatchInOld, $bestMatchSize))) {
            return new MatchingBlock($bestMatchInOld, $bestMatchInNew, $bestMatchSize);
        }
        return null;
    }

    /**
     * O(k) whitespace check using precomputed bitmap. No trim() calls.
     */
    protected function isOnlyWhitespace(int $start, int $count) : bool
    {
        for ($i = $start, $end = $start + $count; $i < $end; $i++) {
            if (!$this->oldWordIsWhitespace[$i]) return false;
        }
        return true;
    }

    // Kept for API compatibility
    protected function oldTextIsOnlyWhitespace(int $start, int $count) : bool
    {
        return $this->isOnlyWhitespace($start, $count);
    }

    // ========== Fast tag detection (character-level + cache) ==========

    protected function isTagFast($item) : bool
    {
        if (isset($this->tagCache[$item])) return $this->tagCache[$item];
        $r = (isset($item[0]) && $item[0] === '<');
        $this->tagCache[$item] = $r;
        return $r;
    }

    protected function isOpeningTagFast($item) : bool
    {
        if (isset($this->openCache[$item])) return $this->openCache[$item];
        $r = (isset($item[0]) && $item[0] === '<' && (!isset($item[1]) || $item[1] !== '/'));
        $this->openCache[$item] = $r;
        return $r;
    }

    protected function isClosingTagFast($item) : bool
    {
        if (isset($this->closeCache[$item])) return $this->closeCache[$item];
        $r = (isset($item[1]) && $item[0] === '<' && $item[1] === '/');
        $this->closeCache[$item] = $r;
        return $r;
    }

    protected function isTag($item) { return $this->isTagFast($item); }
    protected function isOpeningTag($item) : bool { return $this->isOpeningTagFast($item); }
    protected function isClosingTag($item) : bool { return $this->isClosingTagFast($item); }

    protected function stripCached($word) : string
    {
        if (isset($this->stripCache[$word])) return $this->stripCache[$word];
        $r = $this->stripTagAttributes($word);
        $this->stripCache[$word] = $r;
        return $r;
    }

    protected function stripTagAttributes($word)
    {
        $space = strpos($word, ' ', 1);
        if ($space > 0) return '<' . substr($word, 1, $space) . '>';
        return trim($word, '<>');
    }

    protected function findIsolatedDiffTagsInOld($operation, $posInNew)
    {
        return $this->oldIsolatedDiffTags[$operation->startInOld + ($posInNew - $operation->startInNew)];
    }
}
