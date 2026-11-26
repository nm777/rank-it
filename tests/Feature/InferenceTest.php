<?php

namespace Tests\Feature;

use App\Inference\Budget;
use App\Jobs\ImportMailbox;
use App\Jobs\IndexPassage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use UnexpectedValueException;

class InferenceTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        throw_unless(app()->environment('testing') && DB::selectOne('select current_database() as db')->db === 'rank_it_test',
            \RuntimeException::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['inference.cap' => 3000000, 'inference.reserve' => 200000,
            'inference.jev_key' => 'synthetic', 'inference.jev_verified' => true,
            'inference.endpoint' => 'https://embedding.test/v1/embeddings', 'inference.model' => 'fixture',
            'inference.provider' => 'test', 'inference.dimensions' => 2, 'inference.rate' => 2, 'inference.max_tokens' => 500]);
    }

    private function runWork(int $id): void
    {
        app()->call([new IndexPassage($id), 'handle']);
    }

    public function test_import_screens_only_original_wording_and_replays_without_repeat_charges(): void
    {
        Storage::fake('import-staging');
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/imports', ['mailbox' => UploadedFile::fake()->createWithContent(
            'test.mbox', file_get_contents(base_path('tests/Fixtures/synthetic.mbox')))]);
        $batch = DB::table('mailbox_imports')->first();
        do {
            app()->call([new ImportMailbox($owner->id, $batch->id, $batch->position), 'handle']);
            $batch = DB::table('mailbox_imports')->first();
        } while ($batch->status === 'Processing');
        $this->get('/imports/'.$batch->id)->assertSee('Meaning-based search is pending.');
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://api.typesafe.ai/v1/systemone') {
                $reply = $request['state']['new_wording'] === 'Agreed.';
                if ($reply) {
                    $this->assertStringContainsString('B-17', implode(' ', $request['state']['context']));
                }
                $this->assertSame('noul', $request['questions']['remember']['type']);

                return Http::response(['answers' => ['remember' => ['noul' => $reply ? 0.1 : 0.9]], 'usage' => ['input_tokens' => 20]]);
            }
            $this->assertSame('The key is in B-17.', $request['input']);

            return Http::response(['data' => [['embedding' => [1, 2]]], 'usage' => ['prompt_tokens' => 6]]);
        });
        $ids = DB::table('inference_work')->pluck('id');
        $this->assertCount(2, $ids);
        foreach ($ids as $id) {
            $this->runWork($id);
            $this->runWork($id);
            $this->runWork($id);
        }
        Http::assertSentCount(3);
        $this->assertDatabaseHas('inference_work', ['stage' => 'skipped']);
        $indexed = DB::table('inference_work')->where('stage', 'indexed')->sole();
        $this->assertSame('[1,2]', $indexed->embedding);
        $this->assertSame('fixture', json_decode($indexed->configuration)->model);
        $this->assertDatabaseHas('inference_budget', ['committed' => 1692]);
        $this->assertDatabaseCount('inference_calls', 3);
        $this->get('/profile/usage')->assertOk()->assertSee('$0.000001692 USD')->assertSee('3 recorded AI calls');
        $this->post('/search', ['wording' => 'Agreed.'])->assertSee('Agreed.');
    }

    public function test_passage_ratings_are_processed_once_with_negative_background_headroom(): void
    {
        config(['inference.cap' => 100, 'inference.reserve' => 20]);
        $owner = User::factory()->create();
        $messageId = DB::table('mail_messages')->insertGetId([
            'user_id' => $owner->id,
            'identity' => hash('sha256', 'rating fixture'),
        ]);
        $passageId = DB::table('mail_passages')->insertGetId([
            'message_id' => $messageId,
            'ordinal' => 0,
            'wording' => 'Lemon cake is 9/10.',
        ]);
        $workId = DB::table('inference_work')->insertGetId(['passage_id' => $passageId, 'stage' => 'embed']);
        $answerCall = app(Budget::class)->reserve('answer', 90, $owner->id);
        $this->assertNotNull($answerCall);
        Http::fake();

        $this->runWork($workId);

        $this->assertDatabaseHas('ratings', ['passage_id' => $passageId, 'subject' => 'Lemon cake', 'score' => 9, 'scale' => 10]);
        $this->assertDatabaseHas('inference_work', ['id' => $workId, 'ratings_processed' => true, 'stage' => 'embed', 'call_id' => null]);

        $this->runWork($workId);

        $this->assertDatabaseCount('ratings', 1);
        $this->assertDatabaseCount('inference_calls', 1);
        $this->assertDatabaseHas('inference_budget', ['id' => 1, 'committed' => 90]);
        Http::assertNothingSent();
    }

    public function test_queue_resumes_capture_after_edit_using_only_current_wording(): void
    {
        config(['inference.cap' => 0]);
        Http::fake(['embedding.test/*' => Http::response([
            'data' => [['embedding' => [1, 0]]], 'usage' => ['prompt_tokens' => 3],
        ])]);
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/memories', ['wording' => 'Original fixture'])->assertRedirect('/');
        $url = route('memories.show', $owner->memories()->sole());
        $this->patch($url, ['wording' => 'Corrected fixture'])->assertRedirect();
        $this->artisan('queue:work database --stop-when-empty --sleep=0')->assertSuccessful();
        Http::assertNothingSent();
        config(['inference.cap' => 3000000]);
        $this->artisan('inference:resume')->assertSuccessful();
        $this->artisan('inference:resume')->assertSuccessful();
        $this->artisan('queue:work database --stop-when-empty --sleep=0')->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['input'] === 'Corrected fixture');
        $this->assertDatabaseHas('inference_work', ['stage' => 'indexed']);
        $this->assertDatabaseHas('inference_budget', ['committed' => 6]);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_competing_workers_share_one_cap(): void
    {
        $db = DB::connection()->getConfig();
        $script = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            config(['inference.cap' => 100, 'inference.reserve' => 10]);
            $result = app(App\Inference\Budget::class)->reserve('embed', 60);
            echo $result === null ? 'paused' : 'reserved';
            PHP;
        $workers = [];
        for ($i = 0; $i < 4; $i++) {
            $worker = new Process([PHP_BINARY, '-r', $script], base_path(), [
                'APP_ENV' => 'testing', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'],
                'DB_DATABASE' => 'rank_it_test', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'],
                'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            ]);
            $worker->start();
            $workers[] = $worker;
        }
        $outcomes = [];
        foreach ($workers as $worker) {
            $this->assertSame(0, $worker->wait());
            $outcomes[] = $worker->getOutput();
        }
        $this->assertEqualsCanonicalizing(['reserved', 'paused', 'paused', 'paused'], $outcomes);
        $this->assertDatabaseHas('inference_budget', ['committed' => 60]);
    }

    #[DataProvider('ambiguousEmbeddingResponses')]
    public function test_capture_pauses_resumes_and_keeps_ambiguous_reservations(int $status): void
    {
        Http::fake(['embedding.test/*' => Http::response([], $status)]);
        Log::spy();
        config(['inference.cap' => 1000, 'inference.reserve' => 50]);
        $this->actingAs(User::factory()->create())->post('/memories', ['wording' => 'Synthetic capture']);
        $work = DB::table('inference_work')->sole();
        $this->assertSame('embed', $work->stage);
        $this->runWork($work->id);
        Http::assertNothingSent();
        $budget = app(Budget::class);
        $this->assertNull($budget->reserve('answer', 1001));
        $answer = $budget->reserve('answer', 1000);
        $budget->settle($answer, 100, 10);
        $budget->settle($answer, 100, 10);
        config(['inference.cap' => 1200]);
        $this->artisan('inference:resume')->assertSuccessful();
        $this->runWork($work->id);
        $this->runWork($work->id);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('inference_budget', ['committed' => 1100]);
        $call = DB::table('inference_work')->value('call_id');
        $this->assertNotNull($call);
        $this->assertDatabaseHas('inference_calls', ['id' => $call, 'actual' => null]);
        Log::shouldHaveReceived('warning')->once()->with(
            'Passage indexing is blocked pending inference call reconciliation.',
            \Mockery::subset(['work_id' => $work->id, 'call_id' => $call, 'stage' => 'embed']),
        );
        $this->artisan("inference:reconcile $call 10")->assertSuccessful();
        $this->artisan("inference:reconcile $call 10")->assertFailed();
        $this->assertDatabaseHas('inference_budget', ['committed' => 110]);
    }

    public static function ambiguousEmbeddingResponses(): array
    {
        return [
            'provider unavailable' => [503],
            'malformed success response' => [200],
        ];
    }

    public function test_unexpected_indexing_exception_fails_the_queued_job_and_keeps_its_reservation(): void
    {
        Http::fake(['embedding.test/*' => fn () => throw new UnexpectedValueException('Indexing bug fixture')]);
        $this->actingAs(User::factory()->create())->post('/memories', ['wording' => 'Synthetic capture'])
            ->assertRedirect('/');
        $work = DB::table('inference_work')->sole();

        $this->artisan('queue:work database --stop-when-empty --sleep=0 --tries=1')->assertSuccessful();

        $failure = DB::table('failed_jobs')->sole();
        $this->assertStringContainsString('UnexpectedValueException: Indexing bug fixture', $failure->exception);
        $call = DB::table('inference_work')->where('id', $work->id)->value('call_id');
        $this->assertNotNull($call);
        $this->assertDatabaseHas('inference_calls', ['id' => $call, 'reserved' => 1000, 'actual' => null]);
        $this->assertDatabaseHas('inference_budget', ['committed' => 1000]);
        $this->artisan('inference:resume')->assertSuccessful();
        $this->assertDatabaseEmpty('jobs');
    }
}
