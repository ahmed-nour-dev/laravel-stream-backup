<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit;

use Ahmednour\StreamBackup\Enums\RestoreStatus;
use PHPUnit\Framework\TestCase;

final class RestoreStatusTransitionTest extends TestCase
{
    public function test_importing_can_reach_either_completion_status(): void
    {
        self::assertTrue(RestoreStatus::Importing->canTransitionTo(RestoreStatus::Completed));
        self::assertTrue(RestoreStatus::Importing->canTransitionTo(RestoreStatus::CompletedWithWarnings));
        self::assertTrue(RestoreStatus::Importing->canTransitionTo(RestoreStatus::Failed));
        self::assertTrue(RestoreStatus::Importing->canTransitionTo(RestoreStatus::Aborted));
    }

    public function test_completed_and_completed_with_warnings_are_distinct_terminal_states(): void
    {
        self::assertTrue(RestoreStatus::Completed->isTerminal());
        self::assertTrue(RestoreStatus::CompletedWithWarnings->isTerminal());

        // A clean restore is never reported as, or confused with, a
        // best-effort restore that swallowed skippable SQL errors.
        self::assertNotSame(RestoreStatus::Completed, RestoreStatus::CompletedWithWarnings);
        self::assertSame('completed', RestoreStatus::Completed->value);
        self::assertSame('completed_with_warnings', RestoreStatus::CompletedWithWarnings->value);
    }

    public function test_terminal_states_cannot_transition_further(): void
    {
        foreach ([RestoreStatus::Completed, RestoreStatus::CompletedWithWarnings, RestoreStatus::Failed, RestoreStatus::Aborted] as $terminal) {
            self::assertTrue($terminal->isTerminal(), "{$terminal->value} should be terminal");
            self::assertFalse($terminal->canTransitionTo(RestoreStatus::Importing));
            self::assertFalse($terminal->canTransitionTo(RestoreStatus::Completed));
        }
    }

    public function test_non_terminal_states_are_not_terminal(): void
    {
        self::assertFalse(RestoreStatus::Pending->isTerminal());
        self::assertFalse(RestoreStatus::Downloading->isTerminal());
        self::assertFalse(RestoreStatus::Decrypting->isTerminal());
        self::assertFalse(RestoreStatus::Decompressing->isTerminal());
        self::assertFalse(RestoreStatus::Parsing->isTerminal());
        self::assertFalse(RestoreStatus::Importing->isTerminal());
    }
}
