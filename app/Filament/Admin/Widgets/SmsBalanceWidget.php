<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Messaging\SmsBalance;
use App\Services\Messaging\ZadxBalanceChecker;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The ZADX SMS balance on the dashboard: messages left, the plan, and when it
 * expires. Hidden entirely while ZADX is not configured.
 */
class SmsBalanceWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    // The reading is cached; there is nothing to gain from polling it.
    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return app(ZadxBalanceChecker::class)->isConfigured();
    }

    protected function getHeading(): ?string
    {
        return __('filament.sms_balance.heading');
    }

    protected function getStats(): array
    {
        $balance = app(ZadxBalanceChecker::class)->current();

        if ($balance === null) {
            return [
                Stat::make(__('filament.sms_balance.remaining'), '—')
                    ->description(__('filament.sms_balance.unavailable'))
                    ->color('gray'),
            ];
        }

        $color = $balance->alertColor() ?? 'success';

        return [
            Stat::make(__('filament.sms_balance.remaining'), number_format($balance->remaining))
                ->description(__('filament.sms_balance.of_quota', ['quota' => number_format($balance->quota)]))
                ->descriptionIcon('heroicon-m-chat-bubble-left-ellipsis')
                ->color(in_array($balance->alert(), [SmsBalance::ALERT_EMPTY, SmsBalance::ALERT_LOW, SmsBalance::ALERT_NO_PLAN], true) ? $color : 'success'),

            Stat::make(__('filament.sms_balance.plan'), $balance->hasActivePlan
                ? ($balance->plan ?? '—')
                : __('filament.sms_balance.no_plan'))
                ->color($balance->hasActivePlan ? 'gray' : 'danger'),

            Stat::make(__('filament.sms_balance.expires'), $balance->expiresOn() ?? '—')
                ->description($this->daysLeft($balance))
                ->color(in_array($balance->alert(), [SmsBalance::ALERT_EXPIRING, SmsBalance::ALERT_NO_PLAN], true) ? $color : 'gray'),
        ];
    }

    private function daysLeft(SmsBalance $balance): string
    {
        $days = $balance->daysLeft();

        return match (true) {
            $days === null => __('filament.sms_balance.no_expiry'),
            $days < 0 => __('filament.sms_balance.expired'),
            $days === 0 => __('filament.sms_balance.expires_today'),
            default => __('filament.sms_balance.days_left', ['days' => $days]),
        };
    }
}
