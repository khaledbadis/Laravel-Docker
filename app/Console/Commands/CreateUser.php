<?php

namespace App\Console\Commands;

use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateUser extends Command
{
    protected $signature = 'app:create-user';

    protected $description = 'Create an account interactively without opening public registration';

    public function handle(): int
    {
        $input = [
            'name' => trim((string) $this->ask('Name')),
            'email' => Str::lower(trim((string) $this->ask('Email'))),
            'password' => $this->secret('Password'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];
        $validator = Validator::make($input, (new RegisterRequest)->rules());
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        User::create(collect($validator->validated())->only(['name', 'email', 'password'])->all());
        $this->info('Account created.');

        return self::SUCCESS;
    }
}
