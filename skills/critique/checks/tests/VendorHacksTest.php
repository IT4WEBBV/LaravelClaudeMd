<?php

it('finds it4web php files newer than installed.json', function () {
    $root = sys_get_temp_dir() . '/critique-vendor-' . uniqid();
    mkdir("$root/vendor/composer", 0777, true);
    mkdir("$root/vendor/it4web/tallui", 0777, true);
    file_put_contents("$root/vendor/composer/installed.json", '{}');
    touch("$root/vendor/composer/installed.json", time() - 60);
    file_put_contents("$root/vendor/it4web/tallui/Hacked.php", '<?php');

    $findings = check_vendor_hacks($root);
    expect($findings)->toHaveCount(1)
        ->and($findings[0]['file'])->toContain('Hacked.php')
        ->and($findings[0]['check'])->toBe('vendor-hack');
});

it('returns empty when vendor/it4web is absent', function () {
    $root = sys_get_temp_dir() . '/critique-vendor-' . uniqid();
    mkdir($root, 0777, true);
    expect(check_vendor_hacks($root))->toBe([]);
});
