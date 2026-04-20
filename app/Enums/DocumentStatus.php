<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Ready || $this === self::Failed;
    }

    public function isPending(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }
}
