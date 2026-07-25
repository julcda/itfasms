<?php

declare(strict_types=1);

namespace App\Models\Classroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonResource extends Model
{
    protected $table = 'classroom_lesson_resources';

    protected $fillable = [
        'lesson_id', 'type', 'title', 'url', 'file_path', 'file_name',
        'file_size', 'mime_type', 'sort_order', 'created_by',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->type, 'video_');
    }

    /** Best-effort YouTube/Vimeo/Drive embed URL from whatever the teacher pasted. */
    public function embedUrl(): ?string
    {
        $url = (string) $this->url;
        if ($url === '') {
            return null;
        }
        return match ($this->type) {
            'video_youtube' => self::youtubeEmbed($url),
            'video_vimeo'   => self::vimeoEmbed($url),
            'video_gdrive'  => self::gdriveEmbed($url),
            default         => $url,
        };
    }

    private static function youtubeEmbed(string $url): string
    {
        $id = null;
        if (preg_match('/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|shorts\/))([A-Za-z0-9_-]{6,})/', $url, $m)) {
            $id = $m[1];
        }
        return $id ? "https://www.youtube.com/embed/{$id}" : $url;
    }

    private static function vimeoEmbed(string $url): string
    {
        $id = null;
        if (preg_match('/vimeo\.com\/(?:video\/)?(\d+)/', $url, $m)) {
            $id = $m[1];
        }
        return $id ? "https://player.vimeo.com/video/{$id}" : $url;
    }

    private static function gdriveEmbed(string $url): string
    {
        $id = null;
        if (preg_match('/\/d\/([A-Za-z0-9_-]{10,})/', $url, $m)) {
            $id = $m[1];
        } elseif (preg_match('/[?&]id=([A-Za-z0-9_-]{10,})/', $url, $m)) {
            $id = $m[1];
        }
        return $id ? "https://drive.google.com/file/d/{$id}/preview" : $url;
    }
}
