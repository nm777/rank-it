<?php

namespace Tests\Feature;

use App\Inference\Budget;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountControllerTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        throw_unless(app()->environment('testing') && DB::selectOne('select current_database() as db')->db === 'rank_it_test',
            \RuntimeException::class);
    }

    public function test_guests_are_redirected_to_login_from_account_pages(): void
    {
        $this->get('/profile')->assertRedirect('/login');
        $this->get('/profile/usage')->assertRedirect('/login');
        $this->get('/profile/password')->assertRedirect('/login');
        $this->patch('/profile')->assertRedirect('/login');
        $this->put('/profile/password')->assertRedirect('/login');
    }

    public function test_profile_displays_account_details_and_links_to_usage(): void
    {
        $user = User::factory()->create(['name' => 'Pat <Example>']);

        $this->actingAs($user)->get('/profile')
            ->assertOk()->assertSee($user->name)->assertSee($user->email)
            ->assertSee(route('profile.show'))->assertSee(route('profile.usage'))
            ->assertSee(route('profile.password.show'))->assertDontSee('name="password_confirmation"', false);
    }

    public function test_user_updates_name_without_a_password_when_email_is_unchanged(): void
    {
        $user = User::factory()->create(['email' => 'pat@example.com']);

        $this->actingAs($user)->patch('/profile', [
            'name' => 'Pat Example',
            'email' => 'PAT@example.com',
        ])->assertRedirect('/profile')->assertSessionHas('status', 'Profile updated.');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Pat Example',
            'email' => 'pat@example.com',
            'email_verified_at' => $user->email_verified_at,
        ]);
    }

    public function test_email_change_requires_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/profile')->patch('/profile', [
            'name' => 'Pat Example',
            'email' => 'new@example.com',
        ])->assertRedirect('/profile')->assertSessionHasErrors(['current_password']);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]);
    }

    public function test_email_change_rejects_an_incorrect_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/profile')->patch('/profile', [
            'name' => $user->name,
            'email' => 'new@example.com',
            'current_password' => 'incorrect password',
        ])->assertRedirect('/profile')->assertSessionHasErrors(['current_password'])
            ->assertSessionMissing('_old_input.current_password');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
    }

    public function test_user_updates_name_and_email_with_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/profile', [
            'name' => 'Pat Example',
            'email' => 'PAT@example.com',
            'current_password' => 'password',
            'password' => 'ignored input',
        ])->assertRedirect('/profile')->assertSessionHas('status', 'Profile updated.');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Pat Example',
            'email' => 'pat@example.com',
            'email_verified_at' => null,
            'password' => $user->getOriginal('password'),
        ]);
    }

    public function test_profile_rejects_an_email_owned_by_another_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create(['email' => 'other@example.com']);

        $this->actingAs($owner)->from('/profile')->patch('/profile', [
            'name' => 'Changed name',
            'email' => strtoupper($other->email),
            'current_password' => 'password',
        ])->assertRedirect('/profile')->assertSessionHasErrors(['email']);

        $this->assertDatabaseHas('users', ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email]);
    }

    public function test_change_password_page_displays_the_password_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile/password')->assertOk()
            ->assertSee('name="current_password"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false);
    }

    public function test_user_changes_password_and_can_sign_in_with_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'password',
            'password' => 'a new passphrase',
            'password_confirmation' => 'a new passphrase',
        ])->assertRedirect('/profile')->assertSessionHas('status', 'Password updated.');

        $this->assertFalse(Hash::check('password', $user->fresh()->password));
        $this->post('/logout')->assertRedirect('/login');
        $this->post('/login', ['email' => $user->email, 'password' => 'a new passphrase'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_password_change_rejects_wrong_current_password_and_mismatched_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/profile/password')->put('/profile/password', [
            'current_password' => 'incorrect password',
            'password' => 'a new passphrase',
            'password_confirmation' => 'a different passphrase',
        ])->assertRedirect('/profile/password')->assertSessionHasErrors(['current_password', 'password'])
            ->assertSessionMissing('_old_input.current_password')->assertSessionMissing('_old_input.password');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'password' => $user->password]);
    }

    public function test_usage_totals_only_settled_calls_for_the_current_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        config(['inference.cap' => 10_000_000_000]);
        $budget = app(Budget::class);
        $budget->settle($budget->reserve('answer', 500_000_000, $user->id), 400_000_000, 20);
        $budget->settle($budget->reserve('embed', 500_000_000, $user->id), 50_000_000, 10);
        $budget->reserve('answer', 900_000_000, $user->id);
        $budget->settle($budget->reserve('answer', 500_000_000, $other->id), 300_000_000, 10);
        $budget->settle($budget->reserve('answer', 500_000_000), 200_000_000, 10);

        $this->actingAs($user)->get('/profile/usage')
            ->assertOk()->assertSee('$0.45 USD')->assertSee('2 recorded AI calls');
    }
}
