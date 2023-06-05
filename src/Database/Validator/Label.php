<?php

namespace Utopia\Database\Validator;

class Label extends Alphanumeric
{
    /**
     * Label constructor.
     *
     * Validate text is a valid label
     *
     */
    public function __construct()
    {
        parent::__construct(36, 1);
    }
}
