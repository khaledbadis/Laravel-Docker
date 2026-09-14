<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoSeeder is restricted to the local environment.');
        }

        DB::transaction(function () {
            // Never change the password or tasks of an existing account.
            if (User::where('email', 'demo@example.test')->exists()) {
                $this->command?->info('Demo account already exists; no data changed.');

                return;
            }

            $user = User::create([
                'name' => 'Demo User',
                'email' => 'demo@example.test',
                'password' => Hash::make('local-demo-password'),
            ]);
            foreach (['Learn Docker volumes', 'Try the task filters', 'Write a small test'] as $title) {
                $user->tasks()->create(['title' => $title]);
            }
            $task = $user->tasks()->create(['title' => 'Set up Laravel']);
            $task->completed_at = now();
            $task->save();
        });
    }
}
