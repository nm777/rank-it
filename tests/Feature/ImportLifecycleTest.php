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
use ZipArchive;

class ImportLifecycleTest extends TestCase
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
}
