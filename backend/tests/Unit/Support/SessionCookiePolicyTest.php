<?php

namespace Tests\Unit\Support;

use App\Support\SessionCookiePolicy;
use PHPUnit\Framework\TestCase;

class SessionCookiePolicyTest extends TestCase
{
    public function test_same_site_none_with_secure_true_does_not_throw(): void
    {
        SessionCookiePolicy::assertSecureWhenSameSiteIsNone('none', 'true');
        SessionCookiePolicy::assertSecureWhenSameSiteIsNone('none', true);
        SessionCookiePolicy::assertSecureWhenSameSiteIsNone('none', '1');

        $this->addToAssertionCount(3);
    }

    public function test_same_site_none_with_secure_false_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        SessionCookiePolicy::assertSecureWhenSameSiteIsNone('none', 'false');
    }

    public function test_same_site_none_with_secure_unset_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        SessionCookiePolicy::assertSecureWhenSameSiteIsNone('none', null);
    }

    public function test_same_site_lax_without_secure_does_not_throw(): void
    {
        // カスタムドメイン(親ドメイン共有)構成の推奨値: SameSite=lax + Secureは必須ではない。
        SessionCookiePolicy::assertSecureWhenSameSiteIsNone('lax', null);
        SessionCookiePolicy::assertSecureWhenSameSiteIsNone('lax', 'false');

        $this->addToAssertionCount(2);
    }

    public function test_same_site_strict_without_secure_does_not_throw(): void
    {
        SessionCookiePolicy::assertSecureWhenSameSiteIsNone('strict', null);

        $this->addToAssertionCount(1);
    }

    public function test_null_same_site_does_not_throw(): void
    {
        SessionCookiePolicy::assertSecureWhenSameSiteIsNone(null, null);

        $this->addToAssertionCount(1);
    }
}
