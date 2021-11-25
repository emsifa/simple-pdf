<?php

namespace Emsifa\SimplePdf;

use Exception;

class SimplePdf
{
    const STATE_NO_PAGE = 0;
    const STATE_BEGIN_PAGE = 2;
    const STATE_END_PAGE = 1;
    const STATE_END_DOCUMENT = 3;

    const STYLE_BOLD = 'B';

    const ORIENTATION_PORTRAIT = 'P';
    const ORIENTATION_LANDSCAPE = 'L';

    const UNIT_PT = 'pt';
    const UNIT_MM = 'mm';
    const UNIT_CM = 'cm';
    const UNIT_INCH = 'in';

    protected int $page = 0; // current page number
    protected int $objectNumber = 0; // current object number
    protected array $offsets = []; // array of object offsets
    protected string $buffer = ''; // buffer holding in-memory PDF
    protected array $pages = []; // array containing pages
    protected int $state = 0; // current document state
    protected bool $compress = false; // compression flag
    protected float $scaleFactor = 0; // scale factor (number of points in user unit)
    protected string $defaultOrientation = 'P'; // default orientation
    protected string $currentOrientation = 'P'; // current orientation
    protected array $standardPageSize = []; // standard page sizes
    protected array $defaultPageSize = []; // default page size
    protected array $currentPageSize = []; // current page size
    protected float $currentRotation = 0; // current page rotation
    protected array $pageInfo = []; // page-related data
    protected float $widthPt = 0;
    protected float $heightPt = 0; // dimensions of current page in points
    protected float $width = 0;
    protected float $height = 0; // dimensions of current page in user unit
    protected float $leftMargin = 0; // left margin
    protected float $topMargin = 0; // top margin
    protected float $rightMargin = 0; // right margin
    protected float $bottomMargin = 0; // page break margin
    protected float $cellMargin = 0; // cell margin
    protected float $x = 0;
    protected float $y = 0; // current position in user unit
    protected float $lastHeight = 0; // height of last printed cell
    protected float $lineWidth = 0; // line width in user unit
    protected string $fontpath = ''; // path containing fonts
    protected array $coreFonts = ['courier', 'helvetica', 'times', 'symbol', 'zapfdingbats']; // array of core font names
    protected array $fonts = []; // array of used fonts
    protected array $fontFiles = []; // array of font files
    protected array $encodings = []; // array of encodings
    protected array $cmaps = []; // array of ToUnicode CMaps
    protected string $fontFamily = ''; // current font family
    protected string $fontStyle = ''; // current font style
    protected bool $underline = false; // underlining flag
    protected array $currentFont = []; // current font info
    protected float $fontSizePt = 12; // current font size in points
    protected float $fontSize = 0; // current font size in user unit
    protected string $drawColor = '0 G'; // commands for drawing color
    protected string $fillColor = '0 g'; // commands for filling color
    protected string $textColor = '0 g'; // commands for text color
    protected bool $colorFlag = false; // indicates whether fill and text colors are different
    protected bool $withAlpha = false; // indicates whether alpha channel is used
    protected float $wordSpacing = 0; // word spacing
    protected array $images = []; // array of used images
    protected array $pageLinks = []; // array of links in pages
    protected array $links = []; // array of internal links
    protected bool $autoPageBreak = true; // automatic page breaking
    protected float $pageBreakTrigger = 0; // threshold used to trigger page breaks
    protected bool $inHeader = false; // flag set when processing header
    protected bool $inFooter = false; // flag set when processing footer
    protected string $aliasNbPages = ''; // alias for total number of pages
    protected string $zoomMode = ''; // zoom display mode
    protected string $layoutMode = ''; // layout display mode
    protected array $metadata = []; // document properties
    protected string $pdfVersion = '1.3'; // PDF version number
    protected string $producer = "SimplePDF";

    public function __construct($orientation = 'P', $unit = 'mm', $size = 'A4')
    {
        // Some checks
        $this->doChecks();

        $this->objectNumber = 2;
        $this->fontpath = __DIR__ . '/fonts/';

        $this->scaleFactor = match ($unit) {
            static::UNIT_PT => 1,
            static::UNIT_MM => 72 / 25.4,
            static::UNIT_CM => 72 / 2.54,
            static::UNIT_INCH => 72,
            default => $this->error("Incorrect unit: {$unit}"),
        };

        // Page sizes
        $this->standardPageSize = [
            'a3' => [841.89, 1190.55],
            'a4' => [595.28, 841.89],
            'a5' => [420.94, 595.28],
            'letter' => [612, 792],
            'legal' => [612, 1008],
        ];

        $size = $this->getPageSize($size);
        $this->defaultPageSize = $size;
        $this->currentPageSize = $size;

        [$this->width, $this->height] = match ($orientation) {
            static::ORIENTATION_PORTRAIT => [$size[0], $size[1]],
            static::ORIENTATION_LANDSCAPE => [$size[1], $size[0]],
            default => $this->error("Incorrect orientation: {$orientation}"),
        };

        $this->defaultOrientation = $orientation;
        $this->currentOrientation = $this->defaultOrientation;
        $this->widthPt = $this->width * $this->scaleFactor;
        $this->heightPt = $this->height * $this->scaleFactor;
        // Page rotation
        $this->currentRotation = 0;
        // Page margins (1 cm)
        $margin = 28.35 / $this->scaleFactor;
        $this->setMargins($margin, $margin);
        // Interior cell margin (1 mm)
        $this->cellMargin = $margin / 10;
        // Line width (0.2 mm)
        $this->lineWidth = .567 / $this->scaleFactor;
        // Automatic page break
        $this->setAutoPageBreak(true, 2 * $margin);
        // Default display mode
        $this->setDisplayMode('default');
        // Enable compression
        $this->setCompression(true);
        // Set default PDF version number
        $this->pdfVersion = '1.3';

        $this->addPage();
        $this->setFont('arial');
    }

    public function setMargins($left, $top, $right = null)
    {
        // Set left, top and right margins
        $this->leftMargin = $left;
        $this->topMargin = $top;
        if ($right === null) {
            $right = $left;
        }
        $this->rightMargin = $right;
    }

    public function setLeftMargin($margin)
    {
        // Set left margin
        $this->leftMargin = $margin;
        if ($this->page > 0 && $this->x < $margin) {
            $this->x = $margin;
        }
    }

    public function setTopMargin($margin)
    {
        // Set top margin
        $this->topMargin = $margin;
    }

    public function setRightMargin($margin)
    {
        // Set right margin
        $this->rightMargin = $margin;
    }

    public function setAutoPageBreak($auto, $margin = 0)
    {
        // Set auto page break mode and triggering margin
        $this->autoPageBreak = $auto;
        $this->bottomMargin = $margin;
        $this->pageBreakTrigger = $this->height - $margin;
    }

    public function setDisplayMode($zoom, $layout = 'default')
    {
        // Set display mode in viewer
        if ($zoom == 'fullpage' || $zoom == 'fullwidth' || $zoom == 'real' || $zoom == 'default' || !is_string($zoom)) {
            $this->zoomMode = $zoom;
        } else {
            $this->error('Incorrect zoom display mode: ' . $zoom);
        }
        if ($layout == 'single' || $layout == 'continuous' || $layout == 'two' || $layout == 'default') {
            $this->layoutMode = $layout;
        } else {
            $this->error('Incorrect layout display mode: ' . $layout);
        }
    }

    public function setCompression($compress)
    {
        // Set page compression
        if (function_exists('gzcompress')) {
            $this->compress = $compress;
        } else {
            $this->compress = false;
        }
    }

    public function setTitle($title, $isUTF8 = false)
    {
        // Title of document
        $this->metadata['Title'] = $isUTF8 ? $title : utf8_encode($title);
    }

    public function setAuthor($author, $isUTF8 = false)
    {
        // Author of document
        $this->metadata['Author'] = $isUTF8 ? $author : utf8_encode($author);
    }

    public function setSubject($subject, $isUTF8 = false)
    {
        // Subject of document
        $this->metadata['Subject'] = $isUTF8 ? $subject : utf8_encode($subject);
    }

