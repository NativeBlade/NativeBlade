<?php

namespace NativeBlade\Mcp\Tools;

use NativeBlade\Mcp\Publishing\Context;
use NativeBlade\Mcp\Tool;

final class StoreListing implements Tool
{
    private const LIMITS = [
        'android' => ['name' => 30, 'short_description' => 80, 'description' => 4000, 'release_notes' => 500],
        'ios' => ['name' => 30, 'subtitle' => 30, 'description' => 4000, 'promotional_text' => 170, 'keywords' => 100, 'release_notes' => 4000, 'review_notes' => 4000],
    ];

    public function name(): string
    {
        return 'store_listing';
    }

    public function description(): string
    {
        return 'Prepare localized Google Play and App Store metadata and release notes from confirmed product facts. Gives the AI a writing brief, an initial draft and field validation. Call again with edited metadata to validate limits, Unicode, bytes and clean punctuation. Does not publish.';
    }

    public function inputSchema(): array
    {
        $platforms = [];
        foreach (self::LIMITS as $platform => $limits) {
            $fields = [];
            foreach ($limits as $field => $limit) {
                $fields[$field] = ['type' => 'string', 'description' => "Maximum {$limit} " . ($this->isBytes($platform, $field) ? 'UTF-8 bytes' : 'Unicode characters')];
            }
            $platforms[$platform] = ['type' => 'object', 'properties' => $fields, 'additionalProperties' => false];
        }
        return [
            'type' => 'object',
            'properties' => [
                'platform' => ['type' => 'string', 'enum' => ['android', 'ios', 'both'], 'default' => 'both'],
                'release_type' => ['type' => 'string', 'enum' => ['first_release', 'update'], 'default' => 'update'],
                'locale' => ['type' => 'string', 'default' => 'pt-BR', 'description' => 'One locale per call. Confirm the locale exists in the target console.'],
                'name' => ['type' => 'string'],
                'summary' => ['type' => 'string', 'description' => 'Confirmed purpose and benefit, in the selected locale.'],
                'audience' => ['type' => 'string'],
                'features' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Confirmed user-facing capabilities. Included plugins alone are not features.'],
                'changes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Confirmed changes for this release only. Never infer changes from installed plugins.'],
                'metadata' => ['type' => 'object', 'properties' => $platforms, 'additionalProperties' => false, 'description' => 'Edited draft fields. Overrides generated fields. No automatic truncation.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(array $args): string
    {
        if (array_diff(array_keys($args), array_keys($this->inputSchema()['properties']))) {
            throw new \InvalidArgumentException('Unknown store listing argument.');
        }
        $platform = Context::choice($args, 'platform', ['android', 'ios', 'both'], 'both');
        $release = Context::choice($args, 'release_type', ['first_release', 'update'], 'update');
        foreach (['locale', 'name', 'summary', 'audience'] as $field) {
            if (isset($args[$field]) && (!is_string($args[$field]) || strlen($args[$field]) > 20000)) {
                throw new \InvalidArgumentException("{$field} must be text up to 20000 bytes.");
            }
        }
        $locale = $args['locale'] ?? 'pt-BR';
        if (!preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $locale)) {
            throw new \InvalidArgumentException('Invalid locale. Use a language tag such as pt-BR.');
        }
        foreach (['features', 'changes'] as $field) {
            $values = $args[$field] ?? [];
            if (!is_array($values) || !array_is_list($values) || count($values) > 100) {
                throw new \InvalidArgumentException("{$field} must be a list of at most 100 strings.");
            }
            foreach ($values as $value) {
                if (!is_string($value) || trim($value) === '' || strlen($value) > 20000) {
                    throw new \InvalidArgumentException("{$field} must contain non-empty text up to 20000 bytes per item.");
                }
            }
        }
        $metadata = $args['metadata'] ?? [];
        if (!is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) {
            throw new \InvalidArgumentException('metadata must be an object.');
        }
        $platforms = $platform === 'both' ? ['android', 'ios'] : [$platform];
        foreach ($metadata as $key => $fields) {
            if (!in_array($key, $platforms, true) || !is_array($fields) || ($fields !== [] && array_is_list($fields))) {
                throw new \InvalidArgumentException('metadata must contain only the selected platforms and field objects.');
            }
            foreach ($fields as $field => $value) {
                if (!isset(self::LIMITS[$key][$field]) || !is_string($value) || strlen($value) > 40000) {
                    throw new \InvalidArgumentException('Invalid metadata field or value.');
                }
            }
        }
        $draft = [];
        $validation = [];
        $missing = [];
        $valid = true;
        foreach ($platforms as $key) {
            $fields = array_fill_keys(array_keys(self::LIMITS[$key]), '');
            $fields['name'] = $args['name'] ?? '';
            $fields['description'] = implode("\n\n", array_filter([
                $args['summary'] ?? '', implode("\n", $args['features'] ?? []),
            ], fn ($text) => $text !== ''));
            $fields['release_notes'] = implode("\n", $args['changes'] ?? []);
            if ($key === 'android') {
                $fields['short_description'] = $args['summary'] ?? '';
            }
            $fields = array_replace($fields, $metadata[$key] ?? []);
            $required = $key === 'android' ? ['name', 'short_description', 'description'] : ['name', 'description', 'keywords'];
            if ($release === 'update') {
                $required[] = 'release_notes';
            }
            foreach ($fields as $field => $value) {
                $bytes = $this->isBytes($key, $field);
                $length = $bytes ? strlen($value) : mb_strlen($value, 'UTF-8');
                $issues = [];
                if (!mb_check_encoding($value, 'UTF-8')) $issues[] = 'invalid_utf8';
                if ($length > self::LIMITS[$key][$field]) $issues[] = 'over_limit';
                if (preg_match('/[\x{2013}\x{2014}]/u', $value)) $issues[] = 'use_plain_punctuation';
                if (strip_tags($value) !== $value) $issues[] = 'use_plain_text';
                if (in_array($field, $required, true) && trim($value) === '') {
                    $issues[] = 'missing';
                    $missing[] = "{$key}.{$field}";
                }
                if ($key === 'ios' && $field === 'name' && $length > 0 && $length < 2) $issues[] = 'minimum_2_characters';
                $validation[$key][$field] = ['length' => $length, 'limit' => self::LIMITS[$key][$field], 'unit' => $bytes ? 'bytes' : 'characters', 'issues' => $issues];
                if ($issues !== []) $valid = false;
            }
            $draft[$key] = $fields;
        }

        return Context::encode([
            'locale' => $locale,
            'release_type' => $release,
            'status' => 'draft',
            'communication' => Context::communication(),
            'freshness' => Context::freshness(),
            'project' => Context::project($platform),
            'brief' => array_intersect_key($args, array_flip(['name', 'summary', 'audience', 'features', 'changes'])),
            'draft' => $draft,
            'validation' => $validation,
            'text_checks_passed' => $valid,
            'missing_fields' => $missing,
            'writing_instructions' => [
                'The initial draft is assembled from supplied facts. The host AI writes the final copy in the selected locale. Ask for purpose, audience and real benefits when missing.',
                'Describe the whole product in description. Describe only confirmed release changes in release_notes. Ask for the intended diff or changelog if changes are unknown. Do not invent generic bug fixes.',
                'Offer up to three concise title or subtitle options when useful. Explain the recommendation using the actual audience and purpose.',
                'Use natural sentences and plain text. No em dash, en dash, keyword stuffing, unsupported superlatives, competitor names or unverified privacy claims.',
                'For iOS keywords, use relevant comma-separated terms without duplicating the app or company name. The reference specification uses 100 UTF-8 bytes. review_notes also uses bytes.',
                'Return each field separately, ready to copy, with its count. Rewrite over-limit fields, never silently truncate. Call store_listing again with the edited metadata to validate.',
                'For first releases, iOS release notes can be omitted. This tool asks for update notes on both platforms as an editorial completeness check.',
                'Text checks do not verify factual accuracy, all required console metadata, supported locale, assets or approval. Confirm URLs, categories, pricing, screenshots and declarations in the publication guide.',
            ],
            'sources' => [
                'android_listing' => 'https://support.google.com/googleplay/android-developer/answer/9859152',
                'android_release_notes' => 'https://support.google.com/googleplay/android-developer/answer/9859348',
                'ios_app_information' => 'https://developer.apple.com/help/app-store-connect/reference/app-information/app-information/',
                'ios_version_information' => 'https://developer.apple.com/help/app-store-connect/reference/app-information/platform-version-information/',
            ],
        ]);
    }

    private function isBytes(string $platform, string $field): bool
    {
        return $platform === 'ios' && in_array($field, ['keywords', 'review_notes'], true);
    }
}
