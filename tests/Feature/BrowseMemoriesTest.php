<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrowseMemoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_memories_requires_sign_in(): void
    {
        $this->get(route('memories.browse'))->assertRedirect(route('login'));
    }

    public function test_search_lists_only_owned_matches_and_keeps_the_filter_on_the_next_page(): void
    {
        $user = User::factory()->create();
        $this->freezeTime();
        $oldest = $user->memories()->create(['wording' => 'ORCHARD oldest']);
        $newest = $oldest;
        for ($number = 1; $number <= 20; $number++) {
            $newest = $user->memories()->create(['wording' => "Orchard visit $number"]);
        }
        $user->memories()->create(['wording' => 'Beach outing']);
        User::factory()->create()->memories()->create(['wording' => 'Orchard private']);

        $firstPage = $this->actingAs($user)->get(route('memories.browse', ['q' => 'orchard']));

        $firstPage->assertOk()
            ->assertSeeInOrder([$newest->wording, 'Orchard visit 1'])
            ->assertDontSee($oldest->wording)
            ->assertDontSee('Beach outing')
            ->assertDontSee('Orchard private');
        $this->get($firstPage->viewData('memories')->nextPageUrl())
            ->assertOk()
            ->assertSee($oldest->wording)
            ->assertDontSee($newest->wording)
            ->assertDontSee('Beach outing')
            ->assertDontSee('Load more memories');
    }

    public function test_menu_opens_the_full_memory_list_and_empty_search_has_feedback(): void
    {
        $user = User::factory()->create();
        $user->memories()->create(['wording' => '<b>Beach outing</b>']);

        $this->actingAs($user)->get(route('memories.index'))
            ->assertOk()
            ->assertSee('href="'.route('memories.browse').'">Memories</a>', false);
        $this->get(route('memories.browse'))->assertOk()
            ->assertSee('<b>Beach outing</b>')
            ->assertDontSee('<b>Beach outing</b>', false);
        $this->get(route('memories.browse', ['q' => 'orchard']))
            ->assertOk()->assertSee('No matching memories.');
    }

    public function test_malformed_cursor_returns_not_found(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('memories.browse', ['cursor' => 'invalid']))
            ->assertNotFound();
    }
}
