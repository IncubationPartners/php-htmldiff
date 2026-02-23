<?php

namespace Caxy\HtmlDiff;

/**
 * Class HtmlDiffConfig.
 *
 * Rewritten for performance: uses hash sets internally, lazy initialization.
 */
class HtmlDiffConfig
{
    protected $specialCaseChars = array('.', ',', '(', ')', '\'');
    protected $groupDiffs = true;
    protected $insertSpaceInReplace = false;
    protected $keepNewLines = false;
    protected $encoding = 'UTF-8';
    protected $isolatedDiffTags = array(
        'ol' => '[[REPLACE_ORDERED_LIST]]',
        'ul' => '[[REPLACE_UNORDERED_LIST]]',
        'sub' => '[[REPLACE_SUB_SCRIPT]]',
        'sup' => '[[REPLACE_SUPER_SCRIPT]]',
        'dl' => '[[REPLACE_DEFINITION_LIST]]',
        'table' => '[[REPLACE_TABLE]]',
        'strong' => '[[REPLACE_STRONG]]',
        'b' => '[[REPLACE_STRONG]]',
        'em' => '[[REPLACE_EM]]',
        'i' => '[[REPLACE_EM]]',
        'a' => '[[REPLACE_A]]',
        'img' => '[[REPLACE_IMG]]',
        'pre' => '[[REPLACE_PRE]]',
        'picture' => '[[REPLACE_PICTURE]]',
    );
    protected $matchThreshold = 80;
    protected $useTableDiffing = true;
    protected $cacheProvider;
    protected $purifierEnabled = true;
    protected $purifierCacheLocation = null;
    protected $spaceMatching = false;

    /** @var array|null Flipped placeholder set for O(1) lookup */
    private $placeholderSet = null;

    public static function create() { return new self(); }
    public function __construct() {}

    public function getMatchThreshold() { return $this->matchThreshold; }
    public function setMatchThreshold($v) { $this->matchThreshold = $v; return $this; }
    public function setSpecialCaseChars(array $chars) { $this->specialCaseChars = $chars; }
    public function getSpecialCaseChars() : array { return $this->specialCaseChars; }
    public function addSpecialCaseChar($char) { if (!in_array($char, $this->specialCaseChars)) $this->specialCaseChars[] = $char; return $this; }
    public function removeSpecialCaseChar($char) { $key = array_search($char, $this->specialCaseChars); if ($key !== false) unset($this->specialCaseChars[$key]); return $this; }
    public function setSpecialCaseTags(array $tags = array()) { return $this; }
    public function addSpecialCaseTag($tag) { return $this; }
    public function removeSpecialCaseTag($tag) { return $this; }
    public function getSpecialCaseTags() { return null; }
    public function isGroupDiffs() { return $this->groupDiffs; }
    public function setGroupDiffs($v) { $this->groupDiffs = $v; return $this; }
    public function getEncoding() { return $this->encoding; }
    public function setEncoding($v) { $this->encoding = $v; return $this; }
    public function isInsertSpaceInReplace() { return $this->insertSpaceInReplace; }
    public function setInsertSpaceInReplace($v) { $this->insertSpaceInReplace = $v; return $this; }
    public function isKeepNewLines() { return $this->keepNewLines; }
    public function setKeepNewLines($v) { $this->keepNewLines = $v; }
    public function getIsolatedDiffTags() { return $this->isolatedDiffTags; }
    public function setIsolatedDiffTags($v) { $this->isolatedDiffTags = $v; $this->placeholderSet = null; return $this; }

    public function addIsolatedDiffTag($tag, $placeholder = null) {
        if (null === $placeholder) $placeholder = sprintf('[[REPLACE_%s]]', mb_strtoupper($tag));
        if ($this->isIsolatedDiffTag($tag) && $this->isolatedDiffTags[$tag] !== $placeholder) throw new \InvalidArgumentException(sprintf('Tag "%s" exists with different placeholder', $tag));
        $matchingKey = array_search($placeholder, $this->isolatedDiffTags, true);
        if (false !== $matchingKey && $matchingKey !== $tag) throw new \InvalidArgumentException(sprintf('Placeholder used for different tag "%s"', $tag));
        if (!array_key_exists($tag, $this->isolatedDiffTags)) { $this->isolatedDiffTags[$tag] = $placeholder; $this->placeholderSet = null; }
        return $this;
    }
    public function removeIsolatedDiffTag($tag) { if ($this->isIsolatedDiffTag($tag)) { unset($this->isolatedDiffTags[$tag]); $this->placeholderSet = null; } return $this; }
    public function isIsolatedDiffTag($tag) { return array_key_exists($tag, $this->isolatedDiffTags); }

    public function isIsolatedDiffTagPlaceholder($text) {
        if ($this->placeholderSet === null) $this->placeholderSet = array_flip($this->isolatedDiffTags);
        return isset($this->placeholderSet[$text]);
    }

    public function getIsolatedDiffTagPlaceholder($tag) { return $this->isIsolatedDiffTag($tag) ? $this->isolatedDiffTags[$tag] : null; }
    public function isUseTableDiffing() { return $this->useTableDiffing; }
    public function setUseTableDiffing($v) { $this->useTableDiffing = $v; return $this; }
    public function setCacheProvider(?\Doctrine\Common\Cache\Cache $v = null) { $this->cacheProvider = $v; return $this; }
    public function getCacheProvider() { return $this->cacheProvider; }
    public function isPurifierEnabled(): bool { return $this->purifierEnabled; }
    public function setPurifierEnabled(bool $v = true): self { $this->purifierEnabled = $v; return $this; }
    public function setPurifierCacheLocation($v = null) { $this->purifierCacheLocation = $v; return $this; }
    public function getPurifierCacheLocation() { return $this->purifierCacheLocation; }
    public function isSpaceMatching() { return $this->spaceMatching; }
    public function setSpaceMatching($v) { $this->spaceMatching = $v; }
}
