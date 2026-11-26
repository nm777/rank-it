<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RatingsTest extends TestCase
{
    use DatabaseMigrations;

    public function test_current_ever_scale_date_edit_and_identity_link(): void
    {
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
        $this->post('/input', ['wording' => 'Lemon cake is a nine.'])->assertRedirect();
        $this->post('/input', ['wording' => 'The temperature is 70.'])->assertViewIs('clarify');
        $this->post('/memories', ['wording' => 'John’s work is a 2 out of 5.', 'experience_date' => '2025']);
        $wording = 'John’s work is a 5 out of 5.';
        $this->post('/memories', ['wording' => $wording, 'experience_date' => '2024'])->assertRedirect('/');
        $old = route('memories.show', auth()->user()->memories()->where('wording', $wording)->sole());
        $this->post('/input', ['wording' => 'Which did I rate at least 4/5?'])->assertSee('0 confirmed');
        $this->post('/input', ['wording' => 'Which did I ever rate at least 4/5?'])->assertSee('1 confirmed')->assertSee('John');
        $this->post('/input', ['wording' => 'Which experiences in 2024-03 had ratings?'])->assertSee('0 confirmed')->assertSee('Uncertain dates');
        $this->post($old.'/person', ['person' => 'John from Acme'])->assertRedirect();
        $this->post('/input', ['wording' => 'What scores did John from Acme ever receive?'])->assertSee('John from Acme')->assertSee('1 confirmed');
        $this->patch($old, ['wording' => 'Changed wording.'])->assertRedirect();
        $this->assertDatabaseCount('ratings', 2);
        $this->post('/input', ['wording' => 'Which did I ever rate at least 4/5?'])->assertSee('0 confirmed');
        $this->post('/input', ['wording' => 'What were my lemon cake ratings?'])->assertSee('scale unknown');
    }

    public function test_late_parent_shared_identity_reimport_and_scoped_evidence(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.typesafe.ai/*' => Http::response(['answers' => ['remember' => ['noul' => 1]], 'usage' => ['input_tokens' => 10]])]);
        Storage::fake('import-staging');
        $owner = User::factory()->create();
        $this->actingAs($owner);
        config(['inference.cap' => 10000000, 'inference.reserve' => 1000, 'inference.jev_verified' => true, 'inference.jev_key' => 'fixture', 'inference.endpoint' => null]);
        $upload = function ($name, $from, $body, $parent = false) {
            $mail = "Message-ID: <$name@test>\nFrom: $from\nDate: Tue, 3 Mar 2026 12:00:00 +0000\n".($parent ? "In-Reply-To: <original@test>\n" : '')."\n$body";
            $this->post('/imports', ['files' => [UploadedFile::fake()->createWithContent("$name.eml", $mail)]])->assertRedirect();
            $this->artisan('queue:work database --stop-when-empty --sleep=0')->assertSuccessful();
        };
        $upload('reply', 'shared@example.org', 'Agreed.', true);
        $upload('selective', 'mine@example.org', 'Agreed on the lemon cake.', true);
        $upload('unclear', 'shared@example.org', 'One of those sounds right.', true);
        $original = fn () => $upload('original', 'maya@example.org', 'Lemon cake is 9/10 and carrot cake is 4/10 last month.');
        config(['inference.cap' => 0]);
        $original();
        $question = ['wording' => 'What ratings did I ever give?'];
        $this->post('/input', $question + ['intent' => 'ask'])->assertSee('processed portion');
        config(['inference.cap' => 10000000]);
        $this->artisan('inference:resume');
        $this->artisan('queue:work database --stop-when-empty --sleep=0');
        $replies = DB::table('mail_messages')->where('sender', 'shared@example.org')->orderBy('id')->get();
        $answer = $this->post('/messages/'.$replies[0]->id.'/identity', $question + ['scope' => 'message'])
            ->assertSee('2 confirmed')->assertSee('Agreement')->assertSee('2026-02')->assertDontSee('processed portion');
        foreach (['maya@example.org' => 'Lemon cake is 9/10 and carrot cake is 4/10 last month.', 'shared@example.org' => 'Agreed.'] as $sender => $wording) {
            $passage = DB::table('mail_passage_sources')->where('sender', $sender)->where('wording', $wording)->value('id');
            $url = route('passages.show', $passage);
            $answer->assertSee($url.'#passage-'.$passage);
            $this->get($url)->assertOk()->assertSee('<mark>'.$wording.'</mark>', false);
        }
        $mine = DB::table('mail_messages')->where('sender', 'mine@example.org')->value('id');
        $this->post("/messages/$mine/identity", $question + ['scope' => 'address'])->assertSee('3 confirmed');
        $this->post('/input', ['wording' => 'Which ratings did I give in March 2026?', 'intent' => 'ask'])->assertSee('3 confirmed');
        $this->post('/input', ['wording' => 'Which experiences in February 2026 had my ratings?', 'intent' => 'ask'])->assertSee('1 confirmed')->assertSee('Uncertain dates');
        $this->assertNull(DB::table('mail_messages')->find($replies[1]->id)->authored_by_me);
        $parent = DB::table('mail_messages')->where('sender', 'maya@example.org')->value('id');
        $this->actingAs(User::factory()->create())->post("/messages/$mine/identity", $question + ['scope' => 'address'])->assertNotFound();
        $this->post('/input', $question + ['intent' => 'ask'])->assertSee('0 confirmed')->assertDontSee('maya@example.org');
        $this->actingAs($owner)->delete("/messages/$parent")->assertRedirect();
        $this->post('/input', $question + ['intent' => 'ask'])->assertSee('0 confirmed')->assertDontSee('Original score');
        $original();
        $this->post('/input', $question + ['intent' => 'ask'])->assertSee('3 confirmed');
        $this->assertDatabaseCount('ratings', 2);
    }
}
