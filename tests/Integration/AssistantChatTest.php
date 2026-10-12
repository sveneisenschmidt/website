<?php

namespace Website\Tests\Integration;

use BlogAssistantError;
use Kirby\Exception\PermissionException;

/**
 * The chat of the assistant: rounds, stop, errors, Protokoll, prompt and
 * access. Ported from concertshotsbyluisa.de where the case holds here.
 */
final class AssistantChatTest extends AssistantTestCase
{
    public function testClaudesAnswersGoBackUnchanged(): void
    {
        $thinking = ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'];
        $find = $this->tool('t1', 'find_posts', ['type' => 'photo', 'query' => '', 'from' => '', 'to' => '']);
        $this->reply('tool_use', $thinking, $find);
        $this->reply('end_turn', $this->text('Es gibt ein Foto.'));

        $chat = $this->chat('Wie viele Fotos gibt es?');

        $this->assertSame([$thinking, $find], $this->requests[1]['messages'][1]['content']);
        $this->assertStringContainsString('"total":1', $this->results(1)[0]['content'][0]['text']);
        $this->assertContains(['type' => 'progress', 'lines' => ['Beiträge gesucht']], $chat['items']);
        $this->assertSame('claude-opus-5-5', $this->requests[0]['model']);
        $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $this->requests[0]['cache_control']);
    }

    public function testReadPostShowsFieldsFilesAndRoute(): void
    {
        $this->upload(__DIR__ . '/../fixtures/exif.jpg', 'a.jpg');
        $this->reply('tool_use', $this->tool('t1', 'read_post', ['post' => 'photos/test-photo']));
        $this->reply('end_turn');

        $this->chat('Lies das Foto.');

        $data = json_decode($this->results(1)[0]['content'][0]['text'], true);
        $this->assertSame('photos/test-photo', $data['id']);
        $this->assertSame('Test intro', $data['fields']['intro']['value']);
        $this->assertSame('Anriss', $data['fields']['intro']['label']);
        $this->assertArrayNotHasKey('mappoints', $data['fields']);
        $this->assertSame(['id' => 'photos/test-photo/a.jpg', 'filename' => 'a.jpg', 'texts' => ['alt' => '', 'caption' => '', 'photographer' => ''], 'camera' => ['camera' => 'TEST-CAM', 'lens' => 'Test Lens 24mm', 'focal' => '24', 'aperture' => '2.8', 'shutter' => '1/250', 'iso' => '3200']], $data['files'][0]);
        $this->assertSame(['file' => null, 'km' => 0, 'points' => 0, 'markers' => [], 'zoom' => false], $data['route']);
    }

    public function testAFailedRequestLeavesTheChatAsItWas(): void
    {
        $this->expectErrorLog();
        $id = blog_uploads_accept($this->jpeg(8, 8), 'a.jpg')['id'];
        $this->replies[] = [529, ['error' => ['message' => 'Overloaded']]];
        try {
            blog_assistant_send('Hallo');
            $this->fail('No error');
        } catch (BlogAssistantError $e) {
            $this->assertSame('busy', $e->kind);
        }
        $this->assertSame([], blog_assistant_load()['messages']);
        $this->assertSame([], blog_assistant_load()['items']);
        $this->assertSame([$id], array_column(blog_uploads_pending(), 'id'), 'The upload waits again.');
    }

    public function testAnEmptyBalanceIsItsOwnError(): void
    {
        $this->expectErrorLog();
        $this->replies[] = [400, ['error' => ['message' => 'Your credit balance is too low.']]];
        $this->expectExceptionMessage('Guthaben');
        blog_assistant_send('Hallo');
    }

    public function testARequestThatCannotWorkSuggestsANewChat(): void
    {
        $this->expectErrorLog();
        $this->replies[] = [404, ['error' => ['message' => 'model not found']]];
        $this->expectExceptionMessage('neuen Chat');
        blog_assistant_send('Hallo');
    }

    public function testAChoiceWaitsForSven(): void
    {
        $this->reply('tool_use', $this->tool('t1', 'ask_choice', ['question' => 'Welcher Tag?', 'options' => ['A', 'B']]));

        $chat = blog_assistant_send('Leg den Beitrag an.');

        $this->assertFalse($chat['next']);
        $this->assertSame(['type' => 'choice', 'question' => 'Welcher Tag?', 'options' => ['A', 'B']], end($chat['items']));
    }

    public function testAnswersAfterAStoppedLoopJoinTheToolResults(): void
    {
        $this->reply('tool_use', $this->tool('t1', 'read_post', ['post' => 'photos/test-photo']));
        blog_assistant_send('Lies das Foto.');
        blog_assistant_stop();
        $this->reply('end_turn', $this->text('Okay.'));

        blog_assistant_send('Lass gut sein.');

        $messages = end($this->requests)['messages'];
        $this->assertSame(['user', 'assistant', 'user'], array_column($messages, 'role'));
        $this->assertSame(['tool_result', 'text'], array_column($messages[2]['content'], 'type'));
    }

    public function testStopDuringARoundDropsItsAnswer(): void
    {
        $this->reply('tool_use', $this->tool('t1', 'read_post', ['post' => 'photos/test-photo']));
        blog_assistant_send('Lies das Foto.');
        $running = blog_assistant_load();

        blog_assistant_stop();
        $kept = blog_assistant_keep($running);

        $this->assertFalse($kept['next']);
        $this->assertSame(['type' => 'note', 'text' => 'Abgebrochen. Nichts geändert.'], end($kept['items']));
    }

    public function testABrokenToolCallAnswersAsError(): void
    {
        $this->reply('tool_use', $this->tool('t1', 'view_photo', ['photo' => 'photos/test-photo/missing.jpg', 'crop' => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]]), $this->tool('t2', 'propose_nothing', []));
        $this->reply('end_turn');

        $this->chat('Schau dir das Foto an.');

        $results = $this->results(1);
        $this->assertStringContainsString('No image found', $results[0]['content'][0]['text']);
        $this->assertSame('t2', $results[1]['tool_use_id']);
        $this->assertTrue($results[1]['is_error']);
    }

    public function testAWebSearchShowsAboveTheAnswerWithItsSources(): void
    {
        $this->replies[] = [200, ['stop_reason' => 'end_turn', 'content' => [
            ['type' => 'server_tool_use', 'id' => 's1', 'name' => 'web_search', 'input' => ['query' => 'Groitzsch campsite']],
            ['type' => 'web_search_tool_result', 'tool_use_id' => 's1', 'content' => [['type' => 'web_search_result', 'url' => 'https://camping.test/a', 'title' => 'Camping']]],
            ['type' => 'text', 'text' => 'Laut camping.test ist es offen.', 'citations' => [['type' => 'web_search_result_location', 'url' => 'https://camping.test/a', 'title' => 'Camping', 'cited_text' => '...']]],
        ]]];

        $chat = blog_assistant_send('Hat der Platz offen?');

        $this->assertSame(['progress', 'ai', 'sources'], array_slice(array_column($chat['items'], 'type'), 1));
        $this->assertSame([['url' => 'https://camping.test/a', 'title' => 'Camping']], end($chat['items'])['list']);
        $this->assertSame(['https://camping.test/a'], blog_assistant_view(blog_assistant_load())['sources']);
        $this->assertSame(['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 5], end($this->requests[0]['tools']));
    }

    public function testAPausedTurnGoesOn(): void
    {
        $this->replies[] = [200, ['stop_reason' => 'pause_turn', 'content' => [['type' => 'server_tool_use', 'id' => 's1', 'name' => 'web_search', 'input' => ['query' => 'x']]]]];
        $this->reply('end_turn', $this->text('Fertig.'));

        $chat = $this->chat('Such das.');

        $this->assertCount(2, $this->requests);
        $this->assertSame('assistant', end($this->requests[1]['messages'])['role']);
        $this->assertSame('Fertig.', end($chat['items'])['text']);
    }

    public function testAStoppedPausedSearchDoesNotBreakTheChat(): void
    {
        $this->replies[] = [200, ['stop_reason' => 'pause_turn', 'content' => [['type' => 'server_tool_use', 'id' => 's1', 'name' => 'web_search', 'input' => ['query' => 'x']]]]];
        blog_assistant_send('Such das.');
        blog_assistant_stop();
        $this->reply('end_turn', $this->text('Gut.'));

        blog_assistant_send('Lass gut sein.');

        $this->assertSame(['user'], array_column(end($this->requests)['messages'], 'role'));
    }

    public function testTheProtokollRecordsAnswersWithoutContent(): void
    {
        $this->replies[] = [200, ['stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
            'content' => [$this->tool('t1', 'find_posts', ['type' => '', 'query' => 'Geheimwort', 'from' => '', 'to' => ''])]]];
        $this->replies[] = [200, ['stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 50, 'cache_read_input_tokens' => 100, 'output_tokens' => 10], 'content' => [$this->text('Nichts gefunden.')]]];

        $this->chat('Such nach dem Geheimwort.');

        $answer = blog_assistant_log_read()[0];
        $this->assertSame('answer', $answer['type']);
        $this->assertSame(['find_posts' => 1], $answer['tools']);
        $this->assertSame(2, $answer['rounds']);
        $this->assertSame(250, $answer['tokensIn']);
        $this->assertSame(100, $answer['tokensCached']);
        $this->assertSame('ok', $answer['outcome']);
        $raw = implode('', array_map('file_get_contents', glob($this->root . '/content/.assistant/*.jsonl')));
        $this->assertStringNotContainsString('Geheimwort', $raw);
        $this->assertStringNotContainsString(self::PROMPT, $raw);
        $view = blog_assistant_log_view();
        $this->assertSame(1, $view['totals']['answers']);
        $this->assertSame('40 %', $view['rows'][0]['cached']);
    }

    public function testThereIsNoToolToDeleteOrPublish(): void
    {
        foreach (blog_assistant_tools() as $tool) {
            $this->assertMatchesRegularExpression('/^(find|read|view|propose|ask)_/', $tool['name']);
            $this->assertTrue($tool['strict']);
        }
    }

    public function testTheModelComesFromTheSettings(): void
    {
        $this->assertSame('claude-opus-5-5', blog_assistant_model());
        $this->kirby->site()->update(['assistantModel' => 'claude-haiku-5-5']);
        $this->assertSame('claude-haiku-5-5', blog_assistant_model());
        $this->kirby->site()->update(['assistantModel' => 'claude-retired-1']);
        $this->assertSame('claude-opus-5-5', blog_assistant_model());
    }

    public function testWithoutPromptTheAssistantIsOff(): void
    {
        $this->kirby->site()->update(['assistantPrompt' => '']);

        try {
            blog_assistant_send('Hallo');
            $this->fail('No error');
        } catch (BlogAssistantError $e) {
            $this->assertSame('prompt', $e->kind);
        }
        $this->assertSame([], $this->requests);
        $this->assertSame([], blog_assistant_load()['items']);
        $this->assertFalse(blog_assistant_view(blog_assistant_load())['hasPrompt']);
    }

    public function testTheSystemTextIsFrozenForTheChat(): void
    {
        $this->reply('end_turn', $this->text('Eins.'));
        $this->reply('end_turn', $this->text('Zwei.'));
        $this->chat('Erste Nachricht.');
        $this->kirby->site()->update(['assistantPrompt' => 'PROMPT-CANARY-NEW-91c2']);

        $this->chat('Zweite Nachricht.');

        $this->assertSame($this->requests[0]['system'], $this->requests[1]['system']);
        $this->assertSame(json_encode($this->requests[0]['tools']), json_encode($this->requests[1]['tools']));
        $this->assertSame(BLOG_ASSISTANT_SAFETY . "\n\n" . self::PROMPT, $this->requests[0]['system'][0]['text']);
        $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $this->requests[0]['system'][0]['cache_control']);

        blog_assistant_reset();
        $this->reply('end_turn');
        $this->chat('Neuer Chat.');

        $this->assertStringEndsWith('PROMPT-CANARY-NEW-91c2', $this->requests[2]['system'][0]['text']);
    }

    public function testTheDateComesAsANoteOncePerDay(): void
    {
        $this->reply('end_turn');
        $this->reply('end_turn');
        $this->reply('end_turn');
        $this->chat('Eins.');
        $this->chat('Zwei.');
        $chat = blog_assistant_load();
        $chat['date'] = '2000-01-01';
        blog_assistant_store($chat);

        $this->chat('Drei.');

        $this->assertStringStartsWith('Today is ', $this->lastText($this->requests[0]));
        $this->assertSame('Zwei.', $this->lastText($this->requests[1]));
        $this->assertStringStartsWith('Today is ', $this->lastText($this->requests[2]));
        $this->assertSame($this->requests[0]['system'], $this->requests[2]['system']);
    }

    public function testUploadsGoWithTheNextMessageOnce(): void
    {
        $id = blog_uploads_accept($this->gpsJpeg(1, 'a.jpg'), 'IMG_7.JPG')['id'];
        $this->reply('end_turn');
        $this->reply('end_turn');

        $chat = $this->chat('Hier ist ein Foto.');
        $this->chat('Und jetzt?');

        $first = $this->requests[0]['messages'][0]['content'];
        $this->assertSame(['image', 'text', 'text'], array_column($first, 'type'));
        $this->assertStringContainsString("Upload $id: file name img_7.jpg, 64 x 48 px, camera GPS-CAM.", $first[1]['text']);
        $this->assertSame(['img_7.jpg'], $chat['items'][0]['files']);
        $this->assertSame([], blog_uploads_pending());
        $this->assertSame(['text'], array_column($this->requests[1]['messages'][2]['content'], 'type'));
        $this->assertSame(['type' => 'blog_image', 'file' => "$id.preview.jpg"], blog_assistant_load()['messages'][0]['content'][0], 'The session keeps the name, not the bytes.');
    }

    public function testAnUploadRequestTakesExactlyOneFile(): void
    {
        $file = ['name' => 'a.jpg', 'tmp_name' => $this->jpeg(8, 8), 'error' => UPLOAD_ERR_OK, 'size' => 100];
        foreach ([[], ['a' => $file, 'b' => $file], ['file' => $file]] as $files) {
            try {
                blog_assistant_upload($files);
                $this->fail('Accepted');
            } catch (BlogAssistantError $e) {
                $this->assertSame('upload', $e->kind);
            }
        }
        $this->assertSame([], blog_uploads_pending(), 'A file that did not come through PHP\'s upload is refused too.');
    }

    public function testLogoutTakesTheUploadsAlong(): void
    {
        $id = blog_uploads_accept($this->jpeg(8, 8), 'a.jpg')['id'];
        $folder = dirname(blog_uploads_meta($id)['path']);

        $this->kirby->trigger('user.logout:before', ['user' => $this->kirby->user(), 'session' => $this->kirby->session()]);

        $this->assertDirectoryDoesNotExist($folder);
    }

    public function testOnlyAdminsReachTheAssistant(): void
    {
        $this->kirby->users()->create(['id' => 'editor', 'email' => 'editor@website.test', 'role' => 'editor', 'password' => 'editor-password-1']);
        $this->kirby->impersonate('editor');

        try {
            $this->kirby->api()->call('blog-assistant/send', 'POST', ['body' => ['text' => 'Hallo']]);
            $this->fail('No error');
        } catch (PermissionException) {
        }
        $this->assertSame([], $this->requests);
        $this->assertArrayNotHasKey('views', $this->kirby->load()->areas()['assistent']);
        $this->assertNull(blog_assistant_button($this->post));

        $this->kirby->impersonate('kirby');
        $this->assertArrayHasKey('views', $this->kirby->load()->areas()['assistent']);
        $this->assertSame('assistent?page=photos%2Ftest-photo', blog_assistant_button($this->post)->link);
    }
}
