<?php

namespace Tests\Feature;

use App\Jobs\ImportMailbox;
use App\Jobs\IndexPassage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class ImportLifecycleTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('import-staging');
        Http::preventStrayRequests();
        config(['inference.cap' => 10000000, 'inference.reserve' => 0, 'inference.jev_key' => 'fixture',
            'inference.jev_verified' => true, 'inference.provider' => 'self-hosted', 'inference.model' => 'local',
            'inference.endpoint' => 'http://local.test/embeddings', 'inference.key' => null, 'inference.dimensions' => 2,
            'inference.rate' => 0, 'inference.max_tokens' => 100, 'inference.answer.endpoint' => null,
            'inference.alternate' => ['provider' => 'external', 'model' => 'remote', 'dimensions' => 3,
                'endpoint' => 'https://external.test/embeddings', 'key' => 'fixture', 'rate' => 1, 'max_tokens' => 100]]);
    }

    private function upload(array $files): string
    {
        $uploads = [];
        foreach ($files as $name => $body) {
            $uploads[] = UploadedFile::fake()->createWithContent($name, $body);
        }

        return basename($this->post('/imports', ['files' => $uploads])->assertRedirect()->headers->get('Location'));
    }

    private function drain(): void
    {
        $this->artisan('queue:work database --stop-when-empty --sleep=0')->assertSuccessful();
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_batch_formats_retries_and_private_collection_controls(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner);
        $zip = new ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'word');
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Word fixture</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $email = "From: Example\nMessage-ID: <one@test>\nSubject: Fixture\n\nEmail fixture";
        $first = $this->upload(['one.eml' => $email, 'chat.txt' => "Ada: Hello\nKim: Hello", 'example.docx' => file_get_contents($path), 'other.bin' => 'unsupported']);
        unlink($path);
        $second = $this->upload(['copy.eml' => $email, 'note.md' => '# Markdown fixture']);
        config(['inference.cap' => 0]);
        $this->drain();
        $this->get("/imports/$first")->assertSee('other.bin')->assertSee('Could not import');
        foreach (['Email fixture', 'Kim: Hello', 'Word fixture', 'Markdown fixture'] as $text) {
            $this->post('/search', ['wording' => $text])->assertDontSee('No matching memories')->assertSee('#passage-');
        }
        $message = DB::table('mail_messages')->where('sender', 'Example')->sole()->id;
        $this->actingAs($other)->delete("/messages/$message")->assertNotFound();
        $this->delete("/imports/$first")->assertNotFound();
        $this->actingAs($owner)->delete("/imports/$first")->assertRedirect();
        $this->assertDatabaseCount('mail_messages', 2);
        $this->upload(['pending.eml' => $email]);
        $this->delete("/messages/$message")->assertRedirect();
        $this->drain();
        $this->post('/search', ['wording' => 'Email fixture'])->assertSee('No matching memories');
        $this->upload(['later.eml' => $email]);
        $this->drain();
        $this->delete("/imports/$second")->assertRedirect();
        $this->assertDatabaseCount('mail_messages', 1);
        $this->assertSame([], Storage::disk('import-staging')->allFiles());
    }

    public function test_document_import_makes_readable_files_searchable_and_reports_unreadable_files(): void
    {
        $this->actingAs(User::factory()->create());
        config(['inference.cap' => 0]);

        $batch = $this->upload([
            'document.PDF' => file_get_contents(base_path('tests/Fixtures/import.pdf')),
            'broken.pdf' => '%PDF-1.4',
            'workbook.xlsx' => $this->workbook(IOFactory::WRITER_XLSX, 'Cedar'),
            'legacy.xls' => $this->workbook(IOFactory::WRITER_XLS, 'Maple'),
        ]);
        $this->drain();

        foreach (['document.PDF', 'workbook.xlsx', 'legacy.xls'] as $filename) {
            $this->assertDatabaseHas('mailbox_imports', ['batch_id' => $batch, 'filename' => $filename, 'status' => 'Imported']);
        }
        $this->assertDatabaseHas('mailbox_imports', ['batch_id' => $batch, 'filename' => 'broken.pdf', 'status' => 'Could not import', 'unreadable' => 1]);
        foreach (['Evergreen PDF fixture', 'Cedar delivery schedule', 'Maple delivery schedule'] as $wording) {
            $this->post('/search', ['wording' => $wording])->assertSee('#passage-');
        }
        $this->assertDatabaseHas('mail_passages', ['wording' => "Logistics\nCedar delivery schedule\t0"]);
        $this->assertDatabaseHas('mail_passages', ['wording' => "Logistics\nMaple delivery schedule\t0"]);
        $this->assertSame([], Storage::disk('import-staging')->allFiles());
    }

    private function workbook(string $format, string $label): string
    {
        $workbook = new Spreadsheet;
        $workbook->getActiveSheet()->setCellValue('A1', "$label inventory");
        $sheet = $workbook->createSheet()->setTitle('Logistics');
        $sheet->setCellValue('A1', "$label delivery schedule");
        $sheet->setCellValue('B1', 0);
        $path = tempnam(sys_get_temp_dir(), 'excel');

        try {
            IOFactory::createWriter($workbook, $format)->save($path);

            return file_get_contents($path);
        } finally {
            unlink($path);
            $workbook->disconnectWorksheets();
        }
    }

    public function test_invalid_excel_files_are_reported_as_unreadable(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = $this->upload(['broken.xlsx' => 'invalid workbook', 'broken.xls' => 'invalid workbook']);

        $this->drain();

        foreach (['broken.xlsx', 'broken.xls'] as $filename) {
            $this->assertDatabaseHas('mailbox_imports', ['batch_id' => $batch, 'filename' => $filename,
                'status' => 'Could not import', 'unreadable' => 1]);
        }
        $this->assertDatabaseCount('mail_messages', 0);
    }

    public function test_batch_removal_commits_cleanup_for_each_file_and_retries_a_deletion_failure(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $batch = $this->upload(['first.txt' => 'First fixture', 'second.txt' => 'Second fixture']);
        $disk = Storage::disk('import-staging');
        $path = "$owner->id/$batch";
        rename($disk->path($path), $disk->path("$path.saved"));
        $disk->makeDirectory($path);

        $this->delete("/imports/$batch")->assertRedirect(route('imports.index'));

        $this->assertDatabaseMissing('mailbox_imports', ['batch_id' => $batch]);
        $this->assertDatabaseCount('jobs', 4);
        $this->drain();

        $this->assertDatabaseHas('jobs', ['attempts' => 1, 'reserved_at' => null]);
        $this->assertDatabaseCount('jobs', 1);
        $disk->assertExists([$path, "$path.saved"]);
        $this->assertSame(["$path.saved"], $disk->allFiles());

        $disk->deleteDirectory($path);
        rename($disk->path("$path.saved"), $disk->path($path));
        $this->travel(11)->seconds();
        $this->drain();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame([], $disk->allFiles());
    }

    public function test_rolling_back_batch_removal_keeps_staged_files_and_discards_cleanup_jobs(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $batch = $this->upload(['fixture.txt' => 'Fixture']);

        DB::beginTransaction();
        try {
            $this->delete("/imports/$batch")->assertRedirect(route('imports.index'));
            $this->assertDatabaseMissing('mailbox_imports', ['batch_id' => $batch]);
            $this->assertDatabaseCount('jobs', 2);
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseHas('mailbox_imports', ['id' => $batch, 'status' => 'Queued']);
        $this->assertDatabaseCount('jobs', 1);
        Storage::disk('import-staging')->assertExists("$owner->id/$batch");
    }

    public function test_rechecks_promotion_and_late_context_are_scoped(): void
    {
        $accept = false;
        Http::fake(function (Request $request) use (&$accept) {
            if ($request->url() === 'http://local.test/embeddings') {
                return Http::response(['data' => [['embedding' => [1, 0]]], 'usage' => ['prompt_tokens' => 1]]);
            }
            $late = $request['state']['new_wording'] === 'Agreed.' && in_array('Cake rated 9/10.', $request['state']['context']);

            return Http::response(['answers' => ['remember' => ['noul' => $late || $accept ? 1 : 0]], 'usage' => ['input_tokens' => 1]]);
        });
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner);
        $batch = $this->upload(['first.txt' => 'Synthetic opinion']);
        $this->upload(['reply.eml' => "Message-ID: <reply@test>\nIn-Reply-To: <parent@test>\n\nAgreed.", 'second.txt' => 'Promote me']);
        $this->drain();
        $passage = DB::table('mail_passages')->where('wording', 'Promote me')->value('id');
        $this->get("/passages/$passage")->assertSee('Remember this');
        $this->actingAs($other)->post("/passages/$passage/promote")->assertNotFound();
        $this->actingAs($owner)->post("/passages/$passage/promote")->assertRedirect();
        $this->post("/passages/$passage/promote")->assertRedirect();
        $this->upload(['parent.eml' => "Message-ID: <parent@test>\n\nCake rated 9/10."]);
        $this->drain();
        $this->assertSame(2, DB::table('inference_work')->where('stage', 'indexed')->count());
        $accept = true;
        $this->artisan("inference:recheck $batch")->expectsOutput('1 pending, 0 rechecked, 0 newly qualifying.')->assertSuccessful();
        $this->drain();
        $this->artisan("inference:recheck $batch --status")->expectsOutput('0 pending, 1 rechecked, 1 newly qualifying.')->assertSuccessful();
        $this->get("/imports/$batch")->assertSee('Open newly qualifying passage');
        $this->actingAs($other)->get("/imports/$batch")->assertNotFound();
        $this->assertSame(1, DB::table('inference_work')->where('stage', 'skipped')->count());
    }

    public function test_parent_arriving_during_screening_requeues_reply_without_a_duplicate_call(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $this->upload(['reply.eml' => "Message-ID: <reply@test>\nIn-Reply-To: <parent@test>\n\nAgreed."]);
        $replyContexts = [];
        Http::fake([
            'https://api.typesafe.ai/v1/systemone' => function (Request $request) use ($owner, &$replyContexts) {
                if ($request['state']['new_wording'] !== 'Agreed.') {
                    return Http::response(['answers' => ['remember' => ['noul' => 0]], 'usage' => ['input_tokens' => 1]]);
                }
                $replyContexts[] = $request['state']['context'];
                if (count($replyContexts) === 1) {
                    $parent = $this->upload(['parent.eml' => "Message-ID: <parent@test>\n\nCake rated 9/10."]);
                    app()->call([new ImportMailbox($owner->id, $parent, 0), 'handle']);
                    $workId = DB::table('inference_work')->whereNotNull('call_id')->sole()->id;
                    app()->call([new IndexPassage($workId), 'handle']);
                }

                return Http::response(['answers' => ['remember' => ['noul' => count($replyContexts) === 1 ? 0 : 1]],
                    'usage' => ['input_tokens' => 1]]);
            },
            'http://local.test/embeddings' => Http::response(['data' => [['embedding' => [1, 0]]], 'usage' => ['prompt_tokens' => 1]]),
        ]);

        $this->drain();

        $this->assertSame([[], ['Cake rated 9/10.']], $replyContexts);
        $reply = DB::table('mail_passages')->where('wording', 'Agreed.')->sole();
        $this->assertDatabaseHas('inference_work', ['passage_id' => $reply->id, 'stage' => 'indexed',
            'context_changed' => false, 'call_id' => null]);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_interrupted_completion_rolls_back_the_stage_and_queued_continuation(): void
    {
        $this->actingAs(User::factory()->create());
        $this->upload(['note.txt' => 'Keep this fixture']);
        config(['inference.cap' => 0]);
        $this->drain();
        config(['inference.cap' => 10000000]);
        $workId = DB::table('inference_work')->sole()->id;
        Http::fake([
            'https://api.typesafe.ai/v1/systemone' => Http::response(['answers' => ['remember' => ['noul' => 1]],
                'usage' => ['input_tokens' => 1]]),
            'http://local.test/embeddings' => Http::response(['data' => [['embedding' => [1, 0]]], 'usage' => ['prompt_tokens' => 1]]),
        ]);
        $interruption = new RuntimeException('Interrupted after enqueue');
        $interrupt = true;
        Event::listen(JobQueued::class, function (JobQueued $event) use ($interruption, &$interrupt) {
            if ($interrupt && $event->job instanceof IndexPassage) {
                $interrupt = false;
                throw $interruption;
            }
        });

        try {
            app()->call([new IndexPassage($workId), 'handle']);
            $this->fail('Expected the completion to be interrupted.');
        } catch (RuntimeException $error) {
            $this->assertSame($interruption, $error);
        }

        $callId = DB::table('inference_calls')->sole()->id;
        $this->assertDatabaseHas('inference_calls', ['id' => $callId, 'actual' => null]);
        $this->assertDatabaseHas('inference_work', ['id' => $workId, 'stage' => 'screen', 'call_id' => $callId]);
        $this->assertDatabaseCount('jobs', 0);
        $this->artisan('inference:resume')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        Http::assertSentCount(1);

        $this->artisan("inference:reconcile $callId 84")->assertSuccessful();
        $this->artisan('inference:resume')->assertSuccessful();
        $this->drain();

        $this->assertDatabaseHas('inference_work', ['id' => $workId, 'stage' => 'indexed', 'call_id' => null]);
        Http::assertSentCount(3);
    }

    public function test_partial_rebuild_resumes_before_switching_queries(): void
    {
        $external = [];
        Http::fake(function (Request $request) use (&$external) {
            if ($request->url() === 'https://api.typesafe.ai/v1/systemone') {
                return Http::response(['answers' => ['remember' => ['noul' => 1]], 'usage' => ['input_tokens' => 1]]);
            }
            if ($request->url() === 'https://external.test/embeddings') {
                $this->assertTrue($request->hasHeader('Authorization', 'Bearer fixture'));
                $external[] = $request['input'];
                $vector = [0, 0, 1];
            } else {
                $this->assertSame('http://local.test/embeddings', $request->url());
                $vector = [1, 0];
            }

            return Http::response(['data' => [['embedding' => $vector]], 'usage' => ['prompt_tokens' => 1]]);
        });
        $this->actingAs(User::factory()->create());
        $this->upload(['first.txt' => 'First fixture', 'second.md' => 'Second fixture']);
        $this->drain();
        $spent = DB::table('inference_budget')->value('committed');
        config(['inference.cap' => $spent + 100]);
        $this->artisan('inference:rebuild alternate')->assertExitCode(1);
        $this->post('/input', ['intent' => 'ask', 'wording' => 'Unrelated?'])->assertSee('First fixture');
        $this->assertSame(['First fixture'], $external);
        config(['inference.cap' => 500]);
        $this->artisan('inference:rebuild alternate')->assertSuccessful();
        $this->artisan('inference:rebuild alternate')->assertSuccessful();
        $this->post('/input', ['intent' => 'ask', 'wording' => 'Unrelated?'])->assertSee('Second fixture');
        $this->assertSame(['First fixture', 'Second fixture', 'Unrelated?'], $external);
    }

    public function test_rebuild_replaces_incompatible_vectors_under_the_same_profile(): void
    {
        $requests = [];
        Http::fake([
            'https://api.typesafe.ai/v1/systemone' => Http::response([
                'answers' => ['remember' => ['noul' => 1]], 'usage' => ['input_tokens' => 1],
            ]),
            'http://local.test/embeddings' => function (Request $request) use (&$requests) {
                $requests[] = [$request['model'], $request['input']];

                return Http::response(['data' => [['embedding' => match ($request['model']) {
                    'local' => [1, 0],
                    'intermediate' => [0, 1],
                    'replacement' => [-1, 0],
                }]], 'usage' => ['prompt_tokens' => 1]]);
            },
        ]);
        $this->actingAs(User::factory()->create());
        $this->upload(['first.txt' => 'First fixture', 'second.md' => 'Second fixture']);
        $this->drain();
        $requests = [];
        $indexed = DB::table('inference_work')->where('stage', 'indexed')->orderBy('id');
        $original = $indexed->get(['embedding', 'configuration'])->toJson();
        $spent = DB::table('inference_budget')->value('committed');
        config(['inference.model' => 'intermediate', 'inference.rate' => 1, 'inference.cap' => $spent + 100]);

        $this->artisan('inference:rebuild default')->assertExitCode(1);
        $this->assertSame($original, $indexed->get(['embedding', 'configuration'])->toJson());
        $this->assertDatabaseCount('replacement_embeddings', 1);
        $this->assertSame([['intermediate', 'First fixture']], $requests);

        config(['inference.model' => 'replacement', 'inference.cap' => $spent + 500]);
        $this->artisan('inference:rebuild default')->assertSuccessful();
        $this->artisan('inference:rebuild default')->assertSuccessful();

        $this->assertSame([
            ['intermediate', 'First fixture'],
            ['replacement', 'First fixture'],
            ['replacement', 'Second fixture'],
        ], $requests);
        $this->assertSame(['[-1,0]', '[-1,0]'], $indexed->pluck('embedding')->all());
        $this->assertSame(['replacement', 'replacement'], $indexed->selectRaw("configuration->>'model' as model")->get()->pluck('model')->all());
        $this->assertDatabaseCount('replacement_embeddings', 0);
    }
}
