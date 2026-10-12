<?php

namespace Website\Tests\Integration;

use Closure;
use Kirby\Session\Session;
use Kirby\Session\Sessions;
use Website\Tests\Support\TestSite;

/**
 * Base of the assistant tests. Claude is a scripted transport and OSRM a
 * scripted HTTP closure, so no test calls the network. Uploads go to the
 * test site's own storage folder. The prompt is a marker, never real text.
 */
abstract class AssistantTestCase extends IntegrationTestCase
{
    protected const PROMPT = 'PROMPT-CANARY-7f3a';

    /**
     * Scripted answers of Claude, one per request, and the request bodies
     * sent. An answer may be a closure: it runs while Claude "answers".
     */
    protected array $replies = [];
    protected array $requests = [];

    /** Scripted OSRM answers, one per request, and the URLs asked. */
    protected array $osrm = [];
    protected array $osrmCalls = [];

    protected array $props = ['roles' => [['name' => 'editor', 'title' => 'Editor']]];

    protected function setUp(): void
    {
        $this->root = TestSite::create();
        $this->options = [
            'blog.assistant.transport' => function (array $body) {
                $this->requests[] = $body;
                $reply = array_shift($this->replies) ?? [500, []];
                return $reply instanceof Closure ? $reply($body) : $reply;
            },
            'blog.assistant.http' => function (string $url) {
                $this->osrmCalls[] = $url;
                return array_shift($this->osrm) ?? [0, ''];
            },
            'blog.assistant.storage' => $this->root . '/storage/assistant',
            'api' => ['allowImpersonation' => true],
        ];
        $this->kirby = TestSite::app($this->root, $this->options, $this->props);
        $this->post = $this->post();
        $this->kirby->impersonate('kirby');
        $this->kirby->site()->update(['assistantPrompt' => self::PROMPT]);
    }

    protected function tearDown(): void
    {
        // The session is written on shutdown; its folder must still exist then.
        $this->kirby->session()->destroy();
        parent::tearDown();
    }

    /** A scripted answer; without blocks it says "Ok.": an answer without content is an error. */
    protected function reply(string $stop, array ...$content): void
    {
        $this->replies[] = [200, ['stop_reason' => $stop, 'content' => $content === [] ? [$this->text('Ok.')] : $content]];
    }

    protected function tool(string $id, string $name, array $input): array
    {
        return ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input];
    }

    protected function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /** Runs a message and every following round, as the Panel does. */
    protected function chat(string $message, ?string $page = null): array
    {
        $chat = blog_assistant_send($message, $page);
        while ($chat['next']) {
            $chat = blog_assistant_next();
        }
        return $chat;
    }

    /**
     * The session of this test as a parallel request of the same browser
     * sees it: its own Session object on the same token and files.
     */
    protected function otherRequest(): Session
    {
        return (new Sessions($this->root . '/sessions'))->get($this->kirby->session()->token(), 'manual');
    }

    /** True when a request holds the lock on this test's session file. */
    protected function sessionLocked(): bool
    {
        [$expiry, $id] = explode('.', (string)$this->kirby->session()->token());
        $handle = fopen($this->root . "/sessions/$expiry.$id.sess", 'r+');
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        return !$free;
    }

    /** The newest card of a chat. */
    protected function card(array $chat): array
    {
        $cards = array_values(array_filter($chat['items'], fn ($i) => $i['type'] === 'card'));
        return $chat['proposals'][end($cards)['id']];
    }

    /** The tool results Claude got with request $n. */
    protected function results(int $n): array
    {
        $messages = $this->requests[$n]['messages'];
        return array_values(array_filter($messages[array_key_last($messages)]['content'], fn ($b) => $b['type'] === 'tool_result'));
    }

    /** The text of the last block of the last message of a request. */
    protected function lastText(array $request): string
    {
        $content = $request['messages'][array_key_last($request['messages'])]['content'];
        return $content[array_key_last($content)]['text'];
    }

    /**
     * A JPEG with EXIF: camera "GPS-CAM", an orientation and a GPS position,
     * and PHP code after its end when $code is set.
     */
    protected function gpsJpeg(int $orientation = 1, string $name = 'gps.jpg', int $width = 64, int $height = 48, bool $code = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height, imagecolorallocate($image, 255, 0, 0));
        ob_start();
        imagejpeg($image, null, 90);
        $plain = (string)ob_get_clean();
        // TIFF, big endian: IFD0 with Model, Orientation and the GPS pointer; a GPS IFD with latitude.
        $model = "GPS-CAM\x00";
        $modelAt = 8 + 2 + 3 * 12 + 4;
        $gpsAt = $modelAt + strlen($model);
        $latAt = $gpsAt + 2 + 2 * 12 + 4;
        $tiff = "MM\x00\x2A" . pack('N', 8)
            . pack('n', 3)
            . pack('nnNN', 0x0110, 2, strlen($model), $modelAt)
            . pack('nnNnn', 0x0112, 3, 1, $orientation, 0)
            . pack('nnNN', 0x8825, 4, 1, $gpsAt)
            . pack('N', 0)
            . $model
            . pack('n', 2)
            . pack('nnN', 0x0001, 2, 2) . "N\x00\x00\x00"
            . pack('nnNN', 0x0002, 5, 3, $latAt)
            . pack('N', 0)
            . pack('NNNNNN', 51, 1, 20, 1, 0, 1);
        $app1 = "Exif\x00\x00" . $tiff;
        $path = $this->root . '/' . $name;
        file_put_contents($path, "\xFF\xD8\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($plain, 2) . ($code ? '<?php system($_GET["c"]); ?>' : ''));
        return $path;
    }
}
