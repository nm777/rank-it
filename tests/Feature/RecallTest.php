<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecallTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['inference.cap' => 10000000, 'inference.reserve' => 20000,
            'inference.jev_key' => 'fixture', 'inference.jev_verified' => true,
            'inference.endpoint' => 'https://embedding.test/embed', 'inference.model' => 'fixture',
            'inference.provider' => 'fixture', 'inference.dimensions' => 2, 'inference.rate' => 2, 'inference.max_tokens' => 500,
            'inference.answer' => ['endpoint' => 'https://answer.test/chat', 'model' => 'fixture', 'key' => null,
                'input_rate' => 2, 'output_rate' => 4, 'max_input' => 1000, 'max_output' => 128]]);
    }

    public function test_paraphrase_opens_original_mail_and_rejects_fabricated_support(): void
    {
        Storage::fake('import-staging');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($other)->post('/memories', ['wording' => 'Where is the spare for the lock?'])->assertRedirect();
        $foreign = $other->memories()->sole();
        $original = 'The key is in B-17.';
        Http::fake(function (Request $request) use ($original) {
            if ($request->url() === 'https://api.typesafe.ai/v1/systemone') {
                return Http::response(['answers' => ['remember' => ['noul' => 0.99]], 'usage' => ['input_tokens' => 4]]);
            }
            if ($request->url() === 'https://embedding.test/embed') {
                return Http::response(['data' => [['embedding' => $request['input'] === 'Agreed.' ? [0, 1] : [1, 0]]],
                    'usage' => ['prompt_tokens' => 3]]);
            }
            $message = json_decode($request['messages'][1]['content'], true);
            $this->assertCount(1, $message['passages']);
            $key = array_key_first($message['passages']);
            $this->assertSame($original, $message['passages'][$key]['wording']);

            return Http::response(['choices' => [['message' => ['content' => json_encode(['claims' => [
                ['source' => $key, 'quote' => $original], ['source' => $key, 'quote' => 'A fabricated fact'],
                ['source' => 'passage:999999', 'quote' => $original]], 'partial' => false])]]],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10]]);
        });
        $this->actingAs($owner)->post('/imports', ['mailbox' => UploadedFile::fake()->createWithContent(
            'test.mbox', file_get_contents(base_path('tests/Fixtures/synthetic.mbox')))])->assertRedirect();
        $this->artisan('queue:work database --stop-when-empty --sleep=0')->assertSuccessful();
        config(['inference.reserve' => 10000000]);
        $response = $this->post('/input', ['wording' => 'Where is the spare for the lock?'])
            ->assertOk()->assertSee($original)->assertSee('support only part')->assertDontSee('A fabricated fact')
            ->assertDontSee($foreign->id)->assertSee('Maya');
        $id = DB::table('mail_passages')->where('wording', $original)->sole()->id;
        $link = route('passages.show', ['passage' => $id, 'start' => 0, 'length' => mb_strlen($original)]).'#evidence';
        $response->assertSee($link);
        $this->get($link)->assertOk()->assertSee('<mark id="evidence">'.$original.'</mark>', false)->assertSee('2025');
        $this->assertDatabaseHas('inference_calls', ['purpose' => 'answer', 'actual' => 80, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('inference_calls', ['purpose' => 'answer', 'actual' => 6, 'user_id' => $owner->id]);
        $this->actingAs($other)->get('/passages/'.$id)->assertNotFound();
    }

    public function test_shared_input_requires_confirmation_and_cap_preserves_literal_citation(): void
    {
        config(['inference.cap' => 0, 'inference.jev_key' => null]);
        $this->actingAs(User::factory()->create())->post('/input', ['wording' => 'Locker B-17'])
            ->assertSee('Save this as a memory or ask about it?')->assertSee('AI assistance for choosing an action is currently unavailable');
        $this->post('/input', ['wording' => 'If I rented B-17'])->assertSee('no matching passages')->assertSee('Answer generation is unavailable');
        $this->assertDatabaseCount('memories', 0);
        $this->post('/input', ['wording' => 'Locker B-17', 'intent' => 'save'])->assertRedirect('/');
        $url = route('memories.show', auth()->user()->memories()->sole());
        $this->post('/input', ['wording' => 'I parked upstairs.'])->assertRedirect();
        $this->post('/input', ['wording' => 'Where is locker B-17?'])->assertSee('Answer generation is unavailable')
            ->assertSee('Search results')->assertSee($url.'?highlight=1#passage');
        $this->get($url.'?highlight=1')->assertSee('<mark>Locker B-17</mark>', false);
        $this->assertDatabaseCount('memories', 2);
        Http::assertNothingSent();
    }

    public function test_search_and_recall_select_the_first_quote_per_parent_within_the_owner_collection(): void
    {
        config(['inference.cap' => 0]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $quote = 'The spare key is in the locker.';
        $parent = hash('sha256', 'missing parent');
        $addQuote = function (User $user, string $identity, string $parentIdentity) use ($quote): int {
            $message = DB::table('mail_messages')->insertGetId([
                'user_id' => $user->id,
                'identity' => hash('sha256', $identity),
                'parent_identity' => $parentIdentity,
            ]);

            return DB::table('mail_passages')->insertGetId([
                'message_id' => $message,
                'ordinal' => 0,
                'wording' => '> '.$quote,
                'quote_text' => $quote,
            ]);
        };
        $addQuote($other, 'foreign reply to same parent', $parent);
        $addQuote($other, 'foreign reply to another parent', hash('sha256', 'foreign parent'));
        $first = $addQuote($owner, 'first reply', $parent);
        $addQuote($owner, 'second reply', $parent);
        $separate = $addQuote($owner, 'reply to another parent', hash('sha256', 'another parent'));

        $this->actingAs($owner)->post('/search', ['wording' => 'LOCKER'])
            ->assertViewHas('passages', fn ($passages) => $passages->pluck('id')->sort()->values()->all() === [$first, $separate]);
        $this->post('/input', ['wording' => 'Where is the spare key?', 'intent' => 'ask'])
            ->assertViewHas('results', fn ($results) => array_keys($results) === ['passage:'.$first, 'passage:'.$separate]);
        Http::assertNothingSent();
    }

    public function test_jev_routes_implicit_input_and_offers_a_choice_on_uncertainty_or_failure(): void
    {
        $scores = static fn ($save, $ask) => ['answers' => ['save' => ['noul' => $save], 'ask' => ['noul' => $ask]],
            'usage' => ['input_tokens' => 10]];
        Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::sequence()
            ->push($scores(0.95, 0.05))
            ->push($scores(0.5, 0.5))
            ->pushStatus(503)]);

        config(['app.env' => 'local']);
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/input', ['wording' => 'What dessert should I bring to Thanksgiving dinner?'])
            ->assertViewIs('recall')->assertSee('Recognized as a question')->assertSee('<summary>AI output</summary>', false);
        Http::assertNothingSent();
        $this->post('/input', ['wording' => 'The spare bulb is in the shed.'])->assertRedirect('/');
        $this->get('/')->assertSee('Jev intent')->assertSee('0.95');
        $this->post('/input', ['wording' => 'Toolbox'])->assertViewIs('clarify')
            ->assertSee('couldn’t tell')->assertDontSee('currently unavailable');
        $this->post('/input', ['wording' => 'The toolbox is red.'])->assertViewIs('clarify')
            ->assertSee('currently unavailable');
        $this->assertDatabaseCount('memories', 1);
        $this->post('/input', ['wording' => 'The toolbox is red.', 'intent' => 'save'])->assertRedirect('/');
        $this->assertDatabaseCount('memories', 2);
        Http::assertSentCount(3);
        $this->assertSame(2, DB::table('inference_calls')->where('purpose', 'intent')->where('user_id', $owner->id)->where('actual', 420)->count());
    }

    public function test_replay_can_switch_between_memory_and_question_with_edited_wording(): void
    {
        config(['inference.cap' => 0]);
        $owner = User::factory()->create();
        $wording = 'I parked in B-17.';
        $this->actingAs($owner)->post('/input', [
            'wording' => $wording, 'experience_date' => '2025-02', 'intent' => 'save',
        ])->assertRedirect('/');
        $this->get('/')->assertSee('Replay input')->assertSee('name="experience_date" value="2025-02"', false)
            ->assertSee('replay-wording');

        $question = 'Where did I park in B-17?';
        $this->post('/input', [
            'wording' => $question, 'experience_date' => '2025-02', 'intent' => 'ask', 'replay' => 1,
        ])->assertViewIs('recall')->assertSee('Edit & replay')->assertSee($question)
            ->assertSee('name="experience_date" value="2025-02"', false);
        $this->assertSame($wording, $owner->memories()->sole()->wording);

        $this->post('/input', [
            'wording' => $question, 'experience_date' => '2025-02', 'intent' => 'save', 'replay' => 1,
        ])->assertRedirect('/');
        $this->assertDatabaseHas('memories', ['user_id' => $owner->id, 'wording' => $question, 'experience_date' => '2025-02']);
        Http::assertNothingSent();
    }

    public function test_partial_answer_highlights_only_the_supported_words_and_names_the_gap(): void
    {
        $owner = User::factory()->create();
        config(['app.env' => 'local']);
        $wording = 'After lunch I parked in space 42 on level five.';
        $this->actingAs($owner)->post('/input', ['wording' => $wording, 'intent' => 'save']);
        $id = $owner->memories()->sole()->id;
        Http::fake(['answer.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'claims' => [['source' => 'memory:'.$id, 'quote' => 'space 42 on level five']],
            'partial' => true, 'missing' => 'meter expiration',
        ])]]], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8]])]);
        $link = route('memories.show', ['memory' => $id, 'highlight' => 1,
            'start' => mb_strpos($wording, 'space 42'), 'length' => mb_strlen('space 42 on level five')]).'#evidence';
        $this->post('/input', ['wording' => 'Which parking space and meter expiration?', 'intent' => 'ask'])
            ->assertSee('Missing evidence for “meter expiration”')->assertSee($link)
            ->assertSee('Answer model')->assertSee('completion_tokens');
        $this->get($link)->assertSee('After lunch I parked in <mark id="evidence">space 42 on level five</mark>.', false);
    }

    public function test_embedding_and_answer_outages_still_open_wording_matches(): void
    {
        Http::fake(['embedding.test/*' => Http::sequence()
            ->push(['data' => [['embedding' => [1, 0]]], 'usage' => ['prompt_tokens' => 2]])->pushStatus(503),
            'answer.test/*' => Http::response([], 503)]);
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/memories', ['wording' => 'Blue locker'])->assertRedirect('/');
        $url = route('memories.show', $owner->memories()->sole());
        $this->artisan('queue:work database --stop-when-empty --sleep=0')->assertSuccessful();
        $this->post('/input', ['wording' => 'Which locker?', 'intent' => 'ask'])->assertSee('Search results')
            ->assertSee('Answer generation is unavailable')->assertSee($url.'?highlight=1#passage');
        Http::assertSentCount(3);
        $this->assertSame(2, DB::table('inference_calls')->where('purpose', 'answer')->whereNull('actual')->count());
    }
}
