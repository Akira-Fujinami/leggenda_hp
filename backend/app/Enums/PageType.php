<?php

namespace App\Enums;

enum PageType: string
{
    case Homepage = 'homepage';
    case Robots = 'robots';
    case Sitemap = 'sitemap';
    // 採用ページ(トップページのbusiness_links.recruitから検出したURL)。
    // 検出できなかった場合はこのPageType自体のAnalysisPage行が作られない
    // (「計測対象外」はレポート側で判定する。行が無いこと自体がその表現)。
    case Recruit = 'recruit';
}
