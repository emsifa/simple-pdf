<?php

namespace Emsifa\SimplePdf;

class TextStyle
{
    public function __construct(
        protected ?string $color = null,
        protected ?float $size = null,
        protected ?bool $underline = null,
    )
    {
    }

    public function getColor()
    {
        return $this->color;
    }

    public function getSize()
    {
        return $this->size;
    }

    public function getUnderline()
    {
        return $this->underline;
    }

    public function merge(TextStyle $style): self
    {
        if (is_null($this->color)) {
            $this->color = $style->getColor();
        }
        if (is_null($this->underline)) {
            $this->underline = $style->getUnderline();
        }
        if (is_null($this->size)) {
            $this->size = $style->getSize();
        }
        return $this;
    }
}
