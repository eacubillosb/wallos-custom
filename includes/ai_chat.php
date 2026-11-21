<?php

// Pieces of Dia's chat endpoint that need no database or network, so the
// tests can cover them: the per-user rate limit and the message list.

const AI_CHAT_RATE_LIMIT = 8;          // messages ...
const AI_CHAT_RATE_WINDOW = 60;        // ... per this many seconds
const AI_CHAT_DAILY_LIMIT = 150;       // and at most this many per day
const AI_CHAT_HISTORY_TURNS = 8;
const AI_CHAT_TURN_MAX_CHARS = 2000;

/*
  $timestamps are the Unix times of this user's earlier messages.
  Returns [allowed, timestamps to store]. A refused message is not counted.
*/
function ai_chat_rate_check(array $timestamps, $now)
{
    $now = (int) $now;
    $dayAgo = $now - 86400;
    $recent = array_values(array_filter(array_map('intval', $timestamps), function ($time) use ($dayAgo, $now) {
        return $time > $dayAgo && $time <= $now;
    }));

    $windowStart = $now - AI_CHAT_RATE_WINDOW;
    $inWindow = count(array_filter($recent, function ($time) use ($windowStart) {
        return $time > $windowStart;
    }));

    if ($inWindow >= AI_CHAT_RATE_LIMIT || count($recent) >= AI_CHAT_DAILY_LIMIT) {
        return [false, $recent];
    }

    $recent[] = $now;
    return [true, $recent];
}

function ai_chat_truncate($text, $maxChars)
{
    $text = trim((string) $text);
    if (function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $maxChars ? mb_substr($text, 0, $maxChars, 'UTF-8') : $text;
    }
    // Without mbstring, cut on bytes and drop a broken trailing character.
    return strlen($text) > $maxChars ? (string) iconv('UTF-8', 'UTF-8//IGNORE', substr($text, 0, $maxChars)) : $text;
}

/*
  The browser's chat history, cleaned: the last few turns, both sides, each
  trimmed. Turns must alternate for some providers, so consecutive turns of
  the same role are merged.
*/
function ai_chat_clean_history($history)
{
    if (!is_array($history)) {
        return [];
    }

    $clean = [];
    foreach (array_slice($history, -AI_CHAT_HISTORY_TURNS) as $turn) {
        if (!is_array($turn)) {
            continue;
        }
        $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $content = ai_chat_truncate($turn['content'] ?? '', AI_CHAT_TURN_MAX_CHARS);
        if ($content === '') {
            continue;
        }
        $last = count($clean) - 1;
        if ($last >= 0 && $clean[$last]['role'] === $role) {
            $clean[$last]['content'] .= "\n\n" . $content;
        } else {
            $clean[] = ['role' => $role, 'content' => $content];
        }
    }

    // The conversation sent to the model starts with the user.
    while ($clean && $clean[0]['role'] === 'assistant') {
        array_shift($clean);
    }

    return $clean;
}

/*
  System instructions, then the earlier turns, then the new question. If the
  history ends on a user turn (an earlier message got no reply), the new
  question joins it so roles still alternate.
*/
function ai_chat_messages($systemPrompt, array $cleanHistory, $message)
{
    $messages = [['role' => 'system', 'content' => (string) $systemPrompt]];
    foreach ($cleanHistory as $turn) {
        $messages[] = $turn;
    }

    $last = count($messages) - 1;
    if ($messages[$last]['role'] === 'user') {
        $messages[$last]['content'] .= "\n\n" . $message;
    } else {
        $messages[] = ['role' => 'user', 'content' => (string) $message];
    }

    return $messages;
}
