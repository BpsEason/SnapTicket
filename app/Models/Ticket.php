<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    use HasFactory;

    protected $table = 'tickets';

    protected $fillable = [
        'name',
        'description',
        'total_stock',
        'current_stock',
        'price',
        'start_time',
        'end_time',
        'timeout_minutes',
    ];

    protected $casts = [
        'price' => 'float',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'timeout_minutes' => 'integer',
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
