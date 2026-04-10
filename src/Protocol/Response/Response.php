<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Protocol\Response;

class Response
{
    /**
     * @param UntaggedResponse[] $untagged
     */
    public function __construct(
        public string $status,
        public string $tag,
        public string $text,
        public array $untagged = [],
        public ?string $responseCode = null,
    ) {
    }

    public function isOk(): bool
    {
        return $this->status === ResponseStatus::Ok;
    }

    /**
     * @return UntaggedResponse[]
     */
    public function getUntaggedByType(string $type): array
    {
        return array_filter(
            $this->untagged,
            fn(UntaggedResponse $r) => strcasecmp($r->type, $type) === 0,
        );
    }

    public function getFirstUntaggedByType(string $type): ?UntaggedResponse
    {
        $found = $this->getUntaggedByType($type);

        return $found !== [] ? reset($found) : null;
    }
}
