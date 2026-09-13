<?php

declare(strict_types=1);

namespace App\Pipeline\Message;

/**
 * Start the analysis of every change whose settling window has passed (SPEC.md § 24.4).
 *
 * The same amending act reaches different laws on different days, and analysing the first arrival
 * would describe half the change. So a detected change waits until every law it touches has caught
 * up, or until `review.settling_window_hours` have gone by — whichever comes first.
 *
 * Dispatched by the scheduler, not by the detector: waiting is a property of time, not of an event.
 */
final readonly class AdvanceSettledChanges
{
}
