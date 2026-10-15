<?php

namespace Website\Tests\Integration;

/**
 * Locally there is no mail server. Without env.php, config.php hands every
 * mail of Kirby to bin/mail-catcher.php, which saves it in storage/mail/.
 * The tests run without env.php, as the local site does.
 */
final class MailCatcherTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The catcher prints each mail in the terminal of make dev. phpunit.xml
        // fails a test on output, so the catcher stays quiet here.
        putenv('MAIL_CATCHER_QUIET=1');
    }

    protected function tearDown(): void
    {
        putenv('MAIL_CATCHER_QUIET');
        // The challenge lives in the session. Kirby would write the session
        // at shutdown, after parent::tearDown() has removed the test site.
        $this->kirby->session()->destroy();
        parent::tearDown();
    }

    public function testThePanelLoginCodeLandsInStorage(): void
    {
        $this->kirby->impersonate('kirby', fn() => $this->kirby->users()->create([
            'email' => 'author@example.com',
            'role' => 'admin',
            'password' => 'test-password-1234',
        ]));

        $this->kirby->auth()->createChallenge('author@example.com', false, '2fa');

        $files = glob($this->root . '/storage/mail/*.eml') ?: [];
        $this->assertCount(1, $files);
        $mail = quoted_printable_decode((string) file_get_contents($files[0]));
        $this->assertStringContainsString('To: author@example.com', $mail);
        $this->assertStringContainsString('From: ', $mail);
        $this->assertStringContainsString('panel@localhost.test', $mail);
        $this->assertMatchesRegularExpression('/\b\d{3} \d{3}\b/', $mail);
    }

    public function testTheCatcherPrintsSubjectAndTextInTheTerminal(): void
    {
        $mail = "From: panel@localhost.test\r\nTo: author@example.com\r\nSubject: =?UTF-8?Q?Dein_Anmeldecode_f=C3=BCr_die_Website?=\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\nDein Code: 123 456, g=C3=BCltig 10 Minuten.\r\n";
        $dir = $this->root . '/caught';
        $proc = proc_open([PHP_BINARY, __DIR__ . '/../../bin/mail-catcher.php', $dir, '-i', '-t'], [0 => ['pipe', 'r'], 2 => ['pipe', 'w']], $pipes, null, ['MAIL_CATCHER_QUIET' => '']);
        $this->assertIsResource($proc);
        fwrite($pipes[0], $mail);
        fclose($pipes[0]);
        $terminal = (string) stream_get_contents($pipes[2]);

        $this->assertSame(0, proc_close($proc));
        $this->assertStringContainsString('Mail: Dein Anmeldecode für die Website', $terminal);
        $this->assertStringContainsString('Dein Code: 123 456, gültig 10 Minuten.', $terminal);
        $this->assertSame($mail, file_get_contents((glob("$dir/*.eml") ?: [''])[0]));
    }
}