    public function setKeywords($keywords, $isUTF8 = false)
    {
        // Keywords of document
        $this->metadata['Keywords'] = $isUTF8 ? $keywords : utf8_encode($keywords);
    }

    public function setCreator($creator, $isUTF8 = false)
    {
        // Creator of document
        $this->metadata['Creator'] = $isUTF8 ? $creator : utf8_encode($creator);
    }

    public function aliasNbPages($alias = '{nb}')
    {
        // Define an alias for total number of pages
        $this->aliasNbPages = $alias;
    }

    public function error($msg)
    {
        throw new Exception('SimplePDF Error: ' . $msg);
    }

    public function close()
    {
        // Terminate document
        if ($this->state == 3) {
            return;
        }
        if ($this->page == 0) {
            $this->addPage();
        }
        // Page footer
        $this->inFooter = true;
        $this->footer();
        $this->inFooter = false;
        // Close page
        $this->endPage();
        // Close document
        $this->endDoc();
    }

    public function addPage($orientation = '', $size = '', $rotation = 0)
    {
        // Start a new page
        if ($this->state == 3) {
            $this->error('The document is closed');
        }
        $family = $this->fontFamily;
        $style = $this->fontStyle . ($this->underline ? 'U' : '');
        $fontsize = $this->fontSizePt;
        $lw = $this->lineWidth;
        $dc = $this->drawColor;
        $fc = $this->fillColor;
        $tc = $this->textColor;
        $cf = $this->colorFlag;
        if ($this->page > 0) {
            // Page footer
            $this->inFooter = true;
            $this->footer();
            $this->inFooter = false;
            // Close page
            $this->endPage();
        }
        // Start new page
        $this->_beginpage($orientation, $size, $rotation);
        // Set line cap style to square
        $this->writeOutput('2 J');
        // Set line width
        $this->lineWidth = $lw;
        $this->writeOutput(sprintf('%.2F w', $lw * $this->scaleFactor));
        // Set font
        if ($family) {
            $this->setFont($family, $style, $fontsize);
        }
        // Set colors
        $this->drawColor = $dc;
        if ($dc != '0 G') {
            $this->writeOutput($dc);
        }
        $this->fillColor = $fc;
        if ($fc != '0 g') {
            $this->writeOutput($fc);
        }
        $this->textColor = $tc;
        $this->colorFlag = $cf;
        // Page header
        $this->inHeader = true;
        $this->header();
        $this->inHeader = false;
        // Restore line width
        if ($this->lineWidth != $lw) {
            $this->lineWidth = $lw;
            $this->writeOutput(sprintf('%.2F w', $lw * $this->scaleFactor));
        }
        // Restore font
        if ($family) {
            $this->setFont($family, $style, $fontsize);
        }
        // Restore colors
        if ($this->drawColor != $dc) {
            $this->drawColor = $dc;
            $this->writeOutput($dc);
        }
        if ($this->fillColor != $fc) {
            $this->fillColor = $fc;
            $this->writeOutput($fc);
        }
        $this->textColor = $tc;
        $this->colorFlag = $cf;
    }

    public function header()
    {
        // To be implemented in your own inherited class
    }

    public function footer()
    {
        // To be implemented in your own inherited class
    }

    public function pageNo()
    {
        // Get current page number
        return $this->page;
    }

    public function setDrawColor($r, $g = null, $b = null)
    {
        // Set color for all stroking operations
        if (($r == 0 && $g == 0 && $b == 0) || $g === null) {
            $this->drawColor = sprintf('%.3F G', $r / 255);
        } else {
            $this->drawColor = sprintf('%.3F %.3F %.3F RG', $r / 255, $g / 255, $b / 255);
        }
        if ($this->page > 0) {
            $this->writeOutput($this->drawColor);
        }
    }

    public function setFillColor($r, $g = null, $b = null)
    {
        // Set color for all filling operations
        if (($r == 0 && $g == 0 && $b == 0) || $g === null) {
            $this->fillColor = sprintf('%.3F g', $r / 255);
        } else {
            $this->fillColor = sprintf('%.3F %.3F %.3F rg', $r / 255, $g / 255, $b / 255);
        }
        $this->colorFlag = ($this->fillColor != $this->textColor);
        if ($this->page > 0) {
            $this->writeOutput($this->fillColor);
        }
    }

    public function setTextColor($r, $g = null, $b = null)
    {
        // Set color for text
        if (($r == 0 && $g == 0 && $b == 0) || $g === null) {
            $this->textColor = sprintf('%.3F g', $r / 255);
        } else {
            $this->textColor = sprintf('%.3F %.3F %.3F rg', $r / 255, $g / 255, $b / 255);
        }
        $this->colorFlag = ($this->fillColor != $this->textColor);
    }

    public function getStringWidth($s)
    {
        // Get width of a string in the current font
        $s = (string)$s;
        $cw = &$this->currentFont['cw'];
        $w = 0;
        $l = strlen($s);
        for ($i = 0; $i < $l; $i++) {
            $w += $cw[$s[$i]];
        }
        return $w * $this->fontSize / 1000;
    }

    public function setLineWidth($width)
    {
        // Set line width
        $this->lineWidth = $width;
        if ($this->page > 0) {
            $this->writeOutput(sprintf('%.2F w', $width * $this->scaleFactor));
        }
    }

    public function line($x1, $y1, $x2, $y2)
    {
        // Draw a line
        $this->writeOutput(sprintf('%.2F %.2F m %.2F %.2F l S', $x1 * $this->scaleFactor, ($this->height - $y1) * $this->scaleFactor, $x2 * $this->scaleFactor, ($this->height - $y2) * $this->scaleFactor));
    }

    public function rect($x, $y, $w, $h, $style = '')
    {
        // Draw a rectangle
        if ($style == 'F') {
            $op = 'f';
        } elseif ($style == 'FD' || $style == 'DF') {
            $op = static::STYLE_BOLD;
        } else {
            $op = 'S';
        }
        $this->writeOutput(sprintf('%.2F %.2F %.2F %.2F re %s', $x * $this->scaleFactor, ($this->height - $y) * $this->scaleFactor, $w * $this->scaleFactor, -$h * $this->scaleFactor, $op));
    }

    public function addFont($family, $style = '', $file = '')
    {
        // Add a TrueType, OpenType or Type1 font
        $family = strtolower($family);
        if ($file == '') {
            $file = str_replace(' ', '', $family) . strtolower($style) . '.php';
        }
        $style = strtoupper($style);
        if ($style == 'IB') {
            $style = 'BI';
        }
        $fontkey = $family . $style;
        if (isset($this->fonts[$fontkey])) {
            return;
        }
        $info = $this->loadFont($file);
        $info['i'] = count($this->fonts) + 1;
        if (!empty($info['file'])) {
            // Embedded font
            if ($info['type'] == 'TrueType') {
                $this->fontFiles[$info['file']] = array('length1' => $info['originalsize']);
            } else {
                $this->fontFiles[$info['file']] = array('length1' => $info['size1'], 'length2' => $info['size2']);
            }
        }
        $this->fonts[$fontkey] = $info;
    }

