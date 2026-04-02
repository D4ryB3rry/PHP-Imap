<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Protocol;

use D4ry\ImapClient\ValueObject\Tag;

class TagGenerator
{
    private int $counter = 0;

    public function next(): Tag
    {
        $this->counter++;

        return new Tag(sprintf('A%04d', $this->counter));
    }
}
