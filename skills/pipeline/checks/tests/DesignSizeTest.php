<?php

$none = ['package' => false, 'migration' => false, 'auth' => false, 'ui' => false];

it('reads the design size from the spec header and fails strict', function () {
    expect(DesignSize::fromSpec("# Title\n\n**Design size:** Bounded\n\nBody"))->toBe(DesignSize::Bounded);
    expect(DesignSize::fromSpec("# Title\n\n**Design size:** Architectural\n"))->toBe(DesignSize::Architectural);
    // every spec written before the header existed keeps the full chain
    expect(DesignSize::fromSpec("# Title\n\nNo header.\n"))->toBe(DesignSize::Architectural);
    // mangled values are not a quiet way into Bounded
    expect(DesignSize::fromSpec("**Design size:** bounded\n"))->toBe(DesignSize::Architectural);
    expect(DesignSize::fromSpec("**Design size:** Bounded-ish\n"))->toBe(DesignSize::Architectural);
    expect(DesignSize::fromSpec("Prose mentioning **Design size:** Bounded mid-line.\n"))->toBe(DesignSize::Architectural);
    // the value belongs on the header line itself, not on a line below it
    expect(DesignSize::fromSpec("**Design size:**\nBounded\n"))->toBe(DesignSize::Architectural);
});

it('escalates a bounded design on a migration, an authorization change, or more than 100 code lines', function () use ($none) {
    expect(DesignSize::Bounded->escalation($none, 100))->toBeNull();
    expect(DesignSize::Bounded->escalation($none, 101))->toBe('code-lines: 101 > 100');
    expect(DesignSize::Bounded->escalation([...$none, 'migration' => true], 5))->toBe('migration');
    expect(DesignSize::Bounded->escalation([...$none, 'auth' => true], 5))->toBe('auth');
});

it('does not escalate a bounded design on a package bump or a UI change alone', function () use ($none) {
    expect(DesignSize::Bounded->escalation([...$none, 'package' => true, 'ui' => true], 5))->toBeNull();
});

it('never escalates an architectural design', function () use ($none) {
    expect(DesignSize::Architectural->escalation([...$none, 'migration' => true, 'auth' => true], 5000))->toBeNull();
});
