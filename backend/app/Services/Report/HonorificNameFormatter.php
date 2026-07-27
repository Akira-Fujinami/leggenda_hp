<?php

namespace App\Services\Report;

/**
 * リード入力の会社名をそのまま「{社名}様」に連結すると、入力値に既に
 * 敬称が含まれる場合(「株式会社○○御中」「○○様」等)に「○○御中様」
 * 「○○様様」という二重敬称になってしまう。末尾の既知の敬称を1つだけ
 * 検出して除去してから「様」を付け直すことで、常に単一の敬称になるよう
 * 保証する。
 *
 * また、極端に長い社名(自由入力のため上限がない)でレポートのレイアウトが
 * 崩れないよう、敬称を除いた本体部分の文字数に上限を設けて省略する。
 */
class HonorificNameFormatter
{
    /**
     * 長いものから先に判定する(「御中」は「中」等の部分一致より優先)。
     *
     * @var list<string>
     */
    private const TRAILING_HONORIFICS = ['御中', '様', '殿'];

    // レポートの見出し・本文中に差し込んでもレイアウトが大きく崩れない
    // 長さの目安として、全角40文字を上限とする。
    private const MAX_BODY_LENGTH = 40;

    public function format(string $rawCompanyName): string
    {
        $trimmed = trim($rawCompanyName);

        if ($trimmed === '') {
            return 'お客様';
        }

        $body = $this->truncate($this->stripTrailingHonorific($trimmed));

        return "{$body}様";
    }

    private function stripTrailingHonorific(string $name): string
    {
        foreach (self::TRAILING_HONORIFICS as $honorific) {
            if (str_ends_with($name, $honorific)) {
                $withoutHonorific = mb_substr($name, 0, mb_strlen($name, 'UTF-8') - mb_strlen($honorific, 'UTF-8'), 'UTF-8');

                return rtrim($withoutHonorific);
            }
        }

        return $name;
    }

    private function truncate(string $body): string
    {
        if (mb_strlen($body, 'UTF-8') <= self::MAX_BODY_LENGTH) {
            return $body;
        }

        return mb_substr($body, 0, self::MAX_BODY_LENGTH, 'UTF-8').'…';
    }
}
