<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Gym\Models\Program;
use App\Modules\Gym\Models\Trainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Changing a trainer's photo or a program's image never loses the image: the new file is written and saved first,
 * the old one is removed only after that, and a write that fails keeps the old image, records no missing file and
 * shows the reason on the image field instead of success.
 */
class ImageReplaceSafetyTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private string $locked = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        if ($this->locked !== '') {
            @chmod($this->locked, 0755);
        }
        parent::tearDown();
    }

    private function program(Tenant $club): Program
    {
        Storage::disk('public')->put($old = 'tenants/'.$club->id.'/programs/old.webp', 'old image');

        return $this->asTenant($club, fn () => Program::create(['name' => 'Yoga', 'slug' => 'yoga', 'category' => 'class', 'level' => 'all',
            'color' => '#123456', 'duration_minutes' => 60, 'default_capacity' => 10, 'image_path' => $old]));
    }

    /** @return array<string, mixed> */
    private function programForm(UploadedFile $image): array
    {
        return ['name' => 'Yoga', 'category' => 'class', 'level' => 'all', 'color' => '#123456', 'duration_minutes' => 60,
            'default_capacity' => 10, 'image' => $image];
    }

    /** Makes the club's folder read-only, so the next image write fails the way a full or broken disk would. */
    private function breakWrites(Tenant $club, string $folder): void
    {
        $dir = Storage::disk('public')->path('tenants/'.$club->id.'/'.$folder);
        @mkdir($dir, 0755, true);
        chmod($dir, 0555);
        $this->locked = $dir;
    }

    public function test_a_new_program_image_replaces_the_old_one_only_after_it_is_saved(): void
    {
        $club = $this->club();
        $program = $this->program($club);

        $this->actingAs($this->owner($club))
            ->put(route('club.programs.update', $program), $this->programForm(UploadedFile::fake()->image('new.jpg', 800, 600)))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $path = $this->asTenant($club, fn () => $program->fresh()->image_path);
        $this->assertNotSame('tenants/'.$club->id.'/programs/old.webp', $path);
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertMissing('tenants/'.$club->id.'/programs/old.webp');
    }

    public function test_a_failed_program_image_write_keeps_the_old_image_and_says_so(): void
    {
        $club = $this->club();
        $program = $this->program($club);
        $this->breakWrites($club, 'programs');

        $this->actingAs($this->owner($club))
            ->put(route('club.programs.update', $program), ['name' => 'Yoga renamed'] + $this->programForm(UploadedFile::fake()->image('new.jpg', 800, 600)))
            ->assertSessionHasErrors('image')->assertSessionMissing('success');

        $fresh = $this->asTenant($club, fn () => $program->fresh());
        $this->assertSame('tenants/'.$club->id.'/programs/old.webp', $fresh->image_path);
        $this->assertSame('Yoga', $fresh->name, 'nothing of the edit is half-saved');
        Storage::disk('public')->assertExists('tenants/'.$club->id.'/programs/old.webp');
    }

    public function test_a_failed_trainer_photo_write_keeps_the_old_photo_and_says_so(): void
    {
        $club = $this->club();
        Storage::disk('public')->put($old = 'tenants/'.$club->id.'/trainers/old.webp', 'old photo');
        $trainer = $this->asTenant($club, fn () => Trainer::create(['name' => 'Sara', 'photo_path' => $old]));
        $this->breakWrites($club, 'trainers');

        $this->actingAs($this->owner($club))
            ->put(route('club.trainers.update', $trainer), ['name' => 'Sara', 'photo' => UploadedFile::fake()->image('p.jpg', 400, 400)])
            ->assertSessionHasErrors('photo')->assertSessionMissing('success');

        $this->assertSame($old, $this->asTenant($club, fn () => $trainer->fresh()->photo_path));
        Storage::disk('public')->assertExists($old);
    }

    public function test_a_new_trainer_photo_replaces_the_old_one(): void
    {
        $club = $this->club();
        Storage::disk('public')->put($old = 'tenants/'.$club->id.'/trainers/old.webp', 'old photo');
        $trainer = $this->asTenant($club, fn () => Trainer::create(['name' => 'Sara', 'photo_path' => $old]));

        $this->actingAs($this->owner($club))
            ->put(route('club.trainers.update', $trainer), ['name' => 'Sara', 'photo' => UploadedFile::fake()->image('p.jpg', 400, 400)])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $path = $this->asTenant($club, fn () => $trainer->fresh()->photo_path);
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertMissing($old);
    }
}
