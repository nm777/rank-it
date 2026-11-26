<?php

namespace App;

use App\Inference\Ratings;
use App\Jobs\IndexPassage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveMemory
{
    public function __construct(private MemoryPhoto $photo) {}

    /**
     * @param  array{wording: string, experience_date: ?string}  $data
     * @param  list<array{contents: resource, latitude: ?float, longitude: ?float}>  $preparedPhotos
     */
    public function handle(User $owner, array $data, ?string $passage = null, array $preparedPhotos = []): string
    {
        return DB::transaction(function () use ($owner, $data, $passage, $preparedPhotos) {
            $memory = $owner->memories()->make($data);
            if ($passage !== null) {
                $source = DB::table('mail_passages')->join('mail_messages', 'mail_messages.id', '=', 'mail_passages.message_id')
                    ->where('mail_messages.user_id', $owner->id)->where('mail_passages.id', $passage)
                    ->lockForUpdate()->firstOrFail(['mail_passages.id']);
                $memory->corrects_passage_id = $source->id;
            }
            $memory->save();
            foreach ($preparedPhotos as $preparedPhoto) {
                $this->photo->attach($memory->id, $preparedPhoto);
            }
            Ratings::capture($memory);
            IndexPassage::enqueue(['memory_id' => $memory->id], 'embed');

            return $memory->id;
        });
    }
}
