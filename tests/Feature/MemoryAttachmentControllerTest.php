<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MemoryAttachmentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_composer_saves_a_photo_with_question_like_wording_and_displays_it_privately(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        $jpeg = UploadedFile::fake()->image('window.jpg', 2000, 1000)->getContent();
        // Little-endian EXIF with one GPS directory and two sets of three rationals.
        $exif = "Exif\0\0II".pack('vV', 42, 8)
            .pack('v', 1).pack('vvVV', 0x8825, 4, 1, 26).pack('V', 0)
            .pack('v', 4)
            .pack('vvVV', 1, 2, 2, ord('N')).pack('vvVV', 2, 5, 3, 80)
            .pack('vvVV', 3, 2, 2, ord('W')).pack('vvVV', 4, 5, 3, 104)
            .pack('V', 0)
            .pack('V*', 41, 1, 52, 1, 30, 1, 87, 1, 37, 1, 0, 1);
        $taggedJpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        $wording = 'Why were the windows hard to install? The frames were out of square.';

        $this->actingAs($owner)->from('/')->post('/input', [
            'wording' => $wording,
            'photos' => [
                UploadedFile::fake()->createWithContent('window.jpg', $taggedJpeg),
                UploadedFile::fake()->image('window-frame.png'),
            ],
        ])->assertRedirect('/')->assertSessionHas('saved_with_photo', true);

        $memory = $owner->memories()->sole();
        $this->assertSame($wording, $memory->wording);
        $this->assertDatabaseHas('inference_work', ['memory_id' => $memory->id, 'stage' => 'embed']);
        $this->assertDatabaseCount('memory_attachments', 2);
        $attachment = DB::table('memory_attachments')->where('memory_id', $memory->id)->orderBy('id')->value('id');
        $this->assertDatabaseHas('memory_attachments', [
            'id' => $attachment, 'latitude' => 41.875, 'longitude' => -87.616667,
        ]);
        $url = route('attachments.show', [$memory, $attachment]);

        $this->get(route('memories.show', $memory))->assertOk()->assertSee($url);
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $servedImage = $response->streamedContent();
        $this->assertStringNotContainsString("Exif\0\0", $servedImage);
        $size = getimagesizefromstring($servedImage);
        $this->assertSame([1600, 800, IMAGETYPE_JPEG], array_slice($size, 0, 3));

        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_composer_with_an_empty_photo_field_can_still_save_text(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->call('POST', '/input', [
            'wording' => 'I liked the chicken and gravy.',
        ], files: ['photos' => [
            'name' => [''],
            'type' => [''],
            'tmp_name' => [''],
            'error' => [UPLOAD_ERR_NO_FILE],
            'size' => [0],
        ]])->assertRedirect('/');

        $this->assertSame('I liked the chicken and gravy.', $owner->memories()->sole()->wording);
        $this->assertDatabaseEmpty('memory_attachments');
    }

    public function test_invalid_upload_preserves_wording_without_saving_the_memory(): void
    {
        $wording = 'The chicken and gravy were excellent.';

        $this->actingAs(User::factory()->create())->from('/')->post('/input', [
            'wording' => $wording,
            'photos' => [
                UploadedFile::fake()->image('meal.png'),
                UploadedFile::fake()->createWithContent('meal.jpg', 'This is not an image.'),
            ],
        ])->assertRedirect('/')->assertSessionHasErrors('photo')->assertSessionHasInput('wording', $wording);

        $this->assertDatabaseEmpty('memories');
        $this->assertDatabaseEmpty('memory_attachments');
    }

    public function test_an_explicit_question_with_a_photo_is_rejected_without_saving(): void
    {
        $this->actingAs(User::factory()->create())->from('/')->post('/input', [
            'wording' => 'What did I have for dinner?',
            'intent' => 'ask',
            'photo' => UploadedFile::fake()->image('meal.png'),
        ])->assertRedirect('/')->assertSessionHasErrors('photo');

        $this->assertDatabaseEmpty('memories');
        $this->assertDatabaseEmpty('memory_attachments');
    }

    public function test_owner_can_add_and_remove_photos_and_deleting_a_memory_removes_remaining_photos(): void
    {
        $owner = User::factory()->create();
        $memory = $owner->memories()->create(['wording' => 'Chicken and gravy with herbs.']);
        $showMemory = route('memories.show', $memory);
        $addPhoto = route('attachments.store', $memory);

        $this->actingAs($owner);
        foreach (range(1, 5) as $index) {
            $this->post($addPhoto, ['photo' => UploadedFile::fake()->image("meal-{$index}.png")])
                ->assertRedirect($showMemory);
        }

        $this->post($addPhoto, ['photo' => UploadedFile::fake()->image('meal-extra.png')])
            ->assertSessionHasErrors('photo');
        $this->assertDatabaseCount('memory_attachments', 5);

        $attachment = DB::table('memory_attachments')->where('memory_id', $memory->id)->value('id');
        $photo = route('attachments.show', [$memory, $attachment]);
        $otherMemory = $owner->memories()->create(['wording' => 'Lunch.']);
        $this->get(route('attachments.show', [$otherMemory, $attachment]))->assertNotFound();
        $this->delete(route('attachments.delete', [$otherMemory, $attachment]))->assertNotFound();

        $this->actingAs(User::factory()->create());
        $this->post($addPhoto, ['photo' => UploadedFile::fake()->image('meal.png')])->assertNotFound();
        $this->delete($photo)->assertNotFound();

        $this->actingAs($owner)->delete($photo)->assertRedirect($showMemory);
        $this->get($photo)->assertNotFound();
        $this->assertDatabaseCount('memory_attachments', 4);
        $this->delete(route('memories.delete', $memory))->assertRedirect('/');
        $this->assertDatabaseEmpty('memory_attachments');
    }
}
