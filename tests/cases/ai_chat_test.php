<?php

require_once DIAMON_ROOT . '/includes/ai_chat.php';
require_once DIAMON_ROOT . '/includes/ai_client.php';

diamon_test('Dia allows a burst of messages, then refuses until the minute passes', function () {
    $now = 1000000;
    $times = [];
    for ($i = 0; $i < AI_CHAT_RATE_LIMIT; $i++) {
        [$allowed, $times] = ai_chat_rate_check($times, $now);
        assert_true($allowed, 'message ' . ($i + 1) . ' within the limit is allowed');
    }
    [$allowed, $after] = ai_chat_rate_check($times, $now);
    assert_true(!$allowed, 'one more in the same minute is refused');
    assert_same(count($times), count($after), 'a refused message is not counted');

    [$allowed] = ai_chat_rate_check($times, $now + AI_CHAT_RATE_WINDOW + 1);
    assert_true($allowed, 'after the window, Dia answers again');
});

diamon_test('Dia caps messages per day and forgets times older than a day', function () {
    $now = 2000000;
    $times = [];
    for ($i = 0; $i < AI_CHAT_DAILY_LIMIT; $i++) {
        $times[] = $now - 3600 - $i * 120;
    }
    [$allowed] = ai_chat_rate_check($times, $now);
    assert_true(!$allowed, 'the daily cap refuses');

    [$allowed, $kept] = ai_chat_rate_check(array_merge([$now - 90000, 'junk'], array_slice($times, 0, 3)), $now);
    assert_true($allowed, 'with old times dropped there is room again');
    assert_same(4, count($kept), 'only the last day plus the new message are kept');
});

diamon_test('chat history keeps both sides, alternates roles and trims long turns', function () {
    $history = [
        ['role' => 'assistant', 'content' => 'Hola, soy Dia.'],
        ['role' => 'user', 'content' => '¿Cuánto pago al mes?'],
        ['role' => 'assistant', 'content' => 'Pagas 30.000 pesos.'],
        ['role' => 'assistant', 'content' => 'Y Netflix es la más cara.'],
        ['role' => 'hacker', 'content' => str_repeat('é', AI_CHAT_TURN_MAX_CHARS + 50)],
        'not a turn',
        ['role' => 'user', 'content' => '   '],
    ];
    $clean = ai_chat_clean_history($history);

    assert_same('user', $clean[0]['role'], 'a leading assistant greeting is dropped');
    assert_same('assistant', $clean[1]['role'], 'assistant replies are kept');
    assert_contains('Netflix', $clean[1]['content'], 'two assistant turns in a row are merged');
    assert_same('user', $clean[2]['role'], 'an unknown role is treated as the user');
    if (function_exists('mb_strlen')) {
        assert_same(AI_CHAT_TURN_MAX_CHARS, mb_strlen($clean[2]['content'], 'UTF-8'), 'long turns are cut on characters, not bytes');
    }
    assert_true(json_encode($clean) !== false, 'the trimmed history is still valid UTF-8');
    assert_same(3, count($clean), 'empty and malformed turns are dropped');
    assert_same([], ai_chat_clean_history('nope'), 'a non-list history is ignored');
});

diamon_test('the chat message list is system, history, then the question', function () {
    $messages = ai_chat_messages('rules', [
        ['role' => 'user', 'content' => 'a'],
        ['role' => 'assistant', 'content' => 'b'],
    ], 'c');
    assert_same(['system', 'user', 'assistant', 'user'], array_column($messages, 'role'), 'roles in order');
    assert_same('c', $messages[3]['content'], 'the new question is last');

    $merged = ai_chat_messages('rules', [['role' => 'user', 'content' => 'unanswered']], 'again');
    assert_same(['system', 'user'], array_column($merged, 'role'), 'an unanswered question joins the new one');
    assert_same("unanswered\n\nagain", $merged[1]['content'], 'both questions are kept');
});

diamon_test('each AI provider gets its own chat payload', function () {
    $messages = ai_normalize_messages([
        ['role' => 'system', 'content' => 'rules'],
        ['role' => 'user', 'content' => 'hi'],
        ['role' => 'assistant', 'content' => 'hello'],
        ['role' => 'user', 'content' => ''],
    ]);
    assert_same(3, count($messages), 'empty messages are dropped');

    $openai = ai_provider_payload('chatgpt', 'gpt', $messages);
    assert_same($messages, $openai['messages'], 'OpenAI-style providers take the roles as they are');

    $gemini = ai_provider_payload('gemini', 'g', $messages);
    assert_same('rules', $gemini['systemInstruction']['parts'][0]['text'], 'Gemini gets the system text apart');
    assert_same(['user', 'model'], array_column($gemini['contents'], 'role'), 'Gemini calls the assistant "model"');

    $ollamaChat = ai_provider_payload('ollama', 'llama', $messages, true);
    assert_same($messages, $ollamaChat['messages'], 'Ollama chat takes messages');

    $plain = ai_normalize_messages('one prompt');
    assert_same([['role' => 'user', 'content' => 'one prompt']], $plain, 'a string prompt is one user message');
    $ollamaGenerate = ai_provider_payload('ollama', 'llama', $plain, false);
    assert_same('one prompt', $ollamaGenerate['prompt'], 'a string prompt still uses Ollama generate');
    assert_same(false, ai_provider_payload('openai-compatible', 'm', $plain)['stream'], 'openai-compatible keeps stream off');
});
