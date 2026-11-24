<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        throw_unless(app()->environment('testing') && DB::selectOne('select current_database() as db')->db === 'rank_it_test', \RuntimeException::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['inference.cap' => 0]);
    }

    public function test_followups_invalidate_deleted_evidence_and_dismiss_only_in_this_conversation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $memory = $owner->memories()->create(['wording' => 'The Acme locker is blue.']);
        $url = '/memories/'.$memory->id;
        $this->actingAs($owner);
        $ask = fn ($text, $followup = false) => $this->post('/input', ['wording' => $text, 'intent' => 'ask', 'followup' => $followup]);
        $first = $ask('Imagine visiting Acme')->assertSee('The Acme locker is blue.')->viewData('turn');
        $turn = $ask('Where exactly?', true)->assertSee('The Acme locker is blue.')->viewData('turn');
        $this->assertSame(1, $owner->memories()->count());
        $this->get("/conversation/$first/status")->assertJsonPath('status', 'current');
        $this->patch($url, ['wording' => 'The Acme locker is green.'])->assertRedirect();
        $this->get("/conversation/$turn/status")->assertJsonPath('status', 'edited');
        $this->get($url)->assertSee('green');
        $this->post("/conversation/$turn/dismiss", ['source' => 'memory:'.$memory->id])->assertSee('Suggestion dismissed');
        $ask('Where exactly?', true)->assertDontSee('The Acme locker is green.');
        $this->get($url)->assertSee('green');
        $this->post('/conversation/new')->assertRedirect();
        $this->get("/conversation/$turn/status")->assertNotFound();
        $ask('Where exactly?', true)->assertSee('no matching');
        $turn = $ask('Where is the Acme locker?')->assertSee('green')->viewData('turn');
        $this->actingAs($other)->delete($url)->assertNotFound();
        $this->get("/conversation/$turn/status")->assertNotFound();
        $this->actingAs($owner)->delete($url)->assertRedirect();
        $this->get("/conversation/$turn/status")->assertJsonPath('status', 'deleted');
        $owner->memories()->create(['wording' => 'A second Acme memory.']);
        $ask('Where exactly?', true)->assertSee('no matching')->assertDontSee('A second Acme memory.');
        Http::assertNothingSent();
    }

    public function test_ambiguity_conflicts_and_bounded_list_coverage(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $acme = $owner->memories()->create(['wording' => 'John from Acme hired Lee.', 'experience_date' => '2025-03']);
        $beta = $owner->memories()->create(['wording' => 'John from Beta hired Pat.', 'experience_date' => '2025']);
        config(['inference.cap' => 10000000, 'inference.answer' => ['endpoint' => 'https://answer.test/chat',
            'model' => 'fixture', 'key' => null, 'input_rate' => 1, 'output_rate' => 1, 'max_input' => 1000, 'max_output' => 100]]);
        Http::fake(function ($request) use ($acme, $beta) {
            $payload = json_decode($request['messages'][1]['content'], true);
            $claims = array_map(fn ($m) => ['source' => 'memory:'.$m->id, 'quote' => $m->wording,
                'match' => $m->is($acme) ? 'Lee' : 'Pat'], [$acme, $beta]);
            $draft = ['claims' => $claims, 'partial' => false];
            if ($payload['question'] === 'Who did John hire?') {
                $draft['clarification'] = array_column($claims, 'source');
            } elseif ($payload['question'] === 'I mean John from Acme') {
                $this->assertContains('Who did John hire?', $payload['context']);
                $draft['claims'] = [$claims[0]];
            } elseif ($payload['question'] === 'Do the accounts about John conflict?') {
                $draft['conflict'] = true;
            }

            return Http::response(['choices' => [['message' => ['content' => json_encode($draft)]]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8]]);
        });
        $this->post('/input', ['wording' => 'Who did John hire?'])->assertSee('Which person did you mean?')->assertSee('Beta')->assertSee('Acme');
        $this->post('/input', ['wording' => 'I mean John from Acme', 'intent' => 'ask', 'followup' => 1])
            ->assertSee('hired Lee')->assertDontSee('hired Pat')->assertSee('Save this identity for this memory');
        $this->post('/input', ['wording' => 'List everyone John hired in 2025-03', 'intent' => 'ask'])
            ->assertSee('1 confirmed match found')->assertSee('coverage may be incomplete')->assertSeeInOrder(['hired Lee', 'Uncertain matches', 'hired Pat']);
        $this->post('/input', ['wording' => 'Do the accounts about John conflict?'])
            ->assertSee('conflicting accounts')->assertSee('hired Lee')->assertSee('hired Pat');
        $this->post('/input', ['wording' => 'Remember that John moved offices.', 'intent' => 'ask', 'followup' => 1])->assertRedirect();
        $this->assertDatabaseHas('memories', ['user_id' => $owner->id, 'wording' => 'John moved offices.']);
    }

    public function test_correction_points_to_exact_private_passage_and_survives_deletion(): void
    {
        Storage::fake('import-staging');
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/imports', ['mailbox' => UploadedFile::fake()->createWithContent(
            'test.mbox', file_get_contents(base_path('tests/Fixtures/synthetic.mbox')))])->assertRedirect();
        $this->artisan('queue:work database --stop-when-empty --sleep=0')->assertSuccessful();
        $passage = DB::table('mail_passages')->where('wording', 'The key is in B-17.')->sole();
        $this->actingAs(User::factory()->create())->post("/passages/$passage->id/correct", ['wording' => 'foreign'])->assertNotFound();
        $url = $this->actingAs($owner)->post("/passages/$passage->id/correct", ['wording' => 'The key is in C-12 now.'])->assertRedirect()->headers->get('Location');
        $this->get($url)->assertSee(route('passages.show', $passage->id));
        $this->get("/passages/$passage->id")->assertSee('The key is in B-17.');
        $turn = $this->post('/input', ['wording' => 'Where is the key?'])->assertSee('C-12')->assertSee('B-17')->viewData('turn');
        $this->delete("/messages/$passage->message_id")->assertRedirect();
        $this->get("/conversation/$turn/status")->assertJsonPath('status', 'deleted');
        $this->get($url)->assertSee('C-12')->assertDontSee('original passage');
        $this->post('/input', ['wording' => 'Where is the key?'])->assertSee('C-12')->assertDontSee('B-17');
        Http::assertNothingSent();
    }
}
