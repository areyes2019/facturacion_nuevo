<?php

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Ver 016-constancia-situacion-fiscal-qr.md.
 *
 * Ninguna prueba sale a internet: todas sirven copias congeladas del HTML del SAT. Una prueba que
 * consultara al SAT real fallaría cuando el SAT esté caído, cuando no haya conexión o cuando el
 * contribuyente de prueba cambie sus datos, todo ello con el código en perfecto estado.
 */
const URL_QR_SAT = 'https://siat.sat.gob.mx/app/qr/faces/pages/mobile/valida-credencial.jsf?id=ABC123';

function fixtureConstancia(string $archivo): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/constancias/'.$archivo));
}

function pdfConstancia(string $archivo): UploadedFile
{
    return new UploadedFile(
        base_path('tests/Fixtures/constancias/'.$archivo),
        $archivo,
        'application/pdf',
        null,
        true,
    );
}

function satRespondeCon(string $fixture): void
{
    Http::fake(['*' => Http::response(fixtureConstancia($fixture))]);
}

function satCaido(): void
{
    Http::fake(['*' => fn () => throw new ConnectionException('Connection timed out')]);
}

test('un invitado no puede analizar una constancia', function () {
    $this->postJson('/api/v1/clientes/constancia', ['qr_url' => URL_QR_SAT])
        ->assertUnauthorized();
});

test('una peticion sin qr, sin imagen y sin pdf se rechaza', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/clientes/constancia', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('qr_url');
});

test('una constancia de persona moral devuelve los datos oficiales del SAT', function () {
    satRespondeCon('sat-moral.html');

    $response = $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/clientes/constancia', ['qr_url' => URL_QR_SAT]);

    $response->assertOk();
    $response->assertJsonPath('fuente', 'SAT_QR_DIRECT');
    $response->assertJsonPath('confianza', 'oficial');
    $response->assertJsonPath('advertencias', []);
    $response->assertJsonPath('data.rfc', 'PME120315AB9');
    $response->assertJsonPath('data.razon_social', 'PANDA CONNECT LOGISTICS SA DE CV');
    $response->assertJsonPath('data.regimen_fiscal', '601');
    $response->assertJsonPath('data.codigo_postal_fiscal', '38000');
    $response->assertJsonPath('data.direccion_comercial', 'AV TECNOLOGICO 105, COL INDUSTRIAL, CELAYA, GUANAJUATO');
    $response->assertJsonPath('cliente_existente', null);
});

test('una constancia de persona fisica une el nombre con sus apellidos', function () {
    satRespondeCon('sat-fisica.html');

    $response = $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/clientes/constancia', ['qr_url' => URL_QR_SAT]);

    $response->assertOk();
    $response->assertJsonPath('data.rfc', 'GOMR850712QX1');
    $response->assertJsonPath('data.razon_social', 'ROSA MARIA GOMEZ MARTINEZ');
    $response->assertJsonPath('data.direccion_comercial', 'CALLE ORIZABA 87 INT 3, COL ROMA NORTE, CUAUHTEMOC, CIUDAD DE MEXICO');
});

test('un contribuyente con varios regimenes vigentes los devuelve todos y avisa', function () {
    satRespondeCon('sat-fisica.html');

    $response = $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/clientes/constancia', ['qr_url' => URL_QR_SAT]);

    $response->assertOk();
    // Se propone el primero, pero los dos viajan para que el usuario pueda elegir.
    $response->assertJsonPath('data.regimen_fiscal', '612');
    $response->assertJsonPath('data.regimenes_disponibles.0.id', '612');
    $response->assertJsonPath('data.regimenes_disponibles.1.id', '626');
    expect($response->json('advertencias.0'))->toContain('más de un régimen fiscal vigente');
});

test('un codigo postal fuera del catalogo del SAT llega vacio y con advertencia', function () {
    satRespondeCon('sat-cp-desconocido.html');

    $response = $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/clientes/constancia', ['qr_url' => URL_QR_SAT]);

    $response->assertOk();
    // Precargarlo solo conseguiría que el guardado fallara con un error inexplicable.
    $response->assertJsonPath('data.codigo_postal_fiscal', null);
    expect($response->json('advertencias.0'))->toContain('00000');
});

