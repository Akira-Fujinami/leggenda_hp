<?php

namespace App\Support;

/**
 * 依頼DO: 社内通知の宛先(config('lead.notification_to') = env LEAD_NOTIFICATION_TO)。
 * カンマ(、;・空白も可)で区切って複数書ける。メールアドレスの形でないものは除く。
 * 社外(リード)へのメールの返信先には、先頭の1件(共有の受信箱を先頭に書く運用)だけを使う。
 */
class LeadNotificationRecipients
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $raw = config('lead.notification_to');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $addresses = [];
        foreach (preg_split('/[\s,;、]+/u', trim($raw)) ?: [] as $address) {
            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false && ! in_array($address, $addresses, true)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    public static function replyTo(): ?string
    {
        return self::all()[0] ?? null;
    }
}
