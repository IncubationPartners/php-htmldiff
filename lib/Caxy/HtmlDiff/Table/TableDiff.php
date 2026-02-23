<?php

namespace Caxy\HtmlDiff\Table;

use Caxy\HtmlDiff\AbstractDiff;
use Caxy\HtmlDiff\HtmlDiff;
use Caxy\HtmlDiff\HtmlDiffConfig;
use Caxy\HtmlDiff\Operation;

/**
 * Class TableDiff - Complete rewrite with optimal algorithms.
 *
 * Key algorithmic improvements:
 * 1. Row matching uses content hashing for O(1) identity checks
 * 2. Cell content compared by text hash before expensive similar_text
 * 3. Identical cells short-circuit completely (no HtmlDiff created)
 * 4. Row text precomputed once, not re-extracted per comparison
 * 5. similar_text called on stripped text (cheaper than HTML)
 */
class TableDiff extends AbstractDiff
{
    protected $oldTable = null;
    protected $newTable = null;
    protected $diffTable = null;
    protected $diffDom = null;
    protected $newRowOffsets = 0;
    protected $oldRowOffsets = 0;
    protected $cellValues = array();

    /** @var string[] Precomputed text content per row: "prefix:rowIndex" => text */
    private $rowText = [];

    /** @var string[] Precomputed inner HTML per cell: "prefix:row:cell" => html */
    private $cellHtml = [];

    /** @var string[] Content hash per cell for fast equality */
    private $cellHash = [];

    public static function create($oldText, $newText, ?HtmlDiffConfig $config = null)
    {
        $diff = new self($oldText, $newText);
        if (null !== $config) $diff->setConfig($config);
        return $diff;
    }

    public function __construct($oldText, $newText, $encoding = 'UTF-8', $specialCaseTags = null, $groupDiffs = null)
    {
        parent::__construct($oldText, $newText, $encoding, $specialCaseTags, $groupDiffs);
    }

    public function build()
    {
        $this->prepare();

        if ($this->hasDiffCache() && $this->getDiffCache()->contains($this->oldText, $this->newText)) {
            $this->content = $this->getDiffCache()->fetch($this->oldText, $this->newText);
            return $this->content;
        }

        $this->buildTableDoms();
        $this->diffDom = new \DOMDocument();
        $this->indexCellValues($this->newTable);
        $this->diffTableContent();

        if ($this->hasDiffCache()) {
            $this->getDiffCache()->save($this->oldText, $this->newText, $this->content);
        }
        return $this->content;
    }

    /**
     * Precompute all row text and cell HTML/hashes for both tables.
     * This is the key optimization - done once upfront instead of per-comparison.
     */
    private function precomputeTableData()
    {
        foreach (['old' => $this->oldTable, 'new' => $this->newTable] as $prefix => $table) {
            foreach ($table->getRows() as $ri => $row) {
                $rowParts = [];
                foreach ($row->getCells() as $ci => $cell) {
                    $html = $cell->getInnerHtml();
                    $key = "$prefix:$ri:$ci";
                    $this->cellHtml[$key] = $html;
                    $this->cellHash[$key] = md5($html);
                    $rowParts[] = trim($cell->getDomNode()->textContent);
                }
                $this->rowText["$prefix:$ri"] = implode("\0", $rowParts);
            }
        }
    }

