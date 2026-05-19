<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class NotificationTemplateManager
{
    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    public const string STATUS_ARCHIVED = 'archived';

    /**
     * @param  list<string>  $variablesAllowlist
     */
    public function createVersion(
        string $code,
        string $channel,
        string $category,
        string $language,
        string $bodyTemplate,
        ?string $subject,
        array $variablesAllowlist,
        string $status = self::STATUS_ACTIVE,
    ): object {
        $this->assertSupportedChannelAndCategory($channel, $category);
        if (! in_array($status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_ARCHIVED], true)) {
            throw new InvalidArgumentException('Unsupported notification template status.');
        }
        $this->assertBodyVariablesAllowed($bodyTemplate, $variablesAllowlist);

        return DB::transaction(function () use ($code, $channel, $category, $language, $bodyTemplate, $subject, $variablesAllowlist, $status): object {
            $latestRow = DB::table('notification_templates')
                ->where('code', $code)
                ->orderByDesc('version')
                ->first(['version']);
            $latestVersion = is_object($latestRow) && is_numeric($latestRow->version)
                ? (int) $latestRow->version
                : 0;
            $nextVersion = $latestVersion + 1;

            DB::table('notification_templates')->insert([
                'public_id' => (string) Str::ulid(),
                'code' => $code,
                'version' => $nextVersion,
                'channel' => $channel,
                'category' => $category,
                'language' => $language,
                'subject' => $subject,
                'body_template' => $bodyTemplate,
                'variables_allowlist' => json_encode($variablesAllowlist, JSON_THROW_ON_ERROR),
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('notification_templates')
                ->where('code', $code)
                ->where('version', $nextVersion)
                ->first();
            if (! is_object($row)) {
                throw new InvalidArgumentException('Notification template could not be reloaded.');
            }

            return $row;
        });
    }

    public function resolveActive(string $code, ?string $category = null, string $language = 'fr'): ?object
    {
        $query = DB::table('notification_templates')
            ->where('code', $code)
            ->where('status', self::STATUS_ACTIVE)
            ->where('language', $language)
            ->where(function ($builder): void {
                $builder->whereNull('effective_from')->orWhere('effective_from', '<=', now());
            })
            ->where(function ($builder): void {
                $builder->whereNull('effective_to')->orWhere('effective_to', '>=', now());
            });

        if ($category !== null) {
            $query->where('category', $category);
        }

        $row = $query->orderByDesc('version')->first();

        return is_object($row) ? $row : null;
    }

    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function render(object $template, array $variables): string
    {
        if ($this->rowString($template, 'status') !== self::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Only active templates can be rendered.');
        }

        $allowlist = $this->variablesAllowlist($template);
        $unexpectedVariables = array_values(array_diff($this->variableNames($variables), $allowlist));
        if ($unexpectedVariables !== []) {
            throw new InvalidArgumentException('Render variables are outside the allowlist: '.implode(', ', $unexpectedVariables).'.');
        }

        $body = $this->rowString($template, 'body_template');
        $placeholders = $this->extractPlaceholders($body);
        $unknown = array_values(array_diff($placeholders, $allowlist));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Template body references variables outside the allowlist: '.implode(', ', $unknown).'.');
        }

        $substitutions = [];
        foreach ($placeholders as $name) {
            $value = $variables[$name] ?? null;
            if ($value === null) {
                throw new InvalidArgumentException('Missing render value for variable '.$name.'.');
            }
            $substitutions['{{'.$name.'}}'] = (string) $value;
        }

        return strtr($body, $substitutions);
    }

    /**
     * @param  list<string>  $variablesAllowlist
     */
    private function assertBodyVariablesAllowed(string $bodyTemplate, array $variablesAllowlist): void
    {
        $placeholders = $this->extractPlaceholders($bodyTemplate);
        $unknown = array_values(array_diff($placeholders, $variablesAllowlist));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Template body references variables outside the allowlist: '.implode(', ', $unknown).'.');
        }
    }

    private function assertSupportedChannelAndCategory(string $channel, string $category): void
    {
        if (! in_array($channel, NotificationConsentManager::allowedChannels(), true)) {
            throw new InvalidArgumentException('Unsupported notification template channel.');
        }
        if (! in_array($category, NotificationConsentManager::allowedCategories(), true)) {
            throw new InvalidArgumentException('Unsupported notification template category.');
        }
    }

    /**
     * @param  array<string, scalar|null>  $variables
     * @return list<string>
     */
    private function variableNames(array $variables): array
    {
        return array_values(array_filter(array_keys($variables), 'is_string'));
    }

    /**
     * @return list<string>
     */
    private function extractPlaceholders(string $body): array
    {
        $matches = [];
        preg_match_all('/{{\s*([a-zA-Z][a-zA-Z0-9_]*)\s*}}/', $body, $matches);
        $names = $matches[1];

        return array_values(array_unique(array_filter($names, 'is_string')));
    }

    /**
     * @return list<string>
     */
    private function variablesAllowlist(object $template): array
    {
        $raw = ((array) $template)['variables_allowlist'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_values(array_filter($decoded, 'is_string'));
            }
        }

        return is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
    }
}