test('la pagina del validador se lee aunque venga con cabecera de xml delante', function () {
    // Con una cabecera de XML delante, un lector que adivine el formato toma la página por XML,
    // exige espacios de nombres y no encuentra ni una fila: no falla, devuelve cero. El RFC,
    // además, no es un par etiqueta/valor sino parte de una frase.
    satRespondeCon('sat-validador-fisica.html');

    $response = $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/clientes/constancia', ['qr_url' => URL_QR_SAT]);

    $response->assertOk();
    $response->assertJsonPath('fuente', 'SAT_QR_DIRECT');
    $response->assertJsonPath('data.rfc', 'GOMR850712QX1');
    $response->assertJsonPath('data.razon_social', 'ROSA MARIA GOMEZ MARTINEZ');
    $response->assertJsonPath('data.direccion_comercial', 'ORIZABA 87 INT 3, COL ROMA NORTE, CUAUHTEMOC, CIUDAD DE MEXICO');
});

test('el codigo postal se reconoce con la etiqueta de dos letras del validador', function () {
    satRespondeCon('sat-validador-fisica.html');

    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/clientes/constancia', ['qr_url' => URL_QR_SAT])
        ->assertOk()
        ->assertJsonPath('data.codigo_postal_fiscal', '06700');
});

test('los regimenes del validador se resuelven por su descripcion', function () {
    // Ni el validador ni la constancia impresa publican el código: los dos escriben la descripción,
    // con un "Régimen de las…" delante que el catálogo no lleva.
    satRespondeCon('sat-validador-fisica.html');

    $response = $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/clientes/constancia', ['qr_url' => URL_QR_SAT]);

    $response->assertOk();
    $response->assertJsonPath('data.regimen_fiscal', '612');
    $response->assertJsonPath('data.regimenes_disponibles.1.id', '605');
});

test('una respuesta a la que le falta un campo no marca al SAT como caido', function () {
    satRespondeCon('sat-parcial.html');
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/clientes/constancia', ['qr_url' => URL_QR_SAT])
        ->assertOk()
        ->assertJsonPath('data.razon_social', 'ROSA MARIA GOMEZ MARTINEZ')
        ->assertJsonPath('data.codigo_postal_fiscal', null);

    // La constancia siguiente vuelve a salir a preguntar: un fallo de lectura no es una caída, y
    // confundirlos apagaría la consulta oficial para siempre en ventanas de dos minutos.
    $this->actingAs($user)->postJson('/api/v1/clientes/constancia', ['qr_url' => URL_QR_SAT.'&otro=1'])
        ->assertOk();

    Http::assertSentCount(2);
});

test('el servidor lee el codigo qr del pdf sin que el navegador lo haya leido', function () {
    satRespondeCon('sat-moral.html');

    // Va solo el PDF: ni `qr_url` ni imagen. Es el caso de Firefox y Safari, que no traen lector de
    // códigos, y el de cualquier navegador que no logre leerlo.
    $response = $this->actingAs(User::factory()->create())
        ->post('/api/v1/clientes/constancia', [
            'pdf' => pdfConstancia('csf-con-qr.pdf'),
        ], ['Accept' => 'application/json']);

    $response->assertOk();
    $response->assertJsonPath('fuente', 'SAT_QR_DIRECT');
    $response->assertJsonPath('data.rfc', 'PME120315AB9');
});

test('de los dos codigos qr de la constancia se elige el del validador', function () {
    satRespondeCon('sat-moral.html');

    $this->actingAs(User::factory()->create())
        ->post('/api/v1/clientes/constancia', [
            'pdf' => pdfConstancia('csf-con-qr.pdf'),
        ], ['Accept' => 'application/json'])
        ->assertOk();

    // El del sello digital va primero en el documento, así que quedarse con "el primero que se pueda
    // leer" habría consultado una dirección que no identifica a nadie.
    Http::assertSent(fn ($peticion) => str_contains($peticion->url(), 'D3=16040688444_PME120315AB9'));
});

