<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $roles = collect([
            Role::VIEWER => 'Hanya melihat laporan final/locked dan export.',
            Role::OPERATOR => 'Mengelola batch, import data mentah, dan generate laporan.',
            Role::ADMIN => 'Mengelola master data, user, dan role.',
        ])->mapWithKeys(fn (string $description, string $name) => [
            $name => Role::updateOrCreate(['name' => $name], ['description' => $description]),
        ]);

        collect([
            ['name' => 'Bambang Sutrisno', 'email' => 'viewer@lm.test', 'role' => Role::VIEWER],
            ['name' => 'Operator LM', 'email' => 'operator@lm.test', 'role' => Role::OPERATOR],
            ['name' => 'Admin MIS', 'email' => 'admin@lm.test', 'role' => Role::ADMIN],
        ])->each(function (array $user) use ($roles): void {
            User::updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'role_id' => $roles[$user['role']]->id,
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );
        });

        $this->call([
            RefKlasifikasiSeeder::class,
            RefUnitSeeder::class,
            RefUnitKomoditiSeeder::class,
            LmTemplateRowSeeder::class,
            Lm16AccountMapSeeder::class,
        ]);
    }
}
