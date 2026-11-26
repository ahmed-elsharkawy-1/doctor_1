<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    protected $fillable = [
        'key',
        'category',
        'is_broadcast',
        'body_ar',
        'provider_template_name',
        'language_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_broadcast' => 'boolean',
        ];
    }

    /**
     * The approved template Meta is asked to send: the multi-branch version
     * once that switch is on (ELA-22), the one in the table otherwise.
     */
    public function providerName(): string
    {
        if (self::usesBranchVersions() && isset(config('clinic.messaging.branch_template_names')[$this->key])) {
            return config('clinic.messaging.branch_template_names')[$this->key];
        }

        return $this->provider_template_name ?: $this->key;
    }

    /** Whether this template, as sent, carries the branch line. */
    public function carriesBranch(): bool
    {
        return self::usesBranchVersions() && isset(config('clinic.messaging.branch_template_names')[$this->key]);
    }

    public static function usesBranchVersions(): bool
    {
        return (bool) config('clinic.messaging.branch_templates');
    }
}
