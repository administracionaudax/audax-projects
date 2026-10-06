<?php

use App\Domain\Users\AvatarStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| La foto de perfil (F-029, 10.9b y D-234): se sube ya recortada, el servidor la recorta al cuadrado,
| la deja en 256 px sin metadatos y la sirve solo con una URL firmada.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->me = userWithRole('employee');
});

it('sube la foto: cuadrada, de 256 px, en el disco privado y con URL firmada', function () {
    $this->actingAs($this->me)
        ->post('/ajustes/perfil/foto', ['avatar' => UploadedFile::fake()->image('yo.jpg', 900, 600)])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', __('app.avatar.updated'));

    $path = $this->me->refresh()->avatar_path;
    expect($path)->toStartWith('avatars/'.$this->me->id.'-');
    Storage::disk('local')->assertExists($path);
    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($path));
    expect([$width, $height])->toBe([AvatarStorage::SIZE, AvatarStorage::SIZE]);

    $url = $this->me->avatar_url;
    expect($url)->toStartWith("/avatares/{$this->me->id}?")->toContain('signature=');

    // La misma URL durante el día (caché del navegador); cualquiera de la app la ve con la firma.
    expect($this->me->avatar_url)->toBe($url);
    $this->actingAs(userWithRole('employee'))->get($url)->assertOk()->assertHeader('Cache-Control', 'max-age=86400, private');
    $this->actingAs(userWithRole('employee'))->get("/avatares/{$this->me->id}")->assertForbidden();
    // Un cliente del portal no ve las fotos de la plantilla, ni con la firma.
    $this->actingAs(userWithRole('client'))->get($url)->assertForbidden();

    $this->actingAs($this->me)->get('/ajustes/perfil')->assertInertia(fn (Assert $page) => $page->where('auth.user.avatar', $url));
});

it('cambiarla borra la anterior; quitarla vuelve a las iniciales', function () {
    $this->actingAs($this->me)->post('/ajustes/perfil/foto', ['avatar' => UploadedFile::fake()->image('a.png', 300, 300)]);
    $first = $this->me->refresh()->avatar_path;

    $this->actingAs($this->me)->post('/ajustes/perfil/foto', ['avatar' => UploadedFile::fake()->image('b.png', 300, 400)]);
    $second = $this->me->refresh()->avatar_path;

    expect($second)->not->toBe($first);
    Storage::disk('local')->assertMissing($first);

    $this->actingAs($this->me)->delete('/ajustes/perfil/foto')->assertInertiaFlash('toast.message', __('app.avatar.removed'));

    expect($this->me->refresh()->avatar_path)->toBeNull()
        ->and($this->me->avatar_url)->toBeNull();
    Storage::disk('local')->assertMissing($second);
});

it('rechaza lo que no es una imagen, una que no se puede leer o una demasiado grande', function () {
    $this->actingAs($this->me)
        ->postJson('/ajustes/perfil/foto', ['avatar' => UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')])
        ->assertJsonValidationErrors(['avatar' => __('app.avatar.invalid')]);
    $this->actingAs($this->me)
        ->postJson('/ajustes/perfil/foto', ['avatar' => UploadedFile::fake()->create('falsa.jpg', 10, 'image/jpeg')])
        ->assertJsonValidationErrors(['avatar']);
    $this->actingAs($this->me)
        ->postJson('/ajustes/perfil/foto', ['avatar' => UploadedFile::fake()->image('enorme.jpg')->size(6000)])
        ->assertJsonValidationErrors(['avatar' => __('app.avatar.too_large', ['mb' => 5])]);

    expect($this->me->refresh()->avatar_path)->toBeNull();
});

it('el perfil enseña el departamento y el rol de solo lectura (F-027)', function () {
    $this->actingAs($this->me)->get('/ajustes/perfil')->assertInertia(fn (Assert $page) => $page
        ->where('department', $this->me->department?->name));

    // El departamento no se cambia desde el perfil.
    $this->actingAs($this->me)->patch('/ajustes/perfil', ['name' => $this->me->name, 'email' => $this->me->email, 'department_id' => 999]);
    expect($this->me->refresh()->department_id)->not->toBe(999);
});
