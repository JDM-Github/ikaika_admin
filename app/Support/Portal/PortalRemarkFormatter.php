<?php

namespace App\Support\Portal;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Turns a person's short note into the house template -- a Date line, then What / Changed / Wins
 * lines, each one sentence of their own facts, tidied up. The model only rewrites and structures;
 * this class pins the shape and refuses anything that drifts (an unknown label, a changed date, or a
 * rewritten note that lost its content), so a bad answer never reaches the form.
 *
 * The prompt here mirrors devtools/remark_formatter_probe.py -- keep the two in step.
 */
class PortalRemarkFormatter
{
    /** The only field names the template uses; anything else is a drifting answer. */
    private const LABELS = ['What', 'Changed', 'Wins'];

    private const SYSTEM = <<<'PROMPT'
    You tidy one short work remark for a daily work log.
    Reply with the person's own facts, better written, in this exact template and nothing else --
    no preamble, no notes, no code fences:
    Date: <the date you were given, word for word>
    What: <what the person did>
    Changed: <what changed because of it>
    Wins: <anything worth calling a win>

    Rules:
    - Fix grammar, spelling, punctuation and word order. Write each line as work already done, in past
      tense, starting with a capital and ending with a period.
    - Keep the meaning exactly. Add no fact, number, name or date that the remark does not state.
    - Keep each line short -- about a dozen words.
    - Leave out any field the remark says nothing about. Never write 'none', 'N/A' or 'nothing'.
    - Never invent the Date: copy the one you were given.
    - If the remark is blank, reply with the Date line alone.

    Worked example
    Remark: fix login bug n remove old banner, users can log in now
    What: Fixed the login bug and removed the old banner.
    Changed: Users can log in again.
    PROMPT;

    /**
     * @param  string  $day  the day as the form shows it ("October 17, 2026"), copied into the Date line
     *
     * @throws RuntimeException when the formatter is off, unreachable, or answers off-template
     */
    public function format(string $remark, string $day): string
    {
        if (trim($remark) === '') {
            throw new RuntimeException('Write the remark first.');
        }

        $key = (string) config('services.ollama.key');
        if ($key === '') {
            throw new RuntimeException('The remark formatter is not configured.');
        }

        $lines = $this->validated($this->ask($key, trim($remark), $day), $day);
        if (count($lines) === 1) {
            throw new RuntimeException('The formatter lost the remark; write it yourself.');
        }

        return implode("\n", $lines);
    }

    private function ask(string $key, string $remark, string $day): string
    {
        $host = rtrim((string) config('services.ollama.host'), '/');
        $model = (string) config('services.ollama.model');

        $response = Http::withToken($key)
            ->timeout((int) config('services.ollama.timeout'))
            ->acceptJson()
            ->post("{$host}/api/chat", [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM],
                    ['role' => 'user', 'content' => "Date: {$day}\nRemark: {$remark}"],
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

    /**
     * The template lines only, labels normalised, or an exception. Guards the three ways an answer
     * can be wrong: the wrong Date, a field that is not one of ours, or a Date line with nothing under it.
     *
     * @return list<string>
     */
    private function validated(string $reply, string $day): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $reply) ?: [] as $line) {
            // A fenced answer is the same answer, so the fence lines go; a bolded sentence loses its
            // markers, since the box it lands in is plain text.
            $line = trim($line);
            if (str_starts_with($line, '```')) {
                continue;
            }
            if (preg_match('/^(date|what|changed|wins)\s*:\s*(.*)$/i', $line, $match) === 1) {
                $lines[] = ucfirst(strtolower($match[1])).': '.trim($match[2], ' `*_');
            }
        }

        if ($lines === [] || ! str_starts_with($lines[0], 'Date:')) {
            throw new RuntimeException('The formatter answered off-template; try again.');
        }
        if (! str_contains($lines[0], $day)) {
            throw new RuntimeException('The formatter changed the date; try again.');
        }
        foreach (array_slice($lines, 1) as $line) {
            if (! in_array(strstr($line, ':', true), self::LABELS, true)) {
                throw new RuntimeException('The formatter answered off-template; try again.');
            }
        }

        return $lines;
    }
}
