<?php

use App\Domain\AntiCheat\RequestSigner;

it('signs and verifies canonical requests', function () {
    $payload = RequestSigner::canonical('1700000000', 'post', 'api/v1/x', '{"a":1}');
    expect($payload)->toBe("1700000000\nPOST\n/api/v1/x\n{\"a\":1}");

    $sig = RequestSigner::sign('secret', $payload);
    expect(RequestSigner::verify('secret', $payload, $sig))->toBeTrue()
        ->and(RequestSigner::verify('secret', $payload, strtoupper($sig)))->toBeTrue()
        ->and(RequestSigner::verify('other', $payload, $sig))->toBeFalse()
        ->and(RequestSigner::verify('secret', $payload.'x', $sig))->toBeFalse();
});

it('checks timestamp freshness', function () {
    expect(RequestSigner::isFresh(1000, 300, 1200))->toBeTrue()
        ->and(RequestSigner::isFresh(1000, 300, 1301))->toBeFalse()
        ->and(RequestSigner::isFresh(1400, 300, 1000))->toBeFalse();
});
