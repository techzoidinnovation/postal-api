<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DeleteUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:delete-user {userId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $userId = $this->argument('userId');
        $user = \App\Models\User::where('id', $userId)->orWhere('email', $userId)->first();
        if (!$user) {
            $this->error('User not found.');
            return;
        }
        $user->tokens()->delete(); // Delete all tokens associated with the user
        $user->delete(); // Delete the user
        $this->info('User deleted successfully.');
    }
}
