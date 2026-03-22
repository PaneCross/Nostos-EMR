<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsuranceCoverage extends Model
{
    protected $table = 'emr_insurance_coverages';

    protected $fillable = [
        'participant_id', 'payer_type',
        'member_id', 'group_id', 'plan_name', 'bin_pcn',
        'effective_date', 'term_date', 'is_active',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'term_date'      => 'date',
        'is_active'      => 'boolean',
    ];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'participant_id');
    }

    public function payerLabel(): string
    {
        return match ($this->payer_type) {
            'medicare_a' => 'Medicare Part A',
            'medicare_b' => 'Medicare Part B',
            'medicare_d' => 'Medicare Part D',
            'medicaid'   => 'Medicaid',
            default      => 'Other',
        };
    }
}
