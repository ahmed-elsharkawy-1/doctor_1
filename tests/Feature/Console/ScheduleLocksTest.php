<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * A scheduled run killed before it lets go of its lock — a deploy restarting
 * the scheduler mid-run — must not stop that job for long. On 7 November 2026
 * one such lock held back a clinic's whole morning reminder for the day.
 */
class ScheduleLocksTest extends TestCase
{
    /** @return list<Event> */
    private function events(): array
    {
        return app(Schedule::class)->events();
    }

    private function event(string $command): Event
    {
        foreach ($this->events() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        $this->fail("{$command} is not scheduled.");
    }

    public function test_every_job_takes_a_lock_that_lapses_within_half_an_hour(): void
    {
        $this->assertNotEmpty($this->events());

        foreach ($this->events() as $event) {
            $this->assertTrue($event->withoutOverlapping, "{$event->command} runs without a lock.");
            $this->assertLessThanOrEqual(30, $event->expiresAt, "{$event->command} keeps its lock for {$event->expiresAt} minutes.");
        }
    }

    public function test_the_every_minute_reminders_lapse_within_ten_minutes(): void
    {
        foreach (['clinic:send-reminders', 'clinic:send-daily-reminders', 'clinic:release-lapsed-holds'] as $command) {
            $this->assertLessThanOrEqual(10, $this->event($command)->expiresAt, $command);
        }
    }

    public function test_clearing_the_schedule_cache_releases_a_left_over_lock(): void
    {
        $event = $this->event('clinic:send-daily-reminders');

        $this->assertTrue($event->mutex->create($event));
        $this->assertTrue($event->mutex->exists($event));

        $this->artisan('schedule:clear-cache')->assertSuccessful();

        $this->assertFalse($event->mutex->exists($event));
    }
}
