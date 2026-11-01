<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core;

final readonly class Config
{
    /**
     * Words that mark a key as sensitive when the config does not list its own.
     */
    public const array DEFAULT_SCRUB = ['password', 'secret', 'token', 'authorization', 'cookie', 'api_key', 'credit_card'];

    /**
     * @param  list<string>  $scrub
     */
    public function __construct(
        public string $key,
        public string $url,
        public bool $enabled = true,
        public ?string $release = null,
        public ?string $environment = null,
        public float $sampleRate = 1.0,
        public string $basePath = '',
        public int $maxBreadcrumbs = 30,
        public array $scrub = self::DEFAULT_SCRUB,
        public bool $sendDefaultPii = false,
        public float $timeout = 3.0,
        public int $sourceCodeLines = 5,
    ) {}

    /**
     * Create the config from the "nativewatch" config file.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config, string $basePath = ''): self
    {
        /** @var list<string> $scrub */
        $scrub = is_array($config['scrub'] ?? null) ? array_values($config['scrub']) : self::DEFAULT_SCRUB;

        return new self(
            key: self::string($config, 'key') ?? '',
            url: self::string($config, 'url') ?? '',
            enabled: (bool) ($config['enabled'] ?? true),
            release: self::string($config, 'release'),
            environment: self::string($config, 'environment'),
            sampleRate: is_numeric($config['sample_rate'] ?? null) ? (float) $config['sample_rate'] : 1.0,
            basePath: $basePath,
            maxBreadcrumbs: is_int($config['max_breadcrumbs'] ?? null) ? $config['max_breadcrumbs'] : 30,
            scrub: $scrub,
            sendDefaultPii: (bool) ($config['send_default_pii'] ?? false),
            timeout: is_numeric($config['timeout'] ?? null) ? (float) $config['timeout'] : 3.0,
            sourceCodeLines: is_int($config['source_code_lines'] ?? null) ? $config['source_code_lines'] : 5,
        );
    }

    /**
     * Determine if events should be reported.
     */
    public function isEnabled(): bool
    {
        return $this->enabled && $this->key !== '' && $this->url !== '';
    }

    /**
     * Get the URL that events of the given type are sent to: "occurrences" or "activities".
     */
    public function endpoint(mixed $type = 'occurrences'): string
    {
        return mb_rtrim($this->url, '/').'/api/v1/'.($type === 'activities' ? 'activities' : 'occurrences');
    }

    /**
     * Get the same config with another release.
     */
    public function withRelease(?string $release): self
    {
        return new self(...[...get_object_vars($this), 'release' => $release]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function string(array $config, string $key): ?string
    {
        return isset($config[$key]) && is_string($config[$key]) && $config[$key] !== '' ? $config[$key] : null;
    }
}
