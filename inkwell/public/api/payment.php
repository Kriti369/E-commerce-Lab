<?php
// PAYMENT GATEWAY (SIMULATED).
// A real shop would call Stripe/PayPal here and let THEM handle the card.
// Replace the body of process_payment() with the gateway call; nothing else needs to change.
declare(strict_types=1);

function process_payment(int $amountCents, string $cardNumber, string $exp, string $cvc): array {
    usleep(600000); // pretend to talk to a bank

    // Test cards for the demo:
    if ($cardNumber === '4000000000000002') {
        return ['approved' => false, 'ref' => 'sim_' . bin2hex(random_bytes(6)), 'note' => 'Card declined by issuer.'];
    }
    if ($cardNumber === '4000000000009995') {
        return ['approved' => false, 'ref' => 'sim_' . bin2hex(random_bytes(6)), 'note' => 'Insufficient funds.'];
    }
    return ['approved' => true, 'ref' => 'sim_' . bin2hex(random_bytes(6)), 'note' => 'Approved.'];
}
