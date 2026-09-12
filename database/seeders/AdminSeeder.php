<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
	public function run(): void
	{
		User::updateOrCreate(
			['email' => 'faheeminnovations@gmail.com'],
			[
				'name' => 'Faheem Innovations Admin',
				'password' => Hash::make('rac@123'),
				'role' => 'admin',
			],
		);
	}
}
