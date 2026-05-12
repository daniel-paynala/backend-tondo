<?php

namespace App\Models;

use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TondoSignalement extends Model
{
    use UuidPrimary;

    protected $table = 'tondo_signalements';

    protected $guarded = ['id'];

    protected $casts = [
        'date_creation' => 'datetime',
        'resolu_le' => 'datetime',
    ];

    public function cagnotte(): BelongsTo
    {
        return $this->belongsTo(TondoCagnotte::class, 'cagnotte_id');
    }
}
