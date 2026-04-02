<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Search\Contract;

interface SearchCriteriaInterface
{
    public function compile(): string;
}
