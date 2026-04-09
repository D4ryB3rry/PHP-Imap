<?php

declare(strict_types=1);

namespace D4ry\ImapClient\ValueObject;

readonly class SequenceSet
{
    public function __construct(
        public string $value,
    ) {
    }

    /**
     * @param int[] $numbers
     */
    public static function fromArray(array $numbers): self
    {
        sort($numbers);
        $ranges = [];
        $start = $end = $numbers[0];

        for ($i = 1, $count = count($numbers); $i < $count; $i++) {
            if ($numbers[$i] === $end + 1) {
                $end = $numbers[$i];
            } else {
                // Same equivalent-CastString rationale as Folder::compressUidsToSet
                // — implode(',', …) coerces both branches back to string anyway.
                // @infection-ignore-all
                $ranges[] = $start === $end ? (string) $start : "$start:$end";
                $start = $end = $numbers[$i];
            }
        }

        // @infection-ignore-all
        $ranges[] = $start === $end ? (string) $start : "$start:$end";

        return new self(implode(',', $ranges));
    }

    public static function range(int $start, int $end): self
    {
        return new self("$start:$end");
    }

    public static function all(): self
    {
        return new self('1:*');
    }

    public static function single(int $number): self
    {
        return new self((string) $number);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
