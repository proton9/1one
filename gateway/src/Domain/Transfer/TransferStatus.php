<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

enum TransferStatus: string
{
    case Reserved = 'reserved';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';
}
