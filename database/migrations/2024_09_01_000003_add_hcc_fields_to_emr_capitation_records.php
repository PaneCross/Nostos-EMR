<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emr_capitation_records', function (Blueprint $table) {
            // HCC risk adjustment fields for CMS-HCC model
            $table->decimal('hcc_risk_score', 8, 4)->nullable()->after('eligibility_category');
            $table->decimal('frailty_score', 8, 4)->nullable()->after('hcc_risk_score');
            $table->char('county_fips_code', 5)->nullable()->after('frailty_score');

            // Adjustment type: initial (beginning of year), mid_year, final (reconciliation)
            $table->string('adjustment_type', 20)->nullable()->after('county_fips_code');

            // Rate components for revenue integrity reconciliation
            $table->decimal('medicare_ab_rate', 10, 2)->nullable()->after('adjustment_type');
            $table->decimal('private_pay_rate', 10, 2)->nullable()->after('medicare_ab_rate');
            $table->date('rate_effective_date')->nullable()->after('private_pay_rate');
        });
    }

    public function down(): void
    {
        Schema::table('emr_capitation_records', function (Blueprint $table) {
            $table->dropColumn([
                'hcc_risk_score',
                'frailty_score',
                'county_fips_code',
                'adjustment_type',
                'medicare_ab_rate',
                'private_pay_rate',
                'rate_effective_date',
            ]);
        });
    }
};
