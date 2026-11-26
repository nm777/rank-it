<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MemoryControllerTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['/memories', '  I rated the toolbox 8/10.  ', [], '  I rated the toolbox 8/10.  '])]
    #[TestWith(['/input', '  I rated the toolbox 8/10.  ', ['intent' => 'save'], '  I rated the toolbox 8/10.  '])]
    #[TestWith(['/input', 'Save this fact: I rated the toolbox 8/10.', ['intent' => 'ask', 'followup' => 1], 'I rated the toolbox 8/10.'])]
    #[TestWith(['/input', 'I rated the toolbox 8/10.', [], 'I rated the toolbox 8/10.'])]
    public function test_save_routes_capture_the_rating_and_enqueue_indexing(string $url, string $wording, array $routing, string $savedWording): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post($url, $routing + [
            'wording' => $wording,
            'experience_date' => '2024-02',
            'corrects_passage_id' => 123,
        ]);

        $memory = $owner->memories()->sole();
        $response->assertRedirect('/')->assertSessionHasAll([
            'status' => 'Saved',
            'evidence_changed' => true,
            'saved_memory_id' => $memory->id,
        ]);
        $this->assertSame($savedWording, $memory->wording);
        $this->assertSame('2024-02', $memory->experience_date);
        $this->assertNull($memory->corrects_passage_id);
        $this->assertDatabaseHas('ratings', ['memory_id' => $memory->id, 'score' => 8, 'scale' => 10, 'experience' => '2024-02']);
        $this->assertDatabaseHas('inference_work', ['memory_id' => $memory->id, 'stage' => 'embed']);
        $this->assertDatabaseCount('jobs', 1);
        Http::assertNothingSent();
    }

    public function test_followup_save_rejects_an_invalid_date_without_writing(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post('/input', [
            'wording' => 'Save this fact: I rated the toolbox 8/10.',
            'intent' => 'ask',
            'followup' => 1,
            'experience_date' => '2025-02-29',
        ])->assertSessionHasErrors([
            'experience_date' => 'Enter a year, month, or day in YYYY, YYYY-MM, or YYYY-MM-DD format.',
        ]);

        $this->assertDatabaseCount('memories', 0);
        $this->assertDatabaseCount('ratings', 0);
        $this->assertDatabaseCount('inference_work', 0);
        $this->assertDatabaseCount('jobs', 0);
    }
}