    public function setFont($family, $style = '', $size = 0)
    {
        // Select a font; size given in points
        if ($family == '') {
            $family = $this->fontFamily;
        } else {
            $family = strtolower($family);
        }
        $style = strtoupper($style);
        if (strpos($style, 'U') !== false) {
            $this->underline = true;
            $style = str_replace('U', '', $style);
        } else {
            $this->underline = false;
        }
        if ($style == 'IB') {
            $style = 'BI';
        }
        if ($size == 0) {
            $size = $this->fontSizePt;
        }
        // Test if font is already selected
        if ($this->fontFamily == $family && $this->fontStyle == $style && $this->fontSizePt == $size) {
            return;
        }
        // Test if font is already loaded
        $fontkey = $family . $style;
        if (!isset($this->fonts[$fontkey])) {
            // Test if one of the core fonts
            if ($family == 'arial') {
                $family = 'helvetica';
            }
            if (in_array($family, $this->coreFonts)) {
                if ($family == 'symbol' || $family == 'zapfdingbats') {
                    $style = '';
                }
                $fontkey = $family . $style;
                if (!isset($this->fonts[$fontkey])) {
                    $this->addFont($family, $style);
                }
            } else {
                $this->error('Undefined font: ' . $family . ' ' . $style);
            }
        }
        // Select it
        $this->fontFamily = $family;
        $this->fontStyle = $style;
        $this->fontSizePt = $size;
        $this->fontSize = $size / $this->scaleFactor;
        $this->currentFont = &$this->fonts[$fontkey];
        if ($this->page > 0) {
            $this->writeOutput(sprintf('BT /F%d %.2F Tf ET', $this->currentFont['i'], $this->fontSizePt));
        }
    }

    public function setFontSize($size)
    {
        // Set font size in points
        if ($this->fontSizePt == $size) {
            return;
        }
        $this->fontSizePt = $size;
        $this->fontSize = $size / $this->scaleFactor;
        if ($this->page > 0) {
            $this->writeOutput(sprintf('BT /F%d %.2F Tf ET', $this->currentFont['i'], $this->fontSizePt));
        }
    }

    public function addLink()
    {
        // Create a new internal link
        $n = count($this->links) + 1;
        $this->links[$n] = array(0, 0);
        return $n;
    }

    public function setLink($link, $y = 0, $page = -1)
    {
        // Set destination of internal link
        if ($y == -1) {
            $y = $this->y;
        }
        if ($page == -1) {
            $page = $this->page;
        }
        $this->links[$link] = array($page, $y);
    }

    public function link($x, $y, $w, $h, $link)
    {
        // Put a link on the page
        $this->pageLinks[$this->page][] = array($x * $this->scaleFactor, $this->heightPt - $y * $this->scaleFactor, $w * $this->scaleFactor, $h * $this->scaleFactor, $link);
    }

    public function getDefaultTextStyle(): TextStyle
    {
        return new TextStyle(
            color: $this->textColor,
            size: $this->fontSize,
            underline: $this->underline,
        );
    }

    public function writeText(?string $text, float $x = 0, float $y = 0, ?TextStyle $style = null)
    {
        if (is_null($text)) {
            $text = "";
        }

        $defaultStyle = $this->getDefaultTextStyle();
        $style = $style ? $style->merge($defaultStyle) : $defaultStyle;

        $this->setFontSize($style->getSize());

        $s = sprintf(
            'BT %.2F %.2F Td (%s) Tj ET',
            $x * $this->scaleFactor,
            ($this->height - $y) * $this->scaleFactor,
            $this->_escape($text),
        );

        if ($style->getUnderline() && $text != '') {
            $s .= ' ' . $this->makeUnderline($x, $y, $text);
        }

        $s = 'q ' . $style->getColor() . ' ' . $s . ' Q';

        $this->writeOutput($s);
    }

    public function text($x, $y, $txt)
    {
        // Output a string
        if (!isset($this->currentFont)) {
            $this->error('No font has been set');
        }
        $s = sprintf('BT %.2F %.2F Td (%s) Tj ET', $x * $this->scaleFactor, ($this->height - $y) * $this->scaleFactor, $this->_escape($txt));
        if ($this->underline && $txt != '') {
            $s .= ' ' . $this->makeUnderline($x, $y, $txt);
        }
        if ($this->colorFlag) {
            $s = 'q ' . $this->textColor . ' ' . $s . ' Q';
        }
        $this->writeOutput($s);
    }

    public function acceptPageBreak()
    {
        // Accept automatic page break or not
        return $this->autoPageBreak;
    }

