<?php

/**
 * How much design a change gets (`../references/engine.md` §Design size). Read from the committed
 * spec, never stored: only the exact `**Design size:** Bounded` header line is Bounded, so every
 * spec written before the header existed — and every mangled header — keeps the Architectural chain.
 */
enum DesignSize: string
{
    case Bounded = 'Bounded';
    case Architectural = 'Architectural';

    public const MAX_CODE_LINES = 100;

    public static function fromSpec(string $markdown): self
    {
        if (! preg_match('/^\*\*Design size:\*\*[ \t]*(\S+)[ \t]*$/m', $markdown, $match)) {
            return self::Architectural;
        }

        return self::tryFrom($match[1]) ?? self::Architectural;
    }

    /** Why this design must grow, or null while it may stay as it is. */
    public function escalation(array $triggers, int $codeLines): ?string
    {
        return match ($this) {
            self::Architectural => null,
            self::Bounded => match (true) {
                ! empty($triggers['migration']) => 'migration',
                ! empty($triggers['auth']) => 'auth',
                $codeLines > self::MAX_CODE_LINES => "code-lines: {$codeLines} > " . self::MAX_CODE_LINES,
                default => null,
            },
        };
    }
}
