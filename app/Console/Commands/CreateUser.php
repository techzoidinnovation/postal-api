<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-user {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new user in the application';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $email = $this->argument('email');
        // Make use of validator
        $validator = Validator::make([
            'email' => $email,
        ], [
            'email' => 'required|email|unique:users,email',
        ]);

        if ($validator->fails()) {
            $this->error('Validation failed:');
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return;
        }

        $user = \App\Models\User::create([
            'name' => 'User ' . Str::random(5), // Random name for simplicity
            'email' => $email,
            'password' => bcrypt(Str::random(10)), // Random password for simplicity
        ]);

        // create token
        $token = $user->createToken('API Token')->plainTextToken;

        $this->info('User created successfully!');
        $this->info('Here is your token:');
        $this->info($token);
    }
}