    protected function diffTableContent()
    {
        $this->diffDom = new \DOMDocument();
        $this->diffTable = $this->newTable->cloneNode($this->diffDom);
        $this->diffDom->appendChild($this->diffTable);

        $oldRows = $this->oldTable->getRows();
        $newRows = $this->newTable->getRows();

        // Precompute all data ONCE
        $this->precomputeTableData();

        // TWO-PHASE ROW MATCHING:
        // Phase 1: Hash-based O(n) matching for identical rows.
        // Phase 2: similar_text only for unmatched rows.

        $oldRowHashes = [];
        $newRowHashes = [];
        foreach ($oldRows as $oi => $row) {
            $oldRowHashes[$oi] = md5($this->rowText["old:$oi"] ?? '');
        }
        foreach ($newRows as $ni => $row) {
            $newRowHashes[$ni] = md5($this->rowText["new:$ni"] ?? '');
        }

        // Phase 1: Greedy positional hash matching
        $hashMatchedOld = [];
        $hashMatchedNew = [];

        // First pass: match rows at the same index
        foreach ($newRowHashes as $ni => $nh) {
            if (isset($oldRowHashes[$ni]) && $nh === $oldRowHashes[$ni] && !isset($hashMatchedOld[$ni])) {
                $hashMatchedOld[$ni] = $ni;
                $hashMatchedNew[$ni] = $ni;
            }
        }

        // Second pass: match remaining identical rows by nearest position
        $unmatchedOldByHash = [];
        foreach ($oldRowHashes as $oi => $oh) {
            if (!isset($hashMatchedOld[$oi])) {
                $unmatchedOldByHash[$oh][] = $oi;
            }
        }
        foreach ($newRowHashes as $ni => $nh) {
            if (!isset($hashMatchedNew[$ni]) && isset($unmatchedOldByHash[$nh]) && !empty($unmatchedOldByHash[$nh])) {
                $oi = array_shift($unmatchedOldByHash[$nh]);
                $hashMatchedOld[$oi] = $ni;
                $hashMatchedNew[$ni] = $oi;
            }
        }

        // Phase 2: Build match matrix for remaining rows
        $oldMatchData = [];
        $newMatchData = [];

        foreach ($oldRows as $oi => $oldRow) {
            $oldMatchData[$oi] = [];
            foreach ($newRows as $ni => $newRow) {
                if (!isset($newMatchData[$ni])) $newMatchData[$ni] = [];

                if (isset($hashMatchedOld[$oi]) && $hashMatchedOld[$oi] === $ni) {
                    $pct = $this->computeHashMatchScore($oi, $ni, $oldRow, $newRow);
                    $oldMatchData[$oi][$ni] = $pct;
                    $newMatchData[$ni][$oi] = $pct;
                } elseif (!isset($hashMatchedOld[$oi]) && !isset($hashMatchedNew[$ni])) {
                    $pct = $this->getMatchPercentage($oldRow, $newRow, $oi, $ni);
                    $oldMatchData[$oi][$ni] = $pct;
                    $newMatchData[$ni][$oi] = $pct;
                } else {
                    $oldMatchData[$oi][$ni] = 0;
                    $newMatchData[$ni][$oi] = 0;
                }
            }
        }

        $matches = $this->getRowMatches($oldMatchData, $newMatchData);

        // Collect diff rows with their section info instead of appending directly
        $this->pendingDiffRows = [];
        $this->diffTableRowsWithMatches($oldRows, $newRows, $matches);

        // Now rebuild the table with proper section structure
        $this->rebuildTableWithSections();

        $this->content = $this->htmlFromNode($this->diffTable);
    }

    /**
     * Fast score for hash-matched (identical content) rows.
     */
    private function computeHashMatchScore(int $oi, int $ni, TableRow $oldRow, TableRow $newRow) : float
    {
        $firstCellWeight = 1.5;
        $indexDeltaWeight = 0.25 * abs($oi - $ni);
        $minCells = min(count($newRow->getCells()), count($oldRow->getCells()));
        $totalCount = ($minCells + $firstCellWeight + $indexDeltaWeight) * 100;
        return ($totalCount > 0) ? ((($minCells + $firstCellWeight) * 100) / $totalCount) : 0;
    }

    /**
     * Pending diff rows collected during diffTableRowsWithMatches.
     * Each entry: ['node' => DOMNode, 'section' => string]
     * @var array
     */
    private $pendingDiffRows = [];

