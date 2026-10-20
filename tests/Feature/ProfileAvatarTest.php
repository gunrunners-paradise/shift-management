<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AvatarStorage;
use App\Services\ProfileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProfileAvatarTest extends TestCase
{
    // Real commits are necessary to exercise old-file cleanup after commit.
    use DatabaseMigrations;

    /** @var array<int, File> Keep temporary file handles alive for each request. */
    private array $temporaryUploads = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    #[DataProvider('imageFormats')]
    public function test_avatar_is_stored_privately_with_a_random_name_and_served_as_its_actual_image_type(string $format, string $mimeType): void
    {
        $user = User::factory()->create();
        $photo = $this->actualImage('source.'.$format, $format);
        $originalBytes = file_get_contents($photo->getPathname());

        $this->actingAs($user)->patch(route('profile.update'), $this->details(['photo' => $photo]))
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertMatchesRegularExpression('/\Aavatars\/[a-zA-Z0-9]{40}\.(jpg|png|webp)\z/', $user->photo_path);
        $this->assertStringNotContainsString('source', $user->photo_path);
        Storage::disk('local')->assertExists($user->photo_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertStringStartsWith(route('avatars.show', $user).'?v=', $user->avatarUrl());

        $this->get($user->avatarUrl())->assertOk()
            ->assertHeader('Content-Type', $mimeType)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertStreamedContent($originalBytes);
    }

    public static function imageFormats(): array
    {
        return [
            'JPEG' => ['jpeg', 'image/jpeg'],
            'PNG' => ['png', 'image/png'],
            'WebP' => ['webp', 'image/webp'],
        ];
    }

    public function test_stored_extension_comes_from_actual_mime_instead_of_the_client_filename(): void
    {
        $user = User::factory()->create();
        $photo = $this->actualImage('misleading-name.png', 'jpeg');

        $this->actingAs($user)->patch(route('profile.update'), $this->details(['photo' => $photo]))
            ->assertSessionHasNoErrors();

        $this->assertStringEndsWith('.jpg', $user->fresh()->photo_path);
        $this->get(route('avatars.show', $user))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_svg_php_and_non_image_bytes_are_rejected_even_when_the_client_claims_jpeg(): void
    {
        $user = User::factory()->create();
        $attempts = [
            ['avatar.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            ['avatar.jpg', '<?php echo "not an image";'],
            ['avatar.php', '<?php echo "not an image";'],
        ];

        foreach ($attempts as [$filename, $bytes]) {
            $photo = $this->actualFile($filename, $bytes, 'image/jpeg');

            $this->actingAs($user)->patch(route('profile.update'), $this->details(['photo' => $photo]))
                ->assertSessionHasErrors('photo');
        }

        $this->assertNull($user->fresh()->photo_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_php_extension_is_rejected_even_when_the_bytes_are_a_valid_jpeg(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), $this->details([
            'photo' => $this->actualImage('avatar.php', 'jpeg'),
        ]))->assertSessionHasErrors('photo');

        $this->assertNull($user->fresh()->photo_path);
    }

    public function test_size_and_pixel_limits_reject_oversized_images_before_storage(): void
    {
        $user = User::factory()->create();
        $tooWide = $this->actualImage('too-wide.png', 'png', 2049, 10);
        $tooTall = $this->actualImage('too-tall.png', 'png', 10, 2049);
        $smallImage = $this->actualImage('source.png', 'png');
        $tooManyBytes = $this->actualFile('too-large.png', file_get_contents($smallImage->getPathname()).str_repeat('x', 2048 * 1024));

        foreach ([$tooWide, $tooTall, $tooManyBytes] as $photo) {
            $this->actingAs($user)->patch(route('profile.update'), $this->details(['photo' => $photo]))
                ->assertSessionHasErrors('photo');
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertNull($user->fresh()->photo_path);
    }

    public function test_a_request_exceeding_the_php_post_limit_receives_a_hebrew_upload_error(): void
    {
        // Exercise the server's rejection before FormRequest validation, without
        // allocating a huge upload or depending on the test host's PHP limit.
        $this->app->bind(ValidatePostSize::class, function (): ValidatePostSize {
            return new class extends ValidatePostSize
            {
                protected function getPostMaxSize()
                {
                    return 8 * 1024 * 1024;
                }
            };
        });

        $this->withServerVariables(['CONTENT_LENGTH' => 9 * 1024 * 1024])
            ->post(route('profile.update'), ['_method' => 'PATCH'])
            ->assertStatus(413)->assertSee('הקובץ גדול מדי')->assertSee('2MB');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_replacing_an_avatar_deletes_the_old_file_only_after_successful_commit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patch(route('profile.update'), $this->details([
            'photo' => $this->actualImage('first.png', 'png'),
        ]))->assertSessionHasNoErrors();
        $oldPath = $user->fresh()->photo_path;
        $oldAvatarUrl = $user->fresh()->avatarUrl();

        $this->patch(route('profile.update'), $this->details([
            'photo' => $this->actualImage('replacement.jpeg', 'jpeg'),
        ]))->assertSessionHasNoErrors();

        $newPath = $user->fresh()->photo_path;
        $this->assertNotSame($oldPath, $newPath);
        // Polling must detect a photo-only change without changing the worker ID.
        $this->assertNotSame($oldAvatarUrl, $user->fresh()->avatarUrl());
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($newPath);
        $this->assertCount(1, Storage::disk('local')->allFiles('avatars'));
    }

    public function test_rejected_profile_update_keeps_the_existing_avatar_and_creates_no_orphan(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patch(route('profile.update'), $this->details([
            'photo' => $this->actualImage('first.png', 'png'),
        ]))->assertSessionHasNoErrors();
        $oldPath = $user->fresh()->photo_path;

        $this->patch(route('profile.update'), $this->details([
            'first_name' => '',
            'photo' => $this->actualImage('unused.png', 'png'),
        ]))->assertSessionHasErrors('first_name');

        $this->assertSame($oldPath, $user->fresh()->photo_path);
        Storage::disk('local')->assertExists($oldPath);
        $this->assertCount(1, Storage::disk('local')->allFiles('avatars'));
    }

    public function test_a_committed_new_avatar_survives_an_old_file_cleanup_exception(): void
    {
        $user = User::factory()->create();
        app(ProfileService::class)->update($user, $this->details(), $this->actualImage('first.png', 'png'));
        $oldPath = $user->fresh()->photo_path;
        $this->partialMock(AvatarStorage::class, function (MockInterface $mock) use ($oldPath): void {
            $mock->shouldReceive('delete')->with($oldPath)->once()
                ->andThrow(new RuntimeException('Simulated old-image cleanup failure.'));
        });
        $cleanupFailed = false;

        try {
            app(ProfileService::class)->update($user, $this->details(), $this->actualImage('replacement.jpeg', 'jpeg'));
        } catch (RuntimeException $exception) {
            $cleanupFailed = true;
            $this->assertSame('Simulated old-image cleanup failure.', $exception->getMessage());
        }

        // The callback runs after commit. Removing the new file here would leave
        // the saved profile pointing to a missing image even though the write won.
        $this->assertTrue($cleanupFailed);
        $newPath = $user->fresh()->photo_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('local')->assertExists($newPath);
    }

    public function test_deactivation_during_a_staged_upload_leaves_no_unused_file(): void
    {
        $user = User::factory()->create();
        User::query()->whereKey($user->id)->update(['is_active' => false]);
        $denied = false;

        try {
            app(ProfileService::class)->update($user, $this->details(), $this->actualImage('unused.png', 'png'));
        } catch (AuthorizationException) {
            $denied = true;
        }

        $this->assertTrue($denied);
        $this->assertNull($user->fresh()->photo_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_avatar_delivery_requires_authentication_and_does_not_expose_other_managers_or_inactive_workers(): void
    {
        $viewer = User::factory()->create();
        $worker = User::factory()->create();
        $manager = User::factory()->manager()->create();
        $inactiveWorker = User::factory()->inactive()->create();

        $this->get(route('avatars.show', $worker))->assertRedirect(route('login'));
        $this->actingAs($viewer)->get(route('avatars.show', $worker))->assertOk();
        $this->get(route('avatars.show', $manager))->assertNotFound();
        $this->get(route('avatars.show', $inactiveWorker))->assertNotFound();
        $this->flushSession();
        $this->actingAs($manager)->get(route('avatars.show', $manager))->assertOk();
    }

    public function test_missing_or_invalid_photo_paths_return_the_default_image_without_reading_arbitrary_files(): void
    {
        $user = User::factory()->create();
        Storage::disk('local')->put('private-note.txt', 'private contents');

        foreach (['avatars/'.str_repeat('a', 40).'.png', '../private-note.txt', 'private-note.txt'] as $path) {
            $user->forceFill(['photo_path' => $path])->save();

            $response = $this->actingAs($user)->get(route('avatars.show', $user));

            $response->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
            $this->assertSame(realpath(public_path('images/default-avatar.svg')), $response->baseResponse->getFile()->getRealPath());
        }
    }

    private function actualImage(string $filename, string $format, int $width = 40, int $height = 40): UploadedFile
    {
        $temporary = UploadedFile::fake()->image('source.'.$format, $width, $height);
        $this->temporaryUploads[] = $temporary;

        // A real UploadedFile uses server-side MIME detection. The fake class
        // intentionally derives its MIME from its name, which would hide spoofing.
        return new UploadedFile($temporary->getPathname(), $filename, 'application/octet-stream', null, true);
    }

    private function actualFile(string $filename, string $bytes, string $clientMime = 'application/octet-stream'): UploadedFile
    {
        $temporary = UploadedFile::fake()->createWithContent($filename, $bytes);
        $this->temporaryUploads[] = $temporary;

        return new UploadedFile($temporary->getPathname(), $filename, $clientMime, null, true);
    }

    private function details(array $overrides = []): array
    {
        return array_replace([
            'first_name' => 'דנה',
            'last_name' => 'כהן',
            'gender' => 'female',
            'birth_date' => null,
        ], $overrides);
    }
}
