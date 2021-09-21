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
        return new static(
            size: $this->getSize() ?: $style->getSize(),
            color: $this->getColor() ?: $style->getColor(),
            underline: $this->getUnderline() ?: $style->getUnderline(),
        );
    }
}
