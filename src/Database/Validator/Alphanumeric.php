<?php

namespace Utopia\Database\Validator;

use Utopia\Validator\Text;

class Alphanumeric extends Text
{
    /**
     * Alphanumeric constructor.
     *
     * Validate text with maximum length $length. Use $length = 0 for unlimited length.
     *
     * @param  int  $length
     * @param  int  $min
     */
    public function __construct(int $length, int $min = 1)
    {
        parent::__construct($length, $min, \array_merge(Text::ALPHABET_UPPER, Text::ALPHABET_LOWER, Text::NUMBERS));
    }

    /**
     * Get Description
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        $message = 'Value must be a valid string';

        if ($this->min === $this->length) {
            $message .= ' and exactly '.$this->length.' chars';
        } else {
            if ($this->min) {
                $message .= ' and at least '.$this->min.' chars';
            }

            if ($this->length) {
                $message .= ' and no longer than '.$this->length.' chars';
            }
        }

        $message .= ' and only consist of alphanumeric chars';

        return $message;
    }
}
