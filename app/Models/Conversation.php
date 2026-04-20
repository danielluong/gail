<?php

namespace App\Models;

use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

#[Fillable(['title', 'project_id', 'user_id', 'is_pinned', 'parent_id'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'agent_conversations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = [
        'is_pinned' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<ConversationMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Match conversations whose title contains the query, or whose
     * messages contain the query in their content. The query is
     * matched as a case-insensitive substring.
     *
     * @param  Builder<self>  $query
     */
    public function scopeMatchingQuery(Builder $query, string $term): Builder
    {
        $like = '%'.$term.'%';
        $operator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query->where(function (Builder $q) use ($like, $term, $operator) {
            $q->where('title', $operator, $like)
                ->orWhereHas('messages', function (Builder $messages) use ($term, $operator) {
                    $messages->where('content', $operator, '%'.$term.'%');
                });
        });
    }
}
