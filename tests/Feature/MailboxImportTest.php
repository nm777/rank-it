<?php

namespace Tests\Feature;

use App\Jobs\ImportMailbox;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MailboxImportTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'rank_it_test') {
            throw new \RuntimeException('Mailbox tests require the dedicated testing database.');
        }
    }

    private function worker(bool $once = false): void
    {
        $db = DB::connection()->getConfig();
        $worker = new Process([PHP_BINARY, 'artisan', 'queue:work', 'database', $once ? '--once' : '--stop-when-empty', '--sleep=0'], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => $db['host'],
            'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'rank_it_test', 'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => $db['password'], 'IMPORT_STAGING_PATH' => Storage::disk('import-staging')->path(''),
            'CACHE_STORE' => 'array',
        ]);
        $worker->mustRun();
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_unreadable_file_is_discarded_and_uncertain_messages_stay_separate(): void
    {
        Storage::fake('import-staging');
        $this->actingAs(User::factory()->create());
        $upload = fn ($body) => $this->post('/imports', ['mailbox' => UploadedFile::fake()->createWithContent('fixture', $body)])
            ->assertRedirect()->headers->get('Location');
        $bad = $upload('This file has no MBOX envelopes.');
        $record = "From sender@example.test Wed Mar  5 12:00:00 2025\r\nSubject: HTML\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>Question?</p><blockquote>Earlier.</blockquote>\r\n";
        $good = $upload($record.$record);
        $this->worker();
        $this->get($bad)->assertSee('Could not import')->assertSee('1 known unreadable');
        $this->get($good)->assertSee('2 messages imported');
        $this->assertDatabaseCount('mail_messages', 2);
        $this->assertDatabaseHas('mail_passages', ['quote_text' => 'Earlier.']);
        $this->assertSame([], Storage::disk('import-staging')->allFiles());
    }

    public function test_worker_resumes_and_repaired_upload_reuses_private_evidence(): void
    {
        Storage::fake('import-staging');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $fixture = file_get_contents(base_path('tests/Fixtures/synthetic.mbox'));
        $upload = fn ($content) => $this->post('/imports', ['mailbox' => UploadedFile::fake()->createWithContent('synthetic.mbox', $content)]);
        $this->actingAs($owner)->get('/imports')->assertSee('private collection');
        $url = $upload($fixture)->assertRedirect()->headers->get('Location');
        $id = basename($url);
        $this->get($url)->assertSee('Upload complete')->assertSee('Queued');
        $this->assertDatabaseCount('mail_messages', 0);
        Storage::disk('import-staging')->assertExists("$owner->id/$id");
        $this->worker(true);
        $this->get($url)->assertSee('Processing')->assertSee('1 messages imported');
        // Redeliver the original cursor after the worker has checkpointed it.
        ImportMailbox::dispatch($owner->id, $id);
        $this->worker();
        $this->get($url)->assertSee('Partially imported')->assertSee('2 messages imported')->assertSee('1 known unreadable')->assertSee('inventory.txt');
        $this->assertSame([], Storage::disk('import-staging')->allFiles());
        $repair = str_replace('Unreadable synthetic record', "From: Kim <kim@example.test>\nDate: Mon, 3 Mar 2025 10:01:00 +0000\n\nRecovered.", $fixture);
        $repaired = $upload($repair)->headers->get('Location');
        $upload($repair);
        $this->worker();
        $this->get($repaired)->assertSee('3 messages imported')->assertSee('0 known unreadable');
        $this->assertDatabaseCount('mail_messages', 3);
        $this->assertDatabaseCount('mailbox_entries', 8);
        $passage = DB::table('mail_passages')->where('wording', 'The key is in B-17.')->sole();
        $reply = DB::table('mail_passages')->where('wording', 'Agreed.')->sole();
        $this->post('/search', ['wording' => 'B-17'])->assertSee(route('passages.show', $passage->id))->assertDontSee('Quoted history');
        $this->get("/passages/$reply->id")->assertSee('<mark>Agreed.</mark>', false)->assertSee('Sam')->assertSee('Quoted history')->assertSee(route('passages.show', $passage->id));
        $this->get("/passages/$passage->id")->assertSee('Maya')->assertSee('3 Mar 2025')->assertSee('<mark>The key is in B-17.</mark>', false);
        $this->post('/search', ['wording' => 'Attachment-only'])->assertSee('No matching memories');
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->get("/passages/$passage->id")->assertNotFound();
        $this->post('/search', ['wording' => 'B-17'])->assertSee('No matching memories');
        $upload($fixture);
        $this->worker();
        $this->assertDatabaseCount('mail_messages', 5);
        $this->assertSame([], Storage::disk('import-staging')->allFiles());
    }
}
