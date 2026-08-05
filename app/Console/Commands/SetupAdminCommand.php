<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SetupAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'setup:admin
                            {--name= : Super Admin full name}
                            {--email= : Super Admin email address}
                            {--password= : Super Admin password}
                            {--phone= : Super Admin phone number}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or update Super Admin account for Shirin Beauty Atelier';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('====================================================');
        $this->info('   Shirin Beauty Atelier — Super Admin Account Setup');
        $this->info('====================================================');
        $this->newLine();

        $name = $this->option('name') ?: $this->ask('Enter Super Admin Full Name', 'Super Admin');
        
        $email = $this->option('email');
        while (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = $this->ask('Enter Super Admin Email Address', 'admin@shirinfashionbd.test');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error('Invalid email format. Please enter a valid email.');
            }
        }

        $password = $this->option('password');
        if (!$password) {
            $password = $this->secret('Enter Super Admin Password (min 8 characters, default: password)') ?: 'password';
            if (strlen($password) < 8) {
                $this->warn('Password was less than 8 characters, using default "password".');
                $password = 'password';
            }
        }

        $phone = $this->option('phone') ?: $this->ask('Enter Super Admin Phone Number', '01700000000');

        try {
            $admin = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make($password),
                    'role' => 'admin',
                    'phone' => $phone,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            $this->newLine();
            $this->info('SUCCESS: Super Admin Account created/updated successfully!');
            $this->table(
                ['Field', 'Value'],
                [
                    ['ID', $admin->id],
                    ['Name', $admin->name],
                    ['Email', $admin->email],
                    ['Role', $admin->role],
                    ['Phone', $admin->phone],
                    ['Password', '(Encrypted)'],
                ]
            );

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to create Super Admin: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