test('el rfc del codigo qr manda sobre el impreso y la diferencia se avisa', function () {
    satCaido();

    $response = $this->actingAs(User::factory()->create())
        ->post('/api/v1/clientes/constancia', [
            // El documento dice PME120315AB9; el código QR, otro contribuyente.
            'qr_url' => 'https://siat.sat.gob.mx/app/qr/faces/pages/mobile/validadorqr.jsf?D1=10&D2=1&D3=16040688444_GOMR850712QX1',
            'pdf' => pdfConstancia('csf-moral.pdf'),
        ], ['Accept' => 'application/json']);

    $response->assertOk();
    // El del QR viene codificado para que lo lea una máquina: no puede traer una letra confundida.
    $response->assertJsonPath('data.rfc', 'GOMR850712QX1');
    expect($response->json('advertencias.0'))->toContain('no coincide con el del código QR');
});

test('un qr que apunta fuera del dominio del SAT se rechaza sin consultarlo', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/clientes/constancia', ['qr_url' => 'https://sat.gob.mx.evil.test/qr?id=1'])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'QR_NO_OFICIAL');

    // No basta con devolver el error: hay que probar que el servidor no fue a esa dirección.
    Http::assertNothingSent();
});

test('un qr del SAT servido por http sin cifrar se rechaza', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/clientes/constancia', ['qr_url' => 'http://siat.sat.gob.mx/app/qr?id=1'])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'QR_NO_OFICIAL');

    Http::assertNothingSent();
});

test('si el SAT no responde se pide al frontend repetir con los archivos', function () {
    satCaido();

    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/clientes/constancia', ['qr_url' => URL_QR_SAT])
        ->assertStatus(503)
        ->assertJsonPath('error', 'SAT_NO_DISPONIBLE')
        ->assertJsonPath('estrategia_b', true);
});

test('una pantalla de mantenimiento del SAT se trata igual que una caida', function () {
    satRespondeCon('sat-mantenimiento.html');

    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/clientes/constancia', ['qr_url' => URL_QR_SAT])
        ->assertStatus(503)
        ->assertJsonPath('error', 'SAT_NO_DISPONIBLE');
});

test('durante una caida del SAT las constancias siguientes no vuelven a esperar', function () {
    $intentos = 0;

    // El SAT falla la primera vez y respondería bien la segunda. Si el circuito no existiera, la
    // segunda constancia devolvería 200: que devuelva 503 prueba que ni siquiera salió a intentarlo.
    Http::fake(function () use (&$intentos) {
        $intentos++;

        if ($intentos === 1) {
            throw new ConnectionException('Connection timed out');
        }

        return Http::response(fixtureConstancia('sat-moral.html'));
    });

    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/clientes/constancia', ['qr_url' => URL_QR_SAT])
        ->assertStatus(503);
    $this->actingAs($user)->postJson('/api/v1/clientes/constancia', ['qr_url' => URL_QR_SAT.'&otro=1'])
        ->assertStatus(503);

    expect($intentos)->toBe(1);
});

test('subir dos veces la misma constancia consulta al SAT una sola vez', function () {
    satRespondeCon('sat-moral.html');
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/clientes/constancia', ['qr_url' => URL_QR_SAT])->assertOk();
    $this->actingAs($user)->postJson('/api/v1/clientes/constancia', ['qr_url' => URL_QR_SAT])->assertOk();

    Http::assertSentCount(1);
});

