<?php

use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Imágenes de artículo (ver 020-imagenes-articulos.md).
 */

/** Arma un ZIP real en un temporal y lo devuelve listo para subir. */
function zipConArchivos(array $entradas): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'zip').'.zip';

    $zip = new ZipArchive;
    $zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($entradas as $nombre => $contenido) {
        $zip->addFromString($nombre, $contenido);
    }

    $zip->close();

    return new UploadedFile($ruta, 'fotos.zip', 'application/zip', null, true);
}

/** Contenido binario de un JPEG real de las medidas pedidas. */
function bytesDeImagen(int $ancho = 400, int $alto = 300): string
{
    $imagen = imagecreatetruecolor($ancho, $alto);

    ob_start();
    imagejpeg($imagen);
    imagedestroy($imagen);

    return (string) ob_get_clean();
}

test('un invitado no puede ver la imagen de un articulo', function () {
    $articulo = Articulo::factory()->create();

    $this->getJson("/api/v1/articulos/{$articulo->id}/imagen")->assertUnauthorized();
});

test('subir una imagen la guarda reducida y como webp', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $articulo = Articulo::factory()->for($user)->create();

    $response = $this->actingAs($user)->post("/api/v1/articulos/{$articulo->id}/imagen", [
        'archivo' => UploadedFile::fake()->image('foto.jpg', 4000, 3000),
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.tiene_imagen', true);

    $ruta = $articulo->fresh()->imagen_ruta;
    expect($ruta)->toEndWith('.webp');

    Storage::disk('local')->assertExists($ruta);

    // El lado largo queda en 1200 y la proporción se conserva: 4000x3000 -> 1200x900.
    $medidas = getimagesizefromstring(Storage::disk('local')->get($ruta));
    expect($medidas[0])->toBe(1200);
    expect($medidas[1])->toBe(900);
    expect($medidas[2])->toBe(IMAGETYPE_WEBP);
});

test('una imagen mas chica que el maximo no se amplia', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $articulo = Articulo::factory()->for($user)->create();

    $this->actingAs($user)->post("/api/v1/articulos/{$articulo->id}/imagen", [
        'archivo' => UploadedFile::fake()->image('foto.jpg', 300, 200),
    ])->assertOk();

    $medidas = getimagesizefromstring(Storage::disk('local')->get($articulo->fresh()->imagen_ruta));
    expect($medidas[0])->toBe(300);
    expect($medidas[1])->toBe(200);
});

test('la imagen se sirve como webp al usuario dueño', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $articulo = Articulo::factory()->for($user)->create();

    $this->actingAs($user)->post("/api/v1/articulos/{$articulo->id}/imagen", [
        'archivo' => UploadedFile::fake()->image('foto.jpg', 500, 500),
    ])->assertOk();

    $response = $this->actingAs($user)->get("/api/v1/articulos/{$articulo->id}/imagen");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/webp');
});

test('un articulo sin imagen responde 404 al pedirla', function () {
    $user = User::factory()->create();
    $articulo = Articulo::factory()->for($user)->create();

    $this->actingAs($user)->get("/api/v1/articulos/{$articulo->id}/imagen")->assertNotFound();
});

test('no se puede tocar la imagen de un articulo ajeno', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $ajeno = Articulo::factory()->create();

    $this->actingAs($user)->get("/api/v1/articulos/{$ajeno->id}/imagen")->assertNotFound();
    $this->actingAs($user)->post("/api/v1/articulos/{$ajeno->id}/imagen", [
        'archivo' => UploadedFile::fake()->image('foto.jpg'),
    ])->assertNotFound();
    $this->actingAs($user)->deleteJson("/api/v1/articulos/{$ajeno->id}/imagen")->assertNotFound();
});

