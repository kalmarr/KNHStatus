<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Key-value settings store with group (namespace) support.
 *
 * Settings are grouped by a logical namespace (e.g. 'general', 'notifications',
 * 'monitoring') so that related keys can be fetched or cleared together.
 * Values are always stored as strings; callers are responsible for casting
 * to the appropriate type after retrieval.
 *
 * @property int         $id
 * @property string      $group   Logical namespace for the setting (default: 'general').
 * @property string      $key     Setting key, unique within its group.
 * @property string|null $value   String representation of the setting value.
 */
class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'group',
        'key',
        'value',
    ];

    // -------------------------------------------------------------------------
    // Static helpers
    // -------------------------------------------------------------------------

    /**
     * Retrieve a setting value by group and key.
     *
     * Ha a kulcs nem létezik, a megadott $default értéket adja vissza.
     * Tipikus használat: Setting::get('smtp_host', 'notifications', 'localhost')
     *
     * @param  string       $key     The setting key to look up.
     * @param  string       $group   The group/namespace (default: 'general').
     * @param  string|null  $default Fallback value when the key does not exist.
     */
    public static function get(string $key, string $group = 'general', ?string $default = null): ?string
    {
        return static::where('group', $group)
            ->where('key', $key)
            ->value('value') ?? $default;
    }

    /**
     * Persist a setting value, inserting or updating as needed.
     *
     * Az updateOrCreate garantálja az idempotens műveletet –
     * nem kell előzetesen ellenőrizni, hogy a kulcs létezik-e.
     *
     * @param  string       $key    The setting key.
     * @param  string|null  $value  The value to store (null clears the value).
     * @param  string       $group  The group/namespace (default: 'general').
     */
    public static function set(string $key, ?string $value, string $group = 'general'): void
    {
        static::updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $value]
        );
    }
}
