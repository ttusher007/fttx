<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Unauthenticated visitors to the root are redirected toward auth.
     */
    public function test_the_application_redirects_guests(): void
    {
        // Both "/" and /dashboard sit behind auth, so guests land on login.
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/dashboard')->assertRedirect(route('login'));

        $this->get(route('login'))->assertOk();
    }
}
