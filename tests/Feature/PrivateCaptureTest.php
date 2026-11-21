<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PrivateCaptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_invitation_is_required_and_redeems_once_for_its_email(): void
    {
        $credentials = [
            'name' => 'Fixture One',
            'password' => 'synthetic password',
            'password_confirmation' => 'synthetic password',
            'email' => 'wrong@example.test',
        ];

        $this->post('/register', $credentials)->assertNotFound();
        $this->get('/')->assertRedirect('/login');
        $this->post('/invite/'.str_repeat('A', 64), $credentials)->assertNotFound();

        $url = $this->invite('Fixture-One@Example.test');
        $token = basename($url);
        $this->assertDatabaseHas('invitations', [
            'email' => 'fixture-one@example.test',
            'token_hash' => hash('sha256', $token),
        ]);
        $this->get($url)->assertOk()->assertSee('fixture-one@example.test');
        $this->post($url, $credentials)->assertRedirect('/');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'fixture-one@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'wrong@example.test']);
        $this->post('/logout')->assertRedirect('/login');
        $this->post($url, $credentials)->assertNotFound();
    }

    public function test_expired_and_replaced_invitations_are_invalid(): void
    {
        $original = $this->invite('fixture@example.test');
        $replacement = $this->invite('fixture@example.test');
        $this->get($original)->assertNotFound();
        $this->get($replacement)->assertOk();

        $this->travel(8)->days();
        $this->get($replacement)->assertNotFound();
    }

    public function test_capture_is_immediate_and_reads_and_corrections_are_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $wording = "  Synthetic key B-17.\nStore it in the top drawer.  ";

        config(['queue.default' => 'database']);
        $response = $this->actingAs($owner)->post('/memories', [
            'wording' => $wording,
            'experience_date' => '2025-02',
            'user_id' => $other->id,
        ])->assertRedirect()->assertSessionHas('status', 'Saved');

        $memory = $owner->memories()->sole();
        $url = $response->headers->get('Location');
        $this->assertSame($wording, $memory->wording);
        $this->assertSame('2025-02', $memory->experience_date);
        $captureDate = $memory->created_at->toDateString();

        $this->get($url)->assertOk()->assertSee('Saved')->assertSee($wording)->assertSee('2025-02');
        $this->post('/logout');
        $this->post('/login', ['email' => strtoupper($owner->email), 'password' => 'password'])
            ->assertRedirect('/');
        $this->get('/')->assertOk()->assertSee($url);
        $this->post('/search', ['wording' => 'b-17'])->assertOk()->assertSee($url);
        $this->post('/search', ['wording' => '%'])->assertOk()->assertSee('No matching memories.');

        $this->actingAs($other);
        $this->get('/')->assertOk()->assertDontSee('B-17');
        $this->post('/search', ['wording' => 'B-17'])
            ->assertOk()->assertSee('No matching memories.')->assertDontSee($url);
        $this->get($url)->assertNotFound();
        $this->patch($url, ['wording' => 'Other account'])->assertNotFound();

        $this->travel(1)->days();
        $this->actingAs($owner)->patch($url, ['wording' => 'Corrected fixture', 'experience_date' => '2025'])
            ->assertRedirect($url);
        $this->assertSame('2025', $memory->refresh()->experience_date);
        $this->assertSame($captureDate, $memory->created_at->toDateString());
    }

    public function test_capture_preserves_unknown_and_day_precision_and_rejects_invalid_dates(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/memories', ['wording' => 'Unknown date'])->assertRedirect();
        $this->post('/memories', ['wording' => 'Day precision', 'experience_date' => '2024-02-29'])->assertRedirect();
        $this->post('/memories', ['wording' => 'Bad date', 'experience_date' => '2025-02-29'])
            ->assertSessionHasErrors('experience_date');
        $this->post('/memories', ['wording' => '   '])->assertSessionHasErrors('wording');

        $this->assertDatabaseHas('memories', ['wording' => 'Unknown date', 'experience_date' => null]);
        $this->assertDatabaseHas('memories', ['wording' => 'Day precision', 'experience_date' => '2024-02-29']);
        $this->assertDatabaseCount('memories', 2);
    }

    public function test_postgresql_has_pgvector_and_database_queue_tables(): void
    {
        $this->assertSame('[1,2]', DB::selectOne("SELECT '[1,2]'::vector::text AS value")->value);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    protected function beforeRefreshingDatabase(): void
    {
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'rank_it_test') {
            throw new RuntimeException('Run the capture tests in the testing environment against rank_it_test.');
        }
    }

    private function invite(string $email): string
    {
        $this->assertSame(0, Artisan::call('pilot:invite', ['email' => $email]));

        return trim(Artisan::output());
    }
}
