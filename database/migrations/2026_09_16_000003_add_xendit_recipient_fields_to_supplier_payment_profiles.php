<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payment_profiles', function (Blueprint $table): void {
            $table->string('recipient_type', 20)->default('business')->after('supplier_id');
            $table->string('business_name', 50)->nullable()->after('recipient_type');
            $table->string('given_name', 50)->nullable()->after('business_name');
            $table->string('surname', 50)->nullable()->after('given_name');
            $table->string('recipient_country', 2)->nullable()->after('surname');
            $table->string('recipient_province_state')->nullable()->after('recipient_country');
            $table->string('recipient_city')->nullable()->after('recipient_province_state');
            $table->string('recipient_street_line_1')->nullable()->after('recipient_city');
            $table->string('recipient_street_line_2')->nullable()->after('recipient_street_line_1');
            $table->string('recipient_postal_code', 32)->nullable()->after('recipient_street_line_2');
        });

        DB::table('supplier_payment_profiles')
            ->join('suppliers', 'suppliers.id', '=', 'supplier_payment_profiles.supplier_id')
            ->select([
                'supplier_payment_profiles.id',
                'suppliers.name',
                'suppliers.address',
                'suppliers.city',
                'suppliers.country',
            ])
            ->orderBy('supplier_payment_profiles.id')
            ->each(function (object $profile): void {
                $country = strtoupper(trim((string) $profile->country));

                DB::table('supplier_payment_profiles')
                    ->where('id', $profile->id)
                    ->update([
                        'business_name' => $profile->name,
                        'recipient_country' => strlen($country) === 2 ? $country : null,
                        'recipient_city' => $profile->city,
                        'recipient_street_line_1' => $profile->address,
                        'status' => 'unverified',
                        'verified_by' => null,
                        'verified_at' => null,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('supplier_payment_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'recipient_type',
                'business_name',
                'given_name',
                'surname',
                'recipient_country',
                'recipient_province_state',
                'recipient_city',
                'recipient_street_line_1',
                'recipient_street_line_2',
                'recipient_postal_code',
            ]);
        });
    }
};
