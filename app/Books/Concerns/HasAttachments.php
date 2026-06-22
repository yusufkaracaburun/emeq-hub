<?php

namespace App\Books\Concerns;

use App\Books\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/*
 * Bijlagen op een Books-document (Invoice/Bill). Polymorf, mirror HasPayments.
 * Bij delete van het document worden de bijlagen meegenomen (rij + bestand) —
 * de morph-relatie heeft geen DB-cascade, dus die ruimen we hier op.
 */
trait HasAttachments
{
    public static function bootHasAttachments(): void
    {
        static::deleting(static function (Model $model): void {
            $model->attachments->each->delete();
        });
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
