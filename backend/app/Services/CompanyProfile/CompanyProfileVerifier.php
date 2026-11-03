<?php

namespace App\Services\CompanyProfile;

/**
 * 依頼DK: AIの下書きを、サーバーが確かめる。通ったものだけを残す(通らない項目は null =スライドでは空欄)。
 *
 *  - 事実(value・achievement・recognition):
 *      source_url を自分で取りに行き(CompanyProfileSourceFetcher)、quote がそのページの本文にそのままあること
 *      (空白・全角半角・大文字小文字の違いだけ無視)。text の数字・「」『』の中の語が quote にあること。長さの上限。
 *      value は会社自身のドメインのページに限る(config value_requires_own_site)。
 *  - headline: メッセージの文(sentence_id)の一部か、会社自身のページの quote の一部であること。
 *  - lead・柱の title: 数字が、メッセージの文か、確かめた quote にあること。長さの上限。
 *  - 柱は、title が通り、事実が1つ以上通ったものだけ。
 * 落ちた理由は符号ごとの件数で返す(中身は返さない・ログに出さない)。
 */
class CompanyProfileVerifier
{
    private const FACT_KEYS = ['value', 'achievement', 'recognition'];

    /** @var array<string, int> */
    private array $rejected = [];

    /** @var array<string, string> url => title */
    private array $sources = [];

    public function __construct(
        private readonly CompanyProfileSourceFetcher $fetcher,
        private readonly CompanyDomain $domains,
    ) {}

