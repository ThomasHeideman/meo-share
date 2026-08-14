<?php

declare(strict_types=1);

namespace MeoDrive\Tests\Integration;

use CURLFile;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Eén end-to-end test voor de volledige kernflow: upload -> encryptie ->
 * verificatie (incl. verkeerde code + "opnieuw versturen") -> download-and-
 * destroy. Draait tegen een losse database (meo_share_test, zie
 * TEST_DB_NAME) en een eigen PHP-testserver-instantie, zodat deze test de
 * echte public_html-scripts via echte HTTP-requests doorloopt in plaats van
 * losse stukjes logica na te bouwen.
 *
 * Vereist: een bereikbare MySQL/MariaDB-server met dezelfde inloggegevens
 * als private/credentials.php (alleen de databasenaam wordt overschreven
 * via de omgevingsvariabele MEODRIVE_DB_NAME, zie private/config.php).
 * Het versturen van de verificatie-/downloadmails gaat via de echte
 * Mailtrap-SMTP-configuratie; als die niet bereikbaar is, wordt de test
 * overgeslagen in plaats van rood te slaan (dat zegt dan iets over de
 * lokale mailconfiguratie, niet over de applicatielogica).
 */
final class CoreFlowTest extends TestCase
{
    private const TEST_DB_NAME = 'meo_share_test';
    private const SERVER_HOST = '127.0.0.1';
    private const SERVER_PORT = 8199;

    /** @var resource|null */
    private static $serverProcess;

    /** @var array<int, resource> */
    private static array $serverPipes = [];

    private static PDO $rootPdo;