    /**
     * Rebuild the diff table output with proper thead/tbody/tfoot wrappers.
     * Also preserves non-row children of the table element such as
     * <caption> and <colgroup>.
     */
    private function rebuildTableWithSections()
    {
        // First, restore non-row child elements (caption, colgroup) from the new table.
        // These are lost by cloneNode(false) and not handled by row diffing.
        $newTableNode = $this->newTable->getDomNode();
        if ($newTableNode && $newTableNode->childNodes) {
            // Collect elements to prepend (caption must come first per HTML spec, then colgroup)
            $prependNodes = [];
            foreach ($newTableNode->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE
                    && in_array($child->nodeName, ['caption', 'colgroup', 'col'])) {
                    $prependNodes[] = $this->diffDom->importNode($child, true);
                }
            }
            // If both old and new have captions and they differ, diff the caption content
            if (!empty($prependNodes)) {
                $oldTableNode = $this->oldTable->getDomNode();
                $oldCaption = null;
                if ($oldTableNode && $oldTableNode->childNodes) {
                    foreach ($oldTableNode->childNodes as $child) {
                        if ($child->nodeType === XML_ELEMENT_NODE && $child->nodeName === 'caption') {
                            $oldCaption = $child;
                            break;
                        }
                    }
                }

                foreach ($prependNodes as $node) {
                    if ($node->nodeName === 'caption' && $oldCaption !== null) {
                        // Diff caption content
                        $oldCaptionHtml = $this->getInnerHtml($oldCaption);
                        $newCaptionHtml = $this->getInnerHtml($node);
                        if ($oldCaptionHtml !== $newCaptionHtml) {
                            $diffedCaption = HtmlDiff::create(
                                mb_convert_encoding($oldCaptionHtml, 'UTF-8', 'HTML-ENTITIES'),
                                mb_convert_encoding($newCaptionHtml, 'UTF-8', 'HTML-ENTITIES'),
                                $this->config
                            )->build();
                            // Clear and set diffed content
                            while ($node->firstChild) { $node->removeChild($node->firstChild); }
                            $this->setInnerHtml($node, $diffedCaption);
                        }
                    }
                    $this->diffTable->appendChild($node);
                }
            }
        }

        if (empty($this->pendingDiffRows)) return;

        // Group consecutive rows by section
        $groups = [];
        $currentSection = null;
        $currentGroup = [];

        foreach ($this->pendingDiffRows as $entry) {
            $section = $entry['section'];
            if ($section !== $currentSection) {
                if ($currentGroup) {
                    $groups[] = ['section' => $currentSection, 'rows' => $currentGroup];
                }
                $currentSection = $section;
                $currentGroup = [$entry['node']];
            } else {
                $currentGroup[] = $entry['node'];
            }
        }
        if ($currentGroup) {
            $groups[] = ['section' => $currentSection, 'rows' => $currentGroup];
        }