    /**
     * @param  array<string, mixed>  $decoded  AIの出力
     * @param  array<string, mixed>  $material  CompanyProfileMaterialBuilder::build()の戻り値
     * @return array{profile: ?array<string, mixed>, rejected: array<string, int>}
     */
    public function verify(array $decoded, array $material): array
    {
        $this->rejected = [];
        $this->sources = [];
        $this->fetcher->reset();

        $messageText = implode("\n", array_map(fn (array $s) => (string) $s['text'], (array) $material['sentences']));
        $headline = $this->headline($decoded['headline'] ?? null, $material);

        $pillars = [];
        $quotes = [];
        foreach (array_slice(array_values(array_filter((array) ($decoded['pillars'] ?? []), 'is_array')), 0, (int) config('company_profile.pillars_max')) as $raw) {
            $facts = [];
            $pillarQuotes = [];
            foreach (self::FACT_KEYS as $key) {
                $fact = $this->fact($raw[$key] ?? null, $material, $key === 'value');
                $facts[$key] = $fact === null ? null : ['text' => $fact['text'], 'source_url' => $fact['source_url'], 'source_title' => $fact['source_title']];
                if ($fact !== null) {
                    $pillarQuotes[] = $fact['quote'];
                }
            }
            if ($pillarQuotes === []) {
                $this->reject('pillar_no_fact');

                continue;
            }
            $title = trim((string) ($raw['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > (int) config('company_profile.pillar_title_max_chars')) {
                $this->reject('pillar_title_length');

                continue;
            }
            if (! $this->numbersIn($title, $messageText."\n".implode("\n", $pillarQuotes))) {
                $this->reject('pillar_title_number');

                continue;
            }
            $quotes = array_merge($quotes, $pillarQuotes);
            $pillars[] = ['title' => $title] + $facts;
        }

        $lead = trim((string) ($decoded['lead'] ?? ''));
        if ($lead !== '') {
            if (mb_strlen($lead) > (int) config('company_profile.lead_max_chars')) {
                $this->reject('lead_length');
                $lead = '';
            } elseif (! $this->numbersIn($lead, $messageText."\n".implode("\n", $quotes).($headline['quote'] ?? ''))) {
                $this->reject('lead_number');
                $lead = '';
            }
        }

        if ($headline === null && $pillars === []) {
            return ['profile' => null, 'rejected' => $this->rejected];
        }

        $sources = [];
        foreach ($this->sources as $url => $title) {
            $sources[] = ['url' => $url, 'title' => $title];
        }

        return [
            'profile' => [
                'headline' => $headline === null ? null : ['text' => $headline['text'], 'from' => $headline['from']],
                'lead' => $lead !== '' ? $lead : null,
                'pillars' => $pillars,
                'sources' => $sources,
            ],
            'rejected' => $this->rejected,
        ];
    }

    /**
     * @param  array<string, mixed>  $material
     * @return ?array{text: string, from: string, quote?: string}
     */
    private function headline(mixed $raw, array $material): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $text = trim((string) ($raw['text'] ?? ''));
        $length = mb_strlen($text);
        if ($length < (int) config('company_profile.headline_min_chars') || $length > (int) config('company_profile.headline_max_chars')) {
            $this->reject('headline_length');

            return null;
        }

        $id = $raw['sentence_id'] ?? null;
        if (is_string($id) && $id !== '') {
            foreach ((array) $material['sentences'] as $sentence) {
                if ($sentence['id'] === $id) {
                    if (str_contains($this->normalize((string) $sentence['text']), $this->normalize($text))) {
                        if (($material['message_url'] ?? null) !== null) {
                            $this->sources[(string) $material['message_url']] ??= (string) ($material['message_title'] ?? '');
                        }

                        return ['text' => $text, 'from' => 'message'];
                    }
                    $this->reject('headline_not_in_sentence');

                    return null;
                }
            }
            $this->reject('headline_unknown_sentence');

            return null;
        }

        $fact = $this->fact(['text' => $text, 'source_url' => $raw['source_url'] ?? null, 'quote' => $raw['quote'] ?? null], $material, true, 'headline');
        if ($fact === null) {
            return null;
        }
        if (! str_contains($this->normalize($fact['quote']), $this->normalize($text))) {
            $this->reject('headline_not_in_quote');

            return null;
        }

        return ['text' => $text, 'from' => 'site', 'quote' => $fact['quote']];
    }

    /**
     * @param  array<string, mixed>  $material
     * @return ?array{text: string, source_url: string, source_title: string, quote: string}
     */
    private function fact(mixed $raw, array $material, bool $ownSiteOnly, string $prefix = 'fact'): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (! is_array($raw)) {
            $this->reject($prefix.'_shape');

            return null;
        }
        $text = trim((string) ($raw['text'] ?? ''));
        $url = trim((string) ($raw['source_url'] ?? ''));
        $quote = trim((string) ($raw['quote'] ?? ''));
        if ($text === '' || $url === '' || $quote === '' || preg_match('~^https?://~i', $url) !== 1) {
            $this->reject($prefix.'_shape');

            return null;
        }
        if ($prefix === 'fact' && mb_strlen($text) > (int) config('company_profile.fact_max_chars')) {
            $this->reject('fact_length');

            return null;
        }
        $quoteLength = mb_strlen($quote);
        if ($quoteLength < (int) config('company_profile.quote_min_chars') || $quoteLength > (int) config('company_profile.quote_max_chars')) {
            $this->reject($prefix.'_quote_length');

            return null;
        }
        if ($ownSiteOnly && (bool) config('company_profile.value_requires_own_site', true) && ! $this->domains->isOwn($url, (array) $material['own_domains'])) {
            $this->reject($prefix.'_not_own_site');

            return null;
        }
        if (! $this->numbersIn($text, $quote)) {
            $this->reject($prefix.'_number_not_in_quote');

            return null;
        }
        if (! $this->bracketedIn($text, $quote)) {
            $this->reject($prefix.'_name_not_in_quote');

            return null;
        }

        $page = $this->fetcher->fetch($url);
        if ($page === null) {
            $this->reject($prefix.'_source_unavailable');

            return null;
        }
        if ($ownSiteOnly && (bool) config('company_profile.value_requires_own_site', true) && ! $this->domains->isOwn($page['final_url'], (array) $material['own_domains'])) {
            $this->reject($prefix.'_not_own_site');

            return null;
        }
        if (! str_contains($this->normalize($page['text']), $this->normalize($quote))) {
            $this->reject($prefix.'_quote_not_found');

            return null;
        }

        $this->sources[$url] ??= $page['title'];

        return ['text' => $text, 'source_url' => $url, 'source_title' => $page['title'], 'quote' => $quote];
    }

    /** 文の中の数字(桁区切りは除く)が、すべて $source の中にある。 */
    private function numbersIn(string $text, string $source): bool
    {
        $normalizedSource = str_replace([',', '，'], '', $this->normalize($source));
        preg_match_all('/\d+(?:\.\d+)?/u', str_replace([',', '，'], '', \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text), $m);
        foreach ($m[0] as $number) {
            if (! str_contains($normalizedSource, $number)) {
                return false;
            }
        }

        return true;
    }

    /** 文の中の「」『』の中の語が、すべて $quote の中にある。 */
    private function bracketedIn(string $text, string $quote): bool
    {
        preg_match_all('/[「『]([^」』]+)[」』]/u', $text, $m);
        $normalizedQuote = $this->normalize($quote);
        foreach ($m[1] as $name) {
            if (! str_contains($normalizedQuote, $this->normalize($name))) {
                return false;
            }
        }

        return true;
    }

    private function normalize(string $text): string
    {
        $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;

        return mb_strtolower((string) preg_replace('/[\s\x{3000}]+/u', '', $text));
    }

    private function reject(string $code): void
    {
        $this->rejected[$code] = ($this->rejected[$code] ?? 0) + 1;
    }
}
