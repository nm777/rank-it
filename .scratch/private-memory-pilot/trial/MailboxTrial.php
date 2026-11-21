<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MailboxTrial extends TestCase
{
    use DatabaseMigrations;

    private const KEY = 'The key is in B-17.';

    private const REPLY = 'Agreed.';

    protected function beforeRefreshingDatabase(): void
    {
        throw_unless(app()->environment('testing')
            && str_starts_with(config('database.connections.pgsql.host'), 'rank-it-mailbox-trial-')
            && DB::selectOne('select current_database() as db')->db === 'rank_it_test', RuntimeException::class);
    }

    public static function arms(): array
    {
        return ['screened' => [true], 'unscreened' => [false]];
    }

    #[DataProvider('arms')]
    public function test_paired_mailbox_trial(bool $screened): void
    {
        $questions = [
            'Where is the spare for the lock?' => self::KEY,
            'Did Sam concur?' => self::REPLY,
        ];
        $index = [self::KEY => [1, 0], self::REPLY => [0, 1]];
        $vectors = $index;
        foreach ($questions as $question => $target) {
            $vectors[$question] = $index[$target];
        }
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'inference.cap' => 10000000, 'inference.reserve' => 10000,
            'inference.jev_key' => 'fixture', 'inference.jev_verified' => true,
            'inference.endpoint' => 'https://embedding.test/embed', 'inference.model' => 'trial-fixture',
            'inference.provider' => 'trial-fixture', 'inference.dimensions' => 2,
            'inference.rate' => 100, 'inference.max_tokens' => 500,
            'inference.answer' => ['endpoint' => 'https://answer.test/chat', 'model' => 'trial-fixture',
                'key' => null, 'input_rate' => 2, 'output_rate' => 4, 'max_input' => 1000, 'max_output' => 128],
        ]);
        Http::preventStrayRequests();
        $candidates = $screens = [];
        Http::fake(function (Request $request) use ($vectors, $questions, &$candidates, &$screens) {
            switch ($request->url()) {
                case 'https://api.typesafe.ai/v1/systemone':
                    $state = $request['state'];
                    $screens[$state['new_wording']] = $state['context'];

                    return Http::response(['answers' => ['remember' => ['noul' => $state['new_wording'] === self::KEY ? 0.9 : 0.1]],
                        'usage' => ['input_tokens' => 4]]);
                case 'https://embedding.test/embed':
                    $this->assertSame('trial-fixture', $request['model']);

                    return Http::response(['data' => [['embedding' => $vectors[$request['input']]]],
                        'usage' => ['prompt_tokens' => 5]]);
                case 'https://answer.test/chat':
                    $this->assertSame('trial-fixture', $request['model']);
                    ['question' => $question, 'passages' => $passages] = json_decode($request['messages'][1]['content'], true);
                    $candidates[$question] = array_column($passages, 'wording');
                    $claims = [];
                    foreach ($passages as $key => $passage) {
                        if ($passage['wording'] === $questions[$question]) {
                            $claims[] = ['source' => $key, 'quote' => $passage['wording']];
                        }
                    }

                    return Http::response(['choices' => [['message' => ['content' => json_encode([
                        'claims' => $claims, 'partial' => false,
                    ])]]], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10]]);
                default:
                    $this->fail('Unexpected provider request '.$request->url());
            }
        });

        $disk = Storage::fake('import-staging');
        $owner = User::factory()->create();
        $this->actingAs($owner)->get('/')->assertOk()->assertSee(route('memories.input'))
            ->assertSee('Write a memory or ask a question');
        $start = hrtime(true);
        $upload = $this->post('/imports', ['mailbox' => UploadedFile::fake()->createWithContent(
            'synthetic.mbox', file_get_contents(base_path('tests/Fixtures/synthetic.mbox')))]);
        $upload->assertRedirect()->assertSessionHas('status', 'Upload complete. You can close this tab.');
        $uploadUrl = $upload->headers->get('Location');
        for ($step = 0; $step < 20 && DB::table('jobs')->exists(); $step++) {
            if (! $screened) {
                // Preserve parser eligibility and queued jobs. Skip only screening for this baseline.
                DB::table('inference_work')->where('stage', 'screen')->update(['stage' => 'embed']);
            }
            $this->artisan('queue:work database --once --sleep=0')->assertSuccessful();
        }
        $importMs = $this->elapsedMs($start);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame([], DB::table('failed_jobs')->pluck('exception')->all());
        $this->get($uploadUrl)->assertOk()->assertSee('Partially imported')
            ->assertSee('2 messages imported · 1 known unreadable')->assertSee('inventory.txt');
        $this->assertSame([], $disk->allFiles());
        $stages = DB::table('inference_work')->join('mail_passages', 'mail_passages.id', '=', 'inference_work.passage_id')
            ->orderBy('wording')->pluck('stage', 'wording')->all();
        $this->assertSame([self::REPLY => $screened ? 'skipped' : 'indexed', self::KEY => 'indexed'], $stages);
        $this->assertCount($screened ? 2 : 0, $screens);

        $outcomes = [];
        foreach ($questions as $question => $target) {
            $start = hrtime(true);
            $response = $this->post('/input', ['wording' => $question])->assertOk();
            $askMs = $this->elapsedMs($start);
            $found = ! $screened || $target === self::KEY;
            $this->assertSame($found, in_array($target, $candidates[$question] ?? [], true));
            if ($found) {
                $id = DB::table('mail_passages')->where('wording', $target)->sole()->id;
                preg_match('/href="([^"]+#evidence)"/', $response->getContent(), $link);
                $url = html_entity_decode($link[1] ?? '');
                $this->assertSame(route('passages.show', ['passage' => $id, 'start' => 0, 'length' => mb_strlen($target)]).'#evidence', $url);
                $source = $this->get($url)->assertOk()->assertSee('<mark id="evidence">'.$target.'</mark>', false)
                    ->assertSee($target === self::KEY ? 'Maya' : 'Sam')->assertSee('2025');
                if ($target === self::REPLY) {
                    $source->assertSee('Quoted history')->assertSee(self::KEY);
                }
            } else {
                $response->assertSee('I found no matching passages');
                $search = $this->post('/search', ['wording' => $target])->assertOk()->assertSee($target);
                $id = DB::table('mail_passages')->where('wording', $target)->sole()->id;
                $search->assertSee(route('passages.show', $id).'#passage-'.$id);
                $this->get(route('passages.show', $id))->assertOk()->assertSee($target);
            }
            $outcomes[$question] = [
                'candidates' => $candidates[$question] ?? [],
                'highlighted_citation_opened' => $found,
                'local_ask_ms' => $askMs,
            ];
        }

        $this->post('/input', ['wording' => 'Locker B-17'])->assertOk()->assertSee('Save this as a memory or ask about it?');
        $this->assertDatabaseCount('memories', 0);
        $this->followingRedirects()->post('/input', ['wording' => 'I parked upstairs.'])->assertOk()
            ->assertSee('Saved')->assertSee('I parked upstairs.');
        $this->assertDatabaseCount('memories', 1);

        $ledger = DB::table('inference_calls')->selectRaw('purpose, count(*) as calls, sum(actual) as nanousd')
            ->groupBy('purpose')->orderBy('purpose')->get()->keyBy('purpose');
        $this->assertDatabaseMissing('inference_calls', ['actual' => null]);
        $this->assertSame($screened ? 1916 : 2160, (int) $ledger->sum('nanousd'));
        fwrite(STDOUT, json_encode([
            'arm' => $screened ? 'screened' : 'unscreened', 'local_import_ms' => $importMs,
            'stages' => $stages, 'screen_context' => $screens, 'questions' => $outcomes, 'simulated_ledger' => $ledger,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
    }

    private function elapsedMs(int $start): float
    {
        return round((hrtime(true) - $start) / 1e6, 2);
    }
}
