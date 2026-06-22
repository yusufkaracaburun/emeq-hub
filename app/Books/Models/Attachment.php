<?php

namespace App\Books\Models;

use App\Books\Concerns\BelongsToBooksCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/*
 * Bijlage bij een Books-document (Invoice/Bill). Polymorf; het bestand staat op
 * een private disk, deze rij draagt enkel metadata + pad. Bij delete wordt het
 * fysieke bestand opgeruimd — Filament doet dat niet zelf.
 */
class Attachment extends Model
{
    use BelongsToBooksCompany;

    protected $table = 'books_attachments';

    protected $fillable = [
        'company_id',
        'attachable_type',
        'attachable_id',
        'original_name',
        'disk',
        'path',
        'mime_type',
        'size',
        'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    protected static function booted(): void
    {
        // De FileUpload levert enkel path (+ original_name); disk/metadata/uploader
        // leiden we hier af — onafhankelijk van de Filament-action-API.
        static::creating(static function (Attachment $attachment): void {
            $attachment->disk ??= 'local';

            if (empty($attachment->uploaded_by)) {
                $attachment->uploaded_by = auth()->id();
            }

            if ($attachment->path && Storage::disk($attachment->disk)->exists($attachment->path)) {
                if (empty($attachment->size)) {
                    $attachment->size = Storage::disk($attachment->disk)->size($attachment->path);
                }
                $attachment->mime_type ??= Storage::disk($attachment->disk)->mimeType($attachment->path);
            }
        });

        static::deleting(static function (Attachment $attachment): void {
            if ($attachment->path && Storage::disk($attachment->disk)->exists($attachment->path)) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }
        });
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
