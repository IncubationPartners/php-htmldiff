<?php

namespace Caxy\HtmlDiff;

use Caxy\HtmlDiff\Util\MbStringUtil;
use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Class AbstractDiff - rewritten for performance.
 *
 * Key changes:
 * - Precompiled word-split regex
 * - Single-pass tokenizer
 * - Eliminated redundant string operations
 */
abstract class AbstractDiff
{
    public static $defaultSpecialCaseTags = array('strong', 'b', 'i', 'big', 'small', 'u', 'sub', 'sup', 'strike', 's', 'p');
    public static $defaultSpecialCaseChars = array('.', ',', '(', ')', '\'');
    public static $defaultGroupDiffs = true;

    protected $config;
    protected $content;
    protected $oldText;
    protected $newText;
    protected $oldWords = array();
    protected $newWords = array();
    protected $diffCaches = array();
    protected $purifier;
    protected $purifierConfig = null;
    protected $resetCache = false;
    protected $stringUtil;

    /** @var string|null Cached regex */
    private $wordRegex = null;

    public function __construct($oldText, $newText, $encoding = 'UTF-8', $specialCaseTags = null, $groupDiffs = null)
    {
        $this->stringUtil = new MbStringUtil($oldText, $newText);
        $this->setConfig(HtmlDiffConfig::create()->setEncoding($encoding));
        if ($groupDiffs !== null) $this->config->setGroupDiffs($groupDiffs);
        $this->oldText = $oldText;
        $this->newText = $newText;
        $this->content = '';
    }

    abstract public function build();

    public function initPurifier($defaultPurifierSerializerCache = null)
    {
        $cfg = $this->purifierConfig ?? HTMLPurifier_Config::createDefault();
        if (!is_null($defaultPurifierSerializerCache)) $cfg->set('Cache.SerializerPath', $defaultPurifierSerializerCache);
        $cfg->set('Cache.SerializerPermissions', 0777);
        $this->purifier = new HTMLPurifier($cfg);
    }

    protected function prepare()
    {
        if (false === $this->config->isPurifierEnabled()) return;
        $this->initPurifier($this->config->getPurifierCacheLocation());
        $this->oldText = $this->purifyHtml($this->oldText);
        $this->newText = $this->purifyHtml($this->newText);
    }

    protected function getDiffCache()
    {
        if (!$this->hasDiffCache()) return null;
        $hash = spl_object_hash($this->getConfig()->getCacheProvider());
        if (!array_key_exists($hash, $this->diffCaches)) $this->diffCaches[$hash] = new DiffCache($this->getConfig()->getCacheProvider());
        return $this->diffCaches[$hash];
    }

    protected function hasDiffCache() { return null !== $this->getConfig()->getCacheProvider(); }
    public function getConfig() { return $this->config; }
    public function setConfig(HtmlDiffConfig $config) { $this->config = $config; $this->wordRegex = null; return $this; }
    public function getMatchThreshold() { return $this->config->getMatchThreshold(); }
    public function setMatchThreshold($v) { $this->config->setMatchThreshold($v); return $this; }
    public function setSpecialCaseChars(array $chars) { $this->config->setSpecialCaseChars($chars); }
    public function getSpecialCaseChars() { return $this->config->getSpecialCaseChars(); }
    public function addSpecialCaseChar($char) { $this->config->addSpecialCaseChar($char); }
    public function removeSpecialCaseChar($char) { $this->config->removeSpecialCaseChar($char); }
    public function setSpecialCaseTags(array $tags = array()) { $this->config->setSpecialCaseChars($tags); }
    public function addSpecialCaseTag($tag) { $this->config->addSpecialCaseTag($tag); }
    public function removeSpecialCaseTag($tag) { $this->config->removeSpecialCaseTag($tag); }
    public function getSpecialCaseTags() { return null; }
    public function getOldHtml() { return $this->oldText; }
    public function getNewHtml() { return $this->newText; }
    public function getDifference() { return $this->content; }
    public function clearContent() { $this->content = null; }
    public function setGroupDiffs($boolean) { $this->config->setGroupDiffs($boolean); return $this; }
    public function isGroupDiffs() { return $this->config->isGroupDiffs(); }
    public function setHTMLPurifierConfig(HTMLPurifier_Config $config) { $this->purifierConfig = $config; }
    protected function purifyHtml($html) { return null === $this->purifier ? $html : $this->purifier->purify($html); }

    protected function splitInputsToWords()
    {
        $this->setOldWords($this->convertHtmlToListOfWords($this->oldText));
        $this->setNewWords($this->convertHtmlToListOfWords($this->newText));
    }

    protected function setOldWords(array $oldWords) { $this->resetCache = true; $this->oldWords = $oldWords; }
    protected function setNewWords(array $newWords) { $this->resetCache = true; $this->newWords = $newWords; }

    /**
     * High-performance HTML tokenizer.
     * Compiles word-split regex once, uses efficient array operations.
     */
    protected function convertHtmlToListOfWords(string $text) : array
    {
        $words = [];

        // Normalize no-break-spaces
        $text = str_replace("\xc2\xa0", ' ', $text);

        // Build regex once
        if ($this->wordRegex === null) {
            $sc = '';
            foreach ($this->config->getSpecialCaseChars() as $char) $sc .= '\\' . $char;
            $this->wordRegex = sprintf('/\s|[%s]|[a-zA-Z0-9%s\pL]+[a-zA-Z0-9\pL]|[^\s]/mu', $sc, $sc);
        }

        preg_match_all('/<.+?>|[^<]+/mus', $text, $segments, PREG_SPLIT_NO_EMPTY);

        $keepNewLines = $this->config->isKeepNewLines();

        foreach ($segments[0] as $segment) {
            if ($segment === '') continue;

            if ($segment[0] === '<') {
                $words[] = $segment;
                continue;
            }

            // Normalize whitespace
            if (!$keepNewLines) {
                $segment = preg_replace('/\s\s+|\r+|\n+|\r\n+/', ' ', $segment);
                $len = strlen($segment);
                if ($len > 0) {
                    $first = $segment[0];
                    if ($first === ' ' || $first === "\r" || $first === "\n") $segment = ' ' . ltrim($segment);
                    if ($len > 1) {
                        $last = $segment[strlen($segment) - 1];
                        if ($last === ' ' || $last === "\r" || $last === "\n") $segment = rtrim($segment) . ' ';
                    }
                }
            }

            preg_match_all($this->wordRegex, $segment . ' ', $m, PREG_SPLIT_NO_EMPTY);
            $matched = $m[0];
            array_pop($matched); // Remove trailing space we added
            if ($matched) array_push($words, ...$matched);
        }

        return $words;
    }

    protected function normalizeWhitespaceInHtmlSentence(string $sentence) : string
    {
        if ($this->config->isKeepNewLines()) return $sentence;
        $sentence = preg_replace('/\s\s+|\r+|\n+|\r\n+/', ' ', $sentence);
        $sentenceLength = $this->stringUtil->strlen($sentence);
        $firstCharacter = $this->stringUtil->substr($sentence, 0, 1);
        $lastCharacter  = $this->stringUtil->substr($sentence, $sentenceLength -1, 1);
        if ($firstCharacter === ' ' || $firstCharacter === "\r" || $firstCharacter === "\n") $sentence = ' ' . ltrim($sentence);
        if ($sentenceLength > 1 && ($lastCharacter === ' ' || $lastCharacter === "\r" || $lastCharacter === "\n")) $sentence = rtrim($sentence) . ' ';
        return $sentence;
    }
}
