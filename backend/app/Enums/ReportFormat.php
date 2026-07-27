<?php

namespace App\Enums;

enum ReportFormat: string
{
    case Docx = 'docx';
    case Pdf = 'pdf';

    public function fileExtension(): string
    {
        return $this->value;
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Docx => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            self::Pdf => 'application/pdf',
        };
    }
}
