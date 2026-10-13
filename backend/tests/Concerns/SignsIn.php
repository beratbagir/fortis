<?php

namespace Tests\Concerns;

use App\Models\User;

/**
 * Giriş gelmeden önce yazılmış testler için: her test oturum açmış bir admin ile başlar.
 * Laravel, `setUp<TraitAdı>` metodunu RefreshDatabase'den sonra çağırır.
 */
trait SignsIn
{
    protected User $signedIn;

    protected function setUpSignsIn(): void
    {
        $this->signedIn = User::factory()->admin()->create();
        $this->actingAs($this->signedIn);
    }
}
