<?php

use App\Domain\Identity\CompanyIdentity;
use App\Domain\Reports\Delivery\Documents\PdfTable;
use App\Domain\Reports\Delivery\Documents\ReportPdf;
use App\Domain\Reports\Pdf\ReportHtml;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Admin\UserInvitation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Identidad de la empresa (/admin/identidad, SPEC §14, D-067), solo admin: nombre y logo (PNG, JPG
| o WebP hasta 1 MB; nunca SVG; tipo real con fileinfo), que se usan en la cabecera del portal, en
| los emails y en los PDF. El logo se vuelve a codificar en PNG y se sirve por una ruta pública propia.
*/

beforeEach(function () {
    Storage::fake(CompanyIdentity::DISK);
    $this->admin = userWithRole('admin');

    // PNG con transparencia (fondo transparente y un rectángulo navy), como lo exportaría un diseñador.
    $this->transparentPng = function (int $width = 1200, int $height = 400): UploadedFile {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, (int) ($width / 4), (int) ($height / 4), (int) ($width * 3 / 4), (int) ($height * 3 / 4), (int) imagecolorallocate($image, 0, 27, 57));
        ob_start();
        imagepng($image);

        return UploadedFile::fake()->createWithContent('logo.png', (string) ob_get_clean());
    };
    $this->upload = fn (?UploadedFile $logo, string $name = 'Audax Studio SL') => $this->actingAs($this->admin)
        ->post('/admin/identidad', array_filter(['company_name' => $name, 'logo' => $logo]));
});

test('solo el admin entra en /admin/identidad', function (string $actor, int $status) {
    if ($actor !== 'guest') {
        $this->actingAs(userWithRole($actor));
    }

    $response = $this->get('/admin/identidad')->assertStatus($status);

    match ($actor) {
        'guest' => $response->assertRedirect(route('login')),
        'client' => $response->assertRedirect(route('portal.home')),
        default => null,
    };

    $this->post('/admin/identidad', ['company_name' => 'Otra'])->assertStatus($status === 200 ? 302 : $status);
    expect(Setting::get('company_name'))->toBe($status === 200 ? 'Otra' : 'Audax Studio');
})->with([
    'admin' => ['admin', 200],
    'responsable' => ['department_manager', 403],
    'empleado' => ['employee', 403],
    'cliente' => ['client', 302],
    'invitado' => ['guest', 302],
]);

test('la página enseña el nombre, el logo y los límites', function () {
    ($this->upload)(($this->transparentPng)())->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get('/admin/identidad')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/identity')
            ->where('identity.company_name', 'Audax Studio SL')
            ->where('identity.logo.width', 720)
            ->where('identity.logo.height', 240)
            ->where('identity.logo.url', fn (string $url) => str_contains($url, '/marca/logo/'))
            ->where('limits', ['max_kb' => 1024, 'max_side' => 3000]));
});