    private string $cookieJar;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$rootPdo = self::createRootConnection();
        } catch (\Throwable $e) {
            self::markTestSkipped('Geen databaseverbinding beschikbaar voor de integratietest: ' . $e->getMessage());
        }

        self::setUpTestDatabase();
        self::startTestServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::stopTestServer();
    }

    protected function setUp(): void
    {
        $this->cookieJar = sys_get_temp_dir() . '/meodrive_test_cookies_' . bin2hex(random_bytes(6)) . '.txt';
    }

    protected function tearDown(): void
    {
        if (is_file($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    public function testFullUploadVerifyDownloadFlow(): void
    {
        // Stap 1: uploaden (met een geldige CSRF-token, zoals een echte
        // browser die zou meesturen).
        $csrfToken = $this->fetchCsrfToken('/index.php');

        $tmpFile = sys_get_temp_dir() . '/meodrive_core_flow_' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($tmpFile, 'Inhoud van de kernflow-integratietest.');

        $uploadResponse = $this->post('/upload.php', [
            'user_role'     => 'client',
            'csrf_token'    => $csrfToken,
            'sender_email'  => 'integratietest@example.com',
            'file[]'        => new CURLFile($tmpFile, 'text/plain', 'kernflow-test.txt'),
        ]);

        unlink($tmpFile);

        self::assertSame(200, $uploadResponse['status'], 'Upload zou moeten slagen: ' . $uploadResponse['body']);
        self::assertStringContainsString('Upload geslaagd', $uploadResponse['body']);

        $uploadId = $this->extractQueryParam($uploadResponse['body'], 'verify.php', 'id');
        self::assertNotNull($uploadId, 'Kon geen upload-ID uit de responspagina halen.');

        $row = $this->fetchUploadRow($uploadId);
        self::assertNotFalse($row, 'De upload moet in de database staan na een succesvolle upload.');
        self::assertSame(0, (int) $row['is_verified']);
        self::assertNotEmpty($row['encryption_key'], 'De encryptiesleutel moet direct na upload nog in de database staan.');
        $encryptionKey = $row['encryption_key'];

        $storedFilePath = dirname(__DIR__, 2) . '/private/' . basename($row['file_path']);
        self::assertFileExists($storedFilePath, 'Het versleutelde bestand moet op schijf staan.');
        self::assertNotSame(
            'Inhoud van de kernflow-integratietest.',
            file_get_contents($storedFilePath),
            'Het opgeslagen bestand mag niet in plaintext op schijf staan (AES-256-encryptie).'
        );

        // Stap 2: een onjuiste verificatiecode geeft een duidelijke
        // foutmelding en verhoogt de teller, maar verifieert niet.
        $verifyCsrfToken = $this->fetchCsrfToken('/verify.php?id=' . urlencode($uploadId));

        $wrongCodeResponse = $this->post('/verify.php?id=' . urlencode($uploadId), [
            'csrf_token'        => $verifyCsrfToken,
            'verification_code' => '000000',
        ]);
        self::assertStringContainsString('onjuist', $wrongCodeResponse['body']);

        $row = $this->fetchUploadRow($uploadId);
        self::assertSame(1, (int) $row['verification_attempts'], 'Na één foute poging moet de teller op 1 staan.');

        // Stap 3: "code opnieuw versturen" (met cooldown-rate-limiter). Een
        // korte pauze voordat we de mailserver opnieuw aanspreken: net als
        // een echte gebruiker die niet binnen milliseconden na de eerste
        // mail al op "opnieuw versturen" klikt, en het voorkomt dat we de
        // sandbox-SMTP-server met snelle, opeenvolgende verbindingen laten
        // struikelen over een eigen rate limit.
        usleep(3_000_000);

        $resendResponse = $this->post('/verify.php?id=' . urlencode($uploadId), [
            'csrf_token' => $verifyCsrfToken,
            'action'     => 'resend_code',
        ]);

        if (str_contains($resendResponse['body'], 'Mailtrap') || str_contains($resendResponse['body'], 'mislukt: ')) {
            self::markTestSkipped(
                'Het opnieuw versturen van de code kon niet worden gemaild (geen bereikbare SMTP-server in deze omgeving). '
                . 'Dit zegt iets over de lokale mailconfiguratie, niet over de kernlogica.'
            );
        }
        self::assertStringContainsString('opnieuw verzonden', $resendResponse['body']);

        // Stap 4: de juiste verificatiecode rondt de verificatie af en mailt
        // de downloadlink naar de ontvanger (bij de rol "client" is dat het
        // vaste MEO-adres).
        $verificationCode = $row['verification_code'];

        usleep(3_000_000);

        $correctCodeResponse = $this->post('/verify.php?id=' . urlencode($uploadId), [
            'csrf_token'        => $verifyCsrfToken,
            'verification_code' => $verificationCode,
        ]);

        if (str_contains($correctCodeResponse['body'], 'e-mail mislukt') || str_contains($correctCodeResponse['body'], 'Verzenden mislukt')) {
            self::markTestSkipped(
                'De downloadlink kon niet worden gemaild (geen bereikbare SMTP-server in deze omgeving). '
                . 'Dit zegt iets over de lokale mailconfiguratie, niet over de kernlogica.'
            );
        }

        self::assertStringContainsString('Verificatie geslaagd', $correctCodeResponse['body']);

        $row = $this->fetchUploadRow($uploadId);
        self::assertSame(1, (int) $row['is_verified'], 'De upload moet na de juiste code als geverifieerd staan.');
        self::assertNull($row['encryption_key'], 'De encryptiesleutel moet uit de database zijn gewist na verzending.');

        // Stap 5: de ontvanger bekijkt de landingspagina (receive.php) zonder
        // dat dit het bestand al verwijdert.
        $receiveResponse = $this->get(
            '/receive.php?id=' . urlencode($uploadId) . '&key=' . urlencode($encryptionKey)
        );
        self::assertSame(200, $receiveResponse['status']);
        self::assertStringContainsString('Beveiligd bestand ontvangen', $receiveResponse['body']);
        self::assertFileExists($storedFilePath, 'receive.php mag het bestand nog niet verwijderen.');

        // Stap 6: downloaden ontsleutelt het bestand correct en voert direct
        // daarna "download-and-destroy" uit (bestand + databaserij weg).
        $downloadResponse = $this->get(
            '/download.php?id=' . urlencode($uploadId) . '&key=' . urlencode($encryptionKey)
        );
        self::assertSame(200, $downloadResponse['status']);
        self::assertSame('Inhoud van de kernflow-integratietest.', $downloadResponse['body']);

        self::assertFileDoesNotExist($storedFilePath, 'Het bestand moet na één download definitief verwijderd zijn.');
        self::assertFalse($this->fetchUploadRow($uploadId), 'De databaserij moet na de download verwijderd zijn.');

        // Stap 7: een tweede downloadpoging met dezelfde link moet nu falen,
        // want de link mag maar één keer werken.
        $secondDownloadResponse = $this->get(
            '/download.php?id=' . urlencode($uploadId) . '&key=' . urlencode($encryptionKey)
        );
        self::assertNotSame(200, $secondDownloadResponse['status']);
    }

    // -------------------------------------------------------------------
    // HTTP-helpers
    // -------------------------------------------------------------------

    /**
     * @param array<string, mixed> $fields
     * @return array{status: int, body: string}
     */
    private function post(string $path, array $fields): array
    {
        return $this->request('POST', $path, $fields);
    }

    /**
     * @return array{status: int, body: string}
     */
    private function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $path, array $fields = []): array
    {
        $url = 'http://' . self::SERVER_HOST . ':' . self::SERVER_PORT . $path;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieJar);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL-verzoek naar {$path} mislukt: {$error}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $body];
    }

    private function fetchCsrfToken(string $path): string
    {
        $response = $this->get($path);

        if (!preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $response['body'], $matches)) {
            self::fail("Geen CSRF-token gevonden op {$path}.");
        }

        return $matches[1];
    }

    private function extractQueryParam(string $html, string $scriptName, string $param): ?string
    {
        $pattern = '/' . preg_quote($scriptName, '/') . '\?[^"\']*' . preg_quote($param, '/') . '=([^"\'&]+)/';
        if (!preg_match($pattern, $html, $matches)) {
            return null;
        }

        return urldecode($matches[1]);
    }

    // -------------------------------------------------------------------
    // Database-helpers
    // -------------------------------------------------------------------

    /**
     * @return array<string, mixed>|false
     */
    private function fetchUploadRow(string $uploadId)
    {
        $stmt = self::$rootPdo->prepare('SELECT * FROM ' . self::TEST_DB_NAME . '.uploads WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $uploadId]);

        return $stmt->fetch();
    }

    private static function createRootConnection(): PDO
    {
        $credentials = require dirname(__DIR__, 2) . '/private/credentials.php';

        return new PDO(
            "mysql:host={$credentials['host']};charset=utf8mb4",
            $credentials['user'],
            $credentials['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private static function setUpTestDatabase(): void
    {
        $schemaPath = dirname(__DIR__, 2) . '/schema.sql';
        $schemaSql = file_get_contents($schemaPath);
        if ($schemaSql === false) {
            self::fail('Kon schema.sql niet lezen.');
        }

        // schema.sql definieert de database "meo_share"; voor deze test
        // hergebruiken we exact dezelfde structuur onder een andere naam,
        // zodat het echte ontwikkel-/productieschema de enige bron van
        // waarheid blijft (geen los gedupliceerd testschema om te
        // onderhouden).
        $testSchemaSql = str_replace('meo_share', self::TEST_DB_NAME, $schemaSql);

        self::$rootPdo->exec('DROP DATABASE IF EXISTS ' . self::TEST_DB_NAME);
        self::runSqlScript(self::$rootPdo, $testSchemaSql);
    }

    private static function runSqlScript(PDO $pdo, string $sql): void
    {
        $withoutComments = (string) preg_replace('/^--.*$/m', '', $sql);

        foreach (explode(';', $withoutComments) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    }

    // -------------------------------------------------------------------
    // Testserver-helpers
    // -------------------------------------------------------------------

    private static function startTestServer(): void
    {
        $docRoot = dirname(__DIR__, 2) . '/public_html';

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // putenv() + $env=null (i.e. "erf de omgeving van dit testproces
        // over") is betrouwbaarder dan zelf een env-array samenstellen: een
        // handmatig gebouwde array bleek de PHP-testserver een omgeving te
        // geven waarin de SMTP-verbinding naar Mailtrap niet meer lukte
        // (waarschijnlijk ontbrekende systeem-env-vars voor DNS/TLS).
        putenv('MEODRIVE_DB_NAME=' . self::TEST_DB_NAME);

        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', self::SERVER_HOST . ':' . self::SERVER_PORT, '-t', $docRoot],
            $descriptorSpec,
            self::$serverPipes,
            dirname(__DIR__, 2),
            null
        );

        if (!is_resource(self::$serverProcess)) {
            self::fail('Kon de PHP-testserver niet starten.');
        }

        self::waitUntilServerIsReady();
    }

    private static function waitUntilServerIsReady(): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            $connection = @fsockopen(self::SERVER_HOST, self::SERVER_PORT, $errno, $errstr, 0.5);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(100_000);
        }

        self::fail('De PHP-testserver reageerde niet binnen 10 seconden.');
    }

    private static function stopTestServer(): void
    {
        if (is_resource(self::$serverProcess)) {
            foreach (self::$serverPipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }

        putenv('MEODRIVE_DB_NAME');
    }
}
