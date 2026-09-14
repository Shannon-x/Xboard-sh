<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Knowledge extends Model
{
    public const VISIBILITIES = ['public', 'members', 'subscribers', 'admin'];
    public const PUBLIC_LANGUAGES = ['zh-CN', 'zh-TW', 'en', 'ja', 'ko', 'de'];

    protected $attributes = ['visibility' => 'members', 'show' => false];
    protected $table = 'v2_knowledge';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'show' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'published_at' => 'integer',
    ];
}