test('guarda el nombre y un PNG con transparencia, reducido para caber en la caja', function () {
    ($this->upload)(($this->transparentPng)())
        ->assertRedirect(route('admin.identity.edit'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', __('portal.identity.saved'));

    $logo = app(CompanyIdentity::class)->logo();
    expect(Setting::get('company_name'))->toBe('Audax Studio SL')
        ->and($logo)->not->toBeNull()
        ->and($logo['width'])->toBe(720)
        ->and($logo['height'])->toBe(240)
        ->and($logo['path'])->toStartWith('branding/logo-')->toEndWith('.png');

    $stored = Storage::disk(CompanyIdentity::DISK)->get($logo['path']);
    $image = imagecreatefromstring((string) $stored);
    expect((new finfo(FILEINFO_MIME_TYPE))->buffer((string) $stored))->toBe('image/png')
        ->and(imagesx($image))->toBe(720)
        ->and((imagecolorat($image, 0, 0) >> 24) & 0x7F)->toBe(127);

    $this->assertDatabaseHas('activity_log', ['log_name' => 'settings', 'event' => 'identity_updated', 'causer_id' => $this->admin->id]);
});

test('acepta JPG y WebP (se guardan como PNG) y un logo nuevo borra el anterior', function () {
    ($this->upload)(UploadedFile::fake()->image('logo.jpg', 400, 100))->assertSessionHasNoErrors();
    $first = app(CompanyIdentity::class)->logo();

    ($this->upload)(UploadedFile::fake()->image('logo.webp', 300, 300))->assertSessionHasNoErrors();
    $second = app(CompanyIdentity::class)->logo();

    expect($first['width'])->toBe(400)
        ->and($second['width'])->toBe(240)
        ->and($second['height'])->toBe(240)
        ->and($second['path'])->not->toBe($first['path']);
    Storage::disk(CompanyIdentity::DISK)->assertMissing($first['path']);
    Storage::disk(CompanyIdentity::DISK)->assertExists($second['path']);
});

test('rechaza SVG, SVG disfrazado de PNG, ficheros que no son imágenes, más de 1 MB y más de 3.000 px', function (Closure $file, string $message) {
    ($this->upload)($file())->assertSessionHasErrors(['logo' => $message]);

    expect(app(CompanyIdentity::class)->logo())->toBeNull()
        ->and(Storage::disk(CompanyIdentity::DISK)->allFiles())->toBe([])
        ->and(Setting::get('company_name'))->toBe('Audax Studio');
})->with([
    'SVG' => [fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'El logo tiene que ser una imagen PNG, JPG o WebP (SVG no).'],
    'SVG con extensión .png' => [fn () => UploadedFile::fake()->createWithContent('logo.png', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>'), 'El logo tiene que ser una imagen PNG, JPG o WebP (SVG no).'],
    'texto con extensión .jpg' => [fn () => UploadedFile::fake()->createWithContent('logo.jpg', 'no soy una imagen'), 'El logo tiene que ser una imagen PNG, JPG o WebP (SVG no).'],
    'GIF' => [fn () => UploadedFile::fake()->image('logo.gif', 10, 10), 'El logo tiene que ser una imagen PNG, JPG o WebP (SVG no).'],
    'más de 1 MB' => [fn () => UploadedFile::fake()->create('logo.png', 1500, 'image/png'), 'El logo no puede pasar de 1 MB.'],
    'más de 3.000 px' => [fn () => UploadedFile::fake()->image('logo.png', 3200, 100), 'La imagen es demasiado grande: como máximo 3.000 px por lado.'],
]);

test('el nombre es obligatorio y como mucho de 120 caracteres', function () {
    ($this->upload)(null, '')->assertSessionHasErrors('company_name');
    ($this->upload)(null, str_repeat('a', 121))->assertSessionHasErrors('company_name');
    ($this->upload)(null, '  Audax   Studio  ')->assertSessionHasNoErrors();

    expect(Setting::get('company_name'))->toBe('Audax Studio');
});

test('quitar el logo vuelve al logotipo de Audax y borra el fichero', function () {
    ($this->upload)(($this->transparentPng)())->assertSessionHasNoErrors();
    $logo = app(CompanyIdentity::class)->logo();

    $this->actingAs($this->admin)
        ->delete('/admin/identidad/logo')
        ->assertRedirect(route('admin.identity.edit'))
        ->assertInertiaFlash('toast.message', __('portal.identity.logo_removed'));

    // El ajuste desaparece también de la caché (no solo el fichero).
    expect(app(CompanyIdentity::class)->logo())->toBeNull()
        ->and(Setting::get(CompanyIdentity::LOGO_SETTING))->toBeNull();
    Storage::disk(CompanyIdentity::DISK)->assertMissing($logo['path']);

    $this->actingAs(userWithRole('department_manager'))->delete('/admin/identidad/logo')->assertForbidden();
});

test('el logo se sirve en una ruta pública, sin sesión ni cookies, con caché larga para su versión', function () {
    $this->get('/marca/logo/abc123')->assertNotFound();

    ($this->upload)(($this->transparentPng)())->assertSessionHasNoErrors();
    auth()->logout();
    $logo = app(CompanyIdentity::class)->logo();

    $response = $this->get("/marca/logo/{$logo['version']}")->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->streamedContent())->toBe(Storage::disk(CompanyIdentity::DISK)->get($logo['path']));

    // Un email antiguo (otra versión) recibe el logo actual con una caché corta.
    $old = $this->get('/marca/logo/0123456789ab')->assertOk();
    expect($old->headers->get('Cache-Control'))->toContain('max-age=300');
});

test('la cabecera del portal recibe el nombre y el logo de la empresa', function () {
    $client = User::factory()->portalOf(Client::factory()->create())->create();

    $this->actingAs($client)
        ->get('/portal')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/home')
            ->where('portal.company', ['name' => 'Audax Studio', 'logo' => null])
            ->where('portal.projects', []));

    ($this->upload)(($this->transparentPng)(300, 100), 'Estudio Lur')->assertSessionHasNoErrors();
    $logo = app(CompanyIdentity::class)->logo();

    $this->actingAs($client)
        ->get('/portal')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/home')
            ->where('portal.company.name', 'Estudio Lur')
            ->where('portal.company.logo', [
                'url' => route('brand.logo', ['version' => $logo['version']]),
                'width' => 300,
                'height' => 100,
            ]));
});

test('los internos no reciben la prop del portal', function () {
    $this->actingAs($this->admin)->get('/')->assertInertia(fn (Assert $page) => $page->missing('portal'));
});

test('los emails llevan el logo en la cabecera (o el texto si no hay)', function () {
    $user = User::factory()->client()->create(['name' => 'Carmen']);
    $render = fn (): string => (string) (new UserInvitation('token-de-prueba', 'Audax Studio'))->toMail($user)->render();

    expect($render())->not->toContain('/marca/logo/')->toContain(config('app.name'));

    ($this->upload)(($this->transparentPng)(480, 120))->assertSessionHasNoErrors();
    $logo = app(CompanyIdentity::class)->logo();

    expect($render())->toContain(route('brand.logo', ['version' => $logo['version']]))
        ->toContain('width="224"')
        ->toContain('height="56"')
        ->toContain('alt="Audax Studio SL"');
});

test('el PDF lleva el logo de la empresa si lo hay (si no, el logotipo de Audax) y su nombre en la cabecera', function () {
    $render = fn (): string => app(ReportHtml::class)->render(new ReportPdf('reports.pdf.department', 'Prueba', 'prueba', [
        'cover' => ['kicker' => 'Informe', 'title' => 'Prueba', 'subtitle' => '', 'facts' => [], 'note' => null],
        'kpis' => [], 'members' => PdfTable::make([['Persona']], []), 'clients' => PdfTable::make([['Cliente']], []), 'definitions' => [],
    ]));

    expect($render())->toContain('aria-label="Audax Studio"')
        ->not->toContain('data:image/png;base64,')
        ->toContain('@top-right{content:"Audax Studio";}');

    ($this->upload)(($this->transparentPng)(), 'Estudio "Lur"')->assertSessionHasNoErrors();

    expect($render())->toContain('<img class="logo logo--custom" src="data:image/png;base64,')
        ->toContain('alt="Estudio &quot;Lur&quot;"')
        ->toContain('@top-right{content:"Estudio \\"Lur\\"";}')
        ->not->toContain('aria-label="Audax Studio"');
});