test('reemplazar una imagen borra el archivo anterior y cambia la version', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $articulo = Articulo::factory()->for($user)->create();

    $this->actingAs($user)->post("/api/v1/articulos/{$articulo->id}/imagen", [
        'archivo' => UploadedFile::fake()->image('primera.jpg', 500, 500),
    ])->assertOk();

    $primera = $articulo->fresh()->imagen_ruta;

    $response = $this->actingAs($user)->post("/api/v1/articulos/{$articulo->id}/imagen", [
        'archivo' => UploadedFile::fake()->image('segunda.jpg', 500, 500),
    ]);

    $segunda = $articulo->fresh()->imagen_ruta;

    expect($segunda)->not->toBe($primera);
    // El nombre nuevo es lo que hace que el navegador no siga mostrando la foto vieja.
    $response->assertJsonPath('data.imagen_version', pathinfo($segunda, PATHINFO_FILENAME));

    Storage::disk('local')->assertMissing($primera);
    Storage::disk('local')->assertExists($segunda);
});

test('eliminar la imagen la quita del articulo y del disco', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $articulo = Articulo::factory()->for($user)->create();

    $this->actingAs($user)->post("/api/v1/articulos/{$articulo->id}/imagen", [
        'archivo' => UploadedFile::fake()->image('foto.jpg', 500, 500),
    ])->assertOk();

    $ruta = $articulo->fresh()->imagen_ruta;

    $this->actingAs($user)->deleteJson("/api/v1/articulos/{$articulo->id}/imagen")
        ->assertOk()
        ->assertJson(['eliminado' => true]);

    expect($articulo->fresh()->imagen_ruta)->toBeNull();
    Storage::disk('local')->assertMissing($ruta);
});

test('eliminar el articulo conserva su archivo de imagen', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $articulo = Articulo::factory()->for($user)->create();

    $this->actingAs($user)->post("/api/v1/articulos/{$articulo->id}/imagen", [
        'archivo' => UploadedFile::fake()->image('foto.jpg', 500, 500),
    ])->assertOk();

    $ruta = $articulo->fresh()->imagen_ruta;

    $this->actingAs($user)->deleteJson("/api/v1/articulos/{$articulo->id}")->assertNoContent();

    // El borrado es lógico: el archivo se queda por si el artículo se restaura.
    Storage::disk('local')->assertExists($ruta);
});

test('un archivo que no es imagen se rechaza en la subida individual', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $articulo = Articulo::factory()->for($user)->create();

    $this->actingAs($user)->postJson("/api/v1/articulos/{$articulo->id}/imagen", [
        'archivo' => UploadedFile::fake()->create('documento.pdf', 10, 'application/pdf'),
    ])->assertStatus(422);

    expect($articulo->fresh()->imagen_ruta)->toBeNull();
});

test('la carga masiva empareja por modelo ignorando mayusculas acentos y separadores', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $conGuion = Articulo::factory()->for($user)->for($catalogo)->create(['modelo' => 'A-1234']);
    $conAcento = Articulo::factory()->for($user)->for($catalogo)->create(['modelo' => 'Sellò Redondo']);

    $response = $this->actingAs($user)->post("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/imagenes", [
        'archivos' => [
            UploadedFile::fake()->image('a 1234.JPG', 500, 500),
            UploadedFile::fake()->image('sello_redondo.png', 500, 500),
        ],
    ]);

    $response->assertOk();
    $response->assertJsonPath('asociadas', 2);
    $response->assertJsonPath('errores', []);

    expect($conGuion->fresh()->imagen_ruta)->not->toBeNull();
    expect($conAcento->fresh()->imagen_ruta)->not->toBeNull();
});

test('un archivo sin articulo se reporta y no crea nada', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();
    Articulo::factory()->for($user)->for($catalogo)->create(['modelo' => 'A-1']);

    $response = $this->actingAs($user)->post("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/imagenes", [
        'archivos' => [UploadedFile::fake()->image('Z-999.jpg', 500, 500)],
    ]);

    $response->assertOk();
    $response->assertJsonPath('asociadas', 0);
    $response->assertJsonPath('errores.0.archivo', 'Z-999.jpg');
    expect($response->json('errores.0.motivo'))->toContain('Z-999');
    expect(Articulo::count())->toBe(1);
});

