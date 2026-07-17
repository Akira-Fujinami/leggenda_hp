<?php

namespace App\Enums;

enum WebsiteAnalysisStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Partial, self::Failed], true);
    }
}