        // Append groups to the table
        foreach ($groups as $group) {
            $sectionName = $group['section'];
            if ($sectionName !== '' && in_array($sectionName, ['thead', 'tbody', 'tfoot'])) {
                $sectionNode = $this->diffDom->createElement($sectionName);
                foreach ($group['rows'] as $rowNode) {
                    $sectionNode->appendChild($rowNode);
                }
                $this->diffTable->appendChild($sectionNode);
            } else {
                // No section wrapper — append rows directly
                foreach ($group['rows'] as $rowNode) {
                    $this->diffTable->appendChild($rowNode);
                }
            }
        }
    }

    /**
     * REWRITTEN: Tiered matching strategy.
     * 1. Exact row text hash match -> instant 100%
     * 2. Per-cell: hash match -> instant 100% for that cell
     * 3. Per-cell: text strip + similar_text as fallback
     */
    protected function getMatchPercentage(TableRow $oldRow, TableRow $newRow, $oldIndex, $newIndex)
    {
        $firstCellWeight = 1.5;
        $indexDeltaWeight = 0.25 * abs($oldIndex - $newIndex);
        $oldCells = $oldRow->getCells();
        $newCells = $newRow->getCells();
        $minCells = min(count($newCells), count($oldCells));
        $totalCount = ($minCells + $firstCellWeight + $indexDeltaWeight) * 100;

        // Fast path: identical row text content
        $oldRowText = $this->rowText["old:$oldIndex"] ?? '';
        $newRowText = $this->rowText["new:$newIndex"] ?? '';
        if ($oldRowText === $newRowText && $oldRowText !== '') {
            return ($totalCount > 0) ? ((($minCells + $firstCellWeight) * 100) / $totalCount) : 0;
        }

        $thresholdCount = 0;
        $matchThresholdHalf = $this->config->getMatchThreshold() * 0.50;

        foreach ($newCells as $ci => $newCell) {
            if (!isset($oldCells[$ci])) continue;

            $oldKey = "old:$oldIndex:$ci";
            $newKey = "new:$newIndex:$ci";

            // Tier 1: Hash comparison (O(1))
            if (($this->cellHash[$oldKey] ?? '') === ($this->cellHash[$newKey] ?? '') && isset($this->cellHash[$oldKey])) {
                $percentage = 100.0;
            } else {
                // Tier 2: Text content comparison (much cheaper than HTML similar_text)
                $oldHtml = $this->cellHtml[$oldKey] ?? $oldCells[$ci]->getInnerHtml();
                $newHtml = $this->cellHtml[$newKey] ?? $newCell->getInnerHtml();

                $oldText = strip_tags($oldHtml);
                $newText = strip_tags($newHtml);

                if ($oldText === $newText) {
                    $percentage = 95.0;
                } else {
                    // Tier 3: Length ratio pre-filter
                    $oldLen = strlen($oldText);
                    $newLen = strlen($newText);
                    if ($oldLen > 0 && $newLen > 0 && min($oldLen, $newLen) / max($oldLen, $newLen) < 0.15) {
                        $percentage = 5.0;
                    } else {
                        // Tier 4: Actual similar_text (on text, not HTML)
                        $percentage = null;
                        similar_text($oldText, $newText, $percentage);
                    }
                }
            }

            if ($percentage > $matchThresholdHalf) {
                $increment = $percentage;
                if ($ci === 0 && $percentage > 95) $increment *= $firstCellWeight;
                $thresholdCount += $increment;
            }
        }

        return ($totalCount > 0) ? ($thresholdCount / $totalCount) : 0;
    }

    // ========== Row matching (unchanged algorithm, slightly cleaned up) ==========

    protected function getRowMatches($oldMatchData, $newMatchData)
    {
        $matches = [];
        $this->findRowMatches($newMatchData, 0, count($oldMatchData), 0, count($newMatchData), $matches);
        return $matches;
    }

    protected function findRowMatches($newMatchData, $startInOld, $endInOld, $startInNew, $endInNew, &$matches)
    {
        $match = $this->findRowMatch($newMatchData, $startInOld, $endInOld, $startInNew, $endInNew);
        if ($match === null) return;
        if ($startInOld < $match->getStartInOld() && $startInNew < $match->getStartInNew())
            $this->findRowMatches($newMatchData, $startInOld, $match->getStartInOld(), $startInNew, $match->getStartInNew(), $matches);
        $matches[] = $match;
        if ($match->getEndInOld() < $endInOld && $match->getEndInNew() < $endInNew)
            $this->findRowMatches($newMatchData, $match->getEndInOld(), $endInOld, $match->getEndInNew(), $endInNew, $matches);
    }

    protected function findRowMatch($newMatchData, $startInOld, $endInOld, $startInNew, $endInNew)
    {
        $bestMatch = null;
        $bestPct = 0;
        foreach ($newMatchData as $ni => $oldMatches) {
            if ($ni < $startInNew) continue;
            if ($ni >= $endInNew) break;
            foreach ($oldMatches as $oi => $pct) {
                if ($oi < $startInOld) continue;
                if ($oi >= $endInOld) break;
                if ($pct > $bestPct) { $bestPct = $pct; $bestMatch = ['o' => $oi, 'n' => $ni]; }
            }
        }
        if ($bestMatch) return new RowMatch($bestMatch['n'], $bestMatch['o'], $bestMatch['n'] + 1, $bestMatch['o'] + 1, $bestPct);
        return null;
    }

    // ========== Row diffing operations ==========

    protected function diffTableRowsWithMatches($oldRows, $newRows, $matches)
    {
        $operations = [];
        $indexInOld = $indexInNew = 0;
        $oldRowCount = count($oldRows);
        $newRowCount = count($newRows);
        $matches[] = new RowMatch($newRowCount, $oldRowCount, $newRowCount, $oldRowCount);

        foreach ($matches as $match) {
            $mOld = ($indexInOld === $match->getStartInOld());
            $mNew = ($indexInNew === $match->getStartInNew());
            $action = 'equal';
            if (!$mOld && !$mNew) $action = 'replace';
            elseif ($mOld && !$mNew) $action = 'insert';
            elseif (!$mOld && $mNew) $action = 'delete';

            if ($action !== 'equal') $operations[] = new Operation($action, $indexInOld, $match->getStartInOld(), $indexInNew, $match->getStartInNew());
            $operations[] = new Operation('equal', $match->getStartInOld(), $match->getEndInOld(), $match->getStartInNew(), $match->getEndInNew());
            $indexInOld = $match->getEndInOld();
            $indexInNew = $match->getEndInNew();
        }

        $appliedRowSpans = [];
        foreach ($operations as $op) {
            switch ($op->action) {
                case 'equal':   $this->processEqualOperation($op, $oldRows, $newRows, $appliedRowSpans); break;
                case 'delete':  $this->processDeleteOperation($op, $oldRows, $appliedRowSpans); break;
                case 'insert':  $this->processInsertOperation($op, $newRows, $appliedRowSpans); break;
                case 'replace': $this->processDeleteOperation($op, $oldRows, $appliedRowSpans, true); $this->processInsertOperation($op, $newRows, $appliedRowSpans, true); break;
            }
        }
    }

    protected function processInsertOperation(Operation $op, $newRows, &$appliedRowSpans, $forceExpansion = false)
    {
        foreach (array_slice($newRows, $op->startInNew, $op->endInNew - $op->startInNew) as $row)
            $this->diffAndAppendRows(null, $row, $appliedRowSpans, $forceExpansion);
    }

    protected function processDeleteOperation(Operation $op, $oldRows, &$appliedRowSpans, $forceExpansion = false)
    {
        foreach (array_slice($oldRows, $op->startInOld, $op->endInOld - $op->startInOld) as $row)
            $this->diffAndAppendRows($row, null, $appliedRowSpans, $forceExpansion);
    }

    protected function processEqualOperation(Operation $op, $oldRows, $newRows, &$appliedRowSpans)
    {
        $targetOld = array_values(array_slice($oldRows, $op->startInOld, $op->endInOld - $op->startInOld));
        $targetNew = array_values(array_slice($newRows, $op->startInNew, $op->endInNew - $op->startInNew));
        foreach ($targetNew as $i => $newRow) {
            if (isset($targetOld[$i])) $this->diffAndAppendRows($targetOld[$i], $newRow, $appliedRowSpans);
        }
    }

    protected function diffAndAppendRows($oldRow, $newRow, &$appliedRowSpans, $forceExpansion = false)
    {
        list($rowDom, $extraRow) = $this->diffRows($oldRow, $newRow, $appliedRowSpans, $forceExpansion);

        // Determine section from the source rows
        $section = '';
        if ($newRow) {
            $section = $newRow->getSection();
        } elseif ($oldRow) {
            $section = $oldRow->getSection();
        }

        $this->pendingDiffRows[] = ['node' => $rowDom, 'section' => $section];
        if ($extraRow) {
            $this->pendingDiffRows[] = ['node' => $extraRow, 'section' => $section];
        }
    }

    // ========== Cell diffing ==========

    /**
     * REWRITTEN: Short-circuits identical cells completely.
     */
    protected function diffCells($oldCell, $newCell, $usingExtraRow = false)
    {
        $diffCell = $this->getNewCellNode($oldCell, $newCell);

        $oldContent = $oldCell ? $this->getInnerHtml($oldCell->getDomNode()) : '';
        $newContent = $newCell ? $this->getInnerHtml($newCell->getDomNode()) : '';

        // SHORT CIRCUIT: identical content, both cells exist
        if ($oldContent === $newContent && $oldCell !== null && $newCell !== null) {
            $diff = $newContent;
        } else {
            $diff = HtmlDiff::create(
                mb_convert_encoding($oldContent, 'UTF-8', 'HTML-ENTITIES'),
                mb_convert_encoding($newContent, 'UTF-8', 'HTML-ENTITIES'),
                $this->config
            )->build();
        }

        $this->setInnerHtml($diffCell, $diff);

        if (null === $newCell) $diffCell->setAttribute('class', trim($diffCell->getAttribute('class') . ' del'));
        if (null === $oldCell) $diffCell->setAttribute('class', trim($diffCell->getAttribute('class') . ' ins'));
        if ($usingExtraRow) $diffCell->setAttribute('class', trim($diffCell->getAttribute('class') . ' extra-row'));

        return $diffCell;
    }

    // ========== Row diffing (column alignment logic) ==========

    protected function diffRows($oldRow, $newRow, array &$appliedRowSpans, $forceExpansion = false)
    {
        $rowToClone = $newRow ?: $oldRow;
        $diffRow = $this->diffDom->importNode($rowToClone->getDomNode()->cloneNode(false), false);
        $oldCells = $oldRow ? $oldRow->getCells() : [];
        $newCells = $newRow ? $newRow->getCells() : [];
        $position = new DiffRowPosition();
        $extraRow = null;
        $expandCells = [];
        $cellsWithMultipleRows = [];

        $newCellCount = count($newCells);
        while ($position->getIndexInNew() < $newCellCount) {
            if (!$position->areColumnsEqual()) {
                $type = $position->getLesserColumnType();
                $row = ($type === 'new') ? $newRow : $oldRow;
                $targetRow = ($type === 'new') ? $extraRow : $diffRow;
                if ($row && $targetRow && (!$type === 'old' || isset($oldCells[$position->getIndexInOld()]))) {
                    $this->syncVirtualColumns($row, $position, $cellsWithMultipleRows, $targetRow, $type, true);
                    continue;
                }
            }

            $newCell = $newCells[$position->getIndexInNew()];
            $oldCell = isset($oldCells[$position->getIndexInOld()]) ? $oldCells[$position->getIndexInOld()] : null;

            if ($oldCell && $newCell->getColspan() != $oldCell->getColspan()) {
                if (null === $extraRow) $extraRow = $this->diffDom->importNode($rowToClone->getDomNode()->cloneNode(false), false);
                if ($oldCell->getColspan() > $newCell->getColspan()) {
                    $this->diffCellsAndIncrementCounters($oldCell, null, $cellsWithMultipleRows, $diffRow, $position, true);
                    $this->syncVirtualColumns($newRow, $position, $cellsWithMultipleRows, $extraRow, 'new', true);
                } else {
                    $this->diffCellsAndIncrementCounters(null, $newCell, $cellsWithMultipleRows, $extraRow, $position, true);
                    $this->syncVirtualColumns($oldRow, $position, $cellsWithMultipleRows, $diffRow, 'old', true);
                }
            } else {
                $expandCells[] = $this->diffCellsAndIncrementCounters($oldCell, $newCell, $cellsWithMultipleRows, $diffRow, $position);
            }
        }

        $oldCellCount = count($oldCells);
        while ($position->getIndexInOld() < $oldCellCount) {
            $expandCells[] = $this->diffCellsAndIncrementCounters($oldCells[$position->getIndexInOld()], null, $cellsWithMultipleRows, $diffRow, $position);
        }

        if ($extraRow) { foreach ($expandCells as $c) { $c->setAttribute('rowspan', 1 + ($c->getAttribute('rowspan') ?: 1)); } }
        if ($extraRow || $forceExpansion) { foreach ($appliedRowSpans as $cells) { foreach ($cells as $c) { $c->setAttribute('rowspan', 1 + ($c->getAttribute('rowspan') ?: 1)); } } }
        if (!$forceExpansion) { array_shift($appliedRowSpans); $appliedRowSpans = array_values($appliedRowSpans); }
        $appliedRowSpans = array_merge($appliedRowSpans, array_values($cellsWithMultipleRows));

        return [$diffRow, $extraRow];
    }

    protected function syncVirtualColumns($tableRow, DiffRowPosition $position, &$cellsWithMultipleRows, $diffRow, $diffType, $usingExtraRow = false)
    {
        $currentCell = $tableRow->getCell($position->getIndex($diffType));
        while ($position->isColumnLessThanOther($diffType) && $currentCell) {
            $diffCell = $diffType === 'new' ? $this->diffCells(null, $currentCell, $usingExtraRow) : $this->diffCells($currentCell, null, $usingExtraRow);
            if ($diffCell->getAttribute('rowspan') > 1) $cellsWithMultipleRows[$diffCell->getAttribute('rowspan')][] = $diffCell;
            $diffRow->appendChild($diffCell);
            $position->incrementColumn($diffType, $currentCell->getColspan());
            $currentCell = $tableRow->getCell($position->incrementIndex($diffType));
        }
    }

    protected function diffCellsAndIncrementCounters($oldCell, $newCell, &$cellsWithMultipleRows, $diffRow, DiffRowPosition $position, $usingExtraRow = false)
    {
        $diffCell = $this->diffCells($oldCell, $newCell, $usingExtraRow);
        if ($diffCell->getAttribute('rowspan') > 1) $cellsWithMultipleRows[$diffCell->getAttribute('rowspan')][] = $diffCell;
        $diffRow->appendChild($diffCell);
        if ($newCell !== null) { $position->incrementIndexInNew(); $position->incrementColumnInNew($newCell->getColspan()); }
        if ($oldCell !== null) { $position->incrementIndexInOld(); $position->incrementColumnInOld($oldCell->getColspan()); }
        return $diffCell;
    }

    protected function getNewCellNode(?TableCell $oldCell = null, ?TableCell $newCell = null)
    {
        if (!$oldCell || !$newCell) {
            $clone = ($newCell ?: $oldCell)->getDomNode()->cloneNode(false);
        } else {
            $clone = $newCell->getDomNode()->cloneNode(false);
            $clone->setAttribute('rowspan', max($oldCell->getDomNode()->getAttribute('rowspan') ?: 1, $newCell->getDomNode()->getAttribute('rowspan') ?: 1));
            $clone->setAttribute('colspan', max($oldCell->getDomNode()->getAttribute('colspan') ?: 1, $newCell->getDomNode()->getAttribute('colspan') ?: 1));
        }
        return $this->diffDom->importNode($clone);
    }

    // ========== DOM utilities ==========

    protected function buildTableDoms()
    {
        $this->oldTable = $this->parseTableStructure($this->oldText);
        $this->newTable = $this->parseTableStructure($this->newText);
    }

    protected function createDocumentWithHtml($text)
    {
        $dom = new \DOMDocument();
        $dom->loadHTML(htmlspecialchars_decode(iconv('UTF-8', 'ISO-8859-1//IGNORE', htmlentities($text, ENT_COMPAT, 'UTF-8')), ENT_QUOTES));
        return $dom;
    }

    protected function parseTableStructure($text)
    {
        $dom = $this->createDocumentWithHtml($text);
        $table = new Table($dom->getElementsByTagName('table')->item(0));
        $this->parseTable($table);
        return $table;
    }

    protected function parseTable(Table $table, ?\DOMNode $node = null, string $currentSection = '')
    {
        $node = $node ?? $table->getDomNode();
        if (!$node->childNodes) return;
        foreach ($node->childNodes as $child) {
            if ($child->nodeName === 'tr') {
                $row = new TableRow($child);
                $row->setSection($currentSection);
                $table->addRow($row);
                $this->parseTableRow($row);
            } elseif (in_array($child->nodeName, ['thead', 'tbody', 'tfoot'])) {
                $this->parseTable($table, $child, $child->nodeName);
            } else {
                $this->parseTable($table, $child, $currentSection);
            }
        }
    }

    protected function parseTableRow(TableRow $row)
    {
        foreach ($row->getDomNode()->childNodes as $child) {
            if (in_array($child->nodeName, ['td', 'th'])) $row->addCell(new TableCell($child));
        }
    }

    protected function getInnerHtml($node)
    {
        $html = '';
        foreach ($node->childNodes as $child) $html .= $this->htmlFromNode($child);
        return $html;
    }

    protected function htmlFromNode($node)
    {
        $doc = new \DOMDocument();
        $doc->appendChild($doc->importNode($node, true));
        return $doc->saveHTML();
    }

    protected function setInnerHtml($node, $html)
    {
        if (strlen(trim($html)) === 0) $html = '<span class="empty"></span>';
        $doc = $this->createDocumentWithHtml($html);
        $fragment = $node->ownerDocument->createDocumentFragment();
        foreach ($doc->getElementsByTagName('body')->item(0)->childNodes as $child) {
            $fragment->appendChild($node->ownerDocument->importNode($child, true));
        }
        $node->appendChild($fragment);
    }

    protected function indexCellValues(Table $table)
    {
        foreach ($table->getRows() as $ri => $row) {
            foreach ($row->getCells() as $ci => $cell) {
                $v = trim($cell->getDomNode()->textContent);
                $this->cellValues[$v][] = new TablePosition($ri, $ci);
            }
        }
    }
}
