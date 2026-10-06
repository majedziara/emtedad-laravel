<?php

namespace Tests\Feature\Emtedad;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class EmtedadTestCase extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RolesAndPermissionsSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    protected function user(string $role = 'donor', bool $verified = true): User
    {
        $user = User::factory()->create(['password' => 'StrongPassword123', 'email_verified_at' => $verified ? now() : null]);
        $user->assignRole($role);

        return $user->refresh();
    }

    protected function token(User $user): string
    {
        return $user->createToken('test', ['*'], now()->addHour())->plainTextToken;
    }

    protected function api(string $method, string $uri, array $data = [], ?string $token = null): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->json($method, '/api/v1/'.$uri, $data, ['Accept' => 'application/json', 'Accept-Language' => 'en'] + ($token ? ['Authorization' => 'Bearer '.$token] : []));
    }

    protected function registration(array $override = []): array
    {
        return array_replace(['name' => 'Emtedad Donor', 'email' => 'donor@example.test', 'password' => 'StrongPassword123', 'password_confirmation' => 'StrongPassword123', 'preferred_locale' => 'en'], $override);
    }
}
