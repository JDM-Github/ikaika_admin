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

    /**
     * @param  string  $context  one of contexts(): the box being tidied
     * @param  string  $day      the day as its form shows it ("October 17, 2026"), for a log only
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
            ? $this->asLog($reply, (string) $day, $context)
            : $this->asProse($reply, self::PROSE[$context]);
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
        $reason = in_array('Reason', self::LOG[$context], true);
        $template = "Date: <the date you were given, word for word>\n"
            ."What: <what the person did>\n"
            ."Changed: <what changed because of it>\n"
            ."Wins: <anything worth calling a win>";
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
        foreach (array_keys($values) as $label) {
            if ($label !== 'Date' && ! in_array($label, self::LOG[$context], true) && $label !== 'What'
                && $label !== 'Changed' && $label !== 'Wins') {
                throw new RuntimeException('The formatter answered off-template; try again.');
            }
        }

        $lines = ["Date: {$day}"];
        foreach (self::LOG_ORDER as $label) {
            $allowed = $label !== 'Reason' || in_array('Reason', self::LOG[$context], true);
            if (! $allowed) {
                continue;
            }
            $value = $values[$label] ?? '';
            if ($value === '' && ! in_array($label, self::LOG_REQUIRED, true) && $label !== 'Reason') {
                continue;
            }
            $lines[] = $label.': '.($value === '' ? self::BLANK : $value);
        }

        if (count($lines) === 1 || strlen(implode("\n", $lines)) > 500) {
            throw new RuntimeException('The formatter lost the remark; write it yourself.');
        }

        return $lines;
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
            if (preg_match('/^(date|what|changed|wins|reason)\s*:\s*(.*)$/i', $line, $match) !== 1) {
                continue;
            }
            $label = ucfirst(strtolower($match[1]));
            $value = trim($match[2], " `*_");
            if ($label !== 'Date' && ! isset($values[$label])) {
                $values[$label] = $value;
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
