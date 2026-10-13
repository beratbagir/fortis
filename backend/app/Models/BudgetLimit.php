<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Aylık AI bütçesi: şirket (org), departman veya kullanıcı. Limit boşsa bütçe yok (sınırsız).
 */
#[Table('budgets')]
#[Fillable(['scope', 'scope_id', 'monthly_limit_usd', 'warn_at_percent', 'hard_stop', 'warn_sent_month', 'full_sent_month'])]
class BudgetLimit extends Model
{
    public const ORG = 'org';

    public const DEPARTMENT = 'department';

    public const USER = 'user';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['monthly_limit_usd' => 'float', 'warn_at_percent' => 'integer', 'hard_stop' => 'boolean', 'scope_id' => 'integer'];
    }

    public static function org(): self
    {
        return self::query()->firstOrCreate(['scope' => self::ORG, 'scope_id' => null], ['warn_at_percent' => 80, 'hard_stop' => false]);
    }

    public function label(): string
    {
        return match ($this->scope) {
            self::DEPARTMENT => (Department::query()->whereKey($this->scope_id)->value('name') ?? 'Departman').' departmanı',
            self::USER => (User::query()->whereKey($this->scope_id)->value('name') ?? 'Kullanıcı').' kullanıcısı',
            default => 'Şirket',
        };
    }
}