    public function cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '')
    {
        // Output a cell
        $k = $this->scaleFactor;
        if ($this->y + $h > $this->pageBreakTrigger && !$this->inHeader && !$this->inFooter && $this->acceptPageBreak()) {
            // Automatic page break
            $x = $this->x;
            $ws = $this->wordSpacing;
            if ($ws > 0) {
                $this->wordSpacing = 0;
                $this->writeOutput('0 Tw');
            }
            $this->addPage($this->currentOrientation, $this->currentPageSize, $this->currentRotation);
            $this->x = $x;
            if ($ws > 0) {
                $this->wordSpacing = $ws;
                $this->writeOutput(sprintf('%.3F Tw', $ws * $k));
            }
        }
        if ($w == 0) {
            $w = $this->width - $this->rightMargin - $this->x;
        }
        $s = '';
        if ($fill || $border == 1) {
            if ($fill) {
                $op = ($border == 1) ? static::STYLE_BOLD : 'f';
            } else {
                $op = 'S';
            }
            $s = sprintf('%.2F %.2F %.2F %.2F re %s ', $this->x * $k, ($this->height - $this->y) * $k, $w * $k, -$h * $k, $op);
        }
        if (is_string($border)) {
            $x = $this->x;
            $y = $this->y;
            if (strpos($border, 'L') !== false) {
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x * $k, ($this->height - $y) * $k, $x * $k, ($this->height - ($y + $h)) * $k);
            }
            if (strpos($border, 'T') !== false) {
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x * $k, ($this->height - $y) * $k, ($x + $w) * $k, ($this->height - $y) * $k);
            }
            if (strpos($border, 'R') !== false) {
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', ($x + $w) * $k, ($this->height - $y) * $k, ($x + $w) * $k, ($this->height - ($y + $h)) * $k);
            }
            if (strpos($border, static::STYLE_BOLD) !== false) {
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x * $k, ($this->height - ($y + $h)) * $k, ($x + $w) * $k, ($this->height - ($y + $h)) * $k);
            }
        }
        if ($txt !== '') {
            if (!isset($this->currentFont)) {
                $this->error('No font has been set');
            }
            if ($align == 'R') {
                $dx = $w - $this->cellMargin - $this->getStringWidth($txt);
            } elseif ($align == 'C') {
                $dx = ($w - $this->getStringWidth($txt)) / 2;
            } else {
                $dx = $this->cellMargin;
            }
            if ($this->colorFlag) {
                $s .= 'q ' . $this->textColor . ' ';
            }
            $s .= sprintf('BT %.2F %.2F Td (%s) Tj ET', ($this->x + $dx) * $k, ($this->height - ($this->y + .5 * $h + .3 * $this->fontSize)) * $k, $this->_escape($txt));
            if ($this->underline) {
                $s .= ' ' . $this->makeUnderline($this->x + $dx, $this->y + .5 * $h + .3 * $this->fontSize, $txt);
            }
            if ($this->colorFlag) {
                $s .= ' Q';
            }
            if ($link) {
                $this->link($this->x + $dx, $this->y + .5 * $h - .5 * $this->fontSize, $this->getStringWidth($txt), $this->fontSize, $link);
            }
        }
        if ($s) {
            $this->writeOutput($s);
        }
        $this->lastHeight = $h;
        if ($ln > 0) {
            // Go to next line
            $this->y += $h;
            if ($ln == 1) {
                $this->x = $this->leftMargin;
            }
        } else {
            $this->x += $w;
        }
    }

    public function multiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false)
    {
        // Output text with automatic or explicit line breaks
        if (!isset($this->currentFont)) {
            $this->error('No font has been set');
        }
        $cw = &$this->currentFont['cw'];
        if ($w == 0) {
            $w = $this->width - $this->rightMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cellMargin) * 1000 / $this->fontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") {
            $nb--;
        }
        $b = 0;
        if ($border) {
            if ($border == 1) {
                $border = 'LTRB';
                $b = 'LRT';
                $b2 = 'LR';
            } else {
                $b2 = '';
                if (strpos($border, 'L') !== false) {
                    $b2 .= 'L';
                }
                if (strpos($border, 'R') !== false) {
                    $b2 .= 'R';
                }
                $b = (strpos($border, 'T') !== false) ? $b2 . 'T' : $b2;
            }
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $ns = 0;
        $nl = 1;
        while ($i < $nb) {
            // Get next character
            $c = $s[$i];
            if ($c == "\n") {
                // Explicit line break
                if ($this->wordSpacing > 0) {
                    $this->wordSpacing = 0;
                    $this->writeOutput('0 Tw');
                }
                $this->cell($w, $h, substr($s, $j, $i - $j), $b, 2, $align, $fill);
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $ns = 0;
                $nl++;
                if ($border && $nl == 2) {
                    $b = $b2;
                }
                continue;
            }
            if ($c == ' ') {
                $sep = $i;
                $ls = $l;
                $ns++;
            }
            $l += $cw[$c];
            if ($l > $wmax) {
                // Automatic line break
                if ($sep == -1) {
                    if ($i == $j) {
                        $i++;
                    }
                    if ($this->wordSpacing > 0) {
                        $this->wordSpacing = 0;
                        $this->writeOutput('0 Tw');
                    }
                    $this->cell($w, $h, substr($s, $j, $i - $j), $b, 2, $align, $fill);
                } else {
                    if ($align == 'J') {
                        $this->wordSpacing = ($ns > 1) ? ($wmax - $ls) / 1000 * $this->fontSize / ($ns - 1) : 0;
                        $this->writeOutput(sprintf('%.3F Tw', $this->wordSpacing * $this->scaleFactor));
                    }
                    $this->cell($w, $h, substr($s, $j, $sep - $j), $b, 2, $align, $fill);
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $ns = 0;
                $nl++;
                if ($border && $nl == 2) {
                    $b = $b2;
                }
            } else {
                $i++;
            }
        }
        // Last chunk
        if ($this->wordSpacing > 0) {
            $this->wordSpacing = 0;
            $this->writeOutput('0 Tw');
        }
        if ($border && strpos($border, static::STYLE_BOLD) !== false) {
            $b .= static::STYLE_BOLD;
        }
        $this->cell($w, $h, substr($s, $j, $i - $j), $b, 2, $align, $fill);
        $this->x = $this->leftMargin;
    }

    public function write($h, $txt, $link = '')
    {
        // Output text in flowing mode
        if (!isset($this->currentFont)) {
            $this->error('No font has been set');
        }
        $cw = &$this->currentFont['cw'];
        $w = $this->width - $this->rightMargin - $this->x;
        $wmax = ($w - 2 * $this->cellMargin) * 1000 / $this->fontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            // Get next character
            $c = $s[$i];
            if ($c == "\n") {
                // Explicit line break
                $this->cell($w, $h, substr($s, $j, $i - $j), 0, 2, '', false, $link);
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                if ($nl == 1) {
                    $this->x = $this->leftMargin;
                    $w = $this->width - $this->rightMargin - $this->x;
                    $wmax = ($w - 2 * $this->cellMargin) * 1000 / $this->fontSize;
                }
                $nl++;
                continue;
            }
            if ($c == ' ') {
                $sep = $i;
            }
            $l += $cw[$c];
            if ($l > $wmax) {
                // Automatic line break
                if ($sep == -1) {
                    if ($this->x > $this->leftMargin) {
                        // Move to next line
                        $this->x = $this->leftMargin;
                        $this->y += $h;
                        $w = $this->width - $this->rightMargin - $this->x;
                        $wmax = ($w - 2 * $this->cellMargin) * 1000 / $this->fontSize;
                        $i++;
                        $nl++;
                        continue;
                    }
                    if ($i == $j) {
                        $i++;
                    }
                    $this->cell($w, $h, substr($s, $j, $i - $j), 0, 2, '', false, $link);
                } else {
                    $this->cell($w, $h, substr($s, $j, $sep - $j), 0, 2, '', false, $link);
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                if ($nl == 1) {
                    $this->x = $this->leftMargin;
                    $w = $this->width - $this->rightMargin - $this->x;
                    $wmax = ($w - 2 * $this->cellMargin) * 1000 / $this->fontSize;
                }
                $nl++;
            } else {
                $i++;
            }
        }
        // Last chunk
        if ($i != $j) {
            $this->cell($l / 1000 * $this->fontSize, $h, substr($s, $j), 0, 0, '', false, $link);
        }
    }

    public function ln($h = null)
    {
        // Line feed; default value is the last cell height
        $this->x = $this->leftMargin;
        if ($h === null) {
            $this->y += $this->lastHeight;
        } else {
            $this->y += $h;
        }
    }

    public function image($file, $x = null, $y = null, $w = 0, $h = 0, $type = '', $link = '')
    {
        // Put an image on the page
        if ($file == '') {
            $this->error('Image file name is empty');
        }
        if (!isset($this->images[$file])) {
            // First use of this image, get info
            if ($type == '') {
                $pos = strrpos($file, '.');
                if (!$pos) {
                    $this->error('Image file has no extension and no type was specified: ' . $file);
                }
                $type = substr($file, $pos + 1);
            }
            $type = strtolower($type);
            if ($type == 'jpeg') {
                $type = 'jpg';
            }
            $mtd = 'parse' . $type;
            if (!method_exists($this, $mtd)) {
                $this->error('Unsupported image type: ' . $type);
            }
            $info = $this->$mtd($file);
            $info['i'] = count($this->images) + 1;
            $this->images[$file] = $info;
        } else {
            $info = $this->images[$file];
        }

        // Automatic width and height calculation if needed
        if ($w == 0 && $h == 0) {
            // Put image at 96 dpi
            $w = -96;
            $h = -96;
        }
        if ($w < 0) {
            $w = -$info['w'] * 72 / $w / $this->scaleFactor;
        }
        if ($h < 0) {
            $h = -$info['h'] * 72 / $h / $this->scaleFactor;
        }
        if ($w == 0) {
            $w = $h * $info['w'] / $info['h'];
        }
        if ($h == 0) {
            $h = $w * $info['h'] / $info['w'];
        }

        // Flowing mode
        if ($y === null) {
            if ($this->y + $h > $this->pageBreakTrigger && !$this->inHeader && !$this->inFooter && $this->acceptPageBreak()) {
                // Automatic page break
                $x2 = $this->x;
                $this->addPage($this->currentOrientation, $this->currentPageSize, $this->currentRotation);
                $this->x = $x2;
            }
            $y = $this->y;
            $this->y += $h;
        }

        if ($x === null) {
            $x = $this->x;
        }
        $this->writeOutput(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /I%d Do Q', $w * $this->scaleFactor, $h * $this->scaleFactor, $x * $this->scaleFactor, ($this->height - ($y + $h)) * $this->scaleFactor, $info['i']));
        if ($link) {
            $this->link($x, $y, $w, $h, $link);
        }
    }

    public function getPageWidth()
    {
        // Get current page width
        return $this->width;
    }

    public function getPageHeight()
    {
        // Get current page height
        return $this->height;
    }

    public function getX()
    {
        // Get x position
        return $this->x;
    }

    public function setX($x)
    {
        // Set x position
        if ($x >= 0) {
            $this->x = $x;
        } else {
            $this->x = $this->width + $x;
        }
    }

    public function getY()
    {
        // Get y position
        return $this->y;
    }

    public function setY($y, $resetX = true)
    {
        // Set y position and optionally reset x
        if ($y >= 0) {
            $this->y = $y;
        } else {
            $this->y = $this->height + $y;
        }
        if ($resetX) {
            $this->x = $this->leftMargin;
        }
    }

    public function setXY($x, $y)
    {
        // Set x and y positions
        $this->setX($x);
        $this->setY($y, false);
    }

    public function stream(string $name, bool $isUTF8 = false)
    {
        $this->close();
        $this->checkOutput();
        if (PHP_SAPI != 'cli') {
            // We send to a browser
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; ' . $this->httpEncode('filename', $name, $isUTF8));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
        }
        echo $this->buffer;
        exit;
    }

    public function download(string $name, bool $isUTF8 = false)
    {
        $this->close();
        $this->checkOutput();
        header('Content-Type: application/x-download');
        header('Content-Disposition: attachment; ' . $this->httpEncode('filename', $name, $isUTF8));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $this->buffer;
        exit;
    }

    public function save(string $name)
    {
        $this->close();
        if (!file_put_contents($name, $this->buffer)) {
            $this->error('Unable to create output file: ' . $name);
        }
    }

    public function getBuffer()
    {
        return $this->buffer;
    }

    protected function doChecks()
    {
        // Check mbstring overloading
        if (ini_get('mbstring.func_overload') & 2) {
            $this->error('mbstring overloading must be disabled');
        }
    }

    protected function checkOutput()
    {
        if (PHP_SAPI != 'cli') {
            if (headers_sent($file, $line)) {
                $this->error("Some data has already been output, can't send PDF file (output started at $file:$line)");
            }
        }
        if (ob_get_length()) {
            // The output buffer is not empty
            if (preg_match('/^(\xEF\xBB\xBF)?\s*$/', ob_get_contents())) {
                // It contains only a UTF-8 BOM and/or whitespace, let's clean it
                ob_clean();
            } else {
                $this->error("Some data has already been output, can't send PDF file");
            }
        }
    }

    protected function getPageSize($size): array
    {
        if (is_string($size)) {
            $size = strtolower($size);
            if (!isset($this->standardPageSize[$size])) {
                $this->error('Unknown page size: ' . $size);
            }
            $a = $this->standardPageSize[$size];
            return array($a[0] / $this->scaleFactor, $a[1] / $this->scaleFactor);
        } else {
            if ($size[0] > $size[1]) {
                return array($size[1], $size[0]);
            } else {
                return $size;
            }
        }
    }

    protected function _beginpage($orientation, $size, $rotation)
    {
        $this->page++;
        $this->pages[$this->page] = '';
        $this->pageLinks[$this->page] = [];
        $this->state = static::STATE_BEGIN_PAGE;
        $this->x = $this->leftMargin;
        $this->y = $this->topMargin;
        $this->fontFamily = '';
        // Check page size and orientation
        if ($orientation == '') {
            $orientation = $this->defaultOrientation;
        } else {
            $orientation = strtoupper($orientation[0]);
        }
        if ($size == '') {
            $size = $this->defaultPageSize;
        } else {
            $size = $this->getPageSize($size);
        }
        if ($orientation != $this->currentOrientation || $size[0] != $this->currentPageSize[0] || $size[1] != $this->currentPageSize[1]) {
            // New size or orientation
            if ($orientation == static::ORIENTATION_PORTRAIT) {
                $this->width = $size[0];
                $this->height = $size[1];
            } else {
                $this->width = $size[1];
                $this->height = $size[0];
            }
            $this->widthPt = $this->width * $this->scaleFactor;
            $this->heightPt = $this->height * $this->scaleFactor;
            $this->pageBreakTrigger = $this->height - $this->bottomMargin;
            $this->currentOrientation = $orientation;
            $this->currentPageSize = $size;
        }
        if ($orientation != $this->defaultOrientation || $size[0] != $this->defaultPageSize[0] || $size[1] != $this->defaultPageSize[1]) {
            $this->pageInfo[$this->page]['size'] = array($this->widthPt, $this->heightPt);
        }
        if ($rotation != 0) {
            if ($rotation % 90 != 0) {
                $this->error('Incorrect rotation value: ' . $rotation);
            }
            $this->currentRotation = $rotation;
            $this->pageInfo[$this->page]['rotation'] = $rotation;
        }
    }

    protected function endPage()
    {
        $this->state = static::STATE_END_PAGE;
    }

    protected function loadFont($font)
    {
        // Load a font definition file from the font directory
        if (strpos($font, '/') !== false || strpos($font, "\\") !== false) {
            $this->error('Incorrect font definition file name: ' . $font);
        }
        include($this->fontpath . $font);
        if (!isset($name)) {
            $this->error('Could not include font definition file');
        }
        if (isset($enc)) {
            $enc = strtolower($enc);
        }
        if (!isset($subsetted)) {
            $subsetted = false;
        }
        return get_defined_vars();
    }

    protected function isAscii($s)
    {
        // Test if string is ASCII
        $nb = strlen($s);
        for ($i = 0; $i < $nb; $i++) {
            if (ord($s[$i]) > 127) {
                return false;
            }
        }
        return true;
    }

    protected function httpEncode($param, $value, $isUTF8)
    {
        // Encode HTTP header field parameter
        if ($this->isAscii($value)) {
            return $param . '="' . $value . '"';
        }
        if (!$isUTF8) {
            $value = utf8_encode($value);
        }
        if (strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE') !== false) {
            return $param . '="' . rawurlencode($value) . '"';
        } else {
            return $param . "*=UTF-8''" . rawurlencode($value);
        }
    }

    protected function utf8ToUtf16($s)
    {
        // Convert UTF-8 to UTF-16BE with BOM
        $res = "\xFE\xFF";
        $nb = strlen($s);
        $i = 0;
        while ($i < $nb) {
            $c1 = ord($s[$i++]);
            if ($c1 >= 224) {
                // 3-byte character
                $c2 = ord($s[$i++]);
                $c3 = ord($s[$i++]);
                $res .= chr((($c1 & 0x0F) << 4) + (($c2 & 0x3C) >> 2));
                $res .= chr((($c2 & 0x03) << 6) + ($c3 & 0x3F));
            } elseif ($c1 >= 192) {
                // 2-byte character
                $c2 = ord($s[$i++]);
                $res .= chr(($c1 & 0x1C) >> 2);
                $res .= chr((($c1 & 0x03) << 6) + ($c2 & 0x3F));
            } else {
                // Single-byte character
                $res .= "\0" . chr($c1);
            }
        }
        return $res;
    }

    protected function _escape($s)
    {
        // Escape special characters
        if (strpos($s, '(') !== false || strpos($s, ')') !== false || strpos($s, '\\') !== false || strpos($s, "\r") !== false) {
            return str_replace(array('\\', '(', ')', "\r"), array('\\\\', '\\(', '\\)', '\\r'), $s);
        } else {
            return $s;
        }
    }

    protected function _textstring($s)
    {
        // Format a text string
        if (!$this->isAscii($s)) {
            $s = $this->utf8ToUtf16($s);
        }
        return '(' . $this->_escape($s) . ')';
    }

    protected function makeUnderline($x, $y, $txt)
    {
        // Underline text
        $up = $this->currentFont['up'];
        $ut = $this->currentFont['ut'];
        $w = $this->getStringWidth($txt) + $this->wordSpacing * substr_count($txt, ' ');
        return sprintf(
            '%.2F %.2F %.2F %.2F re f',
            $x * $this->scaleFactor,
            ($this->height - ($y - $up / 1000 * $this->fontSize)) * $this->scaleFactor,
            $w * $this->scaleFactor,
            -$ut / 1000 * $this->fontSizePt,
        );
    }

    protected function parseJpg($file)
    {
        // Extract info from a JPEG file
        $a = getimagesize($file);
        if (!$a) {
            $this->error('Missing or incorrect image file: ' . $file);
        }
        if ($a[2] != 2) {
            $this->error('Not a JPEG file: ' . $file);
        }
        if (!isset($a['channels']) || $a['channels'] == 3) {
            $colspace = 'DeviceRGB';
        } elseif ($a['channels'] == 4) {
            $colspace = 'DeviceCMYK';
        } else {
            $colspace = 'DeviceGray';
        }
        $bpc = isset($a['bits']) ? $a['bits'] : 8;
        $data = file_get_contents($file);
        return array('w' => $a[0], 'h' => $a[1], 'cs' => $colspace, 'bpc' => $bpc, 'f' => 'DCTDecode', 'data' => $data);
    }

    protected function parsePng($file)
    {
        // Extract info from a PNG file
        $f = fopen($file, 'rb');
        if (!$f) {
            $this->error('Can\'t open image file: ' . $file);
        }
        $info = $this->parsePngStream($f, $file);
        fclose($f);
        return $info;
    }

    protected function parsePngStream($f, $file)
    {
        // Check signature
        if ($this->readStream($f, 8) != chr(137) . 'PNG' . chr(13) . chr(10) . chr(26) . chr(10)) {
            $this->error('Not a PNG file: ' . $file);
        }

        // Read header chunk
        $this->readStream($f, 4);
        if ($this->readStream($f, 4) != 'IHDR') {
            $this->error('Incorrect PNG file: ' . $file);
        }
        $w = $this->readInt($f);
        $h = $this->readInt($f);
        $bpc = ord($this->readStream($f, 1));
        if ($bpc > 8) {
            $this->error('16-bit depth not supported: ' . $file);
        }
        $ct = ord($this->readStream($f, 1));
        if ($ct == 0 || $ct == 4) {
            $colspace = 'DeviceGray';
        } elseif ($ct == 2 || $ct == 6) {
            $colspace = 'DeviceRGB';
        } elseif ($ct == 3) {
            $colspace = 'Indexed';
        } else {
            $this->error('Unknown color type: ' . $file);
        }
        if (ord($this->readStream($f, 1)) != 0) {
            $this->error('Unknown compression method: ' . $file);
        }
        if (ord($this->readStream($f, 1)) != 0) {
            $this->error('Unknown filter method: ' . $file);
        }
        if (ord($this->readStream($f, 1)) != 0) {
            $this->error('Interlacing not supported: ' . $file);
        }
        $this->readStream($f, 4);
        $dp = '/Predictor 15 /Colors ' . ($colspace == 'DeviceRGB' ? 3 : 1) . ' /BitsPerComponent ' . $bpc . ' /Columns ' . $w;

        // Scan chunks looking for palette, transparency and image data
        $pal = '';
        $trns = '';
        $data = '';
        do {
            $n = $this->readInt($f);
            $type = $this->readStream($f, 4);
            if ($type == 'PLTE') {
                // Read palette
                $pal = $this->readStream($f, $n);
                $this->readStream($f, 4);
            } elseif ($type == 'tRNS') {
                // Read transparency info
                $t = $this->readStream($f, $n);
                if ($ct == 0) {
                    $trns = array(ord(substr($t, 1, 1)));
                } elseif ($ct == 2) {
                    $trns = array(ord(substr($t, 1, 1)), ord(substr($t, 3, 1)), ord(substr($t, 5, 1)));
                } else {
                    $pos = strpos($t, chr(0));
                    if ($pos !== false) {
                        $trns = array($pos);
                    }
                }
                $this->readStream($f, 4);
            } elseif ($type == 'IDAT') {
                // Read image data block
                $data .= $this->readStream($f, $n);
                $this->readStream($f, 4);
            } elseif ($type == 'IEND') {
                break;
            } else {
                $this->readStream($f, $n + 4);
            }
        } while ($n);

        if ($colspace == 'Indexed' && empty($pal)) {
            $this->error('Missing palette in ' . $file);
        }
        $info = array('w' => $w, 'h' => $h, 'cs' => $colspace, 'bpc' => $bpc, 'f' => 'FlateDecode', 'dp' => $dp, 'pal' => $pal, 'trns' => $trns);
        if ($ct >= 4) {
            // Extract alpha channel
            if (!function_exists('gzuncompress')) {
                $this->error('Zlib not available, can\'t handle alpha channel: ' . $file);
            }
            $data = gzuncompress($data);
            $color = '';
            $alpha = '';
            if ($ct == 4) {
                // Gray image
                $len = 2 * $w;
                for ($i = 0; $i < $h; $i++) {
                    $pos = (1 + $len) * $i;
                    $color .= $data[$pos];
                    $alpha .= $data[$pos];
                    $line = substr($data, $pos + 1, $len);
                    $color .= preg_replace('/(.)./s', '$1', $line);
                    $alpha .= preg_replace('/.(.)/s', '$1', $line);
                }
            } else {
                // RGB image
                $len = 4 * $w;
                for ($i = 0; $i < $h; $i++) {
                    $pos = (1 + $len) * $i;
                    $color .= $data[$pos];
                    $alpha .= $data[$pos];
                    $line = substr($data, $pos + 1, $len);
                    $color .= preg_replace('/(.{3})./s', '$1', $line);
                    $alpha .= preg_replace('/.{3}(.)/s', '$1', $line);
                }
            }
            unset($data);
            $data = gzcompress($color);
            $info['smask'] = gzcompress($alpha);
            $this->withAlpha = true;
            if ($this->pdfVersion < '1.4') {
                $this->pdfVersion = '1.4';
            }
        }
        $info['data'] = $data;
        return $info;
    }

    protected function readStream($f, $n)
    {
        // Read n bytes from stream
        $res = '';
        while ($n > 0 && !feof($f)) {
            $s = fread($f, $n);
            if ($s === false) {
                $this->error('Error while reading stream');
            }
            $n -= strlen($s);
            $res .= $s;
        }
        if ($n > 0) {
            $this->error('Unexpected end of stream');
        }
        return $res;
    }

    protected function readInt($f)
    {
        // Read a 4-byte integer from stream
        $a = unpack('Ni', $this->readStream($f, 4));
        return $a['i'];
    }

    protected function parsegif($file)
    {
        // Extract info from a GIF file (via PNG conversion)
        if (!function_exists('imagepng')) {
            $this->error('GD extension is required for GIF support');
        }
        if (!function_exists('imagecreatefromgif')) {
            $this->error('GD has no GIF read support');
        }
        $im = imagecreatefromgif($file);
        if (!$im) {
            $this->error('Missing or incorrect image file: ' . $file);
        }
        imageinterlace($im, 0);
        ob_start();
        imagepng($im);
        $data = ob_get_clean();
        imagedestroy($im);
        $f = fopen('php://temp', 'rb+');
        if (!$f) {
            $this->error('Unable to create memory stream');
        }
        fwrite($f, $data);
        rewind($f);
        $info = $this->parsePngStream($f, $file);
        fclose($f);
        return $info;
    }

    protected function writeOutput($s)
    {
        match ($this->state) {
            static::STATE_BEGIN_PAGE => $this->pages[$this->page] .= $s . "\n",
            static::STATE_END_PAGE => $this->putBuffer($s),
            static::STATE_NO_PAGE => $this->error('No page has been added yet'),
            static::STATE_END_DOCUMENT => $this->error('The document is closed'),
        };
    }

    protected function putBuffer($s)
    {
        $this->buffer .= $s . "\n";
    }

    protected function getOffset()
    {
        return strlen($this->buffer);
    }

    protected function putObject($n = null)
    {
        // Begin a new object
        if ($n === null) {
            $n = ++$this->objectNumber;
        }
        $this->offsets[$n] = $this->getOffset();
        $this->putBuffer($n . ' 0 obj');
    }

    protected function putStream($data)
    {
        $this->putBuffer('stream');
        $this->putBuffer($data);
        $this->putBuffer('endstream');
    }

    protected function putStreamObject($data)
    {
        if ($this->compress) {
            $entries = '/Filter /FlateDecode ';
            $data = gzcompress($data);
        } else {
            $entries = '';
        }
        $entries .= '/Length ' . strlen($data);
        $this->putObject();
        $this->putBuffer('<<' . $entries . '>>');
        $this->putStream($data);
        $this->putBuffer('endobj');
    }

    protected function putPage($n)
    {
        $this->putObject();
        $this->putBuffer('<</Type /Page');
        $this->putBuffer('/Parent 1 0 R');
        if (isset($this->pageInfo[$n]['size'])) {
            $this->putBuffer(sprintf('/MediaBox [0 0 %.2F %.2F]', $this->pageInfo[$n]['size'][0], $this->pageInfo[$n]['size'][1]));
        }
        if (isset($this->pageInfo[$n]['rotation'])) {
            $this->putBuffer('/Rotate ' . $this->pageInfo[$n]['rotation']);
        }
        $this->putBuffer('/Resources 2 0 R');
        if (!empty($this->pageLinks[$n])) {
            $s = '/Annots [';
            foreach ($this->pageLinks[$n] as $pl) {
                $s .= $pl[5] . ' 0 R ';
            }
            $s .= ']';
            $this->putBuffer($s);
        }
        if ($this->withAlpha) {
            $this->putBuffer('/Group <</Type /Group /S /Transparency /CS /DeviceRGB>>');
        }
        $this->putBuffer('/Contents ' . ($this->objectNumber + 1) . ' 0 R>>');
        $this->putBuffer('endobj');
        // Page content
        if (!empty($this->aliasNbPages)) {
            $this->pages[$n] = str_replace($this->aliasNbPages, $this->page, $this->pages[$n]);
        }
        $this->putStreamObject($this->pages[$n]);
        // Annotations
        foreach ($this->pageLinks[$n] as $pl) {
            $this->putObject();
            $rect = sprintf('%.2F %.2F %.2F %.2F', $pl[0], $pl[1], $pl[0] + $pl[2], $pl[1] - $pl[3]);
            $s = '<</Type /Annot /Subtype /Link /Rect [' . $rect . '] /Border [0 0 0] ';
            if (is_string($pl[4])) {
                $s .= '/A <</S /URI /URI ' . $this->_textstring($pl[4]) . '>>>>';
            } else {
                $l = $this->links[$pl[4]];
                if (isset($this->pageInfo[$l[0]]['size'])) {
                    $h = $this->pageInfo[$l[0]]['size'][1];
                } else {
                    $h = ($this->defaultOrientation == static::ORIENTATION_PORTRAIT) ? $this->defaultPageSize[1] * $this->scaleFactor : $this->defaultPageSize[0] * $this->scaleFactor;
                }
                $s .= sprintf('/Dest [%d 0 R /XYZ 0 %.2F null]>>', $this->pageInfo[$l[0]]['n'], $h - $l[1] * $this->scaleFactor);
            }
            $this->putBuffer($s);
            $this->putBuffer('endobj');
        }
    }

    protected function putPages()
    {
        $nb = $this->page;
        $n = $this->objectNumber;
        for ($i = 1; $i <= $nb; $i++) {
            $this->pageInfo[$i]['n'] = ++$n;
            $n++;
            foreach ($this->pageLinks[$i] as &$pl) {
                $pl[5] = ++$n;
            }
            unset($pl);
        }
        for ($i = 1; $i <= $nb; $i++) {
            $this->putPage($i);
        }
        // Pages root
        $this->putObject(1);
        $this->putBuffer('<</Type /Pages');
        $kids = '/Kids [';
        for ($i = 1; $i <= $nb; $i++) {
            $kids .= $this->pageInfo[$i]['n'] . ' 0 R ';
        }
        $kids .= ']';
        $this->putBuffer($kids);
        $this->putBuffer('/Count ' . $nb);
        if ($this->defaultOrientation == static::ORIENTATION_PORTRAIT) {
            $w = $this->defaultPageSize[0];
            $h = $this->defaultPageSize[1];
        } else {
            $w = $this->defaultPageSize[1];
            $h = $this->defaultPageSize[0];
        }
        $this->putBuffer(sprintf('/MediaBox [0 0 %.2F %.2F]', $w * $this->scaleFactor, $h * $this->scaleFactor));
        $this->putBuffer('>>');
        $this->putBuffer('endobj');
    }

    protected function putFonts()
    {
        foreach ($this->fontFiles as $file => $info) {
            // Font file embedding
            $this->putObject();
            $this->fontFiles[$file]['n'] = $this->objectNumber;
            $font = file_get_contents($this->fontpath . $file, true);
            if (!$font) {
                $this->error('Font file not found: ' . $file);
            }
            $compressed = (substr($file, -2) == '.z');
            if (!$compressed && isset($info['length2'])) {
                $font = substr($font, 6, $info['length1']) . substr($font, 6 + $info['length1'] + 6, $info['length2']);
            }
            $this->putBuffer('<</Length ' . strlen($font));
            if ($compressed) {
                $this->putBuffer('/Filter /FlateDecode');
            }
            $this->putBuffer('/Length1 ' . $info['length1']);
            if (isset($info['length2'])) {
                $this->putBuffer('/Length2 ' . $info['length2'] . ' /Length3 0');
            }
            $this->putBuffer('>>');
            $this->putStream($font);
            $this->putBuffer('endobj');
        }
        foreach ($this->fonts as $k => $font) {
            // Encoding
            if (isset($font['diff'])) {
                if (!isset($this->encodings[$font['enc']])) {
                    $this->putObject();
                    $this->putBuffer('<</Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences [' . $font['diff'] . ']>>');
                    $this->putBuffer('endobj');
                    $this->encodings[$font['enc']] = $this->objectNumber;
                }
            }
            // ToUnicode CMap
            if (isset($font['uv'])) {
                if (isset($font['enc'])) {
                    $cmapkey = $font['enc'];
                } else {
                    $cmapkey = $font['name'];
                }
                if (!isset($this->cmaps[$cmapkey])) {
                    $cmap = $this->toUnicodeCmap($font['uv']);
                    $this->putStreamObject($cmap);
                    $this->cmaps[$cmapkey] = $this->objectNumber;
                }
            }
            // Font object
            $this->fonts[$k]['n'] = $this->objectNumber + 1;
            $type = $font['type'];
            $name = $font['name'];
            if ($font['subsetted']) {
                $name = 'AAAAAA+' . $name;
            }
            if ($type == 'Core') {
                // Core font
                $this->putObject();
                $this->putBuffer('<</Type /Font');
                $this->putBuffer('/BaseFont /' . $name);
                $this->putBuffer('/Subtype /Type1');
                if ($name != 'Symbol' && $name != 'ZapfDingbats') {
                    $this->putBuffer('/Encoding /WinAnsiEncoding');
                }
                if (isset($font['uv'])) {
                    $this->putBuffer('/ToUnicode ' . $this->cmaps[$cmapkey] . ' 0 R');
                }
                $this->putBuffer('>>');
                $this->putBuffer('endobj');
            } elseif ($type == 'Type1' || $type == 'TrueType') {
                // Additional Type1 or TrueType/OpenType font
                $this->putObject();
                $this->putBuffer('<</Type /Font');
                $this->putBuffer('/BaseFont /' . $name);
                $this->putBuffer('/Subtype /' . $type);
                $this->putBuffer('/FirstChar 32 /LastChar 255');
                $this->putBuffer('/Widths ' . ($this->objectNumber + 1) . ' 0 R');
                $this->putBuffer('/FontDescriptor ' . ($this->objectNumber + 2) . ' 0 R');
                if (isset($font['diff'])) {
                    $this->putBuffer('/Encoding ' . $this->encodings[$font['enc']] . ' 0 R');
                } else {
                    $this->putBuffer('/Encoding /WinAnsiEncoding');
                }
                if (isset($font['uv'])) {
                    $this->putBuffer('/ToUnicode ' . $this->cmaps[$cmapkey] . ' 0 R');
                }
                $this->putBuffer('>>');
                $this->putBuffer('endobj');
                // Widths
                $this->putObject();
                $cw = &$font['cw'];
                $s = '[';
                for ($i = 32; $i <= 255; $i++) {
                    $s .= $cw[chr($i)] . ' ';
                }
                $this->putBuffer($s . ']');
                $this->putBuffer('endobj');
                // Descriptor
                $this->putObject();
                $s = '<</Type /FontDescriptor /FontName /' . $name;
                foreach ($font['desc'] as $k => $v) {
                    $s .= ' /' . $k . ' ' . $v;
                }
                if (!empty($font['file'])) {
                    $s .= ' /FontFile' . ($type == 'Type1' ? '' : '2') . ' ' . $this->fontFiles[$font['file']]['n'] . ' 0 R';
                }
                $this->putBuffer($s . '>>');
                $this->putBuffer('endobj');
            } else {
                // Allow for additional types
                $mtd = '_put' . strtolower($type);
                if (!method_exists($this, $mtd)) {
                    $this->error('Unsupported font type: ' . $type);
                }
                $this->$mtd($font);
            }
        }
    }

    protected function toUnicodeCmap($uv)
    {
        $ranges = '';
        $nbr = 0;
        $chars = '';
        $nbc = 0;
        foreach ($uv as $c => $v) {
            if (is_array($v)) {
                $ranges .= sprintf("<%02X> <%02X> <%04X>\n", $c, $c + $v[1] - 1, $v[0]);
                $nbr++;
            } else {
                $chars .= sprintf("<%02X> <%04X>\n", $c, $v);
                $nbc++;
            }
        }
        $s = "/CIDInit /ProcSet findresource begin\n";
        $s .= "12 dict begin\n";
        $s .= "begincmap\n";
        $s .= "/CIDSystemInfo\n";
        $s .= "<</Registry (Adobe)\n";
        $s .= "/Ordering (UCS)\n";
        $s .= "/Supplement 0\n";
        $s .= ">> def\n";
        $s .= "/CMapName /Adobe-Identity-UCS def\n";
        $s .= "/CMapType 2 def\n";
        $s .= "1 begincodespacerange\n";
        $s .= "<00> <FF>\n";
        $s .= "endcodespacerange\n";
        if ($nbr > 0) {
            $s .= "$nbr beginbfrange\n";
            $s .= $ranges;
            $s .= "endbfrange\n";
        }
        if ($nbc > 0) {
            $s .= "$nbc beginbfchar\n";
            $s .= $chars;
            $s .= "endbfchar\n";
        }
        $s .= "endcmap\n";
        $s .= "CMapName currentdict /CMap defineresource pop\n";
        $s .= "end\n";
        $s .= "end";
        return $s;
    }

    protected function putImages()
    {
        foreach (array_keys($this->images) as $file) {
            $this->putImage($this->images[$file]);
            unset($this->images[$file]['data']);
            unset($this->images[$file]['smask']);
        }
    }

    protected function putImage(&$info)
    {
        $this->putObject();
        $info['n'] = $this->objectNumber;
        $this->putBuffer('<</Type /XObject');
        $this->putBuffer('/Subtype /Image');
        $this->putBuffer('/Width ' . $info['w']);
        $this->putBuffer('/Height ' . $info['h']);
        if ($info['cs'] == 'Indexed') {
            $this->putBuffer('/ColorSpace [/Indexed /DeviceRGB ' . (strlen($info['pal']) / 3 - 1) . ' ' . ($this->objectNumber + 1) . ' 0 R]');
        } else {
            $this->putBuffer('/ColorSpace /' . $info['cs']);
            if ($info['cs'] == 'DeviceCMYK') {
                $this->putBuffer('/Decode [1 0 1 0 1 0 1 0]');
            }
        }
        $this->putBuffer('/BitsPerComponent ' . $info['bpc']);
        if (isset($info['f'])) {
            $this->putBuffer('/Filter /' . $info['f']);
        }
        if (isset($info['dp'])) {
            $this->putBuffer('/DecodeParms <<' . $info['dp'] . '>>');
        }
        if (isset($info['trns']) && is_array($info['trns'])) {
            $trns = '';
            for ($i = 0; $i < count($info['trns']); $i++) {
                $trns .= $info['trns'][$i] . ' ' . $info['trns'][$i] . ' ';
            }
            $this->putBuffer('/Mask [' . $trns . ']');
        }
        if (isset($info['smask'])) {
            $this->putBuffer('/SMask ' . ($this->objectNumber + 1) . ' 0 R');
        }
        $this->putBuffer('/Length ' . strlen($info['data']) . '>>');
        $this->putStream($info['data']);
        $this->putBuffer('endobj');
        // Soft mask
        if (isset($info['smask'])) {
            $dp = '/Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns ' . $info['w'];
            $smask = array('w' => $info['w'], 'h' => $info['h'], 'cs' => 'DeviceGray', 'bpc' => 8, 'f' => $info['f'], 'dp' => $dp, 'data' => $info['smask']);
            $this->putImage($smask);
        }
        // Palette
        if ($info['cs'] == 'Indexed') {
            $this->putStreamObject($info['pal']);
        }
    }

    protected function putObjectDict()
    {
        foreach ($this->images as $image) {
            $this->putBuffer('/I' . $image['i'] . ' ' . $image['n'] . ' 0 R');
        }
    }

    protected function putResourceDict()
    {
        $this->putBuffer('/ProcSet [/PDF /Text /ImageB /ImageC /ImageI]');
        $this->putBuffer('/Font <<');
        foreach ($this->fonts as $font) {
            $this->putBuffer('/F' . $font['i'] . ' ' . $font['n'] . ' 0 R');
        }
        $this->putBuffer('>>');
        $this->putBuffer('/XObject <<');
        $this->putObjectDict();
        $this->putBuffer('>>');
    }

    protected function putResources()
    {
        $this->putFonts();
        $this->putImages();
        // Resource dictionary
        $this->putObject(2);
        $this->putBuffer('<<');
        $this->putResourceDict();
        $this->putBuffer('>>');
        $this->putBuffer('endobj');
    }

    protected function putInfo()
    {
        $this->metadata['Producer'] = $this->producer;
        $this->metadata['CreationDate'] = 'D:' . @date('YmdHis');
        foreach ($this->metadata as $key => $value) {
            $this->putBuffer('/' . $key . ' ' . $this->_textstring($value));
        }
    }

    protected function putCatalog()
    {
        $n = $this->pageInfo[1]['n'];
        $this->putBuffer('/Type /Catalog');
        $this->putBuffer('/Pages 1 0 R');
        if ($this->zoomMode == 'fullpage') {
            $this->putBuffer('/OpenAction [' . $n . ' 0 R /Fit]');
        } elseif ($this->zoomMode == 'fullwidth') {
            $this->putBuffer('/OpenAction [' . $n . ' 0 R /FitH null]');
        } elseif ($this->zoomMode == 'real') {
            $this->putBuffer('/OpenAction [' . $n . ' 0 R /XYZ null null 1]');
        } elseif (!is_string($this->zoomMode)) {
            $this->putBuffer('/OpenAction [' . $n . ' 0 R /XYZ null null ' . sprintf('%.2F', $this->zoomMode / 100) . ']');
        }
        if ($this->layoutMode == 'single') {
            $this->putBuffer('/PageLayout /SinglePage');
        } elseif ($this->layoutMode == 'continuous') {
            $this->putBuffer('/PageLayout /OneColumn');
        } elseif ($this->layoutMode == 'two') {
            $this->putBuffer('/PageLayout /TwoColumnLeft');
        }
    }

    protected function putHeader()
    {
        $this->putBuffer('%PDF-' . $this->pdfVersion);
    }

    protected function putTrailer()
    {
        $this->putBuffer('/Size ' . ($this->objectNumber + 1));
        $this->putBuffer('/Root ' . $this->objectNumber . ' 0 R');
        $this->putBuffer('/Info ' . ($this->objectNumber - 1) . ' 0 R');
    }

    protected function endDoc()
    {
        $this->putHeader();
        $this->putPages();
        $this->putResources();
        // Info
        $this->putObject();
        $this->putBuffer('<<');
        $this->putInfo();
        $this->putBuffer('>>');
        $this->putBuffer('endobj');
        // Catalog
        $this->putObject();
        $this->putBuffer('<<');
        $this->putCatalog();
        $this->putBuffer('>>');
        $this->putBuffer('endobj');
        // Cross-ref
        $offset = $this->getOffset();
        $this->putBuffer('xref');
        $this->putBuffer('0 ' . ($this->objectNumber + 1));
        $this->putBuffer('0000000000 65535 f ');
        for ($i = 1; $i <= $this->objectNumber; $i++) {
            $this->putBuffer(sprintf('%010d 00000 n ', $this->offsets[$i]));
        }
        // Trailer
        $this->putBuffer('trailer');
        $this->putBuffer('<<');
        $this->putTrailer();
        $this->putBuffer('>>');
        $this->putBuffer('startxref');
        $this->putBuffer($offset);
        $this->putBuffer('%%EOF');
        $this->state = static::STATE_END_DOCUMENT;
    }
}
