<?php

namespace App\Support\Portal;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Tidies a person's short note into what its box asks for. Two shapes, chosen by the box:
 *
 *  - a **work log** (a report's remarks): a Date line then What / Changed / Wins, plus Reason where the
 *    form needs one. Structure is the point here, so the lines are labelled.
 *  - **prose** (a leave or reimbursement justification): one plain paragraph, no labels at all -- an
 *    approver reads a reason, not a form dump.
 *
 * The model only rewrites; this class pins the shape. It refuses anything that drifts (an unknown
 * label, a changed date, a rewritten note that lost its content), and it -- never the model -- writes
 * the `[Blank]` marker for a line the note could not support, so the person sees what is missing.
 *
 * The log prompt mirrors devtools/remark_formatter_probe.py -- keep the two in step.
 */
class PortalRemarkFormatter
{
    /** The boxes that want the labelled log, and whether that box also asks for a Reason. */
    private const LOG = [
        'report.daily' => false,
        'report.late' => true,
        'report.overtime' => true,
        'report.offset' => true,
    ];

    /** What a log emits, in order. What and Wins always carry something; Changed only when it is said. */
    private const LOG_ORDER = ['What', 'Changed', 'Wins'];

    /** The boxes that want plain prose, and the most the box will hold. */
    private const PROSE = [
        'request.leave' => 500,
        'request.reimbursement' => 120,
        // An approver's note on a decision: short, and the review panel tidies a whole batch of them.
        'approval.remark' => 120,
    ];

    /** Marks a line the note could not support, so the person fills it in. Never stored by a submit. */
    public const BLANK = '[Blank]';

    /**
     * Every box the formatter serves, for the request's own validation.
     *
     * @return list<string>
     */
    public static function contexts(): array
    {
        return array_merge(array_keys(self::LOG), array_keys(self::PROSE));
    }

    /** A log needs the day; prose does not. */
    public static function needsDay(string $context): bool
    {
        return array_key_exists($context, self::LOG);
    }

    /** The boxes a batch may carry: prose only, since a work log has no list shape. */
    public static function batchContexts(): array
    {
        return array_keys(self::PROSE);
    }

    /**
     * @param  string  $context  one of contexts(): the box being tidied
     * @param  string  $day  the day as its form shows it ("October 17, 2026"), for a log only
     *
     * @throws RuntimeException when the tidy is off, unreachable, or answers off its shape
     */
    public function format(string $context, string $remark, ?string $day = null): string
    {
        if (trim($remark) === '') {
            throw new RuntimeException('Write the remark first.');
        }
        if (! in_array($context, self::contexts(), true)) {
            throw new RuntimeException('That box has no tidy-up.');
        }

        $key = (string) config('services.ollama.key');
        if ($key === '') {
            throw new RuntimeException('The remark formatter is not configured.');
        }

        $reply = $this->ask($key, $context, trim($remark), $day);

        return array_key_exists($context, self::LOG)
            ? implode("\n", $this->asLog($reply, (string) $day, $context))
            : $this->asProse($reply, self::PROSE[$context]);
    }

    /**
     * Tidies a whole batch of notes in ONE call, for the box that holds several at once (an approver's
     * rows on the review panel). The answer is a JSON array of the same count, and the schema it is
     * asked in makes a malformed one all but impossible -- so the items map back by position, and
     * nothing is ever guessed or re-quoted. A slot whose note breaks a rule comes back null, which
     * leaves that row exactly as it was typed.
     *
     * @param  list<string>  $remarks
     * @return list<string|null>
     *
     * @throws RuntimeException when the tidy is off, unreachable, or answers off its shape
     */
    public function formatMany(string $context, array $remarks): array
    {
        if (! array_key_exists($context, self::PROSE)) {
            throw new RuntimeException('That box cannot be tidied in a batch.');
        }

        $notes = [];
        foreach ($remarks as $remark) {
            if (! is_string($remark) || trim($remark) === '') {
                throw new RuntimeException('Write the remarks first.');
            }
            $notes[] = trim($remark);
        }
        if ($notes === []) {
            throw new RuntimeException('Write the remarks first.');
        }

        $key = (string) config('services.ollama.key');
        if ($key === '') {
            throw new RuntimeException('The remark formatter is not configured.');
        }

        $cap = self::PROSE[$context];
        $items = $this->askMany($key, $notes, $cap);
        if (count($items) !== count($notes)) {
            throw new RuntimeException('The formatter answered off-template; try again.');
        }

        return array_map(fn (mixed $item): ?string => $this->batchItem($item, $cap), $items);
    }

    /** One item of a batch answer, or null when it broke a rule and its row should be left alone. */
    private function batchItem(mixed $item, int $cap): ?string
    {
        if (! is_string($item)) {
            return null;
        }
        $text = trim(preg_replace('/\s+/', ' ', $item) ?? '');
        if ($text === '' || strlen($text) > $cap) {
            return null;
        }
        foreach (explode("\n", $item) as $line) {
            if (preg_match('/^(date|what|changed|wins|reason)\s*:/i', trim($line)) === 1) {
                return null;
            }
        }

        return $text;
    }

    /**
     * The batch call itself: the notes as a JSON array, the same schema'd array back.
     *
     * @param  list<string>  $notes
     * @return list<mixed>
     */
    private function askMany(string $key, array $notes, int $cap): array
    {
        $host = rtrim((string) config('services.ollama.host'), '/');
        $count = count($notes);

        $response = Http::withToken($key)
            ->timeout((int) config('services.ollama.timeout'))
            ->acceptJson()
            ->post("{$host}/api/chat", [
                'model' => (string) config('services.ollama.model'),
                'messages' => [
                    ['role' => 'system', 'content' => self::batchPrompt($cap)],
                    ['role' => 'user', 'content' => (string) json_encode(array_values($notes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                ],
                'stream' => false,
                'options' => ['temperature' => 0],
                // The sampler itself keeps the array well-formed, the right length and each note short.
                'format' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'maxLength' => $cap],
                    'minItems' => $count,
                    'maxItems' => $count,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('The remark formatter is unavailable right now.');
        }

        $reply = $response->json('message.content');
        if (! is_string($reply) || trim($reply) === '') {
            throw new RuntimeException('The remark formatter gave no answer; try again.');
        }
        $reply = trim($reply);

        $decoded = json_decode($reply, true);
        if (! is_array($decoded)) {
            // One retry around the array itself, for an endpoint that ignored the schema. This only
            // trims what surrounds it; the content is never repaired.
            $start = strpos($reply, '[');
            $end = strrpos($reply, ']');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($reply, $start, $end - $start + 1), true);
            }
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('The formatter answered off-template; try again.');
        }

        return array_values($decoded);
    }

    private function ask(string $key, string $context, string $remark, ?string $day): string
    {
        $host = rtrim((string) config('services.ollama.host'), '/');
        $isLog = array_key_exists($context, self::LOG);
        $prompt = $isLog ? self::logPrompt($context) : self::prosePrompt(self::PROSE[$context]);
        $user = $isLog ? "Date: {$day}\nRemark: {$remark}" : "Note: {$remark}";

        $response = Http::withToken($key)
            ->timeout((int) config('services.ollama.timeout'))
            ->acceptJson()
            ->post("{$host}/api/chat", [
                'model' => (string) config('services.ollama.model'),
                'messages' => [
                    ['role' => 'system', 'content' => $prompt],
                    ['role' => 'user', 'content' => $user],
                ],
                'stream' => false,
                'options' => ['temperature' => 0],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('The remark formatter is unavailable right now.');
        }

        $reply = $response->json('message.content');
        if (! is_string($reply) || trim($reply) === '') {
            throw new RuntimeException('The remark formatter gave no answer; try again.');
        }

        return $reply;
    }

    private static function logPrompt(string $context): string
    {
        $reason = self::LOG[$context];
        $template = "Date: <the date you were given, word for word>\n"
            ."What: <what the person did>\n"
            ."Changed: <what changed because of it>\n"
            .'Wins: <anything worth calling a win>';
        if ($reason) {
            $template .= "\nReason: <why this was late, needed, or asked for>";
        }

        return <<<PROMPT
        You tidy one short work remark for a work log.
        Reply with the person's own facts, better written, in this exact template and nothing else --
        no preamble, no notes, no code fences:
        {$template}

        Rules:
        - Fix grammar, spelling, punctuation and word order. Write each line as work already done, in
          past tense, starting with a capital and ending with a period.
        - Keep the meaning exactly. Add no fact, number, name or date that the remark does not state.
        - Keep each line short -- about a dozen words.
        - Wins is always written: if the remark states no outcome, word the win from the work it already
          describes. Never claim anything the remark does not say.
        - Changed only when the remark states a change.
        - Leave out any line the remark says nothing about, and never write 'none', 'N/A' or 'nothing'.
        - Never invent the Date: copy the one you were given.
        - If the remark is blank, reply with the Date line alone.

        Worked example
        Remark: fix login bug n remove old banner, users can log in now
        What: Fixed the login bug and removed the old banner.
        Changed: Users can log in again.
        PROMPT;
    }

    /** The batch's own prompt: a list in, the same list out, nothing said about it. */
    private static function batchPrompt(int $cap): string
    {
        return <<<PROMPT
        You tidy short notes. You are given a JSON array of notes.
        Reply with ONLY a JSON array of strings -- the same notes, in the same order, the same count,
        one string per note. No commentary, no code fences, nothing before or after the array.

        Rules:
        - Fix grammar, spelling, punctuation and word order. Keep each note's meaning exactly.
        - Add no fact, number, name or date that a note does not state.
        - One short sentence per note, at most {$cap} characters. Never start a string with a word and a colon.

        Example
        ["no reciept", "taxi to client"] -> ["No receipt.", "Taxi to the client."]
        PROMPT;
    }

    private static function prosePrompt(int $cap): string
    {
        return <<<PROMPT
        You tidy one short note a person wrote to justify a request, so that an approver reads it easily.
        Reply with ONE paragraph of the person's own facts, better written, and nothing else -- no
        labels, no bullets, no headings, no preamble, no code fences.

        Rules:
        - Fix grammar, spelling, punctuation and word order.
        - Keep the meaning exactly. Add no fact, number, name or date that the note does not state.
        - One or two sentences, at most {$cap} characters. Never start a line with a word and a colon.
        - If the note is blank, reply with nothing.

        Worked example
        Note: i was sick yesterday so i couldnt file my report on time, sorry
        I was sick yesterday and could not file my report on time.
        PROMPT;
    }

    /**
     * The log lines, in the house order, with [Blank] where a required line could not be written.
     *
     * @return list<string>
     */
    private function asLog(string $reply, string $day, string $context): array
    {
        $values = $this->tidyLines($reply);

        if ($values === []) {
            throw new RuntimeException('The formatter answered off-template; try again.');
        }
        $allowed = self::LOG_ORDER;
        if (self::LOG[$context]) {
            $allowed[] = 'Reason';
        }
        foreach (array_keys($values) as $label) {
            if (! in_array($label, $allowed, true)) {
                throw new RuntimeException('The formatter answered off-template; try again.');
            }
        }

        // Date is the form's own day, never the model's rendering of it; What and Wins always carry
        // something, since a work log without them says nothing.
        $lines = ["Date: {$day}", 'What: '.$this->said($values, 'What')];
        if (($values['Changed'] ?? '') !== '') {
            $lines[] = 'Changed: '.$values['Changed'];
        }
        $lines[] = 'Wins: '.$this->said($values, 'Wins');
        if (self::LOG[$context]) {
            $lines[] = 'Reason: '.$this->said($values, 'Reason');
        }

        if (strlen(implode("\n", $lines)) > 500) {
            throw new RuntimeException('The tidy is longer than the box; shorten the note.');
        }

        return $lines;
    }

    /** A line's words, or the marker that says the person has to write it. */
    private function said(array $values, string $label): string
    {
        return ($values[$label] ?? '') === '' ? self::BLANK : $values[$label];
    }

    /**
     * The labelled lines of a log answer, labels normalised and the Date dropped (the form's own day is
     * the truth). Anything that is not one of our labels is ignored -- prose in, prose ignored out.
     *
     * @return array<string, string>
     */
    private function tidyLines(string $reply): array
    {
        $values = [];
        foreach (preg_split('/\R/', $reply) ?: [] as $line) {
            // A fenced answer is the same answer, so the fence lines go; a bolded sentence loses its
            // markers, since the box it lands in is plain text.
            $line = trim($line);
            if (str_starts_with($line, '```')) {
                continue;
            }
            if (preg_match('/^(date|what|changed|wins|reason)\s*:\s*(.*)$/i', $line, $match) === 1) {
                $label = ucfirst(strtolower($match[1]));
                $value = trim($match[2], ' `*_');
                if ($label !== 'Date' && ! isset($values[$label])) {
                    $values[$label] = $value;
                }

                continue;
            }
            // Any other field name means the model wrote a shape we did not ask for; better to refuse
            // than to swallow whatever it put there.
            if (preg_match('/^[A-Za-z][A-Za-z ]{0,20}:\s/', $line) === 1) {
                throw new RuntimeException('The formatter answered off-template; try again.');
            }
        }

        return $values;
    }

    /** The person's note back as one plain paragraph, or an off-shape/threshold refusal. */
    private function asProse(string $reply, int $cap): string
    {
        $lines = [];
        foreach (preg_split('/\R/', $reply) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, '```')) {
                continue;
            }
            // A prose answer never carries a field name; one means the model used the wrong shape.
            if (preg_match('/^(date|what|changed|wins|reason)\s*:/i', $line) === 1) {
                throw new RuntimeException('The formatter answered off-template; try again.');
            }
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $paragraph = trim(preg_replace('/\s+/', ' ', implode(' ', $lines)) ?? '');
        if ($paragraph === '') {
            throw new RuntimeException('The formatter gave no answer; try again.');
        }
        if (strlen($paragraph) > $cap) {
            throw new RuntimeException('The tidy is longer than the box; shorten the note.');
        }

        return $paragraph;
    }
}