test('con el SAT caido los datos se extraen del texto del pdf', function () {
    satCaido();

    $response = $this->actingAs(User::factory()->create())
        ->post('/api/v1/clientes/constancia', [
            'qr_url' => URL_QR_SAT,
            'pdf' => pdfConstancia('csf-moral.pdf'),
        ], ['Accept' => 'application/json']);

    $response->assertOk();
    $response->assertJsonPath('fuente', 'PDF_TEXTO');
    $response->assertJsonPath('confianza', 'documento');
    $response->assertJsonPath('data.rfc', 'PME120315AB9');
    $response->assertJsonPath('data.razon_social', 'PANDA CONNECT LOGISTICS SA DE CV');
    $response->assertJsonPath('data.regimen_fiscal', '601');
    $response->assertJsonPath('data.codigo_postal_fiscal', '38000');
    $response->assertJsonPath('data.direccion_comercial', 'AV TECNOLOGICO 105, COL INDUSTRIAL, CELAYA, GUANAJUATO');
});

test('un pdf escaneado sin texto deja el reconocimiento al navegador', function () {
    satCaido();

    $response = $this->actingAs(User::factory()->create())
        ->post('/api/v1/clientes/constancia', [
            'qr_url' => URL_QR_SAT,
            'pdf' => pdfConstancia('csf-escaneada.pdf'),
            'imagen' => UploadedFile::fake()->image('constancia.png', 600, 800),
        ], ['Accept' => 'application/json']);

    $response->assertUnprocessable();
    $response->assertJsonPath('error', 'PDF_SIN_TEXTO');
    $response->assertJsonPath('puede_ocr', true);
});

test('una imagen sin qr legible y sin pdf deja el reconocimiento al navegador', function () {
    Http::fake();

    $response = $this->actingAs(User::factory()->create())
        ->post('/api/v1/clientes/constancia', [
            'imagen' => UploadedFile::fake()->image('constancia.png', 600, 800),
        ], ['Accept' => 'application/json']);

    $response->assertUnprocessable();
    $response->assertJsonPath('error', 'QR_NO_LEGIBLE');
    $response->assertJsonPath('puede_ocr', true);
    Http::assertNothingSent();
});

test('un rfc ya registrado por el usuario se informa para no duplicarlo', function () {
    satRespondeCon('sat-moral.html');
    $user = User::factory()->create();
    $cliente = Cliente::factory()->for($user)->create([
        'rfc' => 'PME120315AB9',
        'razon_social' => 'PANDA CONNECT LOGISTICS SA DE CV',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/clientes/constancia', ['qr_url' => URL_QR_SAT]);

    $response->assertOk();
    $response->assertJsonPath('cliente_existente.id', $cliente->id);
    $response->assertJsonPath('cliente_existente.razon_social', 'PANDA CONNECT LOGISTICS SA DE CV');
});

test('el mismo rfc registrado por otro usuario no dispara el aviso de duplicado', function () {
    satRespondeCon('sat-moral.html');
    Cliente::factory()->for(User::factory()->create())->create(['rfc' => 'PME120315AB9']);

    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/clientes/constancia', ['qr_url' => URL_QR_SAT])
        ->assertOk()
        ->assertJsonPath('cliente_existente', null);
});

test('un archivo de mas de 10 MB se rechaza', function () {
    $this->actingAs(User::factory()->create())
        ->post('/api/v1/clientes/constancia', [
            'pdf' => UploadedFile::fake()->create('constancia.pdf', 11000, 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pdf');
});

test('mas de diez constancias por minuto se frenan', function () {
    $user = User::factory()->create();

    // El límite protege la IP del servidor completo: el bloqueo que aplicaría el SAT por exceso de
    // consultas dejaría la función inservible para todos, no solo para quien la disparó.
    foreach (range(1, 10) as $ignored) {
        $this->actingAs($user)->postJson('/api/v1/clientes/constancia', [])->assertUnprocessable();
    }

    $this->actingAs($user)->postJson('/api/v1/clientes/constancia', [])->assertStatus(429);
});

test('procesar una constancia no deja archivos en el servidor', function () {
    Storage::fake('local');
    satCaido();

    $this->actingAs(User::factory()->create())
        ->post('/api/v1/clientes/constancia', [
            'pdf' => pdfConstancia('csf-moral.pdf'),
            'imagen' => UploadedFile::fake()->image('constancia.png', 600, 800),
        ], ['Accept' => 'application/json'])
        ->assertOk();

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});
