<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class Stage7SecurityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('privateResponses')]
    public function test_browser_and_json_responses_disable_shared_caching_and_restrict_external_content(?string $role, string $routeName): void
    {
        if ($role !== null) {
            $this->actingAs(User::factory()->create(['role' => $role]));
        }

        $response = $this->get(route($routeName));

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'same-origin');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $contentPolicy = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $contentPolicy);
        $this->assertStringContainsString("frame-ancestors 'none'", $contentPolicy);
        $this->assertStringContainsString("form-action 'self'", $contentPolicy);
        $this->assertStringNotContainsString('unsafe-inline', $contentPolicy);
        $this->assertStringNotContainsString('unsafe-eval', $contentPolicy);
    }

    public static function privateResponses(): array
    {
        return [
            'guest login' => [null, 'login'],
            'manager dashboard' => ['manager', 'admin.users.index'],
            'worker information JSON' => ['worker', 'worker.today'],
        ];
    }

    public function test_a_server_failure_with_debug_disabled_returns_a_generic_hebrew_page_without_internal_details(): void
    {
        config(['app.debug' => false]);

        // This route exists only inside this test. Its synthetic message must
        // never reach a browser when production-style error rendering is used.
        Route::middleware('web')->get('/stage7-error-probe', function (): never {
            throw new RuntimeException('Synthetic-private-diagnostic-Stage7');
        });

        $this->get('/stage7-error-probe')->assertStatus(500)
            ->assertSee('lang="he"', false)
            ->assertSee('dir="rtl"', false)
            ->assertDontSee('Synthetic-private-diagnostic-Stage7')
            ->assertDontSee('RuntimeException')
            ->assertDontSee(base_path());
    }
}
