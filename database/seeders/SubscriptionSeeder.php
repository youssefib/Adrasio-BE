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

        // Yearly-only billing: only price_yearly is offered. The 3/6-month
        // columns are kept in sync with the yearly price as defense-in-depth
        // (the request endpoint also restricts duration to 12 months).

        // Plan 1: Essentiel — 990 MAD/an (slug kept as 'starter' for stable plan_id references)
        SubscriptionPlan::updateOrCreate(['slug' => 'starter'], [
            'name'                  => 'Essentiel',
            'description'           => "L'essentiel pour gérer votre école au quotidien : élèves, classes, présence et paiements.",
            'max_students'          => 100,
            'max_teachers'          => 20,
            'max_classes'           => 20,
            'storage_limit_mb'      => 1024,
            'price_monthly'         => 83,
            'price_yearly'          => 990,
            'price_3months'         => 990,
            'price_6months'         => 990,
            'allows_both_types'     => true,
            'allows_file_upload'    => true,
            'allows_teacher_portal' => true,
            'is_active'             => true,
        ]);

        // Plan 2: Pro — 2 490 MAD/an
        SubscriptionPlan::updateOrCreate(['slug' => 'pro'], [
            'name'                  => 'Pro',
            'description'           => 'Pour les établissements qui veulent aller plus loin : finances avancées, paie et personnalisation.',
            'max_students'          => 1000,
            'max_teachers'          => null,
            'max_classes'           => null,
            'storage_limit_mb'      => 5120,
            'price_monthly'         => 208,
            'price_yearly'          => 2490,
            'price_3months'         => 2490,
            'price_6months'         => 2490,
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