test('un modelo repetido en el catalogo no se asigna a ninguno y se reporta', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $uno = Articulo::factory()->for($user)->for($catalogo)->create(['modelo' => 'DUP-1']);
    $otro = Articulo::factory()->for($user)->for($catalogo)->create(['modelo' => 'dup 1']);

    $response = $this->actingAs($user)->post("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/imagenes", [
        'archivos' => [UploadedFile::fake()->image('DUP-1.jpg', 500, 500)],
    ]);

    $response->assertOk();
    $response->assertJsonPath('asociadas', 0);
    expect($response->json('errores.0.motivo'))->toContain('2 artículos');
    expect($uno->fresh()->imagen_ruta)->toBeNull();
    expect($otro->fresh()->imagen_ruta)->toBeNull();
});

test('un archivo que no es imagen se reporta sin tumbar la tanda', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $bueno = Articulo::factory()->for($user)->for($catalogo)->create(['modelo' => 'OK-1']);
    Articulo::factory()->for($user)->for($catalogo)->create(['modelo' => 'MALO-1']);

    $response = $this->actingAs($user)->post("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/imagenes", [
        'archivos' => [
            UploadedFile::fake()->image('OK-1.jpg', 500, 500),
            // Terminación de imagen, contenido que no lo es: el formato se comprueba por contenido.
            UploadedFile::fake()->createWithContent('MALO-1.jpg', 'esto no es una imagen'),
        ],
    ]);

    $response->assertOk();
    $response->assertJsonPath('asociadas', 1);
    $response->assertJsonPath('errores.0.archivo', 'MALO-1.jpg');
    expect($bueno->fresh()->imagen_ruta)->not->toBeNull();
});

test('dos archivos para el mismo articulo dejan el ultimo y reportan el desplazado', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create(['modelo' => 'A-1']);

    $response = $this->actingAs($user)->post("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/imagenes", [
        'archivos' => [
            UploadedFile::fake()->image('A-1.jpg', 500, 500),
            UploadedFile::fake()->image('a_1.png', 500, 500),
        ],
    ]);

    $response->assertOk();
    // Una sola foto quedó asociada, aunque llegaron dos archivos que apuntaban al mismo artículo.
    $response->assertJsonPath('asociadas', 1);
    $response->assertJsonPath('errores.0.archivo', 'A-1.jpg');
    expect($articulo->fresh()->imagen_ruta)->not->toBeNull();
});

test('una tanda con mas de 20 archivos se rechaza en vez de perderlos en silencio', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $archivos = [];
    for ($i = 0; $i < 21; $i++) {
        $archivos[] = UploadedFile::fake()->image("F-{$i}.jpg", 50, 50);
    }

    $this->actingAs($user)
        ->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/imagenes", ['archivos' => $archivos])
        ->assertStatus(422);
});

test('la carga masiva exige imagenes o zip pero no las dos cosas', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/imagenes", [])
        ->assertStatus(422);
});

test('un zip plano se procesa igual que la seleccion multiple', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create(['modelo' => 'A-1234']);

    $zip = zipConArchivos(['A-1234.jpg' => bytesDeImagen()]);

    $response = $this->actingAs($user)->post(
        "/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/imagenes",
        ['archivo' => $zip],
    );

    $response->assertOk();
    $response->assertJsonPath('asociadas', 1);
    expect($articulo->fresh()->imagen_ruta)->not->toBeNull();
});

test('un zip con carpetas se rechaza entero sin guardar ninguna imagen', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create(['modelo' => 'A-1234']);

    $zip = zipConArchivos(['fotos/A-1234.jpg' => bytesDeImagen()]);

    $this->actingAs($user)->postJson(
        "/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/imagenes",
        ['archivo' => $zip],
    )->assertStatus(422);

    expect($articulo->fresh()->imagen_ruta)->toBeNull();
});

test('no se puede cargar imagenes contra un catalogo ajeno', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $ajeno = Catalogo::factory()->create();

    $this->actingAs($user)->post("/api/v1/catalogos-proveedor/{$ajeno->id}/articulos/imagenes", [
        'archivos' => [UploadedFile::fake()->image('A-1.jpg', 50, 50)],
    ])->assertNotFound();
});
