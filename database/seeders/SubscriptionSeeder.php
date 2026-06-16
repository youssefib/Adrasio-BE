<?php

namespace Database\Seeders;

use App\Models\BankingInfo;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionSeeder extends Seeder
{
    public function run(): void
    {
        // Remove legacy plans (basic / standard / premium) left over from earlier seeders
        \App\Models\SubscriptionPlan::whereIn('slug', ['basic', 'standard', 'premium'])->delete();

        // Plan 1: Starter
        SubscriptionPlan::updateOrCreate(['slug' => 'starter'], [
            'name'                  => 'Starter',
            'description'           => 'Pour les petites structures — un seul mode scolaire, accès directeur uniquement.',
            'max_students'          => 100,
            'max_teachers'          => 10,
            'max_classes'           => 10,
            'storage_limit_mb'      => 0,
            'price_monthly'         => 50,
            'price_yearly'          => 480,
            'price_3months'         => 150,
            'price_6months'         => 270,
            'allows_both_types'     => false,
            'allows_file_upload'    => false,
            'allows_teacher_portal' => false,
            'is_active'             => true,
        ]);

        // Plan 2: Pro
        SubscriptionPlan::updateOrCreate(['slug' => 'pro'], [
            'name'                  => 'Pro',
            'description'           => 'Accès complet — les deux modes, portail enseignant, téléversement de fichiers.',
            'max_students'          => null,
            'max_teachers'          => null,
            'max_classes'           => null,
            'storage_limit_mb'      => 5120,
            'price_monthly'         => 100,
            'price_yearly'          => 960,
            'price_3months'         => 300,
            'price_6months'         => 540,
            'allows_both_types'     => true,
            'allows_file_upload'    => true,
            'allows_teacher_portal' => true,
            'is_active'             => true,
        ]);

        // Banking info
        BankingInfo::truncate();
        $bankingInfos = [
            [
                'label'   => 'CIH Bank',
                'type'    => 'bank',
                'details' => ['account_name' => 'SuiMedrassa SARL', 'iban' => 'MA64 0110 0000 0000 1234 5678 900', 'bank' => 'CIH Bank', 'city' => 'Casablanca'],
                'order'   => 1,
            ],
            [
                'label'   => 'Attijariwafa Bank',
                'type'    => 'bank',
                'details' => ['account_name' => 'SuiMedrassa SARL', 'rib' => '007 780 0001234567890112', 'bank' => 'Attijariwafa', 'city' => 'Casablanca'],
                'order'   => 2,
            ],
            [
                'label'   => 'Western Union',
                'type'    => 'western_union',
                'details' => ['recipient_name' => 'Mohammed Alami', 'country' => 'Maroc', 'city' => 'Casablanca', 'instructions' => 'Envoyez le MTCN après le transfert'],
                'order'   => 3,
            ],
            [
                'label'   => 'MoneyGram',
                'type'    => 'moneygram',
                'details' => ['recipient_name' => 'Mohammed Alami', 'country' => 'Maroc', 'city' => 'Casablanca', 'instructions' => 'Envoyez le numéro de confirmation après le transfert'],
                'order'   => 4,
            ],
            [
                'label'   => 'Paiement en espèces',
                'type'    => 'cash',
                'details' => ['instructions' => "Disponible uniquement pour les établissements situés à Casablanca. Contactez-nous pour convenir d'un rendez-vous.", 'contact' => '+212 6XX XXX XXX'],
                'order'   => 5,
            ],
        ];

        foreach ($bankingInfos as $info) {
            BankingInfo::create(array_merge($info, ['is_active' => true]));
        }
    }
}
