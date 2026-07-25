<?php

declare(strict_types=1);

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Model;

class GradingPeriod extends Model
{
    protected $table = 'grading_period';
    public $timestamps = true;
    protected $fillable = [];

    public function isOpen(): bool
    {
        return (string) $this->status === 'Open';
    }
}
