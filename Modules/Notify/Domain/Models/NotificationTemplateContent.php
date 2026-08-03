<?php

declare(strict_types=1);

namespace Modules\Notify\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NotificationTemplateContent extends Model
{
    use HasUuids;

    protected $table = 'notification_template_contents';

    protected $fillable = ['template_id', 'locale', 'subject', 'body'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_id');
    }
}
