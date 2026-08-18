<?php

namespace App\Enums;

enum SalesStatus: string
{
    case Uncontacted = 'uncontacted';
    case Contacted = 'contacted';
    case Meeting = 'meeting';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Uncontacted => '未対応',
            self::Contacted => '連絡済み',
            self::Meeting => '商談中',
            self::Won => '受注',
            self::Lost => '見送り',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status) => [$status->value => $status->label()])->all();
    }
}
